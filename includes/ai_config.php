<?php
// ============================================================================
// AI REQUEST CONFIGURATION AND FAILURE POLICY
//
// One place that answers: which model, how hot, how big, how long to wait, and
// how many times to try again.
//
// ---------------------------------------------------------------------------
// WHY THIS IS SERVER-CONTROLLED AND NOT A SETTINGS SCREEN
//
// DFMIS already has a settings hierarchy (includes/settings.php: platform ->
// organization -> farm) and a feature-flag system (includes/features.php). It
// would be easy to hang temperature and max_tokens off one of them. That would
// be wrong here, for a reason worth stating:
//
//   these values SPEND MONEY and CHANGE CLINICAL OUTPUT.
//
// max_tokens is a cost multiplier on a shared metered allowance. temperature
// changes how confidently a veterinary assistant states an uncertain thing.
// Neither is a farm's preference to set, and exposing them would make an
// abuse or a mistake indistinguishable from a configuration change. They are
// therefore ENVIRONMENT configuration — the same tier as the API key and the
// database credentials — set by whoever operates the installation.
//
// The feature flag stays where it belongs: a farm can switch the assistant OFF
// (that is its decision), but not retune it.
//
// ---------------------------------------------------------------------------
// INVALID CONFIGURATION FAILS SAFE, AND THE TWO SOURCES FAIL DIFFERENTLY
//
//   ENVIRONMENT   A bad AI_MAX_TOKENS is an operator typo on a running system.
//                 The value is DISCARDED, the default is used, and the fact is
//                 logged loudly. Refusing to serve AI at all because someone
//                 wrote "1o24" would turn a typo into an outage.
//
//   CALLER        A bad override passed in code is a BUG, and it throws.
//                 Nothing but our own code passes overrides, so degrading
//                 quietly would only hide the mistake until it reached a
//                 tenant.
//
// ---------------------------------------------------------------------------
// NO SECOND PROVIDER IS INVENTED HERE
//
// Anthropic is the provider this installation is configured for. AI_PROVIDER
// is read and VALIDATED against the providers that actually exist, so the
// value is meaningful rather than decorative — but no empty class is added to
// claim multi-provider support. A new provider is a new implementation of
// DfmsAiProvider plus an entry in DFMS_AI_PROVIDERS, and nothing else changes.
// ============================================================================

require_once __DIR__ . '/ai_provider.php';

/** A caller-supplied configuration override that is out of bounds. A bug. */
class AiConfigViolation extends InvalidArgumentException {}

/** Providers that genuinely exist in this codebase. */
const DFMS_AI_PROVIDERS = ['anthropic', 'gemini'];

/**
 * The model each provider uses when AI_MODEL is not set.
 *
 * WHY THIS EXISTS. Before a second provider there was one global default
 * (DFMS_AI_CONFIG_DEFAULTS['model'], below) and it was correct because there
 * was one vendor. With two, a deployment that sets AI_PROVIDER=gemini and
 * leaves AI_MODEL unset would otherwise send 'claude-sonnet-5' to Google and
 * get a 400 it could not explain. The default model is a property OF THE
 * PROVIDER, so it is resolved from the provider.
 *
 * The anthropic entry is deliberately identical to the global default, so
 * nothing about the existing single-provider behaviour changes.
 *
 * These are starting points, not a supported-model list. Any model the
 * deployment's key can reach may be pinned with AI_MODEL.
 */
const DFMS_AI_PROVIDER_DEFAULT_MODELS = [
    'anthropic' => 'claude-sonnet-5',
    'gemini'    => 'gemini-2.5-flash',
];

/**
 * Defaults. `temperature` is null meaning "do not send one" — the provider's
 * own default is a deliberate choice by its vendor and second-guessing it
 * without a measured reason is noise.
 */
