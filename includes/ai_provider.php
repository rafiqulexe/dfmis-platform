<?php
// ============================================================================
// AI PROVIDER LAYER
//
// The one place in DFMIS that knows a specific vendor exists. Everything above
// it — the veterinary assistant, the context builder, the UI, the API — talks
// to DfmsAiProvider and cannot name a vendor even by accident.
//
// WHY THIS EXISTS
// Before Phase 5D, includes/ai_service.php curled api.anthropic.com directly
// from the middle of the tenant-accounting logic. That worked, but it meant a
// second provider could only be added by editing the function every feature
// calls. The interface moves that decision to one file.
//
// WHY ONLY ONE PROVIDER IS IMPLEMENTED
// Anthropic is the provider this deployment is configured for
// (ANTHROPIC_API_KEY, already in config/database.php and .env.example). Adding
// a second implementation nobody has credentials for would ship dead code that
// no test can exercise end to end. The interface is the deliverable; a new
// provider is a new file implementing it, with no change anywhere else.
//
// ---------------------------------------------------------------------------
// CREDENTIAL HANDLING — the rules this file enforces
//
//   1. The key is read from the environment ONLY, through the constant
//      config/database.php defines. It is never a parameter, never stored,
//      never defaulted to a literal.
//   2. No method ever RETURNS the key, or any structure containing it.
//   3. Nothing is logged except a machine token and an HTTP status. The
//      upstream response body is never logged, because a provider that echoes
//      a request header into an error message would otherwise put the key in
//      our log file.
//   4. Exceptions are not allowed to escape carrying request context. Every
//      failure becomes a reason CODE.
//   5. isConfigured() answers a boolean. It is the only thing any caller may
//      learn about credentials.
// ---------------------------------------------------------------------------
// ============================================================================

require_once __DIR__ . '/../config/database.php';
// Phase 5E. The two files reference each other deliberately: configuration
// describes how a provider is called, and the provider is what a configuration
// summary reports on. The cycle is inert — neither file runs code at include
// time that needs the other's declarations.
require_once __DIR__ . '/ai_config.php';

/**
 * A completion backend.
 *
 * Implementations must satisfy the credential rules above. The interface is
 * deliberately narrow: multi-turn completion and nothing else. Streaming,
 * tool use and embeddings are not in this phase's scope and adding empty
 * methods for them would be abstraction for its own sake.
 */
interface DfmsAiProvider
{
    /** Short stable token for logging and the ai_usage row, e.g. 'anthropic'. */
    public function name(): string;

    /** True when this provider has usable credentials. Never reveals them. */
    public function isConfigured(): bool;

    /** The model this provider will use unless overridden. */
    public function defaultModel(): string;

    /**
     * Run a multi-turn completion.
     *
     * @param string $systemPrompt  the safety and role contract
     * @param list<array{role: string, content: string}> $messages
     *        Alternating conversation turns, oldest first, ending with 'user'.
     * @param array $options        ['max_tokens' => int, 'model' => string, 'temperature' => float]
     *
     * @return array{ok: bool, text: ?string, reason: ?string, model: string,
     *               input_tokens: int, output_tokens: int}
     *         `reason` is a MACHINE TOKEN on failure, never upstream prose.
     */
    public function complete(string $systemPrompt, array $messages, array $options = []): array;
}

// ---------------------------------------------------------------------------
// Failure reason tokens. A closed set, so the UI can map each to safe text and
// an unrecognised value can never be rendered to a user verbatim.
// ---------------------------------------------------------------------------
const DFMS_AI_FAILURE_REASONS = [
    'not_configured'     => 'The AI service is not configured on this installation.',
    'no_tenant'          => 'No active farm context.',
    'plan_limit_reached' => 'This organization has used its AI allowance for the month.',
    'empty_prompt'       => 'There was nothing to send.',
    'request_failed'     => 'The AI service could not be reached.',
    'rate_limited'       => 'The AI service is busy. Try again shortly.',
    'upstream_error'     => 'The AI service returned an error.',
    'empty_response'     => 'The AI service returned no content.',
    'transport_error'    => 'The AI service could not be reached.',

    // ---- Phase 5E ---------------------------------------------------------
    // Split out of 'upstream_error' so the retry policy can tell a transient
    // server-side failure (worth another attempt) from a request the provider
    // rejected as malformed (retrying identical bytes cannot help).
    'invalid_request'    => 'The AI service rejected the request.',
    // OUR limiter, not the provider's. Distinct from 'rate_limited' because
    // the two mean different things to the person reading the message and to
    // whoever is diagnosing it later: one is a busy vendor, the other is this
    // installation refusing to spend more on a shared allowance right now.
    'ai_rate_limited'    => 'Too many AI requests just now. Wait a moment and try again.',
];

/** Safe, user-facing text for a failure token. Never echoes an upstream body. */
function dfms_ai_failure_message(?string $reason): string {
    return DFMS_AI_FAILURE_REASONS[$reason ?? '']
        ?? 'The AI service is unavailable right now.';
}

// ===========================================================================
// ANTHROPIC
// ===========================================================================

final class DfmsAnthropicProvider implements DfmsAiProvider
{
    private string $model;
    private int $timeoutSeconds;

