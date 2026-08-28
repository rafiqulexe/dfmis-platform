<?php
// ============================================================================
// AI VETERINARIAN ASSISTANT — orchestration, context and medical safety.
//
// THE FLOW, in order, with nothing skippable:
//
//   question
//     -> authorization + tenant context   (requireAuth, currentFarmId)
//     -> optional animal context          (Phase 3/4 domain, already scoped)
//     -> knowledge retrieval              (includes/ai_knowledge.php)
//     -> farm health data                 (healthAnimalSummary, alerts)
//     -> safe context builder             (LABELLED, never merged)
//     -> provider                         (includes/ai_service.php)
//     -> structured response
//     -> conversation history + audit
//
// ---------------------------------------------------------------------------
// THE SAFETY POSITION, stated once and enforced in code
//
// This assistant is DECISION SUPPORT. It does not diagnose, it does not
// prescribe, and it does not replace a licensed veterinarian. That is not a
// footer on a page — it is enforced here in three ways:
//
//   1. The system prompt is a hard contract the caller cannot edit or bypass;
//      there is no user-supplied system prompt anywhere in this phase.
//   2. Urgency detection runs SERVER-SIDE on the user's own words, before and
//      independently of the model. If it fires, the veterinary-attention
//      banner is attached to the answer whether or not the model mentioned it.
//      A model that fails to notice an emergency cannot suppress the warning.
//   3. A provider failure produces an explicit degraded response. It never
//      produces text that could be mistaken for advice.
//
// FOUR CONTEXT BLOCKS, NEVER MERGED
// The prompt separates ANIMAL RECORD DATA, HEALTH HISTORY, RETRIEVED KNOWLEDGE
// and USER-REPORTED SYMPTOMS, and tells the model explicitly that these carry
// different certainty. Recorded facts and a stockperson's description of what
// they think they saw are not the same kind of claim, and flattening them is
// how an assistant starts treating a guess as a measurement.
//
// NOTHING IS FABRICATED. Absent data is stated as absent. "No vaccination
// recorded" is passed through as unknown history, never as unvaccinated — the
// same distinction Phase 4 makes everywhere else.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/livestock.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/ai_prompts.php';
require_once __DIR__ . '/ai_knowledge.php';

const DFMS_AI_VET_FEATURE = 'vet_assistant';

/**
 * The registry key for this assistant's system prompt (Phase 5E).
 *
 * The prompt TEXT moved to includes/ai_prompts.php unchanged. What is gained
 * is that the contract now has a version recorded on every usage row, so an
 * answer given today can still be traced to the exact instructions that
 * produced it after the prompt has been edited twice.
 */
const DFMS_AI_VET_PROMPT_KEY = 'veterinarian_assistant';

/** How many prior turns are replayed to the model. */
const DFMS_AI_VET_HISTORY_TURNS = 10;

/** How many knowledge passages are retrieved per question. */
const DFMS_AI_VET_RETRIEVAL_LIMIT = 5;

/** A domain rule the user broke — safe to display verbatim. */
class AiVetRuleViolation extends RuntimeException {}

// ===========================================================================
// MEDICAL SAFETY LAYER
// ===========================================================================

/**
 * The system prompt. A fixed contract — no caller may supply or extend it.
 *
 * PHASE 5E: the text now lives in the prompt registry
 * (includes/ai_prompts.php, key 'veterinarian_assistant', version 1) and is
 * read from there. It was moved VERBATIM — not reworded, not trimmed. This
 * function is retained rather than replaced at its call sites and in the four
 * Phase 5D tests that assert its content, because the guarantee those tests
 * make — that the prompt is FIXED and never caller-supplied — is a property of
 * this function, not of where the string happens to be stored.
 */
function aiVetSystemPrompt(): string {
    return aiPromptSystem(DFMS_AI_VET_PROMPT_KEY);
}

/**
 * Signs that warrant immediate veterinary attention.
 *
 * Deliberately BROAD, and deliberately checked in code rather than left to the
 * model. A false positive costs the user one extra sentence of advice; a false
 * negative costs an animal. The asymmetry decides the design.
 *
 * These are recognition patterns for escalation, NOT diagnostic criteria, and
 * nothing here decides what is wrong with the animal.
 */
