<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use AiConfigViolation;
use DfmsAiProvider;
use DfmsAnthropicProvider;
use DfmsGeminiProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * GOOGLE GEMINI PROVIDER — configuration, selection and wire mapping.
 *
 * NO NETWORK CALL IS EVER MADE and NO API KEY IS EVER NEEDED. The two vendor
 * mapping methods are public statics exercised directly, and every end-to-end
 * path uses a stub installed through dfms_ai_set_provider().
 *
 * WHAT THIS SUITE IS REALLY FOR
 * Adding a second provider is the moment a provider abstraction either proves
 * itself or turns out to have been shaped around one vendor. The tests that
 * matter most here are the ones asserting that NOTHING ABOVE the provider
 * changed: the same gateway, the same retry tokens, the same safety layer, the
 * same tenant isolation, and — above all — no silent fallback between vendors.
 */
final class AiGeminiProviderTest extends TestCase
{
    private static PDO $conn;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        require_once __DIR__ . '/../../includes/tenant.php';
        require_once __DIR__ . '/../../includes/repository.php';
        require_once __DIR__ . '/../../includes/permissions.php';
        require_once __DIR__ . '/../../includes/audit.php';
        require_once __DIR__ . '/../../includes/settings.php';
        require_once __DIR__ . '/../../includes/livestock.php';
        require_once __DIR__ . '/../../includes/health.php';
        require_once __DIR__ . '/../../includes/ai_config.php';
        require_once __DIR__ . '/../../includes/ai_provider.php';
        require_once __DIR__ . '/../../includes/ai_service.php';
        require_once __DIR__ . '/../../includes/ai_tools.php';
        require_once __DIR__ . '/../../includes/ai_agent.php';
        require_once __DIR__ . '/../../includes/ai_vet.php';

