<?php
// ============================================================================
// AI ANIMAL IMAGE ANALYSIS — AI-ASSISTED OBSERVATION, NOT DIAGNOSIS.
//
// READ THIS FIRST. This module does not detect disease and must never be
// described as if it did. It sends one photograph of one animal to a model and
// gets back a bounded description of WHAT IS VISIBLE, what that could be
// consistent with, and how quickly a person should involve a veterinarian.
// Only a veterinarian examining the animal can diagnose anything.
//
// That distinction is not a disclaimer bolted on at the end. It is enforced in
// four independent places, each of which holds if the others fail:
//
//   1. THE PROMPT   'animal_image_observation' in includes/ai_prompts.php
//                   forbids naming a disease, giving a probability, or
//                   prescribing anything.
//   2. THE SCHEMA   aiImageAnalysisSchema() below has NOWHERE to put a
//                   diagnosis or a percentage. includes/ai_schema.php rejects
//                   unknown keys, so a model that invents `diagnosis` or
//                   `confidence_pct` produces a REFUSED analysis rather than a
//                   partially-accepted one.
//   3. THE FLOOR    aiImageResolveUrgency() takes the HIGHER of what the model
//                   said and what the deterministic scan of the person's own
//                   words found. A model that misses an emergency cannot lower
//                   the warning.
//   4. THE WRITE    nothing here writes to the health domain. An analysis is
//                   never a case, a diagnosis, a treatment or an observation.
//
// ---------------------------------------------------------------------------
// WHAT THIS MODULE REUSES AND DOES NOT REBUILD
//
//   file storage        includes/storage.php  (stored_files, outside the web
//                       root, random names, tenant directories)
//   tenant scoping      includes/repository.php
//   animal ownership    animalFind() in includes/livestock.php
//   the AI gateway      aiStructured() in includes/ai_service.php, which
//                       already applies the plan gate, the rate limiter, the
//                       retry policy, usage accounting and prompt versioning
//   output validation   includes/ai_schema.php
//   urgency patterns    aiVetDetectUrgency() in includes/ai_vet.php
//   audit               auditLog() in includes/audit.php
//
// There is no second file store, no second AI gateway, no second provider
// abstraction and no second authorization path in this file.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/livestock.php';
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/ai_schema.php';
require_once __DIR__ . '/ai_prompts.php';
require_once __DIR__ . '/ai_vet.php';

/** The usage feature name recorded on every ai_usage row this module creates. */
const DFMS_AI_IMAGE_FEATURE = 'animal_image_analysis';

/** The registry prompt this module sends. Never caller-controlled. */
const DFMS_AI_IMAGE_PROMPT_KEY = 'animal_image_observation';

/** The feature flag that gates the whole capability. */
const DFMS_AI_IMAGE_FLAG = 'ai_image_analysis';

/** Lifecycle states, matching the ENUM in migration 013 exactly. */
const DFMS_AI_IMAGE_STATUSES = ['pending', 'processing', 'completed', 'failed', 'unavailable'];

/**
 * Storage categories an analysable image may come from.
 *
 * `documents` is deliberately absent: that category holds PDFs, which are not
 * images and which modules/files/serve.php will only ever return as an
 * attachment. Restricting the category here means an analysis request cannot
 * be used to push an arbitrary stored document at a provider.
 */
const DFMS_AI_IMAGE_CATEGORIES = ['animal_photos', 'medical_images'];

/**
 * The largest image this module will send to a provider.
 *
 * NOT a second upload limit and not in competition with one.
 * DFMS_MAX_UPLOAD_BYTES (8 MB) governs what may be STORED and is unchanged; a
 * photo larger than the value below is stored perfectly well and simply cannot
 * be analysed. Two different boundaries, two different limits, and this one is
 * strictly the narrower — which is the only relationship between them that is
 * safe.
 *
 * 4 MB of source bytes is roughly 5.4 MB once base64-encoded, which is inside
 * what both supported providers accept. Refusing here, with a clear message,
 * is deliberately preferred over silently downscaling the image: a resized
 * photograph is not the photograph the stockperson looked at, and quietly
 * changing the evidence before an assessment is exactly the kind of invisible
 * transformation this codebase avoids elsewhere.
 */
const DFMS_AI_IMAGE_MAX_BYTES = 4194304;