const DFMS_AI_CONFIG_DEFAULTS = [
    'provider'            => 'anthropic',
    'model'               => 'claude-sonnet-5',
    'temperature'         => null,
    'max_tokens'          => 1024,
    'timeout_seconds'     => 60,
    // Total attempts, not extra attempts: 2 means one call and at most one
    // retry. Phrasing it as a total makes an unbounded loop impossible to
    // write by accident.
    'max_attempts'        => 2,
    'retry_base_delay_ms' => 250,

    // ---- Phase 5F: the agent loop -----------------------------------------
    //
    // max_model_calls   Provider round-trips for ONE user question. Three is
    //                   the smallest number that supports the real flow —
    //                   ask for a tool, read the result and possibly ask for
    //                   another, then answer — and the loop deliberately stops
    //                   OFFERING tools on the final call, so termination does
    //                   not depend on the model choosing to stop.
    //
    // max_tool_calls    Total tool EXECUTIONS for one question, independent of
    //                   how they are spread across those calls. Five covers
    //                   "look at the animal, its health, its history and the
    //                   farm's alerts" with one to spare. A question needing
    //                   more than that is a question the model has misread.
    //
    // These are separate numbers because they bound different things: one
    // bounds spend and latency, the other bounds how much of the farm's data a
    // single question can sweep up.
    'max_model_calls'     => 3,
    'max_tool_calls'      => 5,
];

/**
 * Hard bounds. A value outside these is refused whatever asked for it.
 *
 * The upper bounds are the interesting ones. timeout_seconds is capped at 120
 * because a web request holding a socket open longer than that has already
 * failed for the person waiting on it, whatever the provider eventually says.
 * max_attempts is capped at 4 so a provider outage costs a bounded amount of
 * wall-clock time instead of turning every request into a slow failure.
 */
const DFMS_AI_CONFIG_BOUNDS = [
    'temperature'         => [0.0, 1.0],
    'max_tokens'          => [1, 8192],
    'timeout_seconds'     => [5, 120],
    'max_attempts'        => [1, 4],
    'retry_base_delay_ms' => [0, 2000],
    // Hard ceilings on the agent loop. The upper bounds are what make
    // "the loop terminates" a property of the CONFIGURATION rather than a
    // promise about the code: no value any operator or caller can supply
    // permits more than 6 provider round-trips or 12 tool executions for one
    // question, whatever the model asks for.
    'max_model_calls'     => [1, 6],
    'max_tool_calls'      => [0, 12],
];

/** A model identifier must look like one. Never interpolated into SQL. */
const DFMS_AI_MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/';

/**
 * Failure tokens worth trying again.
 *
 * TRANSIENT ONLY, and the exclusions matter more than the inclusions:
 *
 *   not_configured    An authentication failure. The credential will not have
 *                     become valid 250ms later, and retrying a rejected key is
 *                     how an account gets rate-limited or locked.
 *   invalid_request   The request itself was malformed. Sending the identical
 *                     bytes again cannot produce a different answer.
 *   empty_prompt      Refused before the network was touched.
 *   empty_response    The call SUCCEEDED and the provider chose to say nothing.
 *                     That is an answer, not an outage.
 */
const DFMS_AI_RETRYABLE_REASONS = ['transport_error', 'rate_limited', 'upstream_error'];

// ===========================================================================
// RESOLUTION
// ===========================================================================

/**
 * Environment overrides that survived validation.
 *
 * Cached per process: the environment does not change mid-request, and the
 * warning for a bad value should be logged once rather than on every call.
 *
 * @return array<string, mixed>
 */
function aiConfigEnvOverrides(bool $reset = false): array {
    static $cached = null;
    if ($reset) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }

    $overrides = [];
    $discard = static function (string $variable, string $why): void {
        // The NAME and the reason, never the value: an operator who pasted a
        // key into the wrong variable must not have it copied into the log.
        error_log("AI configuration: ignoring $variable — $why. Using the default.");
    };

    $provider = getenv('AI_PROVIDER');
    if (is_string($provider) && $provider !== '') {
        if (in_array($provider, DFMS_AI_PROVIDERS, true)) {
            $overrides['provider'] = $provider;
        } else {
            $discard('AI_PROVIDER', 'no such provider is implemented');
        }
    }

    $model = getenv('AI_MODEL');
    if (is_string($model) && $model !== '') {
        if (preg_match(DFMS_AI_MODEL_PATTERN, $model) === 1) {
            $overrides['model'] = $model;
        } else {
            $discard('AI_MODEL', 'not a valid model identifier');
        }
    }

    $numeric = [
        'AI_TEMPERATURE'      => ['temperature', 'float'],
        'AI_MAX_TOKENS'       => ['max_tokens', 'int'],
        'AI_TIMEOUT_SECONDS'  => ['timeout_seconds', 'int'],
        'AI_MAX_ATTEMPTS'     => ['max_attempts', 'int'],
        'AI_RETRY_DELAY_MS'   => ['retry_base_delay_ms', 'int'],
        'AI_MAX_MODEL_CALLS'  => ['max_model_calls', 'int'],
        'AI_MAX_TOOL_CALLS'   => ['max_tool_calls', 'int'],
    ];
    foreach ($numeric as $variable => [$key, $kind]) {
        $raw = getenv($variable);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        if (!is_numeric($raw)) {
            $discard($variable, 'not a number');
            continue;
        }
        $value = $kind === 'int' ? (int) $raw : (float) $raw;
        if ($kind === 'int' && (string) $value !== trim($raw)) {
            $discard($variable, 'must be a whole number');
            continue;
        }
        [$min, $max] = DFMS_AI_CONFIG_BOUNDS[$key];
        if ($value < $min || $value > $max) {
            $discard($variable, "outside the allowed range {$min} to {$max}");
            continue;
        }
        $overrides[$key] = $value;
    }

    $cached = $overrides;
    return $cached;
}

