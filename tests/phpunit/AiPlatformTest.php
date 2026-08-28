<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use AiConfigViolation;
use AiPromptNotFound;
use AiSchemaDefinitionError;
use AiTool;
use DfmsAiProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AI PLATFORM FOUNDATION (Phase 5E).
 *
 * Structured output, the prompt registry, request configuration, the retry and
 * timeout policy, rate limiting and the read-only AI tool registry.
 *
 * Runs against the ISOLATED dfms_test database (phpunit.xml sets DB_NAME and
 * tests/bootstrap.php refuses anything not identifiable as a test database).
 * NO NETWORK CALL IS EVER MADE — every provider here is a stub installed
 * through dfms_ai_set_provider().
 *
 * FIXTURE ASSUMPTIONS, asserted rather than assumed wherever they matter:
 *   admin 1 is farm_manager on farm 1 (organization 1, enterprise plan)
 *   admin 4 is accountant  on farm 1 — a role WITHOUT health.view
 *   farm 2 belongs to a different organization and has at least one animal
 *
 * The tests that matter most are the ones about what must NEVER happen: a
 * model's output becoming application data unvalidated, a tool reaching
 * another farm, a permission granted because a model asked for it, or a
 * credential appearing anywhere at all.
 */
final class AiPlatformTest extends TestCase
{
    private static PDO $conn;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        require_once __DIR__ . '/../../includes/tenant.php';
        require_once __DIR__ . '/../../includes/repository.php';
        require_once __DIR__ . '/../../includes/permissions.php';
        require_once __DIR__ . '/../../includes/audit.php';
        require_once __DIR__ . '/../../includes/features.php';
        require_once __DIR__ . '/../../includes/settings.php';
        require_once __DIR__ . '/../../includes/livestock.php';
        require_once __DIR__ . '/../../includes/health.php';
        require_once __DIR__ . '/../../includes/ai_schema.php';
        require_once __DIR__ . '/../../includes/ai_prompts.php';
        require_once __DIR__ . '/../../includes/ai_config.php';
        require_once __DIR__ . '/../../includes/ai_ratelimit.php';
        require_once __DIR__ . '/../../includes/ai_provider.php';
        require_once __DIR__ . '/../../includes/ai_service.php';
        require_once __DIR__ . '/../../includes/ai_tools.php';
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
        self::$conn->exec('DELETE FROM ai_usage');
        dfms_ai_set_provider(null);
        aiConfigEnvOverrides(true);
        aiRateLimits(true);
        $_SESSION = [];
    }

    // ---- helpers ----------------------------------------------------------

    /** A schema exercising every supported type at once. */
    private function richSchema(): array
    {
        return ['type' => 'object', 'fields' => [
            'summary'  => ['type' => 'string', 'required' => true, 'max' => 200],
            'severity' => ['type' => 'enum', 'required' => true, 'values' => ['low', 'medium', 'high']],
            'score'    => ['type' => 'integer', 'min' => 0, 'max' => 100],
            'ratio'    => ['type' => 'decimal', 'min' => 0.0, 'max' => 1.0],
            'urgent'   => ['type' => 'boolean'],
            'factors'  => ['type' => 'array', 'max_items' => 3, 'items' => [
                'type' => 'object', 'fields' => [
                    'label'  => ['type' => 'string', 'required' => true],
                    'weight' => ['type' => 'decimal'],
                ],
            ]],
        ]];
    }

    /** A provider that succeeds and counts how many times it was called. */
    private function countingProvider(string $reply): object
    {
        return new class ($reply) implements DfmsAiProvider {
            public int $calls = 0;
            public array $lastOptions = [];
            public function __construct(private string $reply) {}
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                $this->lastOptions = $options;
                return [
                    'ok' => true, 'text' => $this->reply, 'reason' => null,
                    'model' => 'stub-model', 'input_tokens' => 3, 'output_tokens' => 5,
                ];
            }
        };
    }

    /** A provider that always fails with one reason, counting attempts. */
    private function countingFailingProvider(string $reason): object
    {
        return new class ($reason) implements DfmsAiProvider {
            public int $calls = 0;
            public function __construct(private string $reason) {}
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                return [
                    'ok' => false, 'text' => null, 'reason' => $this->reason,
                    'model' => 'stub-model', 'input_tokens' => 0, 'output_tokens' => 0,
                ];
            }
        };
    }

    /** A provider that throws, the way a broken vendor SDK does. */
    private function throwingProvider(): object
    {
        return new class implements DfmsAiProvider {
            public int $calls = 0;
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                throw new \RuntimeException('x-api-key: sk-should-never-be-logged');
            }
        };
    }

    /** Fill the per-user rate window with real-looking provider attempts. */
    private function fillUsage(int $rows, string $status = 'success', int $adminId = 1): void
    {
        $stmt = self::$conn->prepare(
            "INSERT INTO ai_usage (organization_id, farm_id, admin_id, feature, model, status)
             VALUES (1, 1, ?, 'vet_assistant', 'stub-model', ?)"
        );
        for ($i = 0; $i < $rows; $i++) {
            $stmt->execute([$adminId, $status]);
        }
    }

    // =======================================================================
    // 1. STRUCTURED OUTPUT — the model is an untrusted external system
    // =======================================================================

    public function testValidStructuredOutputIsAccepted(): void
    {
        $raw = json_encode([
            'summary'  => 'Three animals show reduced intake.',
            'severity' => 'medium',
            'score'    => 42,
            'ratio'    => 0.25,
            'urgent'   => false,
            'factors'  => [['label' => 'intake', 'weight' => 0.7]],
        ]);

        $result = aiStructuredParse((string) $raw, $this->richSchema());

        self::assertTrue($result['ok'], implode(' ', $result['errors']));
        self::assertSame('medium', $result['data']['severity']);
        self::assertSame(42, $result['data']['score']);
        self::assertSame(0.25, $result['data']['ratio']);
        self::assertFalse($result['data']['urgent']);
        self::assertSame('intake', $result['data']['factors'][0]['label']);
    }

    /** Models wrap JSON in prose and fences. That is a formatting quirk. */
    public function testJsonIsRecoveredFromProseAndFences(): void
    {
        $schema = ['type' => 'object', 'fields' => ['a' => ['type' => 'integer', 'required' => true]]];

        $fenced = "Here you go:\n```json\n{\"a\": 1}\n```\nHope that helps.";
        self::assertTrue(aiStructuredParse($fenced, $schema)['ok']);

        $wrapped = "Sure. {\"a\": 2} Let me know if you need more.";
        self::assertSame(2, aiStructuredParse($wrapped, $schema)['data']['a']);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $schema = ['type' => 'object', 'fields' => ['a' => ['type' => 'integer', 'required' => true]]];

        foreach (['not json at all', '{"a": }', '', '   ', '{"a": 1'] as $raw) {
            $result = aiStructuredParse($raw, $schema);
            self::assertFalse($result['ok'], "Rejected: " . var_export($raw, true));
            self::assertSame('invalid_json', $result['reason']);
            self::assertNull($result['data'], 'A rejected response must yield no data');
        }
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        $result = aiStructuredParse('{"severity": "low"}', $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertSame('schema_violation', $result['reason']);
        self::assertStringContainsString("missing required field 'summary'", implode(' ', $result['errors']));
    }

    public function testInvalidEnumValueIsRejected(): void
    {
        $raw = '{"summary": "ok", "severity": "catastrophic"}';
        $result = aiStructuredParse($raw, $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertSame('schema_violation', $result['reason']);
        self::assertStringContainsString('must be one of: low, medium, high', implode(' ', $result['errors']));
    }

    public function testInvalidNestedObjectIsRejected(): void
    {
        // The nested object is missing its required 'label'.
        $raw = '{"summary": "ok", "severity": "low", "factors": [{"weight": 0.5}]}';
        $result = aiStructuredParse($raw, $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertStringContainsString("factors[0] is missing required field 'label'",
            implode(' ', $result['errors']));
    }

    public function testNestedObjectOfTheWrongShapeIsRejected(): void
    {
        $raw = '{"summary": "ok", "severity": "low", "factors": ["just a string"]}';
        $result = aiStructuredParse($raw, $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertStringContainsString('factors[0] must be an object', implode(' ', $result['errors']));
    }

    public function testOversizedResponseIsRejectedBeforeParsing(): void
    {
        $huge = '{"summary": "' . str_repeat('a', DFMS_AI_SCHEMA_LIMITS['max_response_bytes']) . '"}';
        $result = aiStructuredParse($huge, $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertSame('oversized_response', $result['reason']);
    }

    public function testOversizedFieldIsRejectedEvenInsideAValidResponse(): void
    {
        $raw = json_encode(['summary' => str_repeat('b', 500), 'severity' => 'low']);
        $result = aiStructuredParse((string) $raw, $this->richSchema());

        self::assertFalse($result['ok'], 'summary is capped at 200 by the schema');
        self::assertStringContainsString('longer than 200 characters', implode(' ', $result['errors']));
    }

    /** An unexpected key means the model misunderstood the contract. */
    public function testUnexpectedFieldIsRejected(): void
    {
        $raw = '{"summary": "ok", "severity": "low", "execute_sql": "DROP TABLE cows"}';
        $result = aiStructuredParse($raw, $this->richSchema());

        self::assertFalse($result['ok']);
        self::assertStringContainsString("unexpected field 'execute_sql'", implode(' ', $result['errors']));
    }

    /** No coercion, in either direction. "7" is not 7 and "true" is not true. */
    public function testTypesAreNotCoerced(): void
    {
        $schema = ['type' => 'object', 'fields' => [
            'n' => ['type' => 'integer'], 'b' => ['type' => 'boolean'], 'd' => ['type' => 'decimal'],
        ]];

        self::assertFalse(aiStructuredParse('{"n": "7"}', $schema)['ok']);
        self::assertFalse(aiStructuredParse('{"n": 7.5}', $schema)['ok'], 'A float is not a whole number');
        self::assertFalse(aiStructuredParse('{"b": "true"}', $schema)['ok']);
        self::assertFalse(aiStructuredParse('{"b": 1}', $schema)['ok']);
        self::assertFalse(aiStructuredParse('{"d": "0.5"}', $schema)['ok']);
        self::assertTrue(aiStructuredParse('{"d": 2}', $schema)['ok'], 'An integer is a valid decimal');
    }

    public function testNumericBoundsAreEnforced(): void
    {
        self::assertFalse(aiStructuredParse('{"summary":"s","severity":"low","score":101}', $this->richSchema())['ok']);
        self::assertFalse(aiStructuredParse('{"summary":"s","severity":"low","score":-1}', $this->richSchema())['ok']);
        self::assertFalse(aiStructuredParse('{"summary":"s","severity":"low","ratio":1.5}', $this->richSchema())['ok']);
        self::assertTrue(aiStructuredParse('{"summary":"s","severity":"low","score":100}', $this->richSchema())['ok']);
    }

    public function testTooManyArrayItemsAreRejected(): void
    {
        $factors = array_fill(0, 4, ['label' => 'x']);
        $raw = json_encode(['summary' => 's', 'severity' => 'low', 'factors' => $factors]);

        $result = aiStructuredParse((string) $raw, $this->richSchema());
        self::assertFalse($result['ok']);
        self::assertStringContainsString('more than 3 items', implode(' ', $result['errors']));
    }

    public function testExcessivelyNestedStructureIsRejected(): void
    {
        // Deeper than DFMS_AI_SCHEMA_LIMITS['max_depth'] allows json_decode to go.
        $depth = DFMS_AI_SCHEMA_LIMITS['max_depth'] + 10;
        $raw = str_repeat('{"a":', $depth) . '1' . str_repeat('}', $depth);

        $result = aiStructuredParse($raw, ['type' => 'object', 'fields' => []]);
        self::assertFalse($result['ok']);
        self::assertSame('invalid_json', $result['reason']);
    }

    public function testControlCharactersInStringsAreRejected(): void
    {
        $schema = ['type' => 'object', 'fields' => ['s' => ['type' => 'string', 'required' => true]]];
        // A JSON-escaped NUL is valid JSON and invalid content.
        $result = aiStructuredParse('{"s": "before\u0000after"}', $schema);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('invalid characters', implode(' ', $result['errors']));
    }

    public function testNullIsOnlyAcceptedWhereTheSchemaAllowsIt(): void
    {
        $schema = ['type' => 'object', 'fields' => [
            'a' => ['type' => 'string', 'nullable' => true],
            'b' => ['type' => 'string'],
        ]];
        self::assertTrue(aiStructuredParse('{"a": null}', $schema)['ok']);
        self::assertFalse(aiStructuredParse('{"b": null}', $schema)['ok']);
    }

    /** A schema WE wrote badly is a bug and must not pass silently. */
    public function testAMalformedSchemaThrowsRatherThanValidatingNothing(): void
    {
        $this->expectException(AiSchemaDefinitionError::class);
        aiSchemaValidate(['a' => 1], ['type' => 'object', 'fields' => ['a' => ['type' => 'unicorn']]]);
    }

    public function testSchemaDescriptionIsGeneratedFromTheSchema(): void
    {
        $described = aiSchemaDescribe($this->richSchema());

        self::assertStringContainsString('summary (required)', $described);
        self::assertStringContainsString('score (optional)', $described);
        self::assertStringContainsString('low | medium | high', $described);
    }

    // =======================================================================
    // 2. PROMPT REGISTRY AND VERSIONING
    // =======================================================================

    public function testKnownPromptResolves(): void
    {
        $prompt = aiPrompt('veterinarian_assistant');

        self::assertSame(1, $prompt['version']);
        self::assertNotSame('', trim($prompt['system']));
        self::assertTrue($prompt['metadata']['safety_critical']);
        self::assertTrue(aiPromptExists('veterinarian_assistant'));
    }

    public function testUnknownPromptFails(): void
    {
        self::assertFalse(aiPromptExists('health_risk_assessment'),
            'A prompt for an unbuilt phase must not be pre-registered');

        $this->expectException(AiPromptNotFound::class);
        aiPrompt('health_risk_assessment');
    }

    public function testUnknownPromptNeverFallsBackToAnEmptyContract(): void
    {
        foreach (['', 'nonsense', 'veterinarian', 'VETERINARIAN_ASSISTANT'] as $key) {
            try {
                aiPromptSystem($key);
                self::fail("aiPromptSystem('$key') must throw rather than return a default");
            } catch (AiPromptNotFound $e) {
                self::assertStringContainsString('Unknown AI prompt key', $e->getMessage());
            }
        }
    }

    public function testPromptVersionIsRecordedOnTheUsageRow(): void
    {
        dfms_ai_set_provider($this->countingProvider('An answer.'));

        $result = aiChat('vet_assistant', aiVetSystemPrompt(), [
            ['role' => 'user', 'content' => 'A question.'],
        ], ['prompt_key' => 'veterinarian_assistant']);

        self::assertTrue($result['ok']);

        $row = self::$conn->query(
            "SELECT provider, prompt_key, prompt_version, model FROM ai_usage ORDER BY ai_usage_id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame('test-stub', $row['provider']);
        self::assertSame('veterinarian_assistant', $row['prompt_key']);
        self::assertSame(1, (int) $row['prompt_version']);
        self::assertSame(aiPromptVersion('veterinarian_assistant'), (int) $row['prompt_version']);
    }

    /** A caller that names no prompt records null, not a guessed version. */
    public function testAnUnnamedPromptRecordsNoVersionRatherThanAGuess(): void
    {
        dfms_ai_set_provider($this->countingProvider('An answer.'));
        aiChat('vet_assistant', 'A bare prompt.', [['role' => 'user', 'content' => 'Hi.']]);

        $row = self::$conn->query(
            'SELECT prompt_key, prompt_version FROM ai_usage ORDER BY ai_usage_id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNull($row['prompt_key']);
        self::assertNull($row['prompt_version']);
    }

    /** The Phase 5D safety contract survived the move into the registry. */
    public function testTheVeterinarySafetyContractIsUnchangedByTheRegistry(): void
    {
        self::assertSame(aiPromptSystem('veterinarian_assistant'), aiVetSystemPrompt(),
            'aiVetSystemPrompt() must read the registered prompt, not a copy');

        $prompt = aiVetSystemPrompt();
        foreach ([
            'You do NOT replace a licensed veterinarian',
            'Never state that a diagnosis is confirmed',
            'Never invent a medication dose',
            'Never invent facts about this animal',
            'Never hide uncertainty',
            'When to call a veterinarian',
        ] as $guarantee) {
            self::assertStringContainsString($guarantee, $prompt,
                'A Phase 5D safety guarantee must not have been lost in the migration');
        }
    }

    public function testPromptCatalogueOmitsTheBodies(): void
    {
        $catalogue = aiPromptCatalogue();

        self::assertNotSame([], $catalogue);
        foreach ($catalogue as $entry) {
            self::assertArrayHasKey('key', $entry);
            self::assertArrayHasKey('version', $entry);
            self::assertArrayNotHasKey('system', $entry,
                'The catalogue must not print the safety contract');
        }
    }

    // =======================================================================
    // 3. REQUEST CONFIGURATION
    // =======================================================================

    public function testDefaultConfigurationIsWithinItsOwnBounds(): void
    {
        $config = aiRequestConfig();

        foreach (['max_tokens', 'timeout_seconds', 'max_attempts', 'retry_base_delay_ms'] as $key) {
            [$min, $max] = DFMS_AI_CONFIG_BOUNDS[$key];
            self::assertGreaterThanOrEqual($min, $config[$key], "$key below its own minimum");
            self::assertLessThanOrEqual($max, $config[$key], "$key above its own maximum");
        }
        self::assertContains($config['provider'], DFMS_AI_PROVIDERS);
    }

    public function testInvalidCallerModelIsRejected(): void
    {
        foreach (['', 'model with spaces', "bad\nname", 'x;DROP TABLE cows', str_repeat('m', 200)] as $model) {
            try {
                aiRequestConfig(['model' => $model]);
                self::fail('An invalid model identifier must be refused: ' . var_export($model, true));
            } catch (AiConfigViolation $e) {
                self::assertStringContainsString('model', $e->getMessage());
            }
        }
    }

    public function testUnknownProviderIsRejected(): void
    {
        $this->expectException(AiConfigViolation::class);
        aiRequestConfig(['provider' => 'openai']);
    }

    public function testTimeoutAndRetryAreBounded(): void
    {
        foreach ([
            ['timeout_seconds' => 100000],
            ['timeout_seconds' => 0],
            ['max_attempts' => 99],
            ['max_attempts' => 0],
            ['max_tokens' => 999999],
            ['max_tokens' => 0],
            ['temperature' => 5.0],
            ['temperature' => -1.0],
            ['retry_base_delay_ms' => 60000],
        ] as $override) {
            try {
                aiRequestConfig($override);
                self::fail('Out-of-bounds configuration must be refused: ' . json_encode($override));
            } catch (AiConfigViolation $e) {
                self::assertStringContainsString('between', $e->getMessage());
            }
        }
    }

    public function testValidCallerOverridesAreAccepted(): void
    {
        $config = aiRequestConfig(['max_tokens' => 1400, 'temperature' => 0.2, 'model' => 'claude-opus-5']);

        self::assertSame(1400, $config['max_tokens']);
        self::assertSame(0.2, $config['temperature']);
        self::assertSame('claude-opus-5', $config['model']);
    }

    /** An operator typo is a typo, not an outage. */
    public function testInvalidEnvironmentConfigurationFallsBackToTheDefault(): void
    {
        putenv('AI_MAX_TOKENS=1o24');
        putenv('AI_TIMEOUT_SECONDS=99999');
        putenv('AI_MODEL=not a model name');
        putenv('AI_PROVIDER=some-vendor-we-never-wrote');
        aiConfigEnvOverrides(true);

        $config = aiRequestConfig();

        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['max_tokens'], $config['max_tokens']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['timeout_seconds'], $config['timeout_seconds']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['model'], $config['model']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['provider'], $config['provider']);

        putenv('AI_MAX_TOKENS');
        putenv('AI_TIMEOUT_SECONDS');
        putenv('AI_MODEL');
        putenv('AI_PROVIDER');
        aiConfigEnvOverrides(true);
    }

    public function testValidEnvironmentConfigurationIsHonoured(): void
    {
        putenv('AI_MAX_TOKENS=2048');
        aiConfigEnvOverrides(true);

        self::assertSame(2048, aiRequestConfig()['max_tokens']);

        putenv('AI_MAX_TOKENS');
        aiConfigEnvOverrides(true);
    }

    /** Unrelated aiChat() options are not configuration errors. */
    public function testUnrelatedOptionsArePassedOverNotRejected(): void
    {
        $config = aiRequestConfig(['entity_type' => 'cow', 'entity_id' => 7, 'prompt_key' => 'x']);
        self::assertSame(DFMS_AI_CONFIG_DEFAULTS['max_tokens'], $config['max_tokens']);
    }

    public function testConfigSummaryCarriesNoCredential(): void
    {
        $summary = aiConfigSummary();

        self::assertIsBool($summary['configured']);
        $serialised = json_encode($summary);
        // 'anthropic' on its own is NOT forbidden — it is the provider NAME,
        // which is precisely what this summary exists to report. The thing
        // that must never appear is the credential or the variable holding it.
        foreach (['api_key', 'sk-', 'x-api-key', 'anthropic_api_key', 'authorization', 'bearer'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, (string) $serialised,
                'The configuration summary must never carry credential material');
        }
    }

    // =======================================================================
    // 4. RETRY AND TIMEOUT POLICY
    // =======================================================================

    public function testATransientFailureIsRetriedAndTheLoopIsBounded(): void
    {
        $provider = $this->countingFailingProvider('transport_error');

        $result = aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 3, 'retry_base_delay_ms' => 0]);

        self::assertFalse($result['ok']);
        self::assertSame(3, $provider->calls, 'Bounded by max_attempts — never more');
        self::assertSame(3, $result['attempts']);
    }

    public function testTheRetryCeilingCannotBeRaisedAboveTheHardBound(): void
    {
        $provider = $this->countingFailingProvider('upstream_error');

        // Even asked for 500 attempts, the policy clamps to the hard bound.
        aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 500, 'retry_base_delay_ms' => 0]);

        self::assertSame(DFMS_AI_CONFIG_BOUNDS['max_attempts'][1], $provider->calls);
    }

    public function testAuthenticationAndMalformedRequestFailuresAreNeverRetried(): void
    {
        foreach (['not_configured', 'invalid_request', 'empty_prompt', 'empty_response'] as $reason) {
            $provider = $this->countingFailingProvider($reason);

            $result = aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
                ['max_attempts' => 4, 'retry_base_delay_ms' => 0]);

            self::assertSame(1, $provider->calls, "'$reason' must not be retried");
            self::assertSame($reason, $result['reason']);
        }
    }

    public function testASucceedingCallIsNotRetried(): void
    {
        $provider = $this->countingProvider('fine');

        $result = aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 4, 'retry_base_delay_ms' => 0]);

        self::assertTrue($result['ok']);
        self::assertSame(1, $provider->calls);
        self::assertSame(1, $result['attempts']);
    }

    /** A provider that throws must not take the request down with it. */
    public function testAThrowingProviderDegradesInsteadOfCrashing(): void
    {
        $provider = $this->throwingProvider();

        $result = aiCallWithPolicy($provider, 'sys', [['role' => 'user', 'content' => 'q']], [],
            ['max_attempts' => 2, 'retry_base_delay_ms' => 0]);

        self::assertFalse($result['ok']);
        self::assertSame('transport_error', $result['reason']);
        self::assertNull($result['text'], 'A crash must never look like an answer');
    }

    /** The retry is invisible to accounting: one logical call, one usage row. */
    public function testARetriedFailureIsBilledOnceNotPerAttempt(): void
    {
        dfms_ai_set_provider($this->countingFailingProvider('transport_error'));

        aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']]);

        $rows = (int) self::$conn->query("SELECT COUNT(*) FROM ai_usage WHERE status = 'error'")->fetchColumn();
        self::assertSame(1, $rows, 'A tenant must not be charged for our own retry');
    }

    public function testRetryDelayIsZeroUnderTestAndBoundedOtherwise(): void
    {
        self::assertSame(0, aiRetryDelayMicroseconds(1, 250),
            'A suite must not spend real seconds proving a loop is bounded');
        self::assertSame(0, aiRetryDelayMicroseconds(9, 0));
    }

    public function testTheProviderReceivesTheValidatedConfiguration(): void
    {
        $provider = $this->countingProvider('ok');
        dfms_ai_set_provider($provider);

        aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']], ['max_tokens' => 1400]);

        self::assertSame(1400, $provider->lastOptions['max_tokens']);
        self::assertSame(aiRequestConfig()['model'], $provider->lastOptions['model']);
    }

    // =======================================================================
    // 5. RATE LIMITING
    // =======================================================================

    public function testRequestsWithinTheLimitAreAllowed(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute'] - 1);

        $check = aiRateLimitCheck(self::$conn, 1, 1);
        self::assertTrue($check['allowed']);
        self::assertNull($check['scope']);
    }

    public function testExceedingThePerUserLimitFailsSafely(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute']);

        $check = aiRateLimitCheck(self::$conn, 1, 1);
        self::assertFalse($check['allowed']);
        self::assertSame('user_per_minute', $check['scope']);
        self::assertSame(60, $check['retry_after']);
    }

    public function testTheGatewayRefusesAnOverRateRequestWithoutCallingTheProvider(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute']);

        $provider = $this->countingProvider('should never be produced');
        dfms_ai_set_provider($provider);

        $result = aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']]);

        self::assertFalse($result['ok']);
        self::assertSame('ai_rate_limited', $result['reason']);
        self::assertNull($result['text']);
        self::assertSame(0, $provider->calls, 'A refused request must cost nothing');
        self::assertArrayHasKey('ai_rate_limited', DFMS_AI_FAILURE_REASONS,
            'The token must map to safe user-facing text');
    }

    /** A refusal is recorded as blocked, and blocked rows do not extend it. */
    public function testBlockedRowsDoNotCountTowardsTheWindow(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_hour'] + 5, 'blocked');

        $check = aiRateLimitCheck(self::$conn, 1, 1);
        self::assertTrue($check['allowed'],
            'Being refused must not keep a user refused');
    }

    public function testARefusedRequestIsRecordedAsBlocked(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute']);
        dfms_ai_set_provider($this->countingProvider('x'));

        aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']]);

        $blocked = (int) self::$conn->query("SELECT COUNT(*) FROM ai_usage WHERE status = 'blocked'")->fetchColumn();
        self::assertSame(1, $blocked);
    }

    /** The limit is derived from the session and the database, not from input. */
    public function testTheLimitCannotBeBypassedWithClientSuppliedParameters(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute']);
        $provider = $this->countingProvider('x');
        dfms_ai_set_provider($provider);

        // Everything a hostile client might try to smuggle through the option
        // bag. None of it is read by the limiter.
        $result = aiChat('vet_assistant', 'sys', [['role' => 'user', 'content' => 'q']], [
            'organization_id'  => 999,
            'admin_id'         => 999,
            'farm_id'          => 2,
            'rate_limit'       => false,
            'skip_rate_limit'  => true,
            'user_per_minute'  => 100000,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('ai_rate_limited', $result['reason']);
        self::assertSame(0, $provider->calls);
    }

    public function testAnotherUsersCallsDoNotConsumeThisUsersAllowance(): void
    {
        $limits = aiRateLimits();
        $this->fillUsage($limits['user_per_minute'], 'success', 3);

        $check = aiRateLimitCheck(self::$conn, 1, 1);
        self::assertTrue($check['allowed'], 'The per-user window is per user');
    }

    public function testTheOrganizationLimitCatchesSpreadOutAbuse(): void
    {
        $limits = aiRateLimits();
        // Spread across several users so no single per-user limit trips first.
        $perUser = (int) ceil($limits['org_per_minute'] / 4);
        foreach ([2, 3, 4, 5] as $adminId) {
            $this->fillUsage($perUser, 'success', $adminId);
        }

        $check = aiRateLimitCheck(self::$conn, 1, 1);
        self::assertFalse($check['allowed']);
        self::assertSame('org_per_minute', $check['scope']);
    }

    public function testInvalidRateLimitConfigurationFallsBackToTheDefault(): void
    {
        putenv('AI_RATE_USER_PER_MINUTE=unlimited');
        aiRateLimits(true);
        self::assertSame(DFMS_AI_RATE_DEFAULTS['user_per_minute'], aiRateLimits()['user_per_minute']);

        putenv('AI_RATE_USER_PER_MINUTE=999999');
        aiRateLimits(true);
        self::assertSame(DFMS_AI_RATE_DEFAULTS['user_per_minute'], aiRateLimits()['user_per_minute'],
            'A limit above the hard bound is refused, not honoured');

        putenv('AI_RATE_USER_PER_MINUTE');
        aiRateLimits(true);
    }

    /** Rate limiting is a SECOND gate, not a replacement for the plan gate. */
    public function testTheRateLimitDoesNotReplaceThePlanGate(): void
    {
        self::assertArrayHasKey('plan_limit_reached', DFMS_AI_FAILURE_REASONS);
        self::assertArrayHasKey('ai_rate_limited', DFMS_AI_FAILURE_REASONS);
        self::assertNotSame(
            dfms_ai_failure_message('plan_limit_reached'),
            dfms_ai_failure_message('ai_rate_limited'),
            'The two limits mean different things and must read differently'
        );
    }

    // =======================================================================
    // 6. AI TOOLS — registry, authorization and tenant isolation
    // =======================================================================

    public function testTheRegistryHoldsExactlyTheToolsWeWrote(): void
    {
        $names = array_keys(aiToolRegistry());
        sort($names);

        self::assertSame([
            'get_animal',
            'get_animal_health_history',
            'get_animal_health_summary',
            'get_farm_summary',
            'get_recent_alerts',
        ], $names, 'The tool set is an explicit list, never discovered by scanning');
    }

    public function testEveryToolDeclaresAKnownPermissionAndAnObjectSchema(): void
    {
        foreach (aiToolRegistry() as $name => $tool) {
            self::assertArrayHasKey($tool->requiredPermission(), DFMS_PERMISSIONS,
                "$name declares a permission that does not exist");
            $schema = $tool->inputSchema();
            self::assertSame('object', $schema['type'], "$name must take an object");
            self::assertArrayHasKey('fields', $schema);
            self::assertArrayNotHasKey('allow_unknown', $schema,
                "$name must not accept unknown arguments");
            self::assertNotSame('', trim($tool->description()));
        }
    }

    public function testAKnownToolExecutes(): void
    {
        $animalId = (int) self::$conn->query(
            'SELECT cow_id FROM cows WHERE farm_id = 1 AND deleted_at IS NULL LIMIT 1'
        )->fetchColumn();
        self::assertGreaterThan(0, $animalId, 'Fixture assumption: farm 1 has an animal');

        $result = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => $animalId]);

        self::assertTrue($result['ok'], (string) $result['reason']);
        self::assertSame($animalId, $result['data']['animal_id']);
        self::assertArrayHasKey('tag', $result['data']);
    }

    public function testAToolTakingNoArgumentsExecutes(): void
    {
        $result = aiToolExecute(self::$conn, 'get_farm_summary', []);

        self::assertTrue($result['ok'], (string) $result['reason']);
        self::assertIsInt($result['data']['animals']);
        self::assertIsInt($result['data']['active_cases']);
    }

    public function testAnUnknownToolIsRejected(): void
    {
        foreach (['drop_tables', 'get_animal ', 'GET_ANIMAL', '', 'run_sql'] as $name) {
            $result = aiToolExecute(self::$conn, $name, []);
            self::assertFalse($result['ok'], "Rejected: " . var_export($name, true));
            self::assertSame('unknown_tool', $result['reason']);
            self::assertNull($result['data']);
        }
    }

    public function testInvalidToolInputIsRejected(): void
    {
        foreach ([
            [],                                   // required animal_id missing
            ['animal_id' => 'seven'],             // not an integer
            ['animal_id' => 0],                   // below the minimum
            ['animal_id' => -1],
            ['animal_id' => 1.5],
            ['animal_id' => null],
        ] as $input) {
            $result = aiToolExecute(self::$conn, 'get_animal', $input);
            self::assertFalse($result['ok'], 'Rejected: ' . json_encode($input));
            self::assertSame('invalid_input', $result['reason']);
        }
    }

    /** A model inventing an argument is refused, not quietly ignored. */
    public function testAnInventedToolArgumentIsRejected(): void
    {
        $animalId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 1 LIMIT 1')->fetchColumn();

        $result = aiToolExecute(self::$conn, 'get_animal', [
            'animal_id' => $animalId,
            'farm_id'   => 2,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_input', $result['reason']);
        self::assertStringContainsString("unexpected field 'farm_id'", implode(' ', $result['errors']));
    }

    public function testNoArgumentToolStillRejectsInventedArguments(): void
    {
        $result = aiToolExecute(self::$conn, 'get_farm_summary', ['farm_id' => 2]);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_input', $result['reason']);
    }

    /** THE tenant test: another farm's animal is indistinguishable from absent. */
    public function testCrossTenantAccessIsRejectedByEveryAnimalTool(): void
    {
        $foreignId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $foreignId, 'Fixture assumption: a farm-2 animal exists');

        foreach (['get_animal', 'get_animal_health_summary', 'get_animal_health_history'] as $name) {
            $result = aiToolExecute(self::$conn, $name, ['animal_id' => $foreignId]);

            self::assertFalse($result['ok'], "$name leaked a farm-2 animal");
            self::assertSame('not_found', $result['reason'],
                'A foreign record must be reported exactly as a missing one');
            self::assertNull($result['data']);
        }
    }

    public function testAMissingAnimalAndAForeignAnimalAreIndistinguishable(): void
    {
        $foreignId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1')->fetchColumn();
        $absent = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => 2147483647]);
        $foreign = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => $foreignId]);

        self::assertSame($absent['reason'], $foreign['reason']);
        self::assertSame($absent['message'], $foreign['message']);
    }

    public function testAlertToolReturnsOnlyThisFarmsAlerts(): void
    {
        $result = aiToolExecute(self::$conn, 'get_recent_alerts', ['limit' => 25]);
        self::assertTrue($result['ok'], (string) $result['reason']);

        $farmOneUnread = (int) self::$conn->query(
            'SELECT COUNT(*) FROM alerts WHERE farm_id = 1 AND is_read = 0'
        )->fetchColumn();
        self::assertLessThanOrEqual(min(25, $farmOneUnread), $result['data']['count'],
            'A tool must never return more rows than this farm actually has');
    }

    /**
     * The severity filter binds AFTER the tenant scope.
     *
     * repository.php documents that {scope} is positional parameter #1, so a
     * placeholder appearing before it in the SQL text silently receives the
     * farm id. This tool adds a `?` in the WHERE clause, and this test is what
     * proves it landed on the right side of that boundary.
     */
    public function testTheAlertSeverityFilterBindsCorrectly(): void
    {
        foreach (['critical', 'warning', 'info'] as $severity) {
            $result = aiToolExecute(self::$conn, 'get_recent_alerts', ['severity' => $severity, 'limit' => 25]);

            self::assertTrue($result['ok'], "severity=$severity: " . (string) $result['reason']);
            foreach ($result['data']['alerts'] as $alert) {
                self::assertSame($severity, $alert['severity'],
                    'The filter must not be receiving the farm id');
            }

            $expected = (int) self::$conn->query(
                "SELECT COUNT(*) FROM alerts WHERE farm_id = 1 AND is_read = 0 AND severity = '$severity'"
            )->fetchColumn();
            self::assertSame(min(25, $expected), $result['data']['count']);
        }

        $result = aiToolExecute(self::$conn, 'get_recent_alerts', ['severity' => 'catastrophic']);
        self::assertSame('invalid_input', $result['reason'], 'The severity set is closed');
    }

    /** Insufficient permission denies, and the denial is not a crash. */
    public function testInsufficientPermissionIsRejected(): void
    {
        // admin 4 is an accountant on farm 1: cows.view yes, health.view NO.
        // Asserted rather than assumed, because the whole test rests on it.
        $_SESSION['admin_id'] = 4;
        self::assertSame(1, currentFarmId(), 'Fixture assumption: admin 4 works on farm 1');
        self::assertSame('accountant', currentFarmRole());
        self::assertFalse(hasPermission('health.view'));
        self::assertTrue(hasPermission('cows.view'));

        foreach (['get_animal_health_summary', 'get_animal_health_history'] as $name) {
            $result = aiToolExecute(self::$conn, $name, ['animal_id' => 1]);
            self::assertFalse($result['ok'], "$name ran without health.view");
            self::assertSame('permission_denied', $result['reason']);
        }

        $result = aiToolExecute(self::$conn, 'get_farm_summary', []);
        self::assertSame('permission_denied', $result['reason']);
    }

    /** The permission is checked BEFORE the input, so a denial leaks nothing. */
    public function testPermissionIsCheckedBeforeTheRecordIsLookedUp(): void
    {
        $_SESSION['admin_id'] = 4;
        self::assertSame('accountant', currentFarmRole());

        // A well-formed request for a real farm-1 animal still denies, and
        // denies with the permission reason rather than revealing whether the
        // animal exists.
        $animalId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 1 LIMIT 1')->fetchColumn();
        $real = aiToolExecute(self::$conn, 'get_animal_health_summary', ['animal_id' => $animalId]);
        $fake = aiToolExecute(self::$conn, 'get_animal_health_summary', ['animal_id' => 2147483647]);

        self::assertSame('permission_denied', $real['reason']);
        self::assertSame($real['reason'], $fake['reason'],
            'A denied caller must not learn which animals exist');
    }

    public function testAvailableToolsAreFilteredByTheCallersPermissions(): void
    {
        $_SESSION['admin_id'] = 4;
        self::assertSame('accountant', currentFarmRole());

        $names = array_column(aiToolsAvailable(), 'name');
        self::assertContains('get_animal', $names);
        self::assertNotContains('get_animal_health_summary', $names);
        self::assertNotContains('get_farm_summary', $names);
    }

    /** An unknown permission DENIES. It must never fall open. */
    public function testAToolWithAnUnknownPermissionIsRefused(): void
    {
        $tool = new class extends AiTool {
            public function name(): string { return 'test_misconfigured'; }
            public function description(): string { return 'A tool whose permission was renamed away.'; }
            public function inputSchema(): array { return ['type' => 'object', 'fields' => []]; }
            public function requiredPermission(): string { return 'permission.that.does.not.exist'; }
            protected function run(PDO $conn, array $input): ?array { return ['reached' => true]; }
        };

        $result = $tool->execute(self::$conn, []);

        self::assertFalse($result['ok']);
        self::assertSame('unknown_permission', $result['reason']);
        self::assertNull($result['data'], 'The body must never have run');
    }

    /** No tenant context means no tool, whatever the permission matrix says. */
    public function testToolsRefuseWithoutTenantContext(): void
    {
        $_SESSION = [];
        $result = aiToolExecute(self::$conn, 'get_farm_summary', []);

        self::assertFalse($result['ok']);
        self::assertSame('not_authenticated', $result['reason']);
    }

    /** A tool body that throws is contained, and its message is not returned. */
    public function testAThrowingToolIsContained(): void
    {
        $tool = new class extends AiTool {
            public function name(): string { return 'test_exploding'; }
            public function description(): string { return 'Throws.'; }
            public function inputSchema(): array { return ['type' => 'object', 'fields' => []]; }
            public function requiredPermission(): string { return 'cows.view'; }
            protected function run(PDO $conn, array $input): ?array
            {
                throw new \RuntimeException('internal detail that must not escape');
            }
        };

        $result = $tool->execute(self::$conn, []);

        self::assertFalse($result['ok']);
        self::assertSame('tool_failed', $result['reason']);
        self::assertStringNotContainsString('internal detail', (string) $result['message']);
    }

    /** Nothing in the tool layer writes. Asserted, not assumed. */
    public function testToolsCannotMutateData(): void
    {
        $tables = ['cows', 'health_cases', 'health_treatments', 'alerts',
            'medication_administrations', 'vaccination_records', 'ai_conversations'];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = (int) self::$conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }

        $animalId = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 1 LIMIT 1')->fetchColumn();
        foreach (aiToolRegistry() as $name => $tool) {
            $input = $tool->inputSchema()['fields'] === [] ? [] : ['animal_id' => $animalId];
            if ($name === 'get_recent_alerts') {
                $input = ['limit' => 5];
            }
            aiToolExecute(self::$conn, $name, $input);
        }

        foreach ($tables as $table) {
            $after = (int) self::$conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            self::assertSame($before[$table], $after, "A tool changed $table — tools are read-only");
        }
    }

    public function testToolDefinitionsDescribeTheirInputsForAModel(): void
    {
        foreach (aiToolDefinitions() as $definition) {
            self::assertArrayHasKey('name', $definition);
            self::assertArrayHasKey('input_schema', $definition);
            self::assertArrayHasKey('permission', $definition);
            self::assertNotSame('', aiSchemaDescribe($definition['input_schema']));
        }
    }

    // =======================================================================
    // 7. SECURITY — the credential, the audit log and the tenant boundary
    // =======================================================================

    /** No API key, header or secret may reach the usage or audit record. */
    public function testNoCredentialMaterialIsRecordedAnywhere(): void
    {
        dfms_ai_set_provider($this->countingProvider('An answer.'));
        aiChat('vet_assistant', aiVetSystemPrompt(), [['role' => 'user', 'content' => 'q']],
            ['prompt_key' => 'veterinarian_assistant']);

        $usage = self::$conn->query('SELECT * FROM ai_usage ORDER BY ai_usage_id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $serialised = strtolower((string) json_encode($usage));

        foreach (['sk-', 'x-api-key', 'api_key', 'authorization', 'bearer', 'anthropic_api_key'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialised,
                'A usage row must never carry credential material');
        }
    }

    /** The audit deny-list still covers everything this phase can write. */
    public function testTheAuditDenyListStillCoversCredentialKeys(): void
    {
        foreach (['api_key', 'anthropic_api_key', 'authorization', 'token', 'secret'] as $key) {
            self::assertContains($key, DFMS_AUDIT_DENY_KEYS);
        }
    }

    /** Every failure token this phase can produce maps to safe text. */
    public function testEveryFailureTokenHasSafeUserFacingText(): void
    {
        foreach (array_keys(DFMS_AI_FAILURE_REASONS) as $reason) {
            $message = dfms_ai_failure_message($reason);
            self::assertNotSame('', $message);
            self::assertStringNotContainsStringIgnoringCase('sk-', $message);
        }
        foreach (array_keys(DFMS_AI_TOOL_REASONS) as $reason) {
            self::assertNotSame('', aiToolFailureMessage($reason));
        }
        foreach (array_keys(DFMS_AI_SCHEMA_REASONS) as $reason) {
            self::assertNotSame('', aiSchemaFailureMessage($reason));
        }
        // An unrecognised token must never be rendered verbatim.
        self::assertStringNotContainsString('<script>', dfms_ai_failure_message('<script>'));
        self::assertStringNotContainsString('<script>', aiToolFailureMessage('<script>'));
    }

    /** The unbuilt AI phases stay unbuilt and off. */
    public function testFuturePhasesAreNotQuietlyEnabled(): void
    {
        foreach (['future_disease_detection', 'future_ai_copilot', 'future_iot'] as $flag) {
            self::assertFalse(DFMS_FEATURE_REGISTRY[$flag]['default'],
                "$flag must stay default-off — Phase 5E builds infrastructure only");
        }
        self::assertFalse(aiPromptExists('disease_detection'));
        self::assertFalse(aiPromptExists('image_analysis'));
        self::assertNull(aiToolFind('analyse_image'));
        self::assertNull(aiToolFind('predict_disease'));
    }

    /** No tool writes, so no tool needs a write permission. */
    public function testNoToolRequiresAWritePermission(): void
    {
        foreach (aiToolRegistry() as $name => $tool) {
            self::assertStringEndsWith('.view', $tool->requiredPermission(),
                "$name requires a non-read permission — tools are read-only by design");
        }
    }
}