/**
 * Urgency levels, ordered from least to most urgent.
 *
 * The ORDER is load-bearing: aiImageResolveUrgency() compares by index, so
 * inserting a level in the wrong position would change which of two signals
 * wins. Keep it ascending.
 */
const DFMS_AI_IMAGE_URGENCY = ['routine', 'monitor', 'veterinary_review', 'urgent'];

/** Next steps a result may recommend. Actions for a PERSON, never treatments. */
const DFMS_AI_IMAGE_ACTIONS = [
    'observe'             => 'Keep watching and re-check',
    'photograph_again'    => 'Take another photograph, closer or better lit',
    'isolate_if_advised'  => 'Separate the animal if your own protocol advises it',
    'veterinarian_review' => 'Have a veterinarian look at this',
];

/** How clearly something can be seen in the image. */
const DFMS_AI_IMAGE_VISIBILITY = ['clear', 'partial', 'unclear'];

/** How pronounced a visible finding appears. NOT a clinical severity grade. */
const DFMS_AI_IMAGE_SEVERITY = ['subtle', 'moderate', 'pronounced', 'indeterminate'];

/** Bounded certainty words. Deliberately not numbers — see the header. */
const DFMS_AI_IMAGE_CERTAINTY = ['speculative', 'possible', 'likely'];

/** Safe, user-facing text for an image-specific refusal token. */
const DFMS_AI_IMAGE_REASONS = [
    'image_too_large'  => 'That image is too large to analyse. Use one under 4 MB.',
    'image_unreadable' => 'That image could not be read for analysis.',
    'feature_disabled' => 'AI image analysis is switched off for this farm.',
];

// ===========================================================================
// LABELS AND SAFE MESSAGES
// ===========================================================================

function aiImageUrgencyLabel(?string $urgency): string {
    $labels = [
        'routine'           => 'Routine',
        'monitor'           => 'Monitor',
        'veterinary_review' => 'Veterinary review',
        'urgent'            => 'Urgent',
    ];
    return $labels[$urgency ?? ''] ?? 'Not assessed';
}

/** The design system tone for an urgency level, so the UI never invents one. */
function aiImageUrgencyTone(?string $urgency): string {
    $tones = [
        'routine'           => 'success',
        'monitor'           => 'info',
        'veterinary_review' => 'warning',
        'urgent'            => 'danger',
    ];
    return $tones[$urgency ?? ''] ?? 'neutral';
}

function aiImageStatusLabel(?string $status): string {
    $labels = [
        'pending'     => 'Queued',
        'processing'  => 'In progress',
        'completed'   => 'Completed',
        'failed'      => 'Failed',
        'unavailable' => 'Not available',
    ];
    return $labels[$status ?? ''] ?? 'Unknown';
}

function aiImageActionLabel(string $action): string {
    return DFMS_AI_IMAGE_ACTIONS[$action] ?? $action;
}

/**
 * The standing framing carried with every result.
 *
 * One definition, so the profile page, the history list and the API can never
 * disagree about what this feature claims to be.
 */
function aiImageDisclaimer(): string {
    return 'This is an AI description of a photograph, not a diagnosis. A photograph '
        . 'cannot show temperature, pain, appetite or anything internal, and it cannot '
        . 'rule anything out. Confirm anything clinical with your veterinarian.';
}

/**
 * One user-facing message for any failure this module can produce.
 *
 * Consults the image-specific tokens first, then the provider tokens
 * (dfms_ai_failure_message) and the structured-output tokens
 * (aiSchemaFailureMessage), so a stored failure_reason from any layer renders
 * as safe text. An unrecognised token NEVER reaches the user verbatim.
 */
function aiImageFailureMessage(?string $reason): string {
    $key = $reason ?? '';
    if (isset(DFMS_AI_IMAGE_REASONS[$key])) {
        return DFMS_AI_IMAGE_REASONS[$key];
    }
    if (isset(DFMS_AI_FAILURE_REASONS[$key])) {
        return dfms_ai_failure_message($reason);
    }
    if (isset(DFMS_AI_SCHEMA_REASONS[$key])) {
        return aiSchemaFailureMessage($reason);
    }
    return 'The analysis could not be completed.';
}

// ===========================================================================
// THE OUTPUT CONTRACT
// ===========================================================================

