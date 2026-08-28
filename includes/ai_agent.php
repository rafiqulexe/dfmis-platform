<?php
// ============================================================================
// CONTROLLED AI TOOL-CALLING LOOP
//
// Connects two things that already existed and had never been allowed to meet:
// the provider (includes/ai_provider.php) and the read-only tool registry
// (includes/ai_tools.php). Nothing here invents a new AI capability. What it
// adds is the ORCHESTRATION between them, and the rules that make handing a
// model a lever safe.
//
// ---------------------------------------------------------------------------
// THE ONE IDEA THIS FILE IS BUILT ON
//
// A tool request is a SUGGESTION FROM AN UNTRUSTED PARTY.
//
// It arrives from a model that has just read a farm's knowledge-base
// documents, a stockperson's free text and a clinical record — any of which
// can contain instructions aimed at it. The request names a tool and carries
// arguments, and both are attacker-influenceable. So the loop below treats a
// tool request exactly the way the rest of DFMIS treats an HTTP request: the
// name is looked up in a registry, the arguments are validated, the caller's
// permission is checked, and none of that trusts anything the model said.
//
// The model is not an authority. The USER is the authority, and the user's
// permissions are the ceiling. A person cannot learn anything through the
// assistant that they could not open a page and read themselves.
//
// ---------------------------------------------------------------------------
// TERMINATION IS STRUCTURAL, NOT NEGOTIATED
//
// The loop does not end when the model decides to stop. It ends because:
//
//   1. max_model_calls bounds the number of provider round-trips, and
//   2. on the FINAL permitted call the loop stops offering tools at all.
//
// Point 2 is what makes termination deterministic rather than probabilistic. A
// model that has no tools available has nothing to do but answer, so the last
// iteration produces prose by construction. A loop that instead relied on the
// model eventually choosing to stop would be a loop whose bound is a hope.
//
// max_tool_calls bounds something different — how much of the farm's data one
// question can sweep up — and is enforced independently.
//
// ---------------------------------------------------------------------------
// WHAT THE MODEL NEVER RECEIVES
//
// No SQL, no connection, no repository, no table name, no file path, no
// PHP callable, no HTTP client, no environment variable, no credential. It
// receives a tool NAME it may ask for and a JSON string of sanitised domain
// data. Everything crossing back to it goes through aiSanitizeForModel()
// below, which is a deny-list-aware, depth-and-size-bounded serializer, not a
// var_export of a database row.
//
// AND NOTHING HERE WRITES. Every registered tool is read-only (asserted by
// test), the loop has no write path, and a tool result never triggers a
// mutation. Write-capable AI is a separate decision for a separate phase, with
// human confirmation, and it is not quietly pre-built here.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/ai_tools.php';

/**
 * Ceilings on what one tool result may carry back to the model.
 *
 * These are about three separate hazards, not one:
 *
 *   max_bytes    A model's context is finite and metered. An unbounded result
 *                would let one query decide how much a question costs.
 *   max_depth    Bounds the recursion in the serializer itself, so a cyclic
 *                or pathological structure cannot exhaust the stack while we
 *                are in the middle of making it safe.
 *   max_string   A free-text clinical note is the field most likely to carry
 *                something long, and the least likely to need all of it.
 *   max_items    A list result must not become a bulk export. Combined with
 *                the per-tool limits already in the registry, this is the
 *                second wall between a tool call and "read the whole herd".
 */
const DFMS_AI_TOOL_RESULT_LIMITS = [
    'max_bytes'  => 8192,
    'max_depth'  => 6,
    'max_string' => 2000,
    'max_items'  => 50,
    'max_keys'   => 40,
];

/**
 * Why the loop stopped, as machine tokens. A closed set, mapped to safe text
 * through dfms_ai_failure_message() where they overlap with provider tokens.
 */
const DFMS_AI_AGENT_REASONS = [
    'tool_loop_exhausted' => 'The assistant could not finish looking into that. Try asking more narrowly.',
];

/** Safe user-facing text for an agent failure token. */
function aiAgentFailureMessage(?string $reason): string {
    return DFMS_AI_AGENT_REASONS[$reason ?? ''] ?? dfms_ai_failure_message($reason);
}

// ===========================================================================
// SANITIZATION — everything that crosses back to the model
// ===========================================================================