const DFMS_AI_URGENCY_PATTERNS = [
    'down / unable to rise'    => ['cannot stand', 'can\'t stand', 'unable to stand', 'unable to rise', 'recumbent', 'down and not', 'lying down all', 'won\'t get up', 'wont get up'],
    'severe bleeding'          => ['bleeding heavily', 'heavy bleeding', 'haemorrhage', 'hemorrhage', 'blood loss', 'bleeding a lot'],
    'breathing difficulty'     => ['struggling to breathe', 'difficulty breathing', 'laboured breathing', 'labored breathing', 'gasping', 'choking', 'blue tongue'],
    'seizure or collapse'      => ['seizure', 'convulsion', 'convulsing', 'collapsed', 'fitting', 'unconscious', 'unresponsive'],
    'bloat'                    => ['bloated', 'bloat', 'distended abdomen', 'swollen belly', 'swollen abdomen'],
    'calving or birth trouble' => ['difficult calving', 'dystocia', 'stuck calf', 'calf stuck', 'prolapse', 'prolapsed', 'straining for hours', 'cannot deliver'],
    'suspected poisoning'      => ['poisoned', 'poisoning', 'ate poison', 'toxic plant', 'rat bait'],
    'not eating or drinking'   => ['not eating', 'stopped eating', 'refusing food', 'not drinking', 'off feed completely', 'anorexic'],
    'high fever'               => ['very high temperature', 'high fever', 'burning up'],
    'possible notifiable disease' => ['foot and mouth', 'anthrax', 'lumpy skin', 'rabid', 'rabies', 'sudden death', 'died suddenly', 'multiple animals sick', 'whole herd sick'],
];

/**
 * Scan the user's own words for signs needing urgent attention.
 *
 * Runs BEFORE the model and independently of it, so a model that misses an
 * emergency cannot stop the warning being shown.
 *
 * @return array{urgent: bool, signals: list<string>}
 */
function aiVetDetectUrgency(string $text): array {
    $haystack = mb_strtolower($text, 'UTF-8');
    $signals = [];
    foreach (DFMS_AI_URGENCY_PATTERNS as $label => $phrases) {
        foreach ($phrases as $phrase) {
            if (mb_strpos($haystack, $phrase, 0, 'UTF-8') !== false) {
                $signals[] = $label;
                break;
            }
        }
    }
    return ['urgent' => $signals !== [], 'signals' => $signals];
}

/** The banner attached whenever urgency is detected, regardless of the model. */
function aiVetUrgencyBanner(array $signals): string {
    $list = implode(', ', $signals);
    return "URGENT — CONTACT A VETERINARIAN NOW\n"
        . "Your description mentions: " . $list . ".\n"
        . "These signs can indicate a life-threatening emergency. Contact your veterinarian "
        . "immediately rather than waiting. Anything below is background information only "
        . "and is not a substitute for that call.";
}

/** The standing disclaimer carried with every answer. */
function aiVetDisclaimer(): string {
    return 'This is decision support, not a diagnosis. It cannot examine the animal. '
        . 'Confirm anything clinical with your veterinarian before acting.';
}

// ===========================================================================
// CONTEXT BUILDER
// ===========================================================================

/**
 * Build the ANIMAL RECORD and HEALTH HISTORY blocks for one animal.
 *
 * AUTHORIZATION: the animal is resolved through animalFind(), which returns
 * null for a missing animal AND for another tenant's, indistinguishably. Every
 * health read below goes through the same tenant-scoped helpers the health
 * module uses. There is no query in this file that could reach another farm.
 *
 * Nothing is invented. Absent values are reported as not recorded.
 *
 * @return array{ok: bool, animal: ?array, record: string, history: string}
 */