/**
 * The bounded schema a result must satisfy.
 *
 * WHAT THIS SCHEMA CANNOT EXPRESS, deliberately:
 *
 *   - A DISEASE NAME AS A CONCLUSION. possible_concerns[].concern is free
 *     text, but it sits under a required certainty drawn from three hedged
 *     words and beside a required rationale, and the prompt forbids stating one
 *     as settled. There is no field called diagnosis, and no field a UI could
 *     render as one.
 *
 *   - A PROBABILITY. There is no numeric field anywhere in this schema. A model
 *     that returns confidence 0.94 hits the unknown-key rule in
 *     includes/ai_schema.php and the whole analysis is refused rather than
 *     partially accepted.
 *
 *   - A TREATMENT OR A DOSE. recommended_actions is an enum of four next steps
 *     for a person; there is nowhere to put a medicine.
 *
 * limitations is REQUIRED with at least one entry. Every photograph has
 * limitations, and a result claiming none has not understood the task — making
 * it required means such a result is refused rather than displayed as
 * unusually confident.
 */
function aiImageAnalysisSchema(): array {
    return [
        'type' => 'object',
        'fields' => [
            'observations' => [
                'type' => 'array', 'required' => true, 'min_items' => 1, 'max_items' => 10,
                'items' => ['type' => 'object', 'fields' => [
                    'observation' => ['type' => 'string', 'required' => true, 'max' => 400],
                    // Where on the animal. Free text rather than an enum of
                    // body parts: the product covers cattle, buffalo, goats,
                    // sheep and poultry, and one fixed anatomical list would be
                    // wrong for most of them.
                    'location'    => ['type' => 'string', 'max' => 120],
                    'visibility'  => ['type' => 'enum', 'required' => true,
                                      'values' => DFMS_AI_IMAGE_VISIBILITY],
                    'severity'    => ['type' => 'enum', 'values' => DFMS_AI_IMAGE_SEVERITY],
                ]],
            ],
            'possible_concerns' => [
                'type' => 'array', 'max_items' => 6,
                'items' => ['type' => 'object', 'fields' => [
                    'concern'   => ['type' => 'string', 'required' => true, 'max' => 200],
                    'rationale' => ['type' => 'string', 'required' => true, 'max' => 500],
                    'certainty' => ['type' => 'enum', 'required' => true,
                                    'values' => DFMS_AI_IMAGE_CERTAINTY],
                ]],
            ],
            'urgency' => ['type' => 'enum', 'required' => true,
                          'values' => DFMS_AI_IMAGE_URGENCY],
            'recommended_actions' => [
                'type' => 'array', 'required' => true, 'min_items' => 1, 'max_items' => 4,
                'items' => ['type' => 'enum', 'values' => array_keys(DFMS_AI_IMAGE_ACTIONS)],
            ],
            'limitations' => [
                'type' => 'array', 'required' => true, 'min_items' => 1, 'max_items' => 6,
                'items' => ['type' => 'string', 'max' => 300],
            ],
        ],
    ];
}

// ===========================================================================
// THE SAFETY FLOOR
// ===========================================================================

/**
 * Resolve the urgency actually recorded, given the model answer and the
 * deterministic scan of what the PERSON reported.
 *
 * THE RULE: the recorded urgency is never lower than the deterministic floor.
 *
 * Phase 5D established that urgency detection runs server-side on the words the
 * user typed, before the model and independently of it, because a false
 * negative costs an animal and a false positive costs one sentence of advice.
 * That asymmetry does not change because an image is involved. If someone
 * writes "she cannot stand and the wound is bleeding heavily" and attaches a
 * photograph of a hoof, a model looking only at the hoof may quite reasonably
 * answer monitor — and that answer must not be what gets shown.
 *
 * This is a FLOOR, not an override in the other direction: a model that
 * considers something urgent when the words contained no trigger phrase is
 * believed, because raising urgency is always the safe direction.
 *
 * @param ?string $modelUrgency        what the model returned, if anything
 * @param bool    $deterministicUrgent whether aiVetDetectUrgency() found a signal
 */
function aiImageResolveUrgency(?string $modelUrgency, bool $deterministicUrgent): string {
    // An unrecognised or absent value falls to the most cautious level this
    // function will return on its own. Defaulting to routine would let a
    // malformed answer read as reassurance.
    $model = in_array($modelUrgency, DFMS_AI_IMAGE_URGENCY, true)
        ? $modelUrgency
        : 'veterinary_review';

    if (!$deterministicUrgent) {
        return $model;
    }

    // The floor, compared by POSITION in the ordered list — which is why the
    // order of DFMS_AI_IMAGE_URGENCY is load-bearing.
    $modelRank = array_search($model, DFMS_AI_IMAGE_URGENCY, true);
    $floorRank = array_search('urgent', DFMS_AI_IMAGE_URGENCY, true);
    return $modelRank !== false && $modelRank > $floorRank ? $model : 'urgent';
}