/**
 * Make an arbitrary PHP value safe to show a language model.
 *
 * A tool returns application data. Application data is shaped for US: it
 * carries internal keys, nullable columns, numeric ids and whatever a driver
 * happened to hand back. Passing that straight to a model is the same class of
 * mistake as passing a database row straight to a browser, with one extra
 * hazard — a model will happily REPEAT anything it is given, to a user, in
 * prose, so a leaked internal detail does not stay internal.
 *
 * What this enforces:
 *   - the existing audit deny-list, so a key named like a credential is
 *     redacted here for exactly the reason it is redacted in the audit log
 *   - valid UTF-8, because invalid bytes break json_encode and a silent
 *     encoding failure would send the model nothing at all
 *   - no control characters
 *   - bounded depth, string length, list length and key count
 *
 * @param mixed $value
 * @return mixed
 */
function aiSanitizeForModel($value, int $depth = 0) {
    if ($depth > DFMS_AI_TOOL_RESULT_LIMITS['max_depth']) {
        return '[omitted: too deeply nested]';
    }

    if ($value === null || is_bool($value) || is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        // NAN and INF are not representable in JSON. json_encode fails on
        // them, which would turn one odd number into a whole lost result.
        return is_finite($value) ? $value : null;
    }

    if (is_string($value)) {
        return aiSanitizeString($value);
    }

    if (is_array($value)) {
        $isList = array_is_list($value);
        $clean = [];
        $count = 0;
        $limit = $isList
            ? DFMS_AI_TOOL_RESULT_LIMITS['max_items']
            : DFMS_AI_TOOL_RESULT_LIMITS['max_keys'];

        foreach ($value as $key => $item) {
            if ($count >= $limit) {
                if ($isList) {
                    // Stated rather than silently dropped: a model told it has
                    // "the alerts" when it has the first fifty will reason as
                    // though the list were complete.
                    $clean[] = '[truncated: more items exist]';
                }
                break;
            }
            if (!$isList && is_string($key)
                && in_array(strtolower($key), DFMS_AUDIT_DENY_KEYS, true)) {
                // Not redacted-and-shown — REMOVED. A '[REDACTED]' marker in
                // an audit log tells an investigator something existed; the
                // same marker in a prompt only tells a model there is a secret
                // nearby and invites it to ask about it.
                continue;
            }
            $clean[$isList ? $count : aiSanitizeString((string) $key)] = aiSanitizeForModel($item, $depth + 1);
            $count++;
        }
        return $clean;
    }

    // Objects, resources and closures have no business in a tool result. A
    // tool that returns one is a bug in that tool, and the honest answer to
    // the model is that the value is unavailable — not a stringified handle.
    return '[omitted: unsupported value]';
}

/** Scrub one string: valid UTF-8, no control characters, bounded length. */
function aiSanitizeString(string $value): string {
    if (!mb_check_encoding($value, 'UTF-8')) {
        // Substitute rather than reject. A single bad byte in a name typed on
        // a mis-configured terminal should not cost the whole lookup.
        $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = is_string($converted) ? $converted : '';
    }
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    $value = is_string($stripped) ? $stripped : '';

    $max = DFMS_AI_TOOL_RESULT_LIMITS['max_string'];
    if (mb_strlen($value, 'UTF-8') > $max) {
        $value = mb_substr($value, 0, $max, 'UTF-8') . '…[truncated]';
    }
    return $value;
}

/**
 * Serialize a sanitised tool result to the JSON string the model will read.
 *
 * The byte cap is applied AFTER encoding and, when it bites, the payload is
 * replaced wholesale rather than cut mid-string. Truncated JSON is worse than
 * no JSON: it is either a parse failure or, if it happens to close, a
 * plausible-looking document that has quietly lost fields.
 *
 * @param mixed $data
 */
