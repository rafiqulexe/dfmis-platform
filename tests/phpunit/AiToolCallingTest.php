<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use DfmsAiProvider;
use DfmsAnthropicProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * CONTROLLED AI TOOL CALLING (Phase 5F).
 *
 * Provider normalisation, the schema adapter, the bounded agent loop, tool
 * result sanitization, and the security and veterinary-safety properties that
 * must survive a model being handed a lever.
 *
 * NO NETWORK CALL IS EVER MADE and NO API KEY IS EVER NEEDED. Provider
 * behaviour is supplied by scripted stubs installed through
 * dfms_ai_set_provider(); the vendor wire mapping is exercised directly
 * through DfmsAnthropicProvider's two public static mapping methods.
 *
 * FIXTURE ASSUMPTIONS, asserted where they carry weight:
 *   admin 1 is farm_manager on farm 1 (organization 1, enterprise plan)
 *   admin 4 is accountant  on farm 1 — no health.view
 *   farm 2 is another organization and has at least one animal
 *
 * The tests that matter most are the refusals: a model naming a tool it was
 * never offered, arguments that match no schema, another farm's animal, a
 * permission the user does not hold, and a loop that must end whether or not
 * the model cooperates.
 */
final class AiToolCallingTest extends TestCase
{
    private static PDO $conn;