// ===========================================================================
// AUTHORIZATION AND INPUT RESOLUTION
// ===========================================================================

/**
 * Resolve a stored file that this tenant may submit for analysis.
 *
 * EVERY failing condition below produces the SAME null result, on purpose. A
 * caller cannot tell a file that does not exist from another farm file, from a
 * PDF, from one attached to a different animal, from one whose bytes are gone.
 * That is the convention tenantFind() established, and it is what stops a
 * foreign id being used to probe.
 *
 * The checks, cheapest first:
 *   1. the row resolves within the ACTIVE FARM       tenantFindFile()
 *   2. it is linked to THIS animal                   entity_type / entity_id
 *   3. its category is analysable                    DFMS_AI_IMAGE_CATEGORIES
 *   4. its MIME type is an allowed image type        storage.php own list
 *   5. the bytes exist inside the tenant directory   tenantFilePath()
 *
 * Note what is NOT trusted: no organization_id and no farm_id from the caller
 * is consulted anywhere. The tenant comes from the session, through the
 * repository, exactly as everywhere else in this codebase.
 *
 * @return array{file: array, path: string}|null
 */
function aiImageResolveFile(PDO $conn, int $animalId, $fileId): ?array {
    $file = tenantFindFile($conn, $fileId);
    if ($file === null) {
        return null;
    }

    // The image must belong to THIS animal. Without this check a farm member
    // could analyse any of their own farm images while the stored record
    // claimed it was about a different animal — tenancy-safe, but a factually
    // wrong clinical-adjacent record, which is its own kind of harm.
    if (($file['entity_type'] ?? null) !== 'animal'
        || (int) ($file['entity_id'] ?? 0) !== $animalId) {
        return null;
    }

    if (!in_array((string) $file['category'], DFMS_AI_IMAGE_CATEGORIES, true)) {
        return null;
    }

    // The allow-list is storage.php own, not a second copy of it. A type that
    // cannot be stored as an image cannot be analysed as one.
    if (!isset(DFMS_ALLOWED_IMAGE_TYPES[(string) $file['mime_type']])) {
        return null;
    }

    $path = tenantFilePath((string) $file['stored_path']);
    if ($path === null || !is_file($path)) {
        return null;
    }

    return ['file' => $file, 'path' => $path];
}

/**
 * Read a resolved file into the provider-neutral image block.
 *
 * The last line of defence before bytes leave this installation:
 *
 *   - the ON-DISK size is re-checked against DFMS_AI_IMAGE_MAX_BYTES. The
 *     recorded byte_size is deliberately not trusted for this; the file on disk
 *     is what would actually be sent.
 *   - the content is re-sniffed with getimagesize() and must still agree with
 *     the recorded MIME type. A row whose bytes were replaced after upload, or
 *     a type since removed from the allow-list, stops here. "It was valid when
 *     we stored it" is not the same claim as "it is a valid image now".
 *
 * Returns a machine token on refusal — never a path, and never a byte count
 * that would let a caller measure another tenant file.
 *
 * The two shapes are annotated as a UNION rather than as one nullable shape, so
 * a caller that has checked `ok` is statically known to hold a block. Without
 * that, every call site would need a redundant null check whose only purpose is
 * to satisfy the analyser — and a redundant check is one a reader has to stop
 * and reason about.
 *
 * @return array{ok: true, block: array<string, mixed>, reason: null}|array{ok: false, block: null, reason: string}
 */
function aiImageReadBlock(string $path, string $recordedMime): array {
    $size = filesize($path);
    if ($size === false || $size <= 0) {
        return ['ok' => false, 'block' => null, 'reason' => 'image_unreadable'];
    }
    if ($size > DFMS_AI_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'block' => null, 'reason' => 'image_too_large'];
    }

    $info = @getimagesize($path);
    $mime = is_array($info) ? (string) $info['mime'] : '';
    if ($mime === '' || $mime !== $recordedMime || !isset(DFMS_ALLOWED_IMAGE_TYPES[$mime])) {
        return ['ok' => false, 'block' => null, 'reason' => 'image_unreadable'];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'block' => null, 'reason' => 'image_unreadable'];
    }

    return ['ok' => true, 'reason' => null, 'block' => [
        'type'       => DFMS_AI_BLOCK_IMAGE,
        'media_type' => $mime,
        'data'       => base64_encode($bytes),
    ]];
}