function aiToolResultToText($data): string {
    $encoded = json_encode(aiSanitizeForModel($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return '{"error":"The result could not be represented."}';
    }
    if (strlen($encoded) > DFMS_AI_TOOL_RESULT_LIMITS['max_bytes']) {
        return '{"error":"The result was too large to return. Ask for something narrower."}';
    }
    return $encoded;
}

// ===========================================================================
// TOOL EXECUTION, AS THE LOOP SEES IT
// ===========================================================================

/**
 * Run one model-requested tool and build the block that answers it.
 *
 * EVERY denial produces a tool_result marked `is_error`, never an absent
 * result and never an exception. That matters for two reasons:
 *
 *   1. A provider that sent N tool requests requires N results. Omitting one
 *      leaves the exchange malformed and the next call fails for a reason
 *      that has nothing to do with the actual problem.
 *   2. The model must be TOLD it was refused. A silently missing result is
 *      indistinguishable to it from an empty answer, and the failure mode of
 *      "empty answer" is confidently reporting that there is nothing there.
 *
 * The text handed back is drawn from the closed set in ai_tools.php, plus, for
 * a schema failure, the validator's own messages — those are written by us,
 * name only field paths, and let a model correct its arguments instead of
 * repeating the same malformed call.
 *
 * @param array{id: string, name: string, input: array<string, mixed>} $request
 * @return array{block: array<string, mixed>, log: array{name: string, ok: bool, reason: ?string}}
 */
function aiAgentRunTool(PDO $conn, array $request): array {
    $name = (string) $request['name'];
    $input = is_array($request['input'] ?? null) ? $request['input'] : [];

    $result = aiToolExecute($conn, $name, $input);

    if ($result['ok']) {
        $content = aiToolResultToText($result['data']);
        $isError = false;
    } else {
        $reason = (string) $result['reason'];
        $message = aiToolFailureMessage($reason);
        if ($reason === 'invalid_input' && $result['errors'] !== []) {
            $message .= ' ' . aiSanitizeString(implode(' ', array_slice($result['errors'], 0, 5)));
        }
        $content = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE) ?: '{"error":"refused"}';
        $isError = true;
    }

    return [
        'block' => [
            'type'        => DFMS_AI_BLOCK_TOOL_RESULT,
            'tool_use_id' => (string) $request['id'],
            'content'     => $content,
            'is_error'    => $isError,
        ],
        'log' => [
            // Safe metadata only: a registry name (or a truncated one the
            // model invented), a boolean, and a denial category. No arguments,
            // no result data, no clinical content.
            'name'   => mb_substr($name, 0, 64, 'UTF-8'),
            'ok'     => (bool) $result['ok'],
            'reason' => $result['ok'] ? null : (string) $result['reason'],
        ],
    ];
}

// ===========================================================================
// THE LOOP
// ===========================================================================

/**
 * Run a completion that may use tools, and return the model's final prose.
 *
 * Composes with the Phase 5E gateway rather than going around it: EVERY
 * iteration is an ordinary aiChat() call, so authentication, the plan
 * allowance, the rate limit, the retry policy, the usage row and the prompt
 * metadata all apply to each round-trip exactly as they do to a plain
 * consultation. There is no second path to a provider.
 *
 * ACCOUNTING IS PER ROUND-TRIP, DELIBERATELY. A question that takes three
 * provider calls really does cost three, and recording it as one would make
 * the plan allowance and the cost report both wrong in the tenant's favour
 * today and unexplainable later. The consequence — that a tool-using question
 * consumes more of the per-minute rate budget than a plain one — is real and
 * is why max_model_calls is 3 and not 8.
 *
 * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
 * @param array<string, mixed> $options  passed to aiChat(); `tools` is managed here
 *
 * @return array{ok: bool, text: ?string, reason: ?string, model: ?string, provider: ?string,
 *               input_tokens: int, output_tokens: int, model_calls: int, tool_calls: int,
 *               tool_log: list<array{name: string, ok: bool, reason: ?string}>}
 */