function aiVetAnimalContext(PDO $conn, int $animalId): array {
    $animal = animalFind($conn, $animalId, true);
    if ($animal === null) {
        return ['ok' => false, 'animal' => null, 'record' => '', 'history' => ''];
    }

    $summary = healthAnimalSummary($conn, (int) $animal['cow_id']);

    // ---- ANIMAL RECORD DATA ----------------------------------------------
    $record = [];
    $record[] = 'Tag: ' . $animal['unique_id'];
    if (!empty($animal['name'])) {
        $record[] = 'Name: ' . $animal['name'];
    }
    $record[] = 'Species: ' . dfms_species_label($animal['species'] ?? null);
    $record[] = 'Breed: ' . ($animal['breed'] !== null && $animal['breed'] !== '' ? $animal['breed'] : 'not recorded');
    $record[] = 'Sex: ' . dfms_animal_sex_label($animal['species'] ?? null, $animal['sex'] ?? null);
    $record[] = 'Age: ' . animalAgeLabel($animal['date_of_birth'] ?? null);
    $record[] = 'Lifecycle status: ' . (DFMS_LIFECYCLE_STATUSES[$animal['lifecycle_status']]['label'] ?? 'unknown');
    $record[] = 'Current health status: ' . dfms_health_status_label($summary['health_status']);
    if (!empty($animal['reproductive_status'])) {
        $record[] = 'Reproductive status: ' . $animal['reproductive_status'];
    }
    if (!empty($animal['lactation_status'])) {
        $record[] = 'Lactation status: ' . $animal['lactation_status'];
    }

    // ---- HEALTH HISTORY ---------------------------------------------------
    $history = [];

    if ($summary['active_cases'] === []) {
        $history[] = 'Open health cases: none.';
    } else {
        $history[] = 'Open health cases:';
        foreach ($summary['active_cases'] as $case) {
            $history[] = '  - ' . $case['case_reference'] . ' "' . $case['title'] . '"'
                . ' (' . (DFMS_CASE_TYPES[$case['case_type']] ?? $case['case_type']) . ', '
                . $case['severity'] . ', opened ' . $case['opened_on'] . ')';
            foreach (healthCaseDiagnoses($conn, (int) $case['case_id']) as $diagnosis) {
                // The epistemic status travels with the diagnosis. A suspected
                // condition must never reach the model looking confirmed.
                $history[] = '      diagnosis: ' . dfms_condition_label($diagnosis)
                    . ' [' . (DFMS_DIAGNOSIS_STATUSES[$diagnosis['status']]['label'] ?? $diagnosis['status']) . ']'
                    . ($diagnosis['confidence'] !== null
                        ? ' (clinician-stated confidence: ' . $diagnosis['confidence'] . ')'
                        : ' (no confidence stated)');
            }
            foreach (healthCaseSymptoms($conn, (int) $case['case_id']) as $symptom) {
                $history[] = '      recorded symptom: ' . dfms_symptom_label($symptom)
                    . ' (' . $symptom['severity'] . ')';
            }
        }
    }

    if ($summary['active_treatments'] === []) {
        $history[] = 'Active treatments: none.';
    } else {
        $history[] = 'Active treatments:';
        foreach ($summary['active_treatments'] as $treatment) {
            $history[] = '  - ' . $treatment['name']
                . ' (' . (DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? $treatment['treatment_type'])
                . ', started ' . $treatment['started_on'] . ', status ' . $treatment['status'] . ')';
        }
    }

    $medications = healthMedicationHistory($conn, (int) $animal['cow_id'], 8);
    if ($medications === []) {
        $history[] = 'Medication administered: none recorded.';
    } else {
        $history[] = 'Recent medication administered:';
        foreach ($medications as $medication) {
            $history[] = '  - ' . $medication['medication_name'] . ' '
                . rtrim(rtrim(number_format((float) $medication['dose'], 3, '.', ''), '0'), '.')
                . ' ' . $medication['dose_unit'] . ' ' . $medication['route']
                . ' on ' . substr((string) $medication['administered_at'], 0, 10);
        }
    }

    // Vaccination: absence is UNKNOWN history, never "unvaccinated".
    if ($summary['vaccination_count'] === 0) {
        $history[] = 'Vaccination history: no doses recorded in this system. '
            . 'This means the history is UNKNOWN here, not that the animal is unvaccinated.';
    } else {
        $history[] = 'Vaccinations recorded: ' . $summary['vaccination_count'] . '.';
        foreach (healthVaccinations($conn, (int) $animal['cow_id'], 5) as $vaccination) {
            if ($vaccination['administered_on'] === null) {
                continue;
            }
            $history[] = '  - ' . $vaccination['vaccine_name']
                . ' given ' . $vaccination['administered_on']
                . ($vaccination['disease_target'] ? ' (for ' . $vaccination['disease_target'] . ')' : '');
        }
    }
    if ($summary['upcoming_vaccinations'] !== []) {
        $history[] = 'Scheduled vaccinations (NOT yet given):';
        foreach ($summary['upcoming_vaccinations'] as $vaccination) {
            $history[] = '  - ' . $vaccination['vaccine_name'] . ' due ' . (string) $vaccination['scheduled_on'];
        }
    }

    if ($summary['active_quarantine'] !== null) {
        $history[] = 'Currently quarantined: ' . $summary['active_quarantine']['reason']
            . ' (since ' . substr((string) $summary['active_quarantine']['started_at'], 0, 10) . ').';
    }

    if ($summary['active_withdrawals'] === []) {
        $history[] = 'Active product withdrawal restrictions: none.';
    } else {
        $history[] = 'ACTIVE PRODUCT WITHDRAWAL RESTRICTIONS:';
        foreach ($summary['active_withdrawals'] as $withdrawal) {
            $history[] = '  - ' . ($withdrawal['restriction_type'])
                . ' withheld until ' . $withdrawal['ends_on'] . ' inclusive'
                . ' (from ' . $withdrawal['source_label'] . ')';
        }
    }

    if ($summary['recent_observations'] === []) {
        $history[] = 'Recent clinical observations: none recorded.';
    } else {
        $history[] = 'Recent clinical observations:';
        foreach ($summary['recent_observations'] as $observation) {
            $history[] = '  - ' . (DFMS_OBSERVATION_TYPES[$observation['observation_type']]['label']
                    ?? $observation['observation_type'])
                . ': ' . healthFormatObservation($observation)
                . ' on ' . substr((string) $observation['observed_at'], 0, 16);
        }
    }

    foreach ($summary['recent_lab_tests'] as $test) {
        $history[] = 'Laboratory: ' . $test['test_name'] . ' sampled ' . $test['sample_taken_on']
            . ' — ' . ($test['result_status'] === 'pending'
                ? 'result pending'
                : (string) ($test['result_code'] ?? 'result recorded'));
    }

    // Recent unread alerts for this animal, from the existing alerts table.
    $alerts = tenantQuery(
        $conn,
        "SELECT a.alert_type, a.severity, a.description, a.alert_time
           FROM alerts a
          WHERE {scope} AND a.cow_id = ? AND a.is_read = 0
          ORDER BY a.alert_time DESC LIMIT 5",
        [(int) $animal['cow_id']],
        'a'
    );
    foreach ($alerts as $alert) {
        $history[] = 'Open alert: ' . $alert['alert_type'] . ' (' . $alert['severity'] . ')';
    }

    return [
        'ok'      => true,
        'animal'  => $animal,
        'record'  => implode("\n", $record),
        'history' => implode("\n", $history),
    ];
}