/**
 * The configuration for one AI request.
 *
 * Resolution order, narrowest last:
 *   defaults -> validated environment -> caller overrides
 *
 * @param array<string, mixed> $overrides
 * @return array{provider: string, model: string, temperature: ?float, max_tokens: int,
 *               timeout_seconds: int, max_attempts: int, retry_base_delay_ms: int,
 *               max_model_calls: int, max_tool_calls: int}
 * @throws AiConfigViolation on an out-of-bounds caller override
 */
function aiRequestConfig(array $overrides = []): array {
    $environment = aiConfigEnvOverrides();
    $config = array_merge(DFMS_AI_CONFIG_DEFAULTS, $environment);

    // Did anyone actually NAME a model, or is $config['model'] just the global
    // default sitting there? The distinction matters: an unnamed model must
    // follow the provider (see DFMS_AI_PROVIDER_DEFAULT_MODELS), while a named
    // one is the operator's explicit choice and is left exactly as given —
    // including a Gemini model on Anthropic, which is their mistake to make
    // and their 400 to read, not something for this layer to silently correct.
    $modelWasNamed = array_key_exists('model', $environment)
        || (array_key_exists('model', $overrides) && $overrides['model'] !== null);

    foreach ($overrides as $key => $value) {
        // Only the keys this layer owns. Callers legitimately pass unrelated
        // options through aiChat() (entity_type, prompt_key…), and those are
        // not configuration errors — they simply are not ours.
        if (!array_key_exists($key, DFMS_AI_CONFIG_DEFAULTS)) {
            continue;
        }
        if ($value === null && $key === 'temperature') {
            $config['temperature'] = null;
            continue;
        }

        if ($key === 'provider') {
            if (!is_string($value) || !in_array($value, DFMS_AI_PROVIDERS, true)) {
                throw new AiConfigViolation("Unknown AI provider requested.");
            }
            $config['provider'] = $value;
            continue;
        }
        if ($key === 'model') {
            if (!is_string($value) || preg_match(DFMS_AI_MODEL_PATTERN, $value) !== 1) {
                throw new AiConfigViolation('Invalid AI model identifier.');
            }
            $config['model'] = $value;
            continue;
        }

        if (!is_int($value) && !is_float($value)) {
            throw new AiConfigViolation("AI configuration '$key' must be a number.");
        }
        [$min, $max] = DFMS_AI_CONFIG_BOUNDS[$key];
        if ($value < $min || $value > $max) {
            throw new AiConfigViolation("AI configuration '$key' must be between $min and $max.");
        }
        $config[$key] = $key === 'temperature' ? (float) $value : (int) $value;
    }

    // Resolved LAST, once the provider is final: an unnamed model belongs to
    // whichever provider ended up selected.
    if (!$modelWasNamed) {
        $config['model'] = DFMS_AI_PROVIDER_DEFAULT_MODELS[$config['provider']]
            ?? DFMS_AI_CONFIG_DEFAULTS['model'];
    }

    return $config;
}

/**
 * What an operator may safely be shown.
 *
 * Contains NO credential, no key prefix, no key length and no endpoint. Whether
 * a key exists is a boolean, exactly as /api/v1/ai-status already reports it.
 *
 * @return array<string, mixed>
 */
function aiConfigSummary(): array {
    $config = aiRequestConfig();
    return [
        'provider'            => $config['provider'],
        'model'               => $config['model'],
        'configured'          => dfms_ai_provider()->isConfigured(),
        'max_tokens'          => $config['max_tokens'],
        'temperature'         => $config['temperature'],
        'timeout_seconds'     => $config['timeout_seconds'],
        'max_attempts'        => $config['max_attempts'],
        'retry_base_delay_ms' => $config['retry_base_delay_ms'],
        'max_model_calls'     => $config['max_model_calls'],
        'max_tool_calls'      => $config['max_tool_calls'],
    ];
}