    /** @var list<int> conversation ids created by a test */
    private array $conversations = [];

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
        foreach ($this->conversations as $id) {
            self::$conn->prepare('DELETE FROM ai_conversations WHERE conversation_id = ?')->execute([$id]);
        }
        $this->conversations = [];
        self::$conn->exec('DELETE FROM ai_usage');
        dfms_ai_set_provider(null);
        $_SESSION = [];
    }

    // ---- helpers ----------------------------------------------------------

    /** A farm-1 animal id, for tools that take one. */
    private function localAnimalId(): int
    {
        $id = (int) self::$conn->query(
            'SELECT cow_id FROM cows WHERE farm_id = 1 AND deleted_at IS NULL LIMIT 1'
        )->fetchColumn();
        self::assertGreaterThan(0, $id, 'Fixture assumption: farm 1 has an animal');
        return $id;
    }

    private function foreignAnimalId(): int
    {
        $id = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $id, 'Fixture assumption: farm 2 has an animal');
        return $id;
    }

    /**
     * A provider driven by a script: one entry per expected call.
     *
     * Each entry is either ['text' => string] for a final answer, or
     * ['tools' => [[name, input], …]] for a turn that requests tools. It
     * records what it was sent, which is how the tests below inspect the
     * transcript the model would really have seen.
     */
    private function scriptedProvider(array $script): object
    {
        return new class ($script) implements DfmsAiProvider {
            public int $calls = 0;
            /** @var list<array> the $messages of every call */
            public array $sentMessages = [];
            /** @var list<array> the $options of every call */
            public array $sentOptions = [];
            public array $sentSystemPrompts = [];

            public function __construct(private array $script) {}
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }

            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->sentMessages[] = $messages;
                $this->sentOptions[] = $options;
                $this->sentSystemPrompts[] = $systemPrompt;
                $step = $this->script[$this->calls] ?? ['text' => 'Fallback final answer.'];
                $this->calls++;

                $requests = [];
                $blocks = [];
                foreach ($step['tools'] ?? [] as $index => $tool) {
                    $id = 'toolu_' . $this->calls . '_' . $index;
                    $requests[] = ['id' => $id, 'name' => $tool[0], 'input' => $tool[1]];
                    $blocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $tool[0], 'input' => $tool[1]];
                }
                $text = (string) ($step['text'] ?? ($requests === [] ? 'A final answer.' : ''));
                if ($text !== '') {
                    array_unshift($blocks, ['type' => 'text', 'text' => $text]);
                }

                return [
                    'ok' => true, 'text' => $text === '' ? null : $text, 'reason' => null,
                    'model' => 'stub-model', 'input_tokens' => 4, 'output_tokens' => 6,
                    'stop_reason'   => $requests === [] ? 'end_turn' : 'tool_use',
                    'tool_requests' => $requests,
                    'blocks'        => $blocks,
                ];
            }
        };
    }

    /** A provider that always fails, the way an outage does. */
    private function failingProvider(string $reason): object
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
                return ['ok' => false, 'text' => null, 'reason' => $this->reason,
                    'model' => 'stub-model', 'input_tokens' => 0, 'output_tokens' => 0];
            }
        };
    }

    /** Run the agent loop with a plain user turn. */
    private function runAgent(string $question = 'What is going on with this animal?'): array
    {
        return aiAgentRun(self::$conn, 'vet_assistant', aiVetSystemPrompt(), [
            ['role' => 'user', 'content' => $question],
        ]);
    }

    // =======================================================================
    // 1. PROVIDER NORMALISATION — the vendor mapping seam
    // =======================================================================

    public function testANormalFinalResponseNormalisesToTextWithNoToolRequests(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [['type' => 'text', 'text' => 'The records show nothing unusual.']],
            'stop_reason' => 'end_turn',
        ]);

        self::assertSame('The records show nothing unusual.', $parsed['text']);
        self::assertSame([], $parsed['tool_requests']);
        self::assertSame('end_turn', $parsed['stop_reason']);
    }

    public function testASingleToolRequestIsNormalised(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_animal', 'input' => ['animal_id' => 7]],
            ],
            'stop_reason' => 'tool_use',
        ]);

        self::assertCount(1, $parsed['tool_requests']);
        self::assertSame('get_animal', $parsed['tool_requests'][0]['name']);
        self::assertSame('toolu_1', $parsed['tool_requests'][0]['id']);
        self::assertSame(['animal_id' => 7], $parsed['tool_requests'][0]['input']);
        self::assertSame('tool_use', $parsed['stop_reason']);
        // The assistant turn is preserved so it can be replayed verbatim.
        self::assertCount(2, $parsed['blocks']);
    }

    public function testMultipleToolRequestsAreNormalised(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [
                ['type' => 'tool_use', 'id' => 'a', 'name' => 'get_animal', 'input' => ['animal_id' => 1]],
                ['type' => 'tool_use', 'id' => 'b', 'name' => 'get_farm_summary', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
        ]);

        self::assertCount(2, $parsed['tool_requests']);
        self::assertNull($parsed['text'], 'A tool-only turn carries no prose, and that is valid');
    }

    /**
     * THE PHASE 5D BUG THIS PHASE FIXES.
     *
     * The old parser kept text blocks and silently discarded everything else,
     * so a tool_use turn produced no text and was reported as 'empty_response'
     * — a real answer surfaced to the user as an outage.
     */
    public function testAToolOnlyTurnIsNotMistakenForAnEmptyResponse(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [['type' => 'tool_use', 'id' => 'a', 'name' => 'get_animal', 'input' => []]],
            'stop_reason' => 'tool_use',
        ]);

        self::assertNull($parsed['text']);
        self::assertNotSame([], $parsed['tool_requests'],
            'The tool request must survive; discarding it is what made this look empty');
    }

    public function testAMalformedToolBlockIsDroppedRatherThanGuessedAt(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [
                ['type' => 'tool_use', 'id' => 'no-name', 'input' => []],
                ['type' => 'tool_use', 'name' => 'no-id', 'input' => []],
                ['type' => 'tool_use', 'id' => '', 'name' => '', 'input' => []],
                ['type' => 'text', 'text' => 'Still here.'],
            ],
            'stop_reason' => 'tool_use',
        ]);

        self::assertSame([], $parsed['tool_requests'],
            'A request with no name or no correlation id cannot be answered');
        self::assertSame('Still here.', $parsed['text']);
    }

    public function testAnUnknownBlockTypeIsIgnored(): void
    {
        $parsed = DfmsAnthropicProvider::readResponse([
            'content' => [
                ['type' => 'some_future_block', 'payload' => 'whatever'],
                ['type' => 'text', 'text' => 'Kept.'],
                'not even an array',
            ],
            'stop_reason' => 'end_turn',
        ]);

        self::assertSame('Kept.', $parsed['text']);
        self::assertCount(1, $parsed['blocks']);
    }

    public function testAMalformedEnvelopeDegradesRatherThanFataling(): void
    {
        foreach ([[], ['content' => null], ['content' => 'a string'], ['stop_reason' => 'end_turn']] as $envelope) {
            $parsed = DfmsAnthropicProvider::readResponse($envelope);
            self::assertNull($parsed['text']);
            self::assertSame([], $parsed['tool_requests']);
        }
    }

    /** An unknown finish reason must mean "finished", never "keep going". */
    public function testAnUnknownStopReasonNormalisesToOther(): void
    {
        foreach (['something_new', '', null, 42] as $raw) {
            $parsed = DfmsAnthropicProvider::readResponse(['content' => [], 'stop_reason' => $raw]);
            self::assertContains($parsed['stop_reason'], DFMS_AI_STOP_REASONS);
            self::assertNotSame('tool_use', $parsed['stop_reason']);
        }
        self::assertSame('end_turn',
            DfmsAnthropicProvider::readResponse(['content' => [], 'stop_reason' => 'stop_sequence'])['stop_reason']);
    }

    // ---- outbound mapping -------------------------------------------------

    public function testPlainStringTurnsAreUnchangedOnTheWire(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => 'First.'],
            ['role' => 'assistant', 'content' => 'Second.'],
            ['role' => 'user', 'content' => 'Third.'],
        ]);

        self::assertSame([
            ['role' => 'user', 'content' => 'First.'],
            ['role' => 'assistant', 'content' => 'Second.'],
            ['role' => 'user', 'content' => 'Third.'],
        ], $wire, 'Phase 5D message handling must be byte-for-byte unchanged');
    }

    public function testBlockTurnsAreTranslatedNotStringified(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => 'Check this animal.'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'x', 'name' => 'get_animal', 'input' => ['animal_id' => 3]],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => '{"tag":"A1"}', 'is_error' => false],
            ]],
        ]);

        self::assertIsArray($wire[1]['content']);
        self::assertSame('tool_use', $wire[1]['content'][0]['type']);
        self::assertSame('tool_result', $wire[2]['content'][0]['type']);
        self::assertSame('x', $wire[2]['content'][0]['tool_use_id']);
        // is_error false is omitted rather than sent as false.
        self::assertArrayNotHasKey('is_error', $wire[2]['content'][0]);
    }

    public function testAnErrorToolResultIsMarkedOnTheWire(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => '{"error":"no"}', 'is_error' => true],
            ]],
        ]);

        self::assertTrue($wire[0]['content'][0]['is_error']);
    }

    /** Empty tool arguments must serialise as {} — a list here is rejected. */
    public function testEmptyToolArgumentsSerialiseAsAnObject(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => 'q'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'x', 'name' => 'get_farm_summary', 'input' => []],
            ]],
        ]);

        $encoded = json_encode($wire);
        self::assertStringContainsString('"input":{}', (string) $encoded);
        self::assertStringNotContainsString('"input":[]', (string) $encoded);
    }

    /** A string turn adjacent to a block turn must not be concatenated into it. */
    public function testAStringTurnMergingWithABlockTurnIsPromotedNotConcatenated(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => '{}'],
            ]],
            ['role' => 'user', 'content' => 'And also this.'],
        ]);

        self::assertCount(1, $wire);
        self::assertIsArray($wire[0]['content']);
        self::assertCount(2, $wire[0]['content']);
        self::assertSame('tool_result', $wire[0]['content'][0]['type']);
        self::assertSame('text', $wire[0]['content'][1]['type']);
    }

    public function testAnUnrecognisedBlockIsNotForwardedToTheVendor(): void
    {
        $wire = DfmsAnthropicProvider::toWireMessages([
            ['role' => 'user', 'content' => [
                ['type' => 'something_we_never_defined', 'data' => 'x'],
                ['type' => 'text', 'text' => 'Real content.'],
            ]],
        ]);

        self::assertCount(1, $wire[0]['content']);
        self::assertSame('text', $wire[0]['content'][0]['type']);
    }

    // =======================================================================
    // 2. TOOL SCHEMA ADAPTER — one source of truth
    // =======================================================================

    public function testTheProviderSchemaIsDerivedFromTheRegistry(): void
    {
        $registryNames = array_keys(aiToolRegistry());
        $providerNames = array_column(aiToolProviderDefinitions(), 'name');
        sort($registryNames);
        sort($providerNames);

        self::assertSame($registryNames, $providerNames,
            'A hand-kept second list would drift from the registry');

        foreach (aiToolProviderDefinitions() as $definition) {
            $tool = aiToolFind($definition['name']);
            self::assertNotNull($tool);
            self::assertSame($tool->description(), $definition['description'],
                'The description must come from the tool, not a copy');
            self::assertSame(aiToolJsonSchema($tool->inputSchema()), $definition['input_schema']);
        }
    }

    public function testSchemaConversionCoversEveryInternalType(): void
    {
        $json = aiToolJsonSchema(['type' => 'object', 'fields' => [
            'a' => ['type' => 'string', 'required' => true, 'max' => 50],
            'b' => ['type' => 'integer', 'min' => 1, 'max' => 9],
            'c' => ['type' => 'decimal', 'min' => 0.5],
            'd' => ['type' => 'boolean'],
            'e' => ['type' => 'enum', 'values' => ['x', 'y']],
            'f' => ['type' => 'array', 'max_items' => 3, 'items' => ['type' => 'string']],
        ]]);

        self::assertSame('object', $json['type']);
        self::assertSame(['a'], $json['required'], 'Only required fields appear in `required`');
        self::assertFalse($json['additionalProperties'], 'Unknown keys are refused, and the hint says so');
        self::assertSame(50, $json['properties']['a']['maxLength']);
        self::assertSame('integer', $json['properties']['b']['type']);
        self::assertSame(1, $json['properties']['b']['minimum']);
        self::assertSame('number', $json['properties']['c']['type']);
        self::assertSame('boolean', $json['properties']['d']['type']);
        self::assertSame(['x', 'y'], $json['properties']['e']['enum']);
        self::assertSame('array', $json['properties']['f']['type']);
        self::assertSame(3, $json['properties']['f']['maxItems']);
    }

    public function testAToolWithNoArgumentsStillForbidsExtraProperties(): void
    {
        $json = aiToolJsonSchema(aiToolFind('get_farm_summary')->inputSchema());

        self::assertSame([], $json['properties']);
        self::assertFalse($json['additionalProperties']);
        self::assertArrayNotHasKey('required', $json);
    }

    /** The provider schema is a hint. Enforcement is still server-side. */
    public function testTheProviderSchemaDoesNotReplaceServerSideValidation(): void
    {
        // Arguments that the JSON Schema hint would also reject, sent anyway.
        $result = aiToolExecute(self::$conn, 'get_animal', ['animal_id' => 'not a number']);

        self::assertFalse($result['ok']);
        self::assertSame('invalid_input', $result['reason']);
    }

    public function testProviderDefinitionsAreFilteredByPermission(): void
    {
        $full = count(aiToolProviderDefinitions());

        $_SESSION['admin_id'] = 4;   // accountant: no health.view
        self::assertSame('accountant', currentFarmRole());
        $limited = array_column(aiToolProviderDefinitions(), 'name');

        self::assertLessThan($full, count($limited));
        self::assertNotContains('get_animal_health_summary', $limited,
            'A model must not be told about a tool the user cannot run');
    }

    // =======================================================================
    // 3. THE CONTROLLED LOOP
    // =======================================================================

    public function testModelThenToolThenFinalAnswer(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]]]],
            ['text'  => 'The record shows a healthy animal.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertTrue($result['ok'], (string) $result['reason']);
        self::assertSame('The record shows a healthy animal.', $result['text']);
        self::assertSame(2, $result['model_calls']);
        self::assertSame(1, $result['tool_calls']);
        self::assertSame('get_animal', $result['tool_log'][0]['name']);
        self::assertTrue($result['tool_log'][0]['ok']);
    }

    /** The tool result really reaches the model, as a user turn. */
    public function testTheToolResultIsSentBackToTheModelAsAUserTurn(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]]]],
            ['text'  => 'Done.'],
        ]);
        dfms_ai_set_provider($provider);
        $this->runAgent();

        $second = $provider->sentMessages[1];
        $assistantTurn = $second[count($second) - 2];
        $resultTurn = $second[count($second) - 1];

        self::assertSame('assistant', $assistantTurn['role']);
        self::assertSame('tool_use', $assistantTurn['content'][0]['type']);

        self::assertSame('user', $resultTurn['role'],
            'A tool result is the application speaking, not the model');
        self::assertSame('tool_result', $resultTurn['content'][0]['type']);
        self::assertFalse($resultTurn['content'][0]['is_error']);
        self::assertStringContainsString('"tag"', $resultTurn['content'][0]['content']);
    }

    public function testMultipleSequentialToolRoundsAreSupported(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]]]],
            ['tools' => [['get_farm_summary', []]]],
            ['text'  => 'Both checked.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['tool_calls']);
        self::assertSame(['get_animal', 'get_farm_summary'], array_column($result['tool_log'], 'name'));
    }

    public function testSeveralToolsInOneTurnAreAllExecutedAndAllAnswered(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [
                ['get_animal', ['animal_id' => $animalId]],
                ['get_farm_summary', []],
                ['get_recent_alerts', ['limit' => 5]],
            ]],
            ['text' => 'All three checked.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertSame(3, $result['tool_calls']);
        $resultTurn = end($provider->sentMessages[1]);
        self::assertCount(3, $resultTurn['content'],
            'Every request must get a result; a missing one leaves the exchange malformed');
    }

    /**
     * TERMINATION. A model that only ever asks for tools must still stop,
     * because the loop stops OFFERING them on the final permitted call.
     */
    public function testAModelThatOnlyEverAsksForToolsStillTerminates(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider(array_fill(0, 20, [
            'text'  => 'Still looking…',
            'tools' => [['get_animal', ['animal_id' => $animalId]]],
        ]));
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        $maxCalls = aiRequestConfig()['max_model_calls'];
        self::assertSame($maxCalls, $result['model_calls'], 'The loop is bounded by max_model_calls');
        self::assertSame($maxCalls, $provider->calls);
        self::assertTrue($result['ok'], 'The last call produced prose, so there is still an answer');
        self::assertSame('Still looking…', $result['text']);
    }

    /** On the final permitted call the model is offered no tools at all. */
    public function testToolsAreNotOfferedOnTheFinalCall(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider(array_fill(0, 20, [
            'text' => 'x', 'tools' => [['get_animal', ['animal_id' => $animalId]]],
        ]));
        dfms_ai_set_provider($provider);
        $this->runAgent();

        $last = count($provider->sentOptions) - 1;
        self::assertArrayHasKey('tools', $provider->sentOptions[0], 'Tools are offered early');
        self::assertArrayNotHasKey('tools', $provider->sentOptions[$last],
            'Withdrawing the tools is what makes termination structural, not negotiated');
    }

    public function testTheToolBudgetIsEnforcedIndependentlyOfTheCallBudget(): void
    {
        $animalId = $this->localAnimalId();
        $many = array_fill(0, 8, ['get_animal', ['animal_id' => $animalId]]);
        $provider = $this->scriptedProvider([
            ['tools' => $many],
            ['text' => 'Answered anyway.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        $budget = aiRequestConfig()['max_tool_calls'];
        $executed = count(array_filter($result['tool_log'], static fn (array $e): bool => $e['ok']));
        self::assertLessThanOrEqual($budget, $executed,
            'No more tools may RUN than the budget allows');
        // The over-budget requests are still ANSWERED, so the exchange stays
        // well-formed and the model is told why.
        self::assertSame(8, count($result['tool_log']));
        self::assertContains('tool_budget_exhausted', array_column($result['tool_log'], 'reason'));
    }

    public function testAnUnknownToolIsRefusedAndTheModelIsTold(): void
    {
        $provider = $this->scriptedProvider([
            ['tools' => [['drop_all_tables', ['confirm' => true]]]],
            ['text'  => 'I could not look that up.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertTrue($result['ok']);
        self::assertFalse($result['tool_log'][0]['ok']);
        self::assertSame('unknown_tool', $result['tool_log'][0]['reason']);

        $resultTurn = end($provider->sentMessages[1]);
        self::assertTrue($resultTurn['content'][0]['is_error'],
            'A refusal must be reported as an error, not as an empty answer');
    }

    public function testInvalidToolArgumentsAreRefused(): void
    {
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => 'the brown one']]]],
            ['text'  => 'I could not identify that animal.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertSame('invalid_input', $result['tool_log'][0]['reason']);
        $resultTurn = end($provider->sentMessages[1]);
        self::assertTrue($resultTurn['content'][0]['is_error']);
    }

    /** A model inventing an extra argument is refused, not silently obeyed. */
    public function testAnInventedToolArgumentIsRefusedInTheLoop(): void
    {
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $this->localAnimalId(), 'farm_id' => 2]]]],
            ['text'  => 'Refused.'],
        ]);
        dfms_ai_set_provider($provider);

        self::assertSame('invalid_input', $this->runAgent()['tool_log'][0]['reason']);
    }

    public function testAMalformedToolRequestIsDroppedByTheLoop(): void
    {
        self::assertSame([], aiAgentNormaliseRequests([
            ['name' => 'get_animal'],                  // no id
            ['id' => 'x'],                             // no name
            'not an array',
            ['id' => '', 'name' => ''],
        ]));
        self::assertSame([], aiAgentNormaliseRequests('nonsense'));

        $normalised = aiAgentNormaliseRequests([['id' => 'a', 'name' => 'get_animal', 'input' => 'scalar']]);
        self::assertSame([], $normalised[0]['input'],
            'A non-array argument set becomes empty so the schema can reject it cleanly');
    }

    /**
     * Two requests sharing a correlation id cannot both be answered.
     *
     * Each would produce a tool_result carrying the same id; a provider that
     * correlates results to requests rejects that with a 400, which this
     * codebase maps to 'invalid_request' and does not retry — losing the whole
     * consultation after paying for a model call and running both tools.
     */
    public function testDuplicateToolRequestIdsAreDropped(): void
    {
        $normalised = aiAgentNormaliseRequests([
            ['id' => 'dup', 'name' => 'get_animal', 'input' => ['animal_id' => 1]],
            ['id' => 'dup', 'name' => 'get_farm_summary', 'input' => []],
            ['id' => 'other', 'name' => 'get_farm_summary', 'input' => []],
        ]);

        self::assertCount(2, $normalised);
        self::assertSame(['dup', 'other'], array_column($normalised, 'id'));
        self::assertSame('get_animal', $normalised[0]['name'], 'The FIRST request for an id wins');
    }

    /** And the loop answers every request it kept, exactly once. */
    public function testEveryKeptRequestGetsExactlyOneResult(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]], ['get_farm_summary', []]]],
            ['text'  => 'Done.'],
        ]);
        dfms_ai_set_provider($provider);
        $this->runAgent();

        $resultTurn = end($provider->sentMessages[1]);
        $ids = array_column($resultTurn['content'], 'tool_use_id');
        self::assertSame($ids, array_unique($ids), 'A correlation id must appear at most once');
        self::assertCount(2, $ids);
    }

    public function testAProviderFailureMidLoopDegradesSafely(): void
    {
        $animalId = $this->localAnimalId();
        // First call asks for a tool; the provider is then swapped for one
        // that fails, standing in for an outage between rounds.
        $provider = new class ($animalId) implements DfmsAiProvider {
            public int $calls = 0;
            public function __construct(private int $animalId) {}
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return ['ok' => true, 'text' => null, 'reason' => null, 'model' => 'stub-model',
                        'input_tokens' => 1, 'output_tokens' => 1, 'stop_reason' => 'tool_use',
                        'tool_requests' => [['id' => 'a', 'name' => 'get_animal', 'input' => ['animal_id' => $this->animalId]]],
                        'blocks' => [['type' => 'tool_use', 'id' => 'a', 'name' => 'get_animal', 'input' => ['animal_id' => $this->animalId]]]];
                }
                return ['ok' => false, 'text' => null, 'reason' => 'upstream_error',
                    'model' => 'stub-model', 'input_tokens' => 0, 'output_tokens' => 0];
            }
        };
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertFalse($result['ok']);
        self::assertSame('upstream_error', $result['reason']);
        self::assertNull($result['text'], 'A failure must never produce answer text');
        self::assertSame(1, $result['tool_calls'], 'The read that already happened is still reported');
    }

    public function testAProviderThatIsUnavailableFromTheStartDegrades(): void
    {
        dfms_ai_set_provider($this->failingProvider('transport_error'));

        $result = $this->runAgent();

        self::assertFalse($result['ok']);
        self::assertSame('transport_error', $result['reason']);
        self::assertSame(0, $result['tool_calls']);
    }

    /** A final call that produces only tool requests has nothing to say. */
    public function testAFinalCallWithNoProseIsReportedAsExhausted(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider(array_fill(0, 20, [
            'text' => '', 'tools' => [['get_animal', ['animal_id' => $animalId]]],
        ]));
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertFalse($result['ok']);
        self::assertSame('tool_loop_exhausted', $result['reason']);
        self::assertNotSame('', aiAgentFailureMessage('tool_loop_exhausted'));
    }

    /** A provider with no tool support gets exactly Phase 5D behaviour. */
    public function testAProviderWithoutToolSupportStillWorks(): void
    {
        $provider = new class implements DfmsAiProvider {
            public function name(): string { return 'test-stub'; }
            public function isConfigured(): bool { return true; }
            public function defaultModel(): string { return 'stub-model'; }
            public function complete(string $systemPrompt, array $messages, array $options = []): array
            {
                // No stop_reason, no tool_requests, no blocks — a valid
                // Phase 5D provider.
                return ['ok' => true, 'text' => 'A plain answer.', 'reason' => null,
                    'model' => 'stub-model', 'input_tokens' => 2, 'output_tokens' => 3];
            }
        };
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertTrue($result['ok']);
        self::assertSame('A plain answer.', $result['text']);
        self::assertSame(1, $result['model_calls']);
        self::assertSame(0, $result['tool_calls']);
    }

    // =======================================================================
    // 4. TOOL RESULT SANITIZATION
    // =======================================================================

    public function testCredentialShapedKeysAreRemovedNotRedacted(): void
    {
        $clean = aiSanitizeForModel([
            'tag' => 'A1',
            'api_key' => 'sk-live-should-never-appear',
            'password' => 'hunter2',
            'authorization' => 'Bearer abc',
            'nested' => ['secret' => 'x', 'species' => 'cattle'],
        ]);

        self::assertSame('A1', $clean['tag']);
        self::assertSame('cattle', $clean['nested']['species']);
        foreach (['api_key', 'password', 'authorization'] as $key) {
            self::assertArrayNotHasKey($key, $clean);
        }
        self::assertArrayNotHasKey('secret', $clean['nested']);

        $encoded = (string) json_encode($clean);
        self::assertStringNotContainsString('sk-live', $encoded);
        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString('REDACTED', $encoded,
            'A redaction marker only tells a model there is a secret nearby');
    }

    public function testInvalidUtf8IsMadeSafeRatherThanLosingTheResult(): void
    {
        $clean = aiSanitizeForModel(['name' => "Bella\xB1\xC3", 'species' => 'cattle']);
        $encoded = json_encode($clean);

        self::assertNotFalse($encoded, 'Invalid bytes must not break serialization entirely');
        self::assertSame('cattle', $clean['species']);
    }

    public function testControlCharactersAreStripped(): void
    {
        $clean = aiSanitizeForModel(['note' => "before\x00\x07after"]);
        self::assertSame('beforeafter', $clean['note']);
    }

    public function testStringsListsAndDepthAreBounded(): void
    {
        $long = aiSanitizeForModel(str_repeat('a', DFMS_AI_TOOL_RESULT_LIMITS['max_string'] + 500));
        self::assertStringEndsWith('[truncated]', $long);

        $list = aiSanitizeForModel(array_fill(0, DFMS_AI_TOOL_RESULT_LIMITS['max_items'] + 20, 'x'));
        self::assertCount(DFMS_AI_TOOL_RESULT_LIMITS['max_items'] + 1, $list);
        self::assertSame('[truncated: more items exist]', end($list),
            'Truncation must be STATED — a model told it has the whole list will reason as if it did');

        $deep = ['a' => null];
        $cursor = &$deep['a'];
        for ($i = 0; $i < DFMS_AI_TOOL_RESULT_LIMITS['max_depth'] + 5; $i++) {
            $cursor = ['a' => null];
            $cursor = &$cursor['a'];
        }
        unset($cursor);
        self::assertNotFalse(json_encode(aiSanitizeForModel($deep)));
    }

    public function testUnrepresentableValuesAreReplacedNotLeaked(): void
    {
        self::assertNull(aiSanitizeForModel(NAN));
        self::assertNull(aiSanitizeForModel(INF));
        self::assertSame('[omitted: unsupported value]', aiSanitizeForModel(new \stdClass()));
        self::assertSame('[omitted: unsupported value]', aiSanitizeForModel(static fn () => 1));
        self::assertNull(aiSanitizeForModel(null));
        self::assertTrue(aiSanitizeForModel(true));
        self::assertSame(7, aiSanitizeForModel(7));
    }

    public function testAnOversizedResultIsReplacedNotTruncatedMidJson(): void
    {
        $huge = ['notes' => array_fill(0, 40, str_repeat('x', 1500))];
        $text = aiToolResultToText($huge);

        self::assertLessThanOrEqual(DFMS_AI_TOOL_RESULT_LIMITS['max_bytes'] , strlen($text));
        $decoded = json_decode($text, true);
        self::assertIsArray($decoded, 'The result must remain parseable JSON, never a cut-off document');
        self::assertArrayHasKey('error', $decoded);
    }

    public function testEveryToolResultIsValidJsonForTheModel(): void
    {
        $animalId = $this->localAnimalId();
        foreach (aiToolRegistry() as $name => $tool) {
            $input = $tool->inputSchema()['fields'] === [] ? [] : ['animal_id' => $animalId];
            if ($name === 'get_recent_alerts') {
                $input = ['limit' => 5];
            }
            $executed = aiAgentRunTool(self::$conn, ['id' => 'x', 'name' => $name, 'input' => $input]);

            self::assertIsArray(json_decode($executed['block']['content'], true),
                "$name produced content a model could not parse");
            self::assertFalse($executed['block']['is_error'], "$name: " . $executed['block']['content']);
        }
    }

    // =======================================================================
    // 5. SECURITY
    // =======================================================================

    public function testCrossTenantAccessIsRefusedThroughTheLoop(): void
    {
        $foreignId = $this->foreignAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal_health_summary', ['animal_id' => $foreignId]]]],
            ['text'  => 'That animal is not on this farm.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertSame('not_found', $result['tool_log'][0]['reason'],
            'A foreign record must be indistinguishable from a missing one');

        // And nothing about farm 2 reached the model.
        $sent = (string) json_encode($provider->sentMessages);
        $foreignTag = (string) self::$conn->query(
            'SELECT unique_id FROM cows WHERE farm_id = 2 LIMIT 1'
        )->fetchColumn();
        self::assertNotSame('', $foreignTag);
        self::assertStringNotContainsString($foreignTag, $sent,
            'A farm-2 tag must never appear in the transcript sent to the model');
    }

    public function testPermissionIsDeniedBeforeTheResourceIsLookedUp(): void
    {
        $_SESSION['admin_id'] = 4;   // accountant: no health.view
        self::assertSame('accountant', currentFarmRole());

        $real = aiAgentRunTool(self::$conn, [
            'id' => 'a', 'name' => 'get_animal_health_summary', 'input' => ['animal_id' => $this->localAnimalId()],
        ]);
        $fake = aiAgentRunTool(self::$conn, [
            'id' => 'b', 'name' => 'get_animal_health_summary', 'input' => ['animal_id' => 2147483647],
        ]);

        self::assertSame('permission_denied', $real['log']['reason']);
        self::assertSame($real['log']['reason'], $fake['log']['reason'],
            'A denied caller must not learn which animals exist');
        self::assertSame($real['block']['content'], $fake['block']['content']);
    }

    /** The model asking nicely is not authorisation. */
    public function testAModelCannotEscalateByRequestingAToolTheUserLacks(): void
    {
        $_SESSION['admin_id'] = 4;
        self::assertSame('accountant', currentFarmRole());

        $provider = $this->scriptedProvider([
            ['tools' => [['get_farm_summary', []]]],
            ['text'  => 'I could not check that.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertFalse($result['tool_log'][0]['ok']);
        self::assertSame('permission_denied', $result['tool_log'][0]['reason']);
    }

    public function testNoToolInTheLoopCanMutateData(): void
    {
        $tables = ['cows', 'health_cases', 'health_treatments', 'alerts',
            'medication_administrations', 'vaccination_records', 'inventory_items'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = (int) self::$conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        }

        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [
                ['get_animal', ['animal_id' => $animalId]],
                ['get_animal_health_summary', ['animal_id' => $animalId]],
                ['get_animal_health_history', ['animal_id' => $animalId]],
                ['get_recent_alerts', ['limit' => 5]],
                ['get_farm_summary', []],
            ]],
            ['text' => 'Everything checked.'],
        ]);
        dfms_ai_set_provider($provider);
        $this->runAgent();

        foreach ($tables as $table) {
            self::assertSame($before[$table],
                (int) self::$conn->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
                "The tool loop changed $table — every AI tool is read-only");
        }
    }

    public function testTheRegistryContainsNoWriteCapableTool(): void
    {
        foreach (aiToolRegistry() as $name => $tool) {
            self::assertStringEndsWith('.view', $tool->requiredPermission(),
                "$name requires a non-read permission");
            foreach (['create', 'add', 'update', 'edit', 'delete', 'record', 'set', 'prescribe'] as $verb) {
                self::assertStringStartsNotWith($verb . '_', $name,
                    "$name reads as a write tool; Phase 5F is read-only");
            }
        }
    }

    public function testAToolExceptionIsContainedAndItsDetailNeverReachesTheModel(): void
    {
        // get_animal_health_history on a real animal with a nonsense limit:
        // the schema catches it, which is the containment we want. For an
        // exception inside a tool body, AiPlatformTest covers the AiTool
        // contract directly; here we prove the LOOP survives a refusal.
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal_health_history', ['animal_id' => 1, 'limit' => 9999]]]],
            ['text'  => 'I could not read that far back.'],
        ]);
        dfms_ai_set_provider($provider);

        $result = $this->runAgent();

        self::assertTrue($result['ok'], 'A refused tool must not take the request down');
        self::assertSame('invalid_input', $result['tool_log'][0]['reason']);
    }

    /** Nothing credential-shaped may appear anywhere in what the model sees. */
    public function testNoSecretMaterialIsEverSentToTheModel(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]], ['get_farm_summary', []]]],
            ['text'  => 'Done.'],
        ]);
        dfms_ai_set_provider($provider);
        $this->runAgent();

        $sent = strtolower((string) json_encode([
            $provider->sentMessages, $provider->sentOptions, $provider->sentSystemPrompts,
        ]));
        foreach (['sk-', 'x-api-key', 'api_key', 'anthropic_api_key', 'authorization',
                     'bearer ', 'db_pass', 'password', 'select ', 'farm_id'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sent,
                "'$forbidden' reached the model — it must never leave the server");
        }
    }

    // =======================================================================
    // 6. VETERINARY SAFETY, AFTER TOOLS
    // =======================================================================

    /** Urgency is detected server-side, before the model, tools or no tools. */
    public function testUrgencyDetectionStillFiresWhenToolsWereUsed(): void
    {
        $animalId = $this->localAnimalId();
        dfms_ai_set_provider($this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]]]],
            ['text'  => 'Here is some general background about recumbency.'],
        ]));

        $result = aiVetAsk(self::$conn, ['question' => 'My cow cannot stand and is bloated.']);
        $this->conversations[] = $result['conversation_id'];

        self::assertTrue($result['ok']);
        self::assertTrue($result['urgency']['urgent']);
        self::assertStringContainsString('URGENT — CONTACT A VETERINARIAN NOW', (string) $result['answer']);
        self::assertStringStartsWith('URGENT', (string) $result['answer'],
            'The banner leads the answer; it is not a footnote to it');
    }

    /**
     * A tool result cannot switch the safety layer off.
     *
     * The banner is decided by aiVetDetectUrgency() on the user's own words
     * and applied after the model returns, so nothing a tool reported — and
     * nothing the model concluded from it — participates in that decision.
     */
    public function testAToolResultCannotSuppressTheUrgencyBanner(): void
    {
        $animalId = $this->localAnimalId();
        dfms_ai_set_provider($this->scriptedProvider([
            ['tools' => [['get_animal_health_summary', ['animal_id' => $animalId]]]],
            ['text'  => 'The records show this animal is healthy, so there is no emergency '
                . 'and no urgent banner is needed.'],
        ]));

        $result = aiVetAsk(self::$conn, ['question' => 'The cow is having a seizure and is unresponsive.']);
        $this->conversations[] = $result['conversation_id'];

        self::assertTrue($result['urgency']['urgent']);
        self::assertStringContainsString('URGENT — CONTACT A VETERINARIAN NOW', (string) $result['answer']);
    }

    /** The safety contract itself is unchanged by the tool-use addition. */
    public function testTheSafetyContractSurvivesTheToolUseAddition(): void
    {
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
                'A Phase 5D safety guarantee was lost when tool use was added');
        }
        // And the tool rules are subordinate to them, not alongside them.
        self::assertStringContainsString('They do not override anything in the ABSOLUTE RULES', $prompt);
        self::assertStringContainsString('It never changes anything', $prompt);
    }

    /** A degraded consultation still produces no advice-shaped text. */
    public function testADegradedToolConsultationProducesNoAnswerText(): void
    {
        dfms_ai_set_provider($this->failingProvider('upstream_error'));

        $result = aiVetAsk(self::$conn, ['question' => 'A general question about hoof care.']);
        $this->conversations[] = $result['conversation_id'];

        self::assertFalse($result['ok']);
        self::assertNull($result['answer']);
        self::assertSame([], $result['sources']);
    }

    // =======================================================================
    // 7. CONVERSATION PERSISTENCE
    // =======================================================================

    /** The user sees a two-turn exchange. Tool protocol is never a message. */
    public function testToolTrafficIsNotStoredAsConversationMessages(): void
    {
        $animalId = $this->localAnimalId();
        dfms_ai_set_provider($this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]], ['get_farm_summary', []]]],
            ['tools' => [['get_recent_alerts', ['limit' => 3]]]],
            ['text'  => 'The herd looks settled.'],
        ]));

        $result = aiVetAsk(self::$conn, ['question' => 'How is the herd doing today?']);
        $this->conversations[] = $result['conversation_id'];

        $messages = aiVetMessages(self::$conn, $result['conversation_id']);
        self::assertCount(2, $messages, 'One question, one answer — regardless of how many tools ran');
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('assistant', $messages[1]['role']);

        foreach ($messages as $message) {
            foreach (['tool_use', 'tool_result', 'toolu_', 'get_animal', 'input_schema'] as $protocol) {
                self::assertStringNotContainsString($protocol, (string) $message['content'],
                    'Raw protocol must never appear in what a user reads');
            }
        }
    }

    public function testTheRoleColumnStillHoldsOnlyUserAndAssistant(): void
    {
        $column = self::$conn->query("SHOW COLUMNS FROM ai_messages LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame("enum('user','assistant')", $column['Type'],
            'Adding a third role would render protocol to users and corrupt the replay');
    }

    /** Provenance is persisted: names only, successes only. */
    public function testSuccessfulLookupsArePersistedAsSafeMetadata(): void
    {
        $animalId = $this->localAnimalId();
        dfms_ai_set_provider($this->scriptedProvider([
            ['tools' => [
                ['get_animal', ['animal_id' => $animalId]],
                ['not_a_real_tool', ['x' => 1]],
            ]],
            ['text' => 'Checked what I could.'],
        ]));

        $result = aiVetAsk(self::$conn, ['question' => 'Tell me about this animal.']);
        $this->conversations[] = $result['conversation_id'];

        $stored = (string) self::$conn->query(
            'SELECT tools_used FROM ai_messages WHERE conversation_id = ' . (int) $result['conversation_id']
            . " AND role = 'assistant' ORDER BY message_id DESC LIMIT 1"
        )->fetchColumn();

        self::assertSame(['get_animal'], json_decode($stored, true),
            'Names of SUCCESSFUL lookups only — a refusal is audit material, not user-facing');
        self::assertStringNotContainsString('not_a_real_tool', $stored);
        self::assertStringNotContainsString('animal_id', $stored, 'Arguments are never stored');
    }

    public function testStoredProvenanceIsReadBackDefensively(): void
    {
        self::assertSame([], aiVetMessageToolsUsed([]));
        self::assertSame([], aiVetMessageToolsUsed(['tools_used' => null]));
        self::assertSame([], aiVetMessageToolsUsed(['tools_used' => 'not json']));
        self::assertSame([], aiVetMessageToolsUsed(['tools_used' => '{"a":1}']));
        self::assertSame([], aiVetMessageToolsUsed(['tools_used' => '["a_tool_we_removed"]']),
            'A name no longer in the registry must not resurface as a label');
        self::assertSame(['get_animal'],
            aiVetMessageToolsUsed(['tools_used' => '["get_animal","get_animal","nope"]']));
    }

    /** What a user reads is a sentence, never a registry identifier. */
    public function testToolLabelsAreHumanAndNeverExposeRegistryNames(): void
    {
        foreach (array_keys(aiToolRegistry()) as $name) {
            $label = aiAgentToolLabel($name);
            self::assertStringNotContainsString('_', $label);
            self::assertStringNotContainsString($name, $label);
        }
        self::assertSame('Checked farm records', aiAgentToolLabel('a_tool_added_later'),
            'An unmapped name falls back to a phrase, never to the identifier');
    }

    // =======================================================================
    // 8. AUDIT TRAIL
    // =======================================================================

    public function testToolActivityIsRecordedInTheExistingAuditLog(): void
    {
        $animalId = $this->localAnimalId();
        dfms_ai_set_provider($this->scriptedProvider([
            ['tools' => [['get_animal', ['animal_id' => $animalId]], ['nope', []]]],
            ['text'  => 'Done.'],
        ]));

        $result = aiVetAsk(self::$conn, ['question' => 'A question about this animal.']);
        $this->conversations[] = $result['conversation_id'];

        $row = (string) self::$conn->query(
            "SELECT new_value FROM audit_log WHERE action = 'ai.consultation'
             ORDER BY audit_id DESC LIMIT 1"
        )->fetchColumn();
        $payload = json_decode($row, true);

        self::assertIsArray($payload);
        self::assertSame(2, $payload['model_calls']);
        self::assertSame(2, $payload['tool_calls']);
        self::assertSame(1, $payload['tools']['get_animal']['ok']);
        self::assertContains('unknown_tool', $payload['tools']['nope']['denials'],
            'A refused lookup is exactly what an audit reader needs to see');

        // Counts, names and categories — never arguments, results or secrets.
        $serialised = strtolower($row);
        foreach (['sk-', 'api_key', 'authorization', 'animal_id', 'select '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialised);
        }
    }

    public function testTheAuditSummaryCollapsesRepeatedCallsPerTool(): void
    {
        $summary = aiVetAuditToolSummary([
            ['name' => 'get_animal', 'ok' => true, 'reason' => null],
            ['name' => 'get_animal', 'ok' => false, 'reason' => 'not_found'],
            ['name' => 'get_animal', 'ok' => false, 'reason' => 'not_found'],
        ]);

        self::assertSame(3, $summary['get_animal']['calls']);
        self::assertSame(1, $summary['get_animal']['ok']);
        self::assertSame(['not_found'], $summary['get_animal']['denials'],
            'A repeated denial is one category, not three lines');
    }

    // =======================================================================
    // 9. LIMITS AND CONFIGURATION
    // =======================================================================

    public function testLoopLimitsAreBoundedByConfiguration(): void
    {
        $config = aiRequestConfig();
        foreach (['max_model_calls', 'max_tool_calls'] as $key) {
            [$min, $max] = DFMS_AI_CONFIG_BOUNDS[$key];
            self::assertGreaterThanOrEqual($min, $config[$key]);
            self::assertLessThanOrEqual($max, $config[$key]);
        }
        self::assertGreaterThanOrEqual(2, $config['max_model_calls'],
            'A single call cannot express tool -> result -> answer');
    }

    public function testTheLoopLimitsCannotBeRaisedPastTheirHardBounds(): void
    {
        foreach ([['max_model_calls' => 500], ['max_tool_calls' => 500],
                     ['max_model_calls' => 0], ['max_tool_calls' => -1]] as $override) {
            try {
                aiRequestConfig($override);
                self::fail('Out-of-bounds loop configuration must be refused: ' . json_encode($override));
            } catch (\AiConfigViolation $e) {
                self::assertStringContainsString('between', $e->getMessage());
            }
        }
    }

    /** A client cannot widen the loop through the option bag. */
    public function testTheLoopBudgetCannotBeRaisedByAClientParameter(): void
    {
        $animalId = $this->localAnimalId();
        $provider = $this->scriptedProvider(array_fill(0, 30, [
            'text' => 'x', 'tools' => [['get_animal', ['animal_id' => $animalId]]],
        ]));
        dfms_ai_set_provider($provider);

        $result = aiAgentRun(self::$conn, 'vet_assistant', aiVetSystemPrompt(),
            [['role' => 'user', 'content' => 'q']],
            // Values a hostile client might try to smuggle in. The bounded ones
            // are refused by aiRequestConfig(); the invented ones are ignored.
            ['unlimited_tools' => true, 'skip_limits' => true, 'max_iterations' => 999]
        );

        self::assertSame(aiRequestConfig()['max_model_calls'], $result['model_calls']);
    }
}