/**
 * Assemble the user-turn content from the four labelled blocks.
 *
 * The labels are load-bearing, not cosmetic: the system prompt tells the model
 * these blocks carry different certainty, and this is what makes that
 * instruction actionable.
 */
function aiVetBuildUserMessage(string $question, ?array $animalContext, array $retrieval): string {
    $parts = [];

    if ($animalContext !== null && $animalContext['ok']) {
        $parts[] = "=== ANIMAL RECORD DATA (recorded facts from the farm's system) ===\n"
            . $animalContext['record'];
        $parts[] = "=== HEALTH HISTORY (recorded facts from the farm's system) ===\n"
            . $animalContext['history'];
    } else {
        $parts[] = "=== ANIMAL RECORD DATA ===\n"
            . "No specific animal was selected. Answer as a general veterinary question "
            . "and do not assume any animal's details.";
    }

    if ($retrieval['chunks'] === []) {
        $parts[] = "=== RETRIEVED KNOWLEDGE ===\n"
            . "NOTHING RELEVANT WAS FOUND in this farm's knowledge base for this question. "
            . "Say so explicitly in your answer, then reason from general veterinary knowledge "
            . "while making clear that it is not drawn from the farm's own documents.";
    } else {
        $lines = ["=== RETRIEVED KNOWLEDGE (from this farm's own knowledge base — cite by title) ==="];
        foreach ($retrieval['chunks'] as $index => $chunk) {
            $lines[] = '[' . ($index + 1) . '] "' . $chunk['document_title'] . '"'
                . ' — source: ' . $chunk['source_name']
                . ($chunk['attribution'] !== null ? ' (' . $chunk['attribution'] . ')' : '');
            $lines[] = $chunk['body'];
            $lines[] = '';
        }
        $parts[] = rtrim(implode("\n", $lines));
    }

    $parts[] = "=== USER-REPORTED (an observation by the person asking; not a measurement) ===\n"
        . $question;

    return implode("\n\n", $parts);
}