// ===========================================================================
// PERSISTENCE
// ===========================================================================

/**
 * Analyses for one animal, newest first.
 *
 * Tenant-scoped through tenantQuery(), so a foreign animal id yields nothing
 * rather than another farm history. The LIMIT is clamped and interpolated
 * rather than bound because {scope} must be the FIRST placeholder in the
 * statement — see the constraint documented on tenantQuery() in
 * includes/repository.php. It is an integer this function computed, never
 * caller text.
 *
 * The stored_files join carries `AND f.farm_id = a.farm_id` even though
 * aiImageResolveFile() already proves the file belongs to the active farm
 * before the analysis row is written. It is defence in depth against a future
 * write path that forgets: without it, a row whose file_id somehow pointed
 * across a tenant boundary would render that file's name and caption. The
 * condition costs nothing and removes the whole class of problem.
 *
 * admin_users is deliberately NOT scoped — it is a platform-wide table with no
 * farm_id, exactly as animalFiles() joins it.
 */
function aiImageAnalysesForAnimal(PDO $conn, int $animalId, int $limit = 20): array {
    $limit = max(1, min($limit, 100));
    return tenantQuery(
        $conn,
        "SELECT a.*, u.username AS requested_by_name,
                f.original_name, f.caption, f.category
           FROM ai_image_analyses a
           LEFT JOIN admin_users u ON u.admin_id = a.requested_by
           LEFT JOIN stored_files f ON f.file_id = a.file_id AND f.farm_id = a.farm_id
          WHERE {scope} AND a.cow_id = ?
          ORDER BY a.analysis_id DESC
          LIMIT $limit",
        [$animalId],
        'a'
    );
}

/** Load one analysis. Null for missing OR foreign, indistinguishably. */
function aiImageFindAnalysis(PDO $conn, $analysisId): ?array {
    return tenantFind($conn, 'ai_image_analyses', 'analysis_id', $analysisId);
}

/**
 * Decode a stored result.
 *
 * Re-validated against the schema on the way OUT, not only on the way in. The
 * column was written from validated data, but a page that renders whatever JSON
 * happens to be in a row is one migration or one manual edit away from
 * rendering something nobody checked. Validating in both directions costs
 * microseconds and removes the whole class of problem.
 *
 * Returns null rather than a partial structure: a result that no longer
 * validates is not shown at all.
 */
function aiImageDecodeResult(?string $json): ?array {
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }
    $validated = aiSchemaValidate($decoded, aiImageAnalysisSchema());
    return $validated['ok'] ? $validated['value'] : null;
}

/**
 * A one-line summary of a completed analysis, for a history row.
 *
 * Built from the FIRST observation only, truncated. Never renders raw JSON, and
 * never invents a summary for an analysis that has none.
 */
function aiImageSummaryLine(?array $result): ?string {
    if ($result === null || empty($result['observations'][0]['observation'])) {
        return null;
    }
    $first = (string) $result['observations'][0]['observation'];
    $count = count($result['observations']);
    $line = mb_strlen($first, 'UTF-8') > 120
        ? mb_substr($first, 0, 119, 'UTF-8') . '...'
        : $first;
    return $count > 1 ? $line . ' (+' . ($count - 1) . ' more)' : $line;
}

// ===========================================================================
// AVAILABILITY
// ===========================================================================

/**
 * Whether an analysis can be requested right now, and why not if it cannot.
 *
 * Mirrors aiVetAvailability() and adds the one gate specific to this feature —
 * its flag. Reveals nothing about the credential beyond a boolean.
 *
 * The flag is checked FIRST because it is the cheapest question and the only
 * one whose answer is a farm decision rather than a platform state.
 *
 * @return array{available: bool, reason: ?string, message: ?string, plan: ?array}
 */
function aiImageAvailability(PDO $conn): array {
    if (!dfms_feature_enabled(DFMS_AI_IMAGE_FLAG)) {
        return [
            'available' => false,
            'reason'    => 'feature_disabled',
            'message'   => aiImageFailureMessage('feature_disabled'),
            'plan'      => null,
        ];
    }
    // Everything after the flag — configuration, tenant, plan allowance — is
    // exactly the assistant gate, CALLED rather than copied. A second
    // availability implementation would be a second thing to get wrong.
    return aiVetAvailability($conn);
}