function aiAgentRun(PDO $conn, $feature, string $systemPrompt, array $messages, array $options = []): array {
    $config = aiRequestConfig($options);
    $maxModelCalls = max(1, (int) $config['max_model_calls']);
    $toolsRemaining = max(0, (int) $config['max_tool_calls']);

    // Filtered by the CALLER'S permissions before the model is told anything.
    // A user with no tool permissions simply gets Phase 5D behaviour.
    $toolDefinitions = $toolsRemaining > 0 ? aiToolProviderDefinitions() : [];

    $transcript = $messages;
    $toolLog = [];
    $inputTokens = 0;
    $outputTokens = 0;
    $modelCalls = 0;
    $lastModel = null;
    $lastProvider = null;

    $degraded = static function (?string $reason) use (
        &$inputTokens, &$outputTokens, &$modelCalls, &$toolLog, &$lastModel, &$lastProvider
    ): array {
        return [
            'ok' => false, 'text' => null, 'reason' => $reason,
            'model' => $lastModel, 'provider' => $lastProvider,
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            'model_calls' => $modelCalls, 'tool_calls' => count($toolLog),
            'tool_log' => $toolLog,
        ];
    };

    for ($call = 1; $call <= $maxModelCalls; $call++) {
        // THE TERMINATION GUARANTEE. On the last permitted round-trip the model
        // is offered no tools at all, so it has nothing available but an
        // answer. The loop's end does not depend on the model agreeing to end.
        $isFinalCall = ($call === $maxModelCalls);
        $offerTools = !$isFinalCall && $toolsRemaining > 0 && $toolDefinitions !== [];

        $callOptions = $options;
        if ($offerTools) {
            $callOptions['tools'] = $toolDefinitions;
        } else {
            unset($callOptions['tools']);
        }

        $result = aiChat($feature, $systemPrompt, $transcript, $callOptions);
        $modelCalls++;
        $lastModel = $result['model'] ?? $lastModel;
        $lastProvider = $result['provider'] ?? $lastProvider;
        $inputTokens += (int) $result['input_tokens'];
        $outputTokens += (int) $result['output_tokens'];

        if (!$result['ok']) {
            // Covers the provider being unavailable AND the plan or rate gate
            // closing part-way through a multi-call question. Whatever tools
            // already ran were reads; nothing needs undoing.
            return $degraded($result['reason']);
        }

        $requests = aiAgentNormaliseRequests($result['tool_requests'] ?? []);
        $text = is_string($result['text']) ? $result['text'] : '';

        // ---- is this the answer? -----------------------------------------
        if ($requests === [] || $isFinalCall) {
            if (trim($text) !== '') {
                return [
                    'ok' => true, 'text' => $text, 'reason' => null,
                    'model' => $lastModel, 'provider' => $lastProvider,
                    'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
                    'model_calls' => $modelCalls, 'tool_calls' => count($toolLog),
                    'tool_log' => $toolLog,
                ];
            }
            // Nothing usable. On the final call this means the model spent its
            // last turn asking for tools it was not offered; earlier it means a
            // provider returned neither prose nor a request.
            return $degraded($isFinalCall ? 'tool_loop_exhausted' : 'empty_response');
        }

        // ---- the model asked for tools -----------------------------------
        // Replay its turn verbatim, in the internal vocabulary. `blocks` is
        // preferred over rebuilding from `text` + `requests`, because a
        // provider that correlates a result to a request by id needs the id it
        // actually issued, not one we reconstructed.
        $assistantBlocks = $result['blocks'] ?? [];
        if (!is_array($assistantBlocks) || $assistantBlocks === []) {
            $assistantBlocks = aiAgentRebuildAssistantBlocks($text, $requests);
        }
        $transcript[] = ['role' => 'assistant', 'content' => $assistantBlocks];

        $resultBlocks = [];
        foreach ($requests as $request) {
            if ($toolsRemaining <= 0) {
                // The budget is spent. The model is TOLD, in the same shape as
                // any other refusal, so it stops asking and writes an answer
                // that admits what it could not check.
                $resultBlocks[] = [
                    'type'        => DFMS_AI_BLOCK_TOOL_RESULT,
                    'tool_use_id' => $request['id'],
                    'content'     => '{"error":"No more record lookups are available for this question."}',
                    'is_error'    => true,
                ];
                $toolLog[] = ['name' => mb_substr($request['name'], 0, 64, 'UTF-8'),
                    'ok' => false, 'reason' => 'tool_budget_exhausted'];
                continue;
            }

            // Decremented BEFORE execution. A tool that throws has still
            // consumed an attempt, so a failing tool cannot be retried
            // indefinitely at no cost to the budget.
            $toolsRemaining--;

            $executed = aiAgentRunTool($conn, $request);
            $resultBlocks[] = $executed['block'];
            $toolLog[] = $executed['log'];
        }

        // A tool_result turn is a USER turn. That is not a quirk of a wire
        // format — it is the correct reading: the result is something the
        // application is telling the model, not something the model said.
        $transcript[] = ['role' => 'user', 'content' => $resultBlocks];
    }

    // Unreachable while $maxModelCalls >= 1: the final iteration always
    // returns. Kept because a loop that can fall out of the bottom should say
    // what it means when it does, rather than returning null by omission.
    return $degraded('tool_loop_exhausted');
}