// ===========================================================================
// CONVERSATIONS
// ===========================================================================

/**
 * Conversations belonging to the CURRENT user on the ACTIVE farm.
 *
 * Scoped on farm_id (the tenant boundary) AND admin_id, so a colleague on the
 * same farm does not see another person's clinical conversations in their list.
 */
function aiVetConversations(PDO $conn, int $limit = 30): array {
    $limit = max(1, min($limit, 100));
    return tenantQuery(
        $conn,
        "SELECT c.*, a.unique_id AS animal_tag, a.name AS animal_name
           FROM ai_conversations c
           LEFT JOIN cows a ON a.cow_id = c.cow_id AND a.farm_id = c.farm_id
          WHERE {scope} AND c.admin_id = ?
          ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
          LIMIT " . (int) $limit,
        [currentUserId()],
        'c'
    );
}

/**
 * Load one conversation, or null.
 *
 * TWO checks, both required. tenantFind() enforces the farm boundary; the
 * admin_id comparison enforces per-user ownership within the farm. Either one
 * failing yields null, indistinguishable from "does not exist".
 */
function aiVetFindConversation(PDO $conn, $conversationId): ?array {
    $conversation = tenantFind($conn, 'ai_conversations', 'conversation_id', $conversationId);
    if ($conversation === null) {
        return null;
    }
    if ((int) $conversation['admin_id'] !== (int) currentUserId()) {
        return null;
    }
    return $conversation;
}

function aiVetFindConversationOr404(PDO $conn, $conversationId): array {
    $conversation = aiVetFindConversation($conn, $conversationId);
    if ($conversation === null) {
        if (function_exists('auditSecurityEvent')) {
            auditSecurityEvent('ai.conversation_denied', [
                'requested_id' => is_scalar($conversationId) ? (string) $conversationId : '[non-scalar]',
            ]);
        }
        if (function_exists('dfms_abort')) {
            dfms_abort(404, 'ai_conversations miss for user ' . (currentUserId() ?? '?'));
        }
        http_response_code(404);
        exit('Not found.');
    }
    return $conversation;
}

/** Messages in a conversation, oldest first, with their citations attached. */
function aiVetMessages(PDO $conn, int $conversationId): array {
    $messages = tenantQuery(
        $conn,
        "SELECT m.* FROM ai_messages m
          WHERE {scope} AND m.conversation_id = ?
          ORDER BY m.message_id ASC",
        [$conversationId],
        'm'
    );

    foreach ($messages as $index => $message) {
        $messages[$index]['sources'] = $message['role'] === 'assistant'
            ? aiVetMessageSources($conn, (int) $message['message_id'])
            : [];
    }
    return $messages;
}

/**
 * The passages an answer actually relied on.
 *
 * This is what makes a citation TRACEABLE rather than decorative: the answer
 * points at specific chunks, and those chunks point at a titled document and a
 * named source the farm can go and read.
 */
function aiVetMessageSources(PDO $conn, int $messageId): array {
    return tenantQuery(
        $conn,
        "SELECT ms.relevance, ms.chunk_id, ms.document_id,
                d.title AS document_title, s.name AS source_name,
                s.source_type, s.attribution
           FROM ai_message_sources ms
           JOIN ai_knowledge_documents d ON d.document_id = ms.document_id AND d.farm_id = ms.farm_id
           JOIN ai_knowledge_sources s   ON s.source_id  = d.source_id     AND s.farm_id = ms.farm_id
          WHERE {scope} AND ms.message_id = ?
          ORDER BY ms.relevance DESC, ms.message_source_id ASC",
        [$messageId],
        'ms'
    );
}

// ===========================================================================
// THE CONSULTATION
// ===========================================================================

/**
 * Ask the assistant a question.
 *
 * Everything is written in ONE transaction: the user turn, the assistant turn,
 * its citations and the conversation counters. A half-written exchange would
 * show a question with no answer, or an answer with no provenance.
 *
 * A provider failure is RECORDED as a degraded assistant turn rather than
 * discarded, so the history shows honestly that the question was asked and the
 * service was unavailable.
 *
 * @return array{ok: bool, conversation_id: int, answer: ?string, reason: ?string,
 *                urgency: array, sources: list<array>, retrieval_strategy: string}
 * @throws AiVetRuleViolation
 */