// ===========================================================================
// LIFECYCLE WRITES
// ===========================================================================

/** Move an analysis to a lifecycle state. Refuses an unknown one. */
function aiImageSetStatus(PDO $conn, int $analysisId, string $status): void {
    if (!in_array($status, DFMS_AI_IMAGE_STATUSES, true)) {
        throw new InvalidArgumentException("Unknown image analysis status '$status'.");
    }
    tenantExec(
        $conn,
        "UPDATE ai_image_analyses SET status = ? WHERE analysis_id = ? AND {scope}",
        [$status, $analysisId]
    );
}

/**
 * Write the terminal state of an analysis in ONE scoped statement.
 *
 * Every result column is written on every terminal transition, including the
 * ones being set back to NULL. That is deliberate: a partial update is exactly
 * how a failed analysis keeps an urgency or a result from an earlier attempt
 * and then shows a stale assessment as a current one.
 *
 * @param array<string, mixed> $fields
 */
function aiImageFinish(PDO $conn, int $analysisId, string $status, array $fields): void {
    if (!in_array($status, DFMS_AI_IMAGE_STATUSES, true)) {
        throw new InvalidArgumentException("Unknown image analysis status '$status'.");
    }
    tenantExec(
        $conn,
        "UPDATE ai_image_analyses
            SET status = ?, failure_reason = ?, provider = ?, model = ?,
                prompt_key = ?, prompt_version = ?, urgency = ?, result_json = ?,
                input_tokens = ?, output_tokens = ?, completed_at = NOW()
          WHERE analysis_id = ? AND {scope}",
        [
            $status,
            $fields['failure_reason'] ?? null,
            $fields['provider'] ?? null,
            $fields['model'] ?? null,
            $fields['prompt_key'] ?? null,
            $fields['prompt_version'] ?? null,
            $fields['urgency'] ?? null,
            $fields['result_json'] ?? null,
            (int) ($fields['input_tokens'] ?? 0),
            (int) ($fields['output_tokens'] ?? 0),
            $analysisId,
        ]
    );
}

// ===========================================================================
// THE REQUEST
// ===========================================================================

/**
 * The text half of the user turn.
 *
 * Labelled blocks with DIFFERENT standing, the same discipline the veterinary
 * assistant uses: what the farm records say is not what the person believes,
 * and neither is what the photograph shows. A model told which is which can
 * keep them apart in its answer; one handed an undifferentiated paragraph
 * cannot.
 *
 * Only non-clinical identifying facts are included. Sending the animal health
 * history alongside a photograph would invite the model to explain the picture
 * with the record instead of describing what is actually visible — which is the
 * one thing this feature exists to do.
 */
function aiImageUserText(array $animal, string $reported): string {
    $lines = [];
    $lines[] = 'ANIMAL RECORD DATA (recorded facts from the farm system)';
    $lines[] = '- Tag: ' . (string) ($animal['unique_id'] ?? 'not recorded');
    $lines[] = '- Species: ' . dfms_species_label($animal['species'] ?? null);
    if (!empty($animal['breed'])) {
        $lines[] = '- Breed: ' . (string) $animal['breed'];
    }
    $age = animalAgeLabel($animal['date_of_birth'] ?? null);
    $lines[] = '- Age: ' . ($age !== '' ? $age : 'not recorded');

    $lines[] = '';
    $lines[] = 'USER-REPORTED (an observation by the person, not a measurement, and possibly mistaken)';
    $lines[] = $reported !== ''
        ? $reported
        : 'Nothing was reported. Describe only what is visible in the photograph.';

    $lines[] = '';
    $lines[] = 'THE IMAGE is attached to this message. Describe what is visible in it. '
        . 'Do not use the record above to explain something you cannot actually see.';

    return implode("\n", $lines);
}