/**
 * Coerce provider-supplied tool requests into the shape the loop relies on.
 *
 * Anything without a usable name or correlation id is DROPPED. A malformed
 * request cannot be answered — there is no id to answer it against — and
 * inventing one would put a fabricated call into the transcript.
 *
 * @param mixed $requests
 * @return list<array{id: string, name: string, input: array<string, mixed>}>
 */
function aiAgentNormaliseRequests($requests): array {
    if (!is_array($requests)) {
        return [];
    }
    $clean = [];
    $seen = [];
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $id = isset($request['id']) && is_scalar($request['id']) ? (string) $request['id'] : '';
        $name = isset($request['name']) && is_string($request['name']) ? $request['name'] : '';
        if ($id === '' || $name === '') {
            error_log('[' . dfms_ai_request_ref() . '] ai agent: dropped a malformed tool request');
            continue;
        }
        // A REPEATED correlation id is dropped, keeping the first.
        //
        // Two requests sharing an id cannot both be answered: each produces a
        // tool_result carrying that same id, and a provider correlating
        // results to requests rejects the duplicate — a 400, which this
        // codebase maps to 'invalid_request' and deliberately does not retry.
        // The user would lose the whole consultation, after we had already
        // paid for a model call and run both tools. Cheaper to notice here.
        if (isset($seen[$id])) {
            error_log('[' . dfms_ai_request_ref() . '] ai agent: dropped a duplicate tool request id');
            continue;
        }
        $seen[$id] = true;

        $clean[] = [
            'id'    => $id,
            'name'  => $name,
            // Untrusted and unvalidated at this point, by design. A non-array
            // becomes an empty array so the tool's own schema rejects it with
            // a clear message instead of the loop failing on a type error.
            'input' => is_array($request['input'] ?? null) ? $request['input'] : [],
        ];
    }
    return $clean;
}

/**
 * Rebuild an assistant turn when a provider reported requests but no blocks.
 *
 * A fallback for a provider implementation (or a test double) that fills
 * `tool_requests` without filling `blocks`. Reconstructing is second-best —
 * see the call site — but it is better than sending a turn with the tool
 * request missing, which would leave the following tool_result correlated to
 * nothing.
 *
 * @param list<array{id: string, name: string, input: array<string, mixed>}> $requests
 * @return list<array<string, mixed>>
 */
function aiAgentRebuildAssistantBlocks(string $text, array $requests): array {
    $blocks = [];
    if (trim($text) !== '') {
        $blocks[] = ['type' => DFMS_AI_BLOCK_TEXT, 'text' => $text];
    }
    foreach ($requests as $request) {
        $blocks[] = [
            'type'  => DFMS_AI_BLOCK_TOOL_USE,
            'id'    => $request['id'],
            'name'  => $request['name'],
            'input' => $request['input'],
        ];
    }
    return $blocks;
}

/**
 * A compact, user-safe summary of what the assistant looked up.
 *
 * Stored on the message and shown in the thread. Names only, and only for
 * lookups that SUCCEEDED — telling a user "it tried to read something it was
 * not allowed to read" would be alarming, unactionable, and a description of
 * the security layer working correctly.
 *
 * @param list<array{name: string, ok: bool, reason: ?string}> $toolLog
 * @return list<string>
 */
function aiAgentToolsUsed(array $toolLog): array {
    $used = [];
    foreach ($toolLog as $entry) {
        if (!empty($entry['ok']) && !in_array($entry['name'], $used, true)) {
            $used[] = $entry['name'];
        }
    }
    return $used;
}

/**
 * Human wording for a tool name, for the conversation thread.
 *
 * Deliberately a fixed map rather than a prettified tool name: a user should
 * read "Checked this animal's health record", not "get_animal_health_summary".
 * An unmapped name falls back to a generic phrase rather than exposing the
 * registry identifier.
 */
function aiAgentToolLabel(string $name): string {
    $labels = [
        'get_animal'                => 'Looked up the animal\'s record',
        'get_animal_health_summary' => 'Checked the animal\'s current health',
        'get_animal_health_history' => 'Read the animal\'s recorded history',
        'get_recent_alerts'         => 'Checked this farm\'s open alerts',
        'get_farm_summary'          => 'Checked herd-level counts',
    ];
    return $labels[$name] ?? 'Checked farm records';
}