function aiVetAsk(PDO $conn, array $input): array {
    $question = trim((string) ($input['question'] ?? ''));
    if ($question === '') {
        throw new AiVetRuleViolation('Type a question first.');
    }
    if (mb_strlen($question, 'UTF-8') > 4000) {
        throw new AiVetRuleViolation('That question is too long. Keep it under 4000 characters.');
    }

    // ---- animal context, only if the user selected one --------------------
    $animalContext = null;
    $animalId = null;
    if (!empty($input['cow_id'])) {
        $animalContext = aiVetAnimalContext($conn, (int) $input['cow_id']);
        if (!$animalContext['ok']) {
            // Foreign or missing — deliberately the same message for both.
            throw new AiVetRuleViolation('That animal is not on this farm.');
        }
        $animalId = (int) $animalContext['animal']['cow_id'];
    }

    // ---- server-side urgency check, before and independent of the model ---
    $urgency = aiVetDetectUrgency($question);

    // ---- retrieval --------------------------------------------------------
    $species = $animalContext !== null && $animalContext['ok']
        ? ($animalContext['animal']['species'] ?? null)
        : null;
    $retrieval = aiKnowledgeRetrieve($conn, $question, DFMS_AI_VET_RETRIEVAL_LIMIT, $species);

    // ---- conversation -----------------------------------------------------
    $conversation = null;
    if (!empty($input['conversation_id'])) {
        $conversation = aiVetFindConversation($conn, $input['conversation_id']);
        if ($conversation === null) {
            throw new AiVetRuleViolation('That conversation is not available.');
        }
    }

    // ---- prior turns, for a genuine multi-turn exchange --------------------
    $history = [];
    if ($conversation !== null) {
        foreach (aiVetMessages($conn, (int) $conversation['conversation_id']) as $message) {
            if ($message['status'] !== 'ok') {
                continue;   // a degraded turn carries no usable content
            }
            $history[] = [
                'role'    => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $message['content'],
            ];
        }
        $history = array_slice($history, -DFMS_AI_VET_HISTORY_TURNS);
    }

    $history[] = [
        'role'    => 'user',
        'content' => aiVetBuildUserMessage($question, $animalContext, $retrieval),
    ];

    // ---- provider ---------------------------------------------------------
    $result = aiChat(DFMS_AI_VET_FEATURE, aiVetSystemPrompt(), $history, [
        'max_tokens'  => 1400,
        // Phase 5E: names the registered contract so the usage row records
        // WHICH version of the veterinary prompt produced this answer.
        'prompt_key'  => DFMS_AI_VET_PROMPT_KEY,
        'entity_type' => $animalId !== null ? 'cow' : null,
        'entity_id'   => $animalId,
    ]);

    // ---- persist ----------------------------------------------------------
    $answer = $result['ok'] ? (string) $result['text'] : null;

    // The urgency banner is PREPENDED regardless of what the model produced,
    // and is shown even when the provider failed entirely — an emergency must
    // not depend on an AI service being reachable.
    if ($urgency['urgent'] && $answer !== null) {
        $answer = aiVetUrgencyBanner($urgency['signals']) . "\n\n" . $answer;
    }

    $conversationId = tenantTransaction($conn, function (PDO $conn) use (
        $conversation, $animalId, $question, $answer, $result, $retrieval, $urgency
    ): int {
        if ($conversation === null) {
            $conversationId = tenantInsert($conn, 'ai_conversations', [
                'admin_id' => currentUserId(),
                'cow_id'   => $animalId,
                'title'    => aiVetTitleFor($question),
            ]);
        } else {
            $conversationId = (int) $conversation['conversation_id'];
        }

        tenantInsert($conn, 'ai_messages', [
            'conversation_id' => $conversationId,
            'role'            => 'user',
            'content'         => $question,
            'status'          => 'ok',
            'urgency_flagged' => $urgency['urgent'] ? 1 : 0,
            'created_by'      => currentUserId(),
        ]);

        $assistantId = tenantInsert($conn, 'ai_messages', [
            'conversation_id' => $conversationId,
            'role'            => 'assistant',
            'content'         => $answer ?? dfms_ai_failure_message($result['reason']),
            'status'          => $result['ok'] ? 'ok' : 'degraded',
            // A machine token only. The upstream error body never reaches here.
            'failure_reason'  => $result['ok'] ? null : (string) $result['reason'],
            'provider'        => $result['provider'] ?? null,
            'model'           => $result['model'] ?? null,
            'input_tokens'    => (int) $result['input_tokens'],
            'output_tokens'   => (int) $result['output_tokens'],
            'urgency_flagged' => $urgency['urgent'] ? 1 : 0,
            'created_by'      => currentUserId(),
        ]);

        // Citations are recorded only for a real answer. Attaching sources to
        // a failure message would claim the passages informed something.
        if ($result['ok']) {
            foreach ($retrieval['chunks'] as $chunk) {
                tenantInsert($conn, 'ai_message_sources', [
                    'message_id'  => $assistantId,
                    'chunk_id'    => $chunk['chunk_id'],
                    'document_id' => $chunk['document_id'],
                    'relevance'   => $chunk['relevance'],
                ]);
            }
        }

        tenantExec(
            $conn,
            "UPDATE ai_conversations
                SET message_count = message_count + 2, last_message_at = NOW()
              WHERE conversation_id = ? AND {scope}",
            [$conversationId]
        );

        return $conversationId;
    });

    // ---- audit ------------------------------------------------------------
    // Records that a consultation happened and what it touched. It does NOT
    // record the question or the answer: the conversation tables already hold
    // those, and copying clinical prose into the audit log would make the log
    // a second, unscoped store of the same text. No credential can appear here
    // — nothing in this payload comes from the provider configuration.
    auditLog('ai.consultation', 'ai_conversation', $conversationId, null, [
        'cow_id'             => $animalId,
        'status'             => $result['ok'] ? 'ok' : 'degraded',
        'failure_reason'     => $result['ok'] ? null : (string) $result['reason'],
        'provider'           => $result['provider'] ?? null,
        'model'              => $result['model'] ?? null,
        // Phase 5E: which contract was in force. Machine tokens and an
        // integer — the prompt BODY is deliberately not copied here.
        'prompt_key'         => DFMS_AI_VET_PROMPT_KEY,
        'prompt_version'     => aiPromptVersion(DFMS_AI_VET_PROMPT_KEY),
        'retrieval_strategy' => $retrieval['strategy'],
        'sources_used'       => $result['ok'] ? count($retrieval['chunks']) : 0,
        'urgency_flagged'    => $urgency['urgent'],
        'question_chars'     => mb_strlen($question, 'UTF-8'),
    ]);

    return [
        'ok'                 => (bool) $result['ok'],
        'conversation_id'    => $conversationId,
        'answer'             => $answer,
        'reason'             => $result['ok'] ? null : (string) $result['reason'],
        'urgency'            => $urgency,
        'sources'            => $result['ok'] ? $retrieval['chunks'] : [],
        'retrieval_strategy' => $retrieval['strategy'],
    ];
}