/**
 * Request one AI observation of one stored image of one animal.
 *
 * THE ORDER OF THIS FUNCTION IS THE SECURITY MODEL. Nothing is spent and no
 * provider is reached until every gate has passed:
 *
 *   1. feature flag              rollout, never authorization
 *   2. the animal resolves inside the active farm
 *   3. the file resolves inside the active farm, is linked to THIS animal, is
 *      an allowed category and an allowed image type, and its bytes exist
 *   4. the bytes are re-sniffed and size-bounded
 *   5. a row is INSERTED as pending, then moved to processing
 *   6. aiStructured() applies the plan gate, the rate limiter, the prompt
 *      registry, the retry policy, the usage row and output validation
 *   7. the deterministic urgency floor is applied to whatever came back
 *   8. the row is completed, failed or marked unavailable — never left looking
 *      like a success
 *
 * The CALLER performs requireAuth('ai.consult') before reaching here, exactly
 * as the veterinary assistant callers do. This function is still tenant-safe on
 * its own: every lookup inside it is farm-scoped, so it cannot be turned into a
 * cross-tenant read by being called from the wrong place.
 *
 * NOTHING HERE WRITES TO THE HEALTH DOMAIN. No case is opened, no diagnosis
 * recorded, no treatment created, no quarantine started, no animal status
 * changed and no inventory moved. That is the deliberate boundary of this
 * phase: an AI observation is not a clinical record, and turning one into a
 * clinical record is an explicit human action for a later phase to design.
 *
 * @param array{animal_id: mixed, file_id: mixed, reported_signs?: ?string} $input
 * @return array{ok: bool, analysis: ?array, reason: ?string, message: ?string}
 */