    public function __construct(string $model = 'claude-sonnet-5', int $timeoutSeconds = 60)
    {
        $this->model = $model;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function name(): string
    {
        return 'anthropic';
    }

    /**
     * Credentials are read here and nowhere else in the class, so there is
     * exactly one expression in the codebase that evaluates to the key.
     */
    private function apiKey(): string
    {
        return defined('ANTHROPIC_API_KEY') ? (string) ANTHROPIC_API_KEY : '';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function complete(string $systemPrompt, array $messages, array $options = []): array
    {
        $model = (string) ($options['model'] ?? $this->model);
        $fail = static function (string $reason) use ($model): array {
            return [
                'ok' => false, 'text' => null, 'reason' => $reason,
                'model' => $model, 'input_tokens' => 0, 'output_tokens' => 0,
            ];
        };

        if (!$this->isConfigured()) {
            return $fail('not_configured');
        }

        $normalised = self::normaliseMessages($messages);
        if ($normalised === []) {
            return $fail('empty_prompt');
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
            'system'     => $systemPrompt,
            'messages'   => $normalised,
        ];
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return $fail('empty_prompt');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        if ($ch === false) {
            return $fail('transport_error');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey(),
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transportError = curl_errno($ch) !== 0;
        curl_close($ch);

        if ($raw === false || $transportError) {
            // Deliberately does NOT log curl_error(): it can contain the
            // request URL and, on some builds, header context.
            error_log('[' . dfms_ai_request_ref() . '] ai provider anthropic: transport failure');
            return $fail('transport_error');
        }

        if ($httpCode !== 200) {
            // Status code only. The upstream body is NEVER logged — a provider
            // that echoes a request header into its error text would otherwise
            // write our API key into this log line.
            error_log('[' . dfms_ai_request_ref() . "] ai provider anthropic: HTTP $httpCode");
            if ($httpCode === 429) {
                return $fail('rate_limited');
            }
            if ($httpCode === 401 || $httpCode === 403) {
                return $fail('not_configured');
            }
            // Phase 5E: 4xx is OUR mistake, 5xx is theirs, and only the latter
            // is worth retrying. Collapsing both into 'upstream_error' meant a
            // rejected request would be sent again, unchanged, to be rejected
            // again — burning the provider's patience proving nothing.
            return $fail($httpCode >= 400 && $httpCode < 500 ? 'invalid_request' : 'upstream_error');
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $fail('empty_response');
        }

        // The response shape is read defensively: a provider changing its
        // envelope must degrade, not fatal.
        $text = null;
        foreach ($decoded['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? 'text') === 'text' && isset($block['text'])) {
                $text = ($text ?? '') . (string) $block['text'];
            }
        }
        if ($text === null || trim($text) === '') {
            return $fail('empty_response');
        }

        return [
            'ok'            => true,
            'text'          => $text,
            'reason'        => null,
            'model'         => $model,
            'input_tokens'  => (int) ($decoded['usage']['input_tokens'] ?? 0),
            'output_tokens' => (int) ($decoded['usage']['output_tokens'] ?? 0),
        ];
    }

    /**
     * Coerce turns into the provider's expected shape.
     *
     * Consecutive same-role turns are merged and empty ones dropped, because a
     * conversation loaded from the database can legitimately contain a
     * degraded assistant turn that carries no usable content.
     *
     * @param list<array{role: string, content: string}> $messages
     * @return list<array{role: string, content: string}>
     */
    private static function normaliseMessages(array $messages): array
    {
        $clean = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $last = count($clean) - 1;
            if ($last >= 0 && $clean[$last]['role'] === $role) {
                $clean[$last]['content'] .= "\n\n" . $content;
                continue;
            }
            $clean[] = ['role' => $role, 'content' => $content];
        }

        // The provider requires the exchange to begin with a user turn.
        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            array_shift($clean);
        }
        return $clean;
    }
}

// ===========================================================================
// RESOLUTION
// ===========================================================================

// Process-wide provider override. Null means "resolve the default".
// See dfms_ai_set_provider() below for why this seam exists.
$GLOBALS['dfms_ai_provider_override'] = null;

/**
 * The provider this installation uses.
 *
 * Resolved once per request. Swapping providers is a change HERE and in one
 * class — not in the veterinary domain, the UI or the API.
 */
function dfms_ai_provider(): DfmsAiProvider {
    if ($GLOBALS['dfms_ai_provider_override'] instanceof DfmsAiProvider) {
        return $GLOBALS['dfms_ai_provider_override'];
    }
    static $provider = null;
    if ($provider === null) {
        // Phase 5E: the model and the socket timeout now come from the
        // validated configuration layer rather than from a raw getenv() here.
        // A malformed AI_MODEL used to reach this constructor unchecked; it is
        // now discarded with a log line and the default is used instead.
        $config = aiRequestConfig();
        $provider = new DfmsAnthropicProvider(
            $config['model'],
            $config['timeout_seconds']
        );
    }
    return $provider;
}

/**
 * Install a different provider for the rest of this process.
 *
 * THE INJECTION POINT, and the reason the interface is worth having. It exists
 * for two real purposes:
 *
 *   1. Tests must be able to exercise a provider FAILURE and a provider
 *      SUCCESS without network access and without a live API key. Without a
 *      seam, "the assistant degrades safely when the provider fails" is
 *      untestable, and an untested failure path is one that will be wrong when
 *      it matters.
 *   2. A deployment that adds a second provider selects it here rather than by
 *      editing the veterinary domain.
 *
 * Pass null to fall back to the configured default.
 */
function dfms_ai_set_provider(?DfmsAiProvider $provider): void {
    $GLOBALS['dfms_ai_provider_override'] = $provider;
}

/**
 * Correlation id for AI log lines.
 *
 * Reuses the Phase 1 request id when errors.php is loaded, so an AI failure
 * can be tied to the request that caused it. Falls back to a short random
 * token in a CLI or test context where no request id exists.
 */
function dfms_ai_request_ref(): string {
    if (function_exists('dfms_request_id')) {
        return (string) dfms_request_id();
    }
    static $fallback = null;
    if ($fallback === null) {
        $fallback = 'ai-' . bin2hex(random_bytes(4));
    }
    return $fallback;
}