// ===========================================================================
// FAILURE POLICY
// ===========================================================================

/** Whether a provider failure token is worth another attempt. */
function aiIsRetryableReason(?string $reason): bool {
    return $reason !== null && in_array($reason, DFMS_AI_RETRYABLE_REASONS, true);
}

/**
 * How long to wait before attempt N+1, in microseconds.
 *
 * Exponential, capped, with a little jitter so a herd of requests that failed
 * together does not retry in lockstep. Zero during tests — a suite must not
 * spend real seconds proving that a bounded loop is bounded.
 */
function aiRetryDelayMicroseconds(int $attempt, int $baseMs): int {
    if ($baseMs <= 0 || defined('DFMS_TESTING')) {
        return 0;
    }
    $delayMs = min($baseMs * (2 ** max(0, $attempt - 1)), DFMS_AI_CONFIG_BOUNDS['retry_base_delay_ms'][1] * 4);
    $jitterMs = random_int(0, (int) max(1, $delayMs / 4));
    return (int) (($delayMs + $jitterMs) * 1000);
}

/**
 * Call a provider under the configured timeout and retry policy.
 *
 * THE GUARANTEES, which are the whole point of routing every call through here:
 *
 *   - The loop is bounded by max_attempts, which is itself bounded by
 *     DFMS_AI_CONFIG_BOUNDS. There is no path that retries indefinitely.
 *   - Only transient failures are retried (see DFMS_AI_RETRYABLE_REASONS).
 *     An authentication failure or a malformed request is returned on the
 *     first attempt.
 *   - A provider that throws does not reach the web request. Any Throwable is
 *     caught and converted to the same degraded shape as a returned failure,
 *     because a fatal in a vendor call must not take a page down.
 *   - Nothing is logged but a machine token and an attempt number. No prompt,
 *     no response body, no header, no credential.
 *
 * @param list<array{role: string, content: string}> $messages
 * @param array<string, mixed> $options   passed to the provider unchanged
 * @param array{max_attempts: int, retry_base_delay_ms: int} $config
 * @return array{ok: bool, text: ?string, reason: ?string, model: string,
 *               input_tokens: int, output_tokens: int, attempts: int}
 */
function aiCallWithPolicy(
    DfmsAiProvider $provider,
    string $systemPrompt,
    array $messages,
    array $options,
    array $config
): array {
    $maxAttempts = max(1, min((int) $config['max_attempts'], DFMS_AI_CONFIG_BOUNDS['max_attempts'][1]));
    $baseDelayMs = (int) $config['retry_base_delay_ms'];
    $model = (string) ($options['model'] ?? $provider->defaultModel());

    $result = [
        'ok' => false, 'text' => null, 'reason' => 'transport_error',
        'model' => $model, 'input_tokens' => 0, 'output_tokens' => 0,
    ];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $result = $provider->complete($systemPrompt, $messages, $options);
        } catch (Throwable $e) {
            // The exception MESSAGE is not logged. A vendor SDK that puts a
            // request header into an exception would otherwise write our API
            // key into this line — the same rule ai_provider.php follows for
            // response bodies.
            error_log('[' . dfms_ai_request_ref() . '] ai provider '
                . $provider->name() . ': threw ' . get_class($e) . " on attempt $attempt");
            $result = [
                'ok' => false, 'text' => null, 'reason' => 'transport_error',
                'model' => $model, 'input_tokens' => 0, 'output_tokens' => 0,
            ];
        }

        if (!empty($result['ok'])) {
            return $result + ['attempts' => $attempt];
        }

        $reason = isset($result['reason']) ? (string) $result['reason'] : null;
        if (!aiIsRetryableReason($reason) || $attempt >= $maxAttempts) {
            return $result + ['attempts' => $attempt];
        }

        error_log('[' . dfms_ai_request_ref() . '] ai provider ' . $provider->name()
            . ": retrying after '$reason' (attempt $attempt of $maxAttempts)");

        $delay = aiRetryDelayMicroseconds($attempt, $baseDelayMs);
        if ($delay > 0) {
            usleep($delay);
        }
    }

    return $result + ['attempts' => $maxAttempts];
}