function aiImageRequestAnalysis(PDO $conn, array $input): array {
    $reported = trim((string) ($input['reported_signs'] ?? ''));
    if (mb_strlen($reported, 'UTF-8') > 500) {
        $reported = mb_substr($reported, 0, 500, 'UTF-8');
    }

    $refuse = static function (string $reason): array {
        return [
            'ok' => false, 'analysis' => null, 'reason' => $reason,
            'message' => aiImageFailureMessage($reason),
        ];
    };

    // ---- 1. rollout -------------------------------------------------------
    // AFTER the caller requireAuth(), never instead of it. See the security
    // boundary documented at the top of includes/features.php.
    if (!dfms_feature_enabled(DFMS_AI_IMAGE_FLAG)) {
        return $refuse('feature_disabled');
    }

    // ---- 2. the animal ----------------------------------------------------
    // animalFind() returns null for missing AND for another tenant animal.
    $animal = animalFind($conn, $input['animal_id'] ?? null, true);
    if ($animal === null) {
        return $refuse('image_unreadable');
    }
    $animalId = (int) $animal['cow_id'];

    // ---- 3. the file ------------------------------------------------------
    $resolved = aiImageResolveFile($conn, $animalId, $input['file_id'] ?? null);
    if ($resolved === null) {
        // Deliberately the SAME token a corrupt image produces. A caller must
        // not be able to distinguish "that file belongs to another farm" from
        // "that file is not an analysable image".
        return $refuse('image_unreadable');
    }

    // ---- 4. the bytes -----------------------------------------------------
    $image = aiImageReadBlock($resolved['path'], (string) $resolved['file']['mime_type']);
    if (!$image['ok']) {
        return $refuse((string) $image['reason']);
    }

    // ---- the deterministic urgency scan -----------------------------------
    // Runs on the PERSON words, before the model and independently of it, and
    // becomes the floor the model cannot lower. Phase 5D patterns are reused
    // rather than restated: an emergency is an emergency whether it was typed
    // into the assistant or into an image request.
    $urgencyScan = aiVetDetectUrgency($reported);

    // ---- 5. the row, before anything is spent -----------------------------
    $analysisId = tenantInsert($conn, 'ai_image_analyses', [
        'cow_id'          => $animalId,
        'file_id'         => (int) $resolved['file']['file_id'],
        'requested_by'    => currentUserId(),
        'status'          => 'pending',
        'reported_signs'  => $reported !== '' ? $reported : null,
        'urgency_flagged' => $urgencyScan['urgent'] ? 1 : 0,
    ]);

    auditLog('ai_image_analysis.requested', 'ai_image_analysis', $analysisId, null, [
        'animal_id'       => $animalId,
        'file_id'         => (int) $resolved['file']['file_id'],
        // The reported signs themselves are NOT logged. Free text a person
        // typed about an animal is not audit material; the urgency flag records
        // the only part of it that bears on accountability. The image bytes are
        // likewise never logged, audited or copied anywhere.
        'urgency_flagged' => $urgencyScan['urgent'],
    ]);

    // Moved to processing BEFORE the call, so a request that dies mid-flight
    // leaves a row visibly stuck in processing rather than one that reads as
    // never attempted.
    aiImageSetStatus($conn, $analysisId, 'processing');

    // ---- 6. the gateway ---------------------------------------------------
    // aiStructured() is the ONLY path to a model in this codebase. It applies
    // the plan gate, the rate limiter, the retry and timeout policy, the usage
    // row, the prompt version and output validation. This module adds none of
    // that and bypasses none of it — one analysis is one logical provider call
    // and is accounted for exactly like one veterinary question.
    //
    // The user turn is TWO blocks: the image, then the text. The image comes
    // first so the model reads the picture as the subject and the words as
    // context about it, rather than the other way round.
    $messages = [[
        'role'    => 'user',
        'content' => [
            $image['block'],
            ['type' => DFMS_AI_BLOCK_TEXT, 'text' => aiImageUserText($animal, $reported)],
        ],
    ]];

    $result = aiStructured(
        DFMS_AI_IMAGE_FEATURE,
        DFMS_AI_IMAGE_PROMPT_KEY,
        $messages,
        aiImageAnalysisSchema(),
        ['entity_type' => 'animal', 'entity_id' => $animalId]
    );

    // ---- 8a. nothing usable came back -------------------------------------
    if (!$result['ok']) {
        // `unavailable` means nothing was attempted or spent and trying later
        // is sensible advice; `failed` means the provider answered and the
        // answer was unusable. Conflating them would tell a farm to wait when
        // the real problem is that the model will not follow the contract.
        $neverAttempted = in_array((string) $result['reason'], [
            'not_configured', 'plan_limit_reached', 'ai_rate_limited', 'no_tenant',
        ], true);

        aiImageFinish($conn, $analysisId, $neverAttempted ? 'unavailable' : 'failed', [
            'failure_reason' => (string) $result['reason'],
            'provider'       => $result['provider'],
            'model'          => $result['model'],
            'prompt_key'     => $result['prompt_key'],
            'prompt_version' => $result['prompt_version'],
            'input_tokens'   => (int) $result['input_tokens'],
            'output_tokens'  => (int) $result['output_tokens'],
            // NO urgency and NO result are written on a failure. A failed
            // analysis carrying an urgency would be a fabricated assessment,
            // and one carrying a result would show an old answer as a new one.
        ]);

        auditLog('ai_image_analysis.failed', 'ai_image_analysis', $analysisId, null, [
            'animal_id' => $animalId,
            // A machine token, never an upstream body.
            'reason'    => (string) $result['reason'],
        ]);

        return [
            'ok'       => false,
            'analysis' => aiImageFindAnalysis($conn, $analysisId),
            'reason'   => (string) $result['reason'],
            'message'  => aiImageFailureMessage((string) $result['reason']),
        ];
    }

    // ---- 7. the urgency floor ---------------------------------------------
    $data = is_array($result['data']) ? $result['data'] : [];
    $urgency = aiImageResolveUrgency(
        isset($data['urgency']) ? (string) $data['urgency'] : null,
        $urgencyScan['urgent']
    );
    // The STORED structure carries the resolved urgency, not the model raw
    // answer, so every reader — page, history row, API — sees the same
    // escalated value and none of them has to remember to apply the floor.
    $data['urgency'] = $urgency;

    // ---- 8b. completed ----------------------------------------------------
    aiImageFinish($conn, $analysisId, 'completed', [
        'failure_reason' => null,
        'provider'       => $result['provider'],
        'model'          => $result['model'],
        'prompt_key'     => $result['prompt_key'],
        'prompt_version' => $result['prompt_version'],
        'input_tokens'   => (int) $result['input_tokens'],
        'output_tokens'  => (int) $result['output_tokens'],
        'urgency'        => $urgency,
        'result_json'    => json_encode($data, JSON_UNESCAPED_UNICODE),
    ]);

    auditLog('ai_image_analysis.completed', 'ai_image_analysis', $analysisId, null, [
        'animal_id'       => $animalId,
        'urgency'         => $urgency,
        'urgency_flagged' => $urgencyScan['urgent'],
        'provider'        => $result['provider'],
        'model'           => $result['model'],
        'prompt_key'      => $result['prompt_key'],
        'prompt_version'  => $result['prompt_version'],
        // The observations themselves are NOT audited. They are already stored
        // on the row, and the audit log answers "who did what", not "what did
        // the model say".
    ]);

    return [
        'ok'       => true,
        'analysis' => aiImageFindAnalysis($conn, $analysisId),
        'reason'   => null,
        'message'  => null,
    ];
}