/** A short conversation title derived from the first question. */
function aiVetTitleFor(string $question): string {
    $title = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);
    if (mb_strlen($title, 'UTF-8') > 80) {
        $title = mb_substr($title, 0, 77, 'UTF-8') . '…';
    }
    return $title === '' ? 'Veterinary question' : $title;
}

/**
 * Whether the assistant is usable right now, and why not if it is not.
 *
 * Used by the UI and the API to render an honest degraded state. Returns
 * NOTHING about the credential itself beyond a boolean.
 *
 * @return array{available: bool, reason: ?string, message: ?string, plan: ?array}
 */
function aiVetAvailability(PDO $conn): array {
    if (!aiIsConfigured()) {
        return [
            'available' => false,
            'reason'    => 'not_configured',
            'message'   => dfms_ai_failure_message('not_configured'),
            'plan'      => null,
        ];
    }

    $organizationId = currentOrganizationId();
    if ($organizationId === null) {
        return [
            'available' => false,
            'reason'    => 'no_tenant',
            'message'   => dfms_ai_failure_message('no_tenant'),
            'plan'      => null,
        ];
    }

    $plan = aiPlanStatus($conn, $organizationId);
    if ($plan['remaining'] !== null && $plan['remaining'] <= 0) {
        return [
            'available' => false,
            'reason'    => 'plan_limit_reached',
            'message'   => dfms_ai_failure_message('plan_limit_reached'),
            'plan'      => $plan,
        ];
    }

    return ['available' => true, 'reason' => null, 'message' => null, 'plan' => $plan];
}