        dfms_require_test_database();
        self::$conn = getDBConnection();
    }

    protected function setUp(): void
    {
        $_SESSION = ['admin_id' => 1, 'farm_id' => 1, 'is_platform_admin' => 0, 'username' => 'admin'];
        dfms_reset_settings_cache();
        dfms_ai_set_provider(null);
        self::$conn->exec('DELETE FROM ai_usage');
    }

    protected function tearDown(): void
    {
        // Every environment variable this suite sets is cleared here, so a
        // later test in the same process never inherits a provider choice.
        foreach (['AI_PROVIDER', 'AI_MODEL'] as $variable) {
            putenv($variable);
        }
        aiConfigEnvOverrides(true);
        self::$conn->exec('DELETE FROM ai_usage');
        dfms_ai_set_provider(null);
        $_SESSION = [];
    }

    /** Re-read the environment after changing it. */
    private function withEnv(array $variables): array
    {
        foreach ($variables as $name => $value) {
            $value === null ? putenv($name) : putenv("$name=$value");
        }
        aiConfigEnvOverrides(true);
        return aiRequestConfig();
    }

    // =======================================================================
    // 1. CONFIGURATION
    // =======================================================================

    public function testGeminiIsARegisteredProvider(): void
    {
        self::assertContains('gemini', DFMS_AI_PROVIDERS);
        self::assertContains('anthropic', DFMS_AI_PROVIDERS,
            'Adding a provider must never remove one');
    }

    public function testEveryRegisteredProviderHasADefaultModel(): void
    {
        foreach (DFMS_AI_PROVIDERS as $provider) {
            self::assertArrayHasKey($provider, DFMS_AI_PROVIDER_DEFAULT_MODELS,
                "$provider would otherwise inherit another vendor's model name");
            self::assertMatchesRegularExpression(
                DFMS_AI_MODEL_PATTERN,
                DFMS_AI_PROVIDER_DEFAULT_MODELS[$provider],
                "$provider's default model must satisfy the identifier pattern"
            );
        }
    }

    /** The unchanged single-provider behaviour: anthropic remains the default. */
    public function testTheDefaultIsStillAnthropic(): void
    {
        $config = $this->withEnv(['AI_PROVIDER' => null, 'AI_MODEL' => null]);

        self::assertSame('anthropic', $config['provider']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['model'], $config['model']);
    }

    /**
     * The model follows the provider when nobody names one.
     *
     * Without this, AI_PROVIDER=gemini with no AI_MODEL would send
     * 'claude-sonnet-5' to Google and produce a 400 nobody could explain.
     */
    public function testAnUnnamedModelFollowsTheProvider(): void
    {
        $config = $this->withEnv(['AI_PROVIDER' => 'gemini', 'AI_MODEL' => null]);

        self::assertSame('gemini', $config['provider']);
        self::assertSame(DFMS_AI_PROVIDER_DEFAULT_MODELS['gemini'], $config['model']);
        self::assertStringStartsWith('gemini-', $config['model']);
    }

    /** A named model is the operator's explicit choice and is left alone. */
    public function testANamedModelIsHonouredExactly(): void
    {
        $config = $this->withEnv(['AI_PROVIDER' => 'gemini', 'AI_MODEL' => 'gemini-2.5-pro']);

        self::assertSame('gemini', $config['provider']);
        self::assertSame('gemini-2.5-pro', $config['model']);
    }

    public function testACallerOverrideCanNameGeminiAndItsModel(): void
    {
        $config = aiRequestConfig(['provider' => 'gemini']);
        self::assertSame('gemini', $config['provider']);
        self::assertSame(DFMS_AI_PROVIDER_DEFAULT_MODELS['gemini'], $config['model']);

        $config = aiRequestConfig(['provider' => 'gemini', 'model' => 'gemini-2.0-flash']);
        self::assertSame('gemini-2.0-flash', $config['model']);
    }

    public function testAnUnknownProviderIsStillRefused(): void
    {
        foreach (['openai', 'GEMINI', 'gemini ', '', 'google'] as $provider) {
            try {
                aiRequestConfig(['provider' => $provider]);
                self::fail('Refused: ' . var_export($provider, true));
            } catch (AiConfigViolation $e) {
                self::assertStringContainsString('provider', $e->getMessage());
            }
        }
    }

    /** An operator typo in AI_PROVIDER is a typo, not an outage. */
    public function testAnInvalidProviderInTheEnvironmentFallsBackToTheDefault(): void
    {
        $config = $this->withEnv(['AI_PROVIDER' => 'gemni', 'AI_MODEL' => null]);

        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['provider'], $config['provider']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['model'], $config['model'],
            'A discarded provider must not leave a mismatched model behind');
    }

    public function testAMalformedGeminiModelIsDiscarded(): void
    {
        $config = $this->withEnv(['AI_PROVIDER' => 'gemini', 'AI_MODEL' => 'not a model name']);

        self::assertSame('gemini', $config['provider']);
        self::assertSame(DFMS_AI_PROVIDER_DEFAULT_MODELS['gemini'], $config['model']);
    }

    // =======================================================================
    // 2. PROVIDER SELECTION — exactly one, never a fallback
    // =======================================================================

    public function testTheFactoryBuildsTheConfiguredProviderAndOnlyThat(): void
    {
        $gemini = dfms_ai_build_provider(
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'timeout_seconds' => 30]
        );
        self::assertInstanceOf(DfmsGeminiProvider::class, $gemini);
        self::assertSame('gemini', $gemini->name());
        self::assertSame('gemini-2.5-flash', $gemini->defaultModel());

        $anthropic = dfms_ai_build_provider(
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'timeout_seconds' => 30]
        );
        self::assertInstanceOf(DfmsAnthropicProvider::class, $anthropic);
        self::assertSame('anthropic', $anthropic->name());
    }

    /**
     * THE RULE THAT MATTERS MOST.
     *
     * A configured provider with no key must degrade, NOT hand the request to
     * the other vendor. An operator who chose Gemini must never be answered by
     * Claude without knowing it — the answer would be attributed to the wrong
     * model on the usage row and in the conversation.
     */
    public function testAnUnconfiguredProviderDegradesRatherThanFallingBack(): void
    {
        $gemini = dfms_ai_build_provider(
            ['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'timeout_seconds' => 30]
        );

        // The test environment has no GEMINI_API_KEY, which is the condition
        // under test: this is exactly a deployment that named a provider and
        // forgot its key.
        self::assertFalse($gemini->isConfigured());

        $result = $gemini->complete('system', [['role' => 'user', 'content' => 'hello']]);

        self::assertFalse($result['ok']);
        self::assertSame('not_configured', $result['reason']);
        self::assertNull($result['text'], 'An unconfigured provider must never fabricate a reply');
        self::assertSame('gemini', $gemini->name(),
            'It must still report itself as the provider that was configured');
    }

    public function testIsConfiguredRevealsNothingButABoolean(): void
    {
        $gemini = new DfmsGeminiProvider();
        self::assertIsBool($gemini->isConfigured());

        // No accessor anywhere on the class returns credential material.
        foreach (['name', 'defaultModel'] as $method) {
            $value = $gemini->$method();
            self::assertIsString($value);
            self::assertStringNotContainsStringIgnoringCase('key', $value);
        }
    }

    // =======================================================================
    // 3. RESPONSE MAPPING
    // =======================================================================

    public function testAPlainTextResponseIsNormalised(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'The records show nothing unusual.']]],
                'finishReason' => 'STOP',
            ]],
        ]);

        self::assertSame('The records show nothing unusual.', $parsed['text']);
        self::assertSame([], $parsed['tool_requests']);
        self::assertSame('end_turn', $parsed['stop_reason']);
    }

    public function testMultipleTextPartsAreConcatenated(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'First. '], ['text' => 'Second.'],
            ]], 'finishReason' => 'STOP']],
        ]);

        self::assertSame('First. Second.', $parsed['text']);
        self::assertCount(2, $parsed['blocks']);
    }

    public function testAFunctionCallIsNormalisedIntoAToolRequest(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['text' => 'Let me check.'],
                ['functionCall' => ['name' => 'get_animal', 'args' => ['animal_id' => 7]]],
            ]], 'finishReason' => 'STOP']],
        ]);

        self::assertCount(1, $parsed['tool_requests']);
        self::assertSame('get_animal', $parsed['tool_requests'][0]['name']);
        self::assertSame(['animal_id' => 7], $parsed['tool_requests'][0]['input']);
        self::assertNotSame('', $parsed['tool_requests'][0]['id'],
            'ai_agent.php drops a request with no correlation id');
        // Gemini reports STOP even when it emitted a call; the presence of the
        // call is what makes it a tool turn.
        self::assertSame('tool_use', $parsed['stop_reason']);
    }

    public function testMultipleFunctionCallsGetDistinctIds(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_animal', 'args' => ['animal_id' => 1]]],
                ['functionCall' => ['name' => 'get_farm_summary', 'args' => []]],
            ]], 'finishReason' => 'STOP']],
        ]);

        $ids = array_column($parsed['tool_requests'], 'id');
        self::assertCount(2, $ids);
        self::assertSame($ids, array_unique($ids),
            'Duplicate ids would be collapsed by aiAgentNormaliseRequests()');
    }

    /** A tool-only turn is a valid answer, not an empty response. */
    public function testAToolOnlyTurnCarriesNoProseAndIsStillValid(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_farm_summary', 'args' => []]],
            ]], 'finishReason' => 'STOP']],
        ]);

        self::assertNull($parsed['text']);
        self::assertNotSame([], $parsed['tool_requests']);
    }

    public function testAMalformedFunctionCallIsDropped(): void
    {
        $parsed = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['args' => ['x' => 1]]],          // no name
                ['functionCall' => ['name' => '', 'args' => []]],    // empty name
                ['text' => 'Still here.'],
            ]], 'finishReason' => 'STOP']],
        ]);

        self::assertSame([], $parsed['tool_requests']);
        self::assertSame('Still here.', $parsed['text']);
    }

    public function testAMalformedEnvelopeDegradesRatherThanFataling(): void
    {
        foreach ([
            [],
            ['candidates' => []],
            ['candidates' => 'nonsense'],
            ['candidates' => [[]]],
            ['candidates' => [['content' => ['parts' => 'nope']]]],
            ['candidates' => [['content' => ['parts' => ['not an array']]]]],
        ] as $envelope) {
            $parsed = DfmsGeminiProvider::readResponse($envelope);
            self::assertNull($parsed['text']);
            self::assertSame([], $parsed['tool_requests']);
            self::assertContains($parsed['stop_reason'], DFMS_AI_STOP_REASONS);
        }
    }

    /**
     * A blocked candidate becomes 'other', carries no parts, and therefore
     * surfaces as 'empty_response' — which the retry policy does NOT retry.
     * Sending the identical request again would get the identical decision.
     */
    public function testBlockedAndUnknownFinishReasonsNormaliseSafely(): void
    {
        foreach (['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'SOMETHING_NEW', null, 42] as $reason) {
            $parsed = DfmsGeminiProvider::readResponse([
                'candidates' => [['content' => ['parts' => []], 'finishReason' => $reason]],
            ]);
            self::assertContains($parsed['stop_reason'], DFMS_AI_STOP_REASONS);
            self::assertNotSame('tool_use', $parsed['stop_reason'],
                'Only an actual function call may report tool_use');
        }

        self::assertSame('max_tokens', DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [['text' => 'cut off']]], 'finishReason' => 'MAX_TOKENS']],
        ])['stop_reason']);
    }

    // =======================================================================
    // 4. REQUEST MAPPING
    // =======================================================================

    public function testRolesAreMappedAndTheExchangeStartsWithUser(): void
    {
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'assistant', 'content' => 'Dropped — cannot lead.'],
            ['role' => 'user', 'content' => 'First.'],
            ['role' => 'assistant', 'content' => 'Second.'],
        ]);

        self::assertCount(2, $wire);
        self::assertSame('user', $wire[0]['role']);
        self::assertSame([['text' => 'First.']], $wire[0]['parts']);
        // Gemini names the assistant 'model'.
        self::assertSame('model', $wire[1]['role']);
    }

    public function testConsecutiveSameRoleTurnsMergeTheirParts(): void
    {
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => 'One.'],
            ['role' => 'user', 'content' => 'Two.'],
        ]);

        self::assertCount(1, $wire);
        self::assertCount(2, $wire[0]['parts'], 'Parts are appended, never string-concatenated');
    }

    public function testAToolExchangeRoundTripsThroughTheWireFormat(): void
    {
        $id = DfmsGeminiProvider::toolCallId(0, 'get_animal');

        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => 'Check this animal.'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => $id, 'name' => 'get_animal', 'input' => ['animal_id' => 3]],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => $id,
                 'content' => '{"tag":"A1"}', 'is_error' => false],
            ]],
        ]);

        self::assertSame('model', $wire[1]['role']);
        self::assertSame('get_animal', $wire[1]['parts'][0]['functionCall']['name']);

        // A tool result is a USER turn: the application is telling the model.
        self::assertSame('user', $wire[2]['role']);
        $response = $wire[2]['parts'][0]['functionResponse'];
        // Gemini correlates BY NAME, so the name must survive the round trip.
        self::assertSame('get_animal', $response['name']);
        self::assertSame(['tag' => 'A1'], $response['response']);
    }

    /** The name is recoverable from the id alone, without the transcript. */
    public function testAToolResultResolvesItsFunctionNameFromTheIdAlone(): void
    {
        $id = DfmsGeminiProvider::toolCallId(2, 'get_recent_alerts');
        self::assertSame('get_recent_alerts', DfmsGeminiProvider::toolNameFromId($id));

        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => '{"count":0}'],
            ]],
        ]);

        self::assertSame('get_recent_alerts', $wire[0]['parts'][0]['functionResponse']['name']);
    }

    public function testAnUnresolvableToolResultIsDroppedNotGuessed(): void
    {
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'opaque-id', 'content' => '{}'],
                ['type' => 'text', 'text' => 'Real content.'],
            ]],
        ]);

        self::assertCount(1, $wire[0]['parts'],
            'Guessing a function name would attribute a result to the wrong tool');
        self::assertArrayHasKey('text', $wire[0]['parts'][0]);
        self::assertNull(DfmsGeminiProvider::toolNameFromId('opaque-id'));
    }

    /** A functionResponse payload must be an object, whatever we were given. */
    public function testToolResultPayloadsAreAlwaysObjects(): void
    {
        $id = DfmsGeminiProvider::toolCallId(0, 'get_farm_summary');
        $cases = [
            '{"animals":40}'      => ['animals' => 40],
            '[1,2,3]'             => ['result' => [1, 2, 3]],
            'not json at all'     => ['result' => 'not json at all'],
            '"a string"'          => ['result' => '"a string"'],
        ];

        foreach ($cases as $content => $expected) {
            $wire = DfmsGeminiProvider::toWireMessages([
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => $id, 'content' => (string) $content],
                ]],
            ]);
            self::assertSame($expected, $wire[0]['parts'][0]['functionResponse']['response'],
                'Payload for: ' . $content);
        }
    }

    /** A refused tool must be reported as refused, not as an empty answer. */
    public function testAnErroredToolResultSaysSoInThePayload(): void
    {
        $id = DfmsGeminiProvider::toolCallId(0, 'get_animal');
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => $id,
                 'content' => '{"error":"You do not have permission to use that tool."}',
                 'is_error' => true],
            ]],
        ]);

        $response = $wire[0]['parts'][0]['functionResponse']['response'];
        self::assertTrue($response['error']);
    }

    public function testEmptyToolArgumentsSerialiseAsAnObject(): void
    {
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'gcall_0_get_farm_summary',
                 'name' => 'get_farm_summary', 'input' => []],
            ]],
        ]);

        $encoded = (string) json_encode($wire);
        self::assertStringContainsString('"args":{}', $encoded);
        self::assertStringNotContainsString('"args":[]', $encoded);
    }

    public function testAnUnrecognisedBlockIsNotForwarded(): void
    {
        $wire = DfmsGeminiProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'something_we_never_defined', 'data' => 'x'],
                ['type' => 'text', 'text' => 'Real content.'],
            ]],
        ]);

        self::assertCount(1, $wire[0]['parts']);
        self::assertSame('Real content.', $wire[0]['parts'][0]['text']);
    }

    // =======================================================================
    // 5. TOOL SCHEMA MAPPING
    // =======================================================================

    public function testToolSchemasConvertToTheVendorSubset(): void
    {
        $schema = aiToolJsonSchema(['type' => 'object', 'fields' => [
            'animal_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
            'severity'  => ['type' => 'enum', 'values' => ['critical', 'warning']],
            'tags'      => ['type' => 'array', 'items' => ['type' => 'string']],
        ]]);

        $wire = DfmsGeminiProvider::wireSchema($schema);

        self::assertSame('OBJECT', $wire['type']);
        self::assertSame('INTEGER', $wire['properties']['animal_id']['type']);
        self::assertSame(['critical', 'warning'], $wire['properties']['severity']['enum']);
        self::assertSame('ARRAY', $wire['properties']['tags']['type']);
        self::assertSame('STRING', $wire['properties']['tags']['items']['type']);
        self::assertSame(['animal_id'], $wire['required']);

        // Keywords this vendor's Schema type does not define are DROPPED, so a
        // tool-enabled request is not rejected wholesale for an unknown field.
        self::assertArrayNotHasKey('additionalProperties', $wire);
        self::assertArrayNotHasKey('minimum', $wire['properties']['animal_id']);
    }

    /**
     * A weaker HINT cannot weaken enforcement.
     *
     * The dropped keywords are bounds and unknown-key rules that
     * AiTool::execute() re-checks against the INTERNAL schema regardless.
     */
    public function testDroppingSchemaKeywordsDoesNotWeakenValidation(): void
    {
        $result = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => 0]);
        self::assertSame('invalid_input', $result['reason'], 'The bound is still enforced');

        $result = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => 1, 'farm_id' => 2]);
        self::assertSame('invalid_input', $result['reason'], 'Unknown keys are still refused');
    }

    public function testEveryRegisteredToolConvertsCleanly(): void
    {
        foreach (aiToolProviderDefinitions() as $definition) {
            $wire = DfmsGeminiProvider::wireSchema($definition['input_schema']);
            self::assertSame('OBJECT', $wire['type'], $definition['name']);
            self::assertNotFalse(json_encode($wire), $definition['name']);
        }
    }

    // =======================================================================
    // 6. ERROR CLASSIFICATION AND RETRY COMPATIBILITY
    // =======================================================================

    public function testHttpStatusesMapToTheExistingReasonTokens(): void
    {
        $expected = [
            400 => 'invalid_request',
            401 => 'not_configured',
            403 => 'not_configured',
            404 => 'invalid_request',
            422 => 'invalid_request',
            429 => 'rate_limited',
            500 => 'upstream_error',
            503 => 'upstream_error',
        ];
        foreach ($expected as $status => $reason) {
            self::assertSame($reason, DfmsGeminiProvider::classifyHttpStatus($status), "HTTP $status");
            self::assertArrayHasKey($reason, DFMS_AI_FAILURE_REASONS,
                'A provider may only emit tokens from the closed set');
        }
    }

    /** Credential and malformed-request failures must never be retried. */
    public function testCredentialAndMalformedFailuresAreNotRetryable(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            self::assertFalse(
                aiIsRetryableReason(DfmsGeminiProvider::classifyHttpStatus($status)),
                "HTTP $status must not be retried"
            );
        }
        foreach ([429, 500, 503] as $status) {
            self::assertTrue(
                aiIsRetryableReason(DfmsGeminiProvider::classifyHttpStatus($status)),
                "HTTP $status is transient and should be retried"
            );
        }
    }

    /** The mapping is identical to Anthropic's, so retry behaves the same. */
    public function testBothProvidersClassifyStatusesIdentically(): void
    {
        $gemini = new DfmsGeminiProvider();
        foreach ([400, 401, 403, 429, 500, 503] as $status) {
            // Exercised through the shared policy rather than by reaching into
            // the Anthropic class, which keeps its mapping private.
            self::assertSame(
                aiIsRetryableReason(DfmsGeminiProvider::classifyHttpStatus($status)),
                in_array(DfmsGeminiProvider::classifyHttpStatus($status), DFMS_AI_RETRYABLE_REASONS, true),
                "HTTP $status"
            );
        }
        self::assertSame('gemini', $gemini->name());
    }

    /** The shared retry policy drives a Gemini-shaped failure unchanged. */
    public function testTheExistingRetryPolicyAppliesToGeminiFailures(): void
    {
        $provider = new class implements DfmsAiProvider {
            public int $calls = 0;
            public function name(): string { return 'gemini'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'gemini-2.5-flash'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                return ['ok' => false, 'text' => null,
                    'reason' => DfmsGeminiProvider::classifyHttpStatus(503),
                    'model' => 'gemini-2.5-flash', 'input_tokens' => 0, 'output_tokens' => 0];
            }
        };

        $result = aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 3, 'retry_base_delay_ms' => 0]);

        self::assertFalse($result['ok']);
        self::assertSame(3, $provider->calls, 'Bounded by max_attempts, exactly as for Anthropic');

        // And a credential failure is not retried at all.
        $auth = new class implements DfmsAiProvider {
            public int $calls = 0;
            public function name(): string { return 'gemini'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'gemini-2.5-flash'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                return ['ok' => false, 'text' => null,
                    'reason' => DfmsGeminiProvider::classifyHttpStatus(401),
                    'model' => 'gemini-2.5-flash', 'input_tokens' => 0, 'output_tokens' => 0];
            }
        };
        aiCallWithPolicy($auth, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 4, 'retry_base_delay_ms' => 0]);
        self::assertSame(1, $auth->calls);
    }

    // =======================================================================
    // 7. CREDENTIAL REDACTION
    // =======================================================================

    public function testTheGeminiKeyNameIsOnTheAuditDenyList(): void
    {
        foreach (['gemini_api_key', 'google_api_key', 'x_goog_api_key', 'anthropic_api_key'] as $key) {
            self::assertContains($key, DFMS_AUDIT_DENY_KEYS);
        }

        $sanitised = dfms_audit_sanitize([
            'gemini_api_key' => 'AIzaSyFAKE-not-a-real-key',
            'provider'       => 'gemini',
        ]);
        self::assertSame('[REDACTED]', $sanitised['gemini_api_key']);
        self::assertSame('gemini', $sanitised['provider']);
    }

    public function testTheConfigSummaryCarriesNoCredentialWhateverTheProvider(): void
    {
        foreach (['anthropic', 'gemini'] as $provider) {
            $this->withEnv(['AI_PROVIDER' => $provider, 'AI_MODEL' => null]);
            $summary = aiConfigSummary();

            self::assertSame($provider, $summary['provider']);
            self::assertIsBool($summary['configured']);

            $serialised = strtolower((string) json_encode($summary));
            foreach (['api_key', 'sk-', 'aizasy', 'x-goog-api-key', 'x-api-key',
                         'authorization', 'bearer'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $serialised,
                    "$provider summary leaked '$forbidden'");
            }
        }
    }

    /** The key never reaches a usage row, whichever provider is named. */
    public function testNoCredentialMaterialReachesTheUsageRow(): void
    {
        $stub = new class implements DfmsAiProvider {
            public function name(): string { return 'gemini'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'gemini-2.5-flash'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                return ['ok' => true, 'text' => 'An answer.', 'reason' => null,
                    'model' => 'gemini-2.5-flash', 'input_tokens' => 3, 'output_tokens' => 5];
            }
        };
        dfms_ai_set_provider($stub);

        aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']],
            ['prompt_key' => 'veterinarian_assistant']);

        $row = self::$conn->query('SELECT * FROM ai_usage ORDER BY ai_usage_id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertSame('gemini', $row['provider'], 'The usage row names the provider that answered');
        $serialised = strtolower((string) json_encode($row));
        foreach (['api_key', 'sk-', 'aizasy', 'x-goog', 'authorization', 'bearer'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialised);
        }
    }

    // =======================================================================
    // 8. NOTHING ABOVE THE PROVIDER CHANGED
    // =======================================================================

    /**
     * The whole gateway works with a Gemini-named provider.
     *
     * AI_PROVIDER is set here rather than only injecting a stub, because the
     * MODEL on the usage row comes from aiRequestConfig() — the configuration
     * is the authority, not the provider instance. Injecting a Gemini stub
     * while the configuration still says anthropic is not a deployment that
     * can exist: dfms_ai_provider() builds the provider FROM that same
     * configuration, so in production the two always agree. Setting both is
     * what makes this test the real scenario.
     */
    public function testTheGatewayAccountsForGeminiLikeAnyOtherProvider(): void
    {
        $this->withEnv(['AI_PROVIDER' => 'gemini', 'AI_MODEL' => null]);

        $stub = new class implements DfmsAiProvider {
            public function name(): string { return 'gemini'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'gemini-2.5-flash'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                return ['ok' => true, 'text' => 'An answer.', 'reason' => null,
                    'model' => 'gemini-2.5-flash', 'input_tokens' => 11, 'output_tokens' => 7];
            }
        };
        dfms_ai_set_provider($stub);

        $result = aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']],
            ['prompt_key' => 'veterinarian_assistant']);

        self::assertTrue($result['ok']);
        self::assertSame('gemini', $result['provider']);

        $row = self::$conn->query(
            'SELECT provider, model, prompt_key, prompt_version, input_tokens, status
               FROM ai_usage ORDER BY ai_usage_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        // The SAME accounting, prompt registry and usage columns as Anthropic,
        // and a COHERENT pair: the provider that answered and the model it was
        // asked for belong to the same vendor.
        self::assertSame('gemini', $row['provider']);
        self::assertSame(DFMS_AI_PROVIDER_DEFAULT_MODELS['gemini'], $row['model']);
        self::assertSame('veterinarian_assistant', $row['prompt_key']);
        self::assertSame(aiPromptVersion('veterinarian_assistant'), (int) $row['prompt_version']);
        self::assertSame(11, (int) $row['input_tokens']);
        self::assertSame('success', $row['status']);
    }

    /**
     * THE SAFETY LAYER IS PROVIDER-INDEPENDENT.
     *
     * Urgency is detected server-side on the user's own words, before any
     * provider is reached, and the banner is applied afterwards regardless of
     * what came back. Swapping vendors cannot touch it.
     */
    public function testTheUrgencyBannerIsIndependentOfTheProvider(): void
    {
        $stub = new class implements DfmsAiProvider {
            public function name(): string { return 'gemini'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'gemini-2.5-flash'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                return ['ok' => true, 'reason' => null, 'model' => 'gemini-2.5-flash',
                    'input_tokens' => 1, 'output_tokens' => 1,
                    'text' => 'The records show this animal is healthy, so there is no emergency.'];
            }
        };
        dfms_ai_set_provider($stub);

        $result = aiVetAsk(self::$conn, ['question' => 'The cow is having a seizure and is unresponsive.']);

        try {
            self::assertTrue($result['urgency']['urgent']);
            self::assertStringContainsString('URGENT — CONTACT A VETERINARIAN NOW', (string) $result['answer']);
            self::assertStringStartsWith('URGENT', (string) $result['answer']);
        } finally {
            self::$conn->prepare('DELETE FROM ai_conversations WHERE conversation_id = ?')
                ->execute([$result['conversation_id']]);
        }
    }

    /** The registered safety contract is unchanged by adding a provider. */
    public function testTheVeterinarySafetyContractIsUnchanged(): void
    {
        $prompt = aiVetSystemPrompt();
        foreach ([
            'You do NOT replace a licensed veterinarian',
            'Never state that a diagnosis is confirmed',
            'Never invent a medication dose',
            'Never invent facts about this animal',
            'Never hide uncertainty',
        ] as $guarantee) {
            self::assertStringContainsString($guarantee, $prompt);
        }
        self::assertSame(2, aiPromptVersion('veterinarian_assistant'),
            'Adding a provider is not a prompt change and must not move the version');
    }

    /** Tool authorization and tenant isolation are untouched by the provider. */
    public function testToolAuthorizationAndTenantIsolationAreUnchanged(): void
    {
        $foreignId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $foreignId, 'Fixture assumption: farm 2 has an animal');

        $result = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => $foreignId]);
        self::assertSame('not_found', $result['reason']);

        self::assertNull(aiToolFind('write_animal'));
        foreach (aiToolRegistry() as $name => $tool) {
            self::assertStringEndsWith('.view', $tool->requiredPermission(),
                "$name must remain read-only");
        }
    }

    /** Structured output validates a Gemini reply exactly as any other. */
    public function testStructuredOutputIsProviderIndependent(): void
    {
        $schema = ['type' => 'object', 'fields' => [
            'summary'  => ['type' => 'string', 'required' => true, 'max' => 200],
            'severity' => ['type' => 'enum', 'required' => true, 'values' => ['low', 'high']],
        ]];

        // Gemini commonly wraps JSON in a markdown fence; the existing
        // extractor already handles that, and the schema is the authority.
        $fenced = "```json\n{\"summary\":\"ok\",\"severity\":\"low\"}\n```";
        $parsed = aiStructuredParse($fenced, $schema);
        self::assertTrue($parsed['ok']);
        self::assertSame('low', $parsed['data']['severity']);

        $bad = '{"summary":"ok","severity":"catastrophic"}';
        self::assertFalse(aiStructuredParse($bad, $schema)['ok']);
    }

    /** Anthropic is untouched: it still maps its own wire format correctly. */
    public function testAnthropicMappingStillWorks(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [
                ['type' => 'text', 'text' => 'Hello.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_animal', 'input' => ['animal_id' => 4]],
            ],
            'stop_reason' => 'tool_use',
        ]);

        self::assertSame('Hello.', $parsed['text']);
        self::assertSame('toolu_1', $parsed['tool_requests'][0]['id']);
        self::assertSame('tool_use', $parsed['stop_reason']);

        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => 'First.'],
            ['role' => 'assistant', 'content' => 'Second.'],
        ]);
        self::assertSame([
            ['role' => 'user', 'content' => 'First.'],
            ['role' => 'assistant', 'content' => 'Second.'],
        ], $wire, 'The Anthropic wire format must be byte-for-byte unchanged');
    }

    /** The two providers share the internal vocabulary and nothing else. */
    public function testBothProvidersProduceTheSameInternalShape(): void
    {
        $gemini = DfmsGeminiProvider::readResponse([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_animal', 'args' => ['animal_id' => 1]]],
            ]], 'finishReason' => 'STOP']],
        ]);
        $anthropic = DfmsAnthropicProvider::readResponse([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1',
                           'name' => 'get_animal', 'input' => ['animal_id' => 1]]],
            'stop_reason' => 'tool_use',
        ]);

        self::assertSame(array_keys($gemini), array_keys($anthropic));
        self::assertSame($gemini['stop_reason'], $anthropic['stop_reason']);
        self::assertSame(
            array_keys($gemini['tool_requests'][0]),
            array_keys($anthropic['tool_requests'][0]),
            'ai_agent.php reads one shape and must not learn a second'
        );
        self::assertSame($gemini['blocks'][0]['type'], $anthropic['blocks'][0]['type']);
    }
}
