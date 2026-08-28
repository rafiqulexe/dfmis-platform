<?php
// ============================================================================
// AI TOOLS — controlled, read-only server-side operations an AI request may use.
//
// ---------------------------------------------------------------------------
// THE THREAT MODEL, STATED PLAINLY
//
// A tool call is a request that ORIGINATED, in whole or in part, from a
// language model. That means the tool name, the arguments and the reasoning
// for calling it are all attacker-influenceable: by a farm's own knowledge-base
// document, by the text of a health note, by whatever a user typed. A model
// that has read a poisoned document and asks for get_animal(animal_id: 91002)
// on a farm it has no business touching is not a hypothetical — it is the
// normal failure mode of this design.
//
// Therefore: the model's request is a SUGGESTION, and every gate re-runs here.
//
//   AI request
//     -> tool name looked up in a REGISTRY (unknown name is refused)
//     -> authentication      (session, not a parameter)
//     -> tenant context      (active farm + organization membership)
//     -> permission          (unknown permission DENIES; missing role DENIES)
//     -> input validation    (the tool's own schema, includes/ai_schema.php)
//     -> a domain function   (tenant-scoped, exactly as a page calls it)
//     -> a structured result
//
// Nothing in this file constructs SQL from model output. Nothing in this file
// hands a model a repository, a connection or a table name. A tool is a
// FIXED, hand-written query with validated scalar arguments, and the set of
// tools that exist is the set written in aiToolRegistry() below.
//
// ---------------------------------------------------------------------------
// WHY EXECUTION RETURNS INSTEAD OF ABORTING
//
// The rest of DFMIS denies by calling requirePermission() or
// tenantFindOr404(), which EXIT. That is right for a page: the request is
// over. It is wrong here. A denied tool call is a normal event in the middle
// of a larger AI request that must still produce an honest degraded answer,
// and exiting would abandon a half-written conversation. So every gate here
// returns a structured denial — and every denial is still audited, so a
// refused tool call is as visible as a refused page load.
//
// ---------------------------------------------------------------------------
// WHY THESE TOOLS ARE READ-ONLY, WITHOUT EXCEPTION
//
// A write tool would mean a model deciding to change a clinical record. Even
// with perfect authorization, the resulting row would have no honest author:
// the audit trail would say a person did something a model inferred. Whether
// AI-initiated writes should ever exist is a product decision for a later
// phase, and this phase does not quietly pre-answer it by building the
// mechanism. There is no write path here to be enabled by a flag.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/livestock.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/ai_schema.php';

/**
 * Denial tokens. A closed set, mapped to safe text — the same discipline
 * DFMS_AI_FAILURE_REASONS applies to provider failures, and for the same
 * reason: a message that reaches a user must never be assembled from
 * something a model or an attacker influenced.
 */
const DFMS_AI_TOOL_REASONS = [
    'unknown_tool'        => 'That tool is not available.',
    'not_authenticated'   => 'Not signed in.',
    'no_tenant'           => 'No active farm context.',
    'unknown_permission'  => 'That tool is misconfigured and was refused.',
    'permission_denied'   => 'You do not have permission to use that tool.',
    'invalid_input'       => 'The tool was called with invalid input.',
    'not_found'           => 'No matching record on this farm.',
    'tool_failed'         => 'The tool could not complete.',
];

/** Safe, user-facing text for a tool denial token. */
function aiToolFailureMessage(?string $reason): string {
    return DFMS_AI_TOOL_REASONS[$reason ?? ''] ?? 'The tool could not be used.';
}

// ===========================================================================
// THE CONTRACT
// ===========================================================================

abstract class AiTool
{
    /** Stable machine name. This is what a model asks for. */
    abstract public function name(): string;

    /** What it does, in the words a model will read. */
    abstract public function description(): string;

    /** An includes/ai_schema.php object schema for the arguments. */
    abstract public function inputSchema(): array;

    /** A key of DFMS_PERMISSIONS. Anything else denies. */
    abstract public function requiredPermission(): string;

    /**
     * The actual read. Runs ONLY after every gate in execute() has passed.
     *
     * Implementations must use tenant-scoped domain helpers and must not call
     * anything that exits (the *Or404 family), because a tool denial has to be
     * returnable. Return null to signal "no such record on this farm" — which
     * is deliberately the same answer as "that record belongs to someone else".
     *
     * @param array<string, mixed> $input  already validated against inputSchema()
     * @return array<string, mixed>|null
     */
    abstract protected function run(PDO $conn, array $input): ?array;

    /**
     * Run the tool under every gate. FINAL: a subclass must not be able to
     * skip a check by overriding the entry point.
     *
     * @param array<string, mixed> $input  untrusted; may have come from a model
     * @return array{ok: bool, tool: string, data: ?array, reason: ?string,
     *               message: ?string, errors: list<string>}
     */
    final public function execute(PDO $conn, array $input): array
    {
        $deny = function (string $reason, array $errors = []): array {
            return [
                'ok' => false, 'tool' => $this->name(), 'data' => null,
                'reason' => $reason, 'message' => aiToolFailureMessage($reason),
                'errors' => $errors,
            ];
        };

        // ---- 1. authentication ------------------------------------------
        // From the session. There is deliberately no way to pass an identity
        // into this method.
        if (!isLoggedIn()) {
            return $deny('not_authenticated');
        }

        // ---- 2. tenant context -------------------------------------------
        // The same two checks requireAuth() makes: an active farm, and an
        // organization the user is actually a member of. The second catches a
        // farm whose organization changed underneath a live session.
        $organizationId = currentOrganizationId();
        if (currentFarmId() === null || $organizationId === null
            || !in_array($organizationId, allowedOrganizationIds(), true)) {
            return $deny('no_tenant');
        }

        // ---- 3. authorization --------------------------------------------
        $permission = $this->requiredPermission();
        if (!array_key_exists($permission, DFMS_PERMISSIONS)) {
            // An unknown permission DENIES. A tool whose permission was
            // renamed out from under it must become unusable, never
            // unrestricted.
            error_log('AI tool ' . $this->name() . ": unknown permission '$permission' — denying.");
            auditSecurityEvent('ai.tool_misconfigured', [
                'tool' => $this->name(), 'permission' => $permission,
            ]);
            return $deny('unknown_permission');
        }
        if (!hasPermission($permission)) {
            auditSecurityEvent('ai.tool_permission_denied', [
                'tool'       => $this->name(),
                'permission' => $permission,
                'role'       => currentFarmRole(),
            ]);
            return $deny('permission_denied');
        }

        // ---- 4. input validation ------------------------------------------
        // Strict, and by the tool's OWN schema. Unknown argument names are
        // rejected, so a model that invents a parameter — say `farm_id` — gets
        // a refusal rather than having it quietly ignored.
        $validated = aiSchemaValidate($input, $this->inputSchema());
        if (!$validated['ok']) {
            return $deny('invalid_input', $validated['errors']);
        }

        // ---- 5. the read ---------------------------------------------------
        try {
            $data = $this->run($conn, is_array($validated['value']) ? $validated['value'] : []);
        } catch (Throwable $e) {
            // A tool must never take the request down with it. The message is
            // logged (it is ours, not a provider's) but never returned.
            error_log('AI tool ' . $this->name() . ' failed: ' . $e->getMessage());
            return $deny('tool_failed');
        }

        if ($data === null) {
            return $deny('not_found');
        }

        return [
            'ok' => true, 'tool' => $this->name(), 'data' => $data,
            'reason' => null, 'message' => null, 'errors' => [],
        ];
    }

    /**
     * The tool as a model would be told about it.
     *
     * @return array{name: string, description: string, permission: string, input_schema: array<string, mixed>}
     */
    final public function definition(): array
    {
        return [
            'name'         => $this->name(),
            'description'  => $this->description(),
            'permission'   => $this->requiredPermission(),
            'input_schema' => $this->inputSchema(),
        ];
    }
}

// ===========================================================================
// THE TOOLS
//
// Five, and only five. Each one exists because a veterinary or farm question
// genuinely needs it and a tenant-scoped domain helper already answers it.
// Every field returned is a field the calling user could already read on a
// page they have access to — a tool is a different route to the same data,
// never a wider one.
// ===========================================================================

/**
 * Identity and standing of one animal.
 *
 * animalFind() is the tenant boundary: it returns null for an animal that does
 * not exist AND for one on another farm, indistinguishably, which is why the
 * two collapse into the same 'not_found' denial above.
 */
final class AiToolGetAnimal extends AiTool
{
    public function name(): string { return 'get_animal'; }

    public function description(): string
    {
        return 'Look up one animal on the active farm by its internal id. '
            . 'Returns tag, name, species, breed, sex, age and lifecycle status. '
            . 'Returns nothing if the animal is not on this farm.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'fields' => [
            'animal_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
        ]];
    }

    public function requiredPermission(): string { return 'cows.view'; }

    protected function run(PDO $conn, array $input): ?array
    {
        $animal = animalFind($conn, (int) $input['animal_id'], true);
        if ($animal === null) {
            return null;
        }
        return [
            'animal_id'        => (int) $animal['cow_id'],
            'tag'              => (string) $animal['unique_id'],
            'name'             => $animal['name'] !== null && $animal['name'] !== '' ? (string) $animal['name'] : null,
            'species'          => dfms_species_label($animal['species'] ?? null),
            'breed'            => $animal['breed'] !== null && $animal['breed'] !== '' ? (string) $animal['breed'] : null,
            'sex'              => dfms_animal_sex_label($animal['species'] ?? null, $animal['sex'] ?? null),
            'age'              => animalAgeLabel($animal['date_of_birth'] ?? null),
            'lifecycle_status' => DFMS_LIFECYCLE_STATUSES[$animal['lifecycle_status']]['label'] ?? 'unknown',
            'health_status'    => dfms_health_status_label($animal['health_status'] ?? null),
        ];
    }
}

/**
 * The clinical standing of one animal, as counts and headlines.
 *
 * Deliberately a SUMMARY. Returning every case, treatment and observation
 * would push a whole clinical record into a prompt, most of it irrelevant, and
 * the useful part would be diluted. get_animal_health_history exists for when
 * the detail is actually wanted.
 */
final class AiToolGetAnimalHealthSummary extends AiTool
{
    public function name(): string { return 'get_animal_health_summary'; }

    public function description(): string
    {
        return 'Current health standing of one animal on the active farm: open cases, '
            . 'active treatments, quarantine, product withdrawal restrictions and '
            . 'whether any vaccination history is recorded.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'fields' => [
            'animal_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
        ]];
    }

    public function requiredPermission(): string { return 'health.view'; }

    protected function run(PDO $conn, array $input): ?array
    {
        $animalId = (int) $input['animal_id'];
        // Resolved through the tenant boundary FIRST. healthAnimalSummary()
        // calls animalFindOr404(), which exits — and a tool denial has to be
        // returnable, so the miss is caught here before it can.
        $animal = animalFind($conn, $animalId, true);
        if ($animal === null) {
            return null;
        }

        $summary = healthAnimalSummary($conn, (int) $animal['cow_id']);

        $cases = [];
        foreach ($summary['active_cases'] as $case) {
            $cases[] = [
                'reference' => (string) $case['case_reference'],
                'title'     => (string) $case['title'],
                'type'      => DFMS_CASE_TYPES[$case['case_type']] ?? (string) $case['case_type'],
                'severity'  => (string) $case['severity'],
                'opened_on' => (string) $case['opened_on'],
            ];
        }

        $treatments = [];
        foreach ($summary['active_treatments'] as $treatment) {
            $treatments[] = [
                'name'       => (string) $treatment['name'],
                'type'       => DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? (string) $treatment['treatment_type'],
                'started_on' => (string) $treatment['started_on'],
                'status'     => (string) $treatment['status'],
            ];
        }

        $withdrawals = [];
        foreach ($summary['active_withdrawals'] as $withdrawal) {
            $withdrawals[] = [
                'restriction' => (string) $withdrawal['restriction_type'],
                'ends_on'     => (string) $withdrawal['ends_on'],
            ];
        }

        return [
            'animal_id'          => (int) $animal['cow_id'],
            'tag'                => (string) $animal['unique_id'],
            'health_status'      => dfms_health_status_label($summary['health_status']),
            'active_cases'       => $cases,
            'active_treatments'  => $treatments,
            'active_withdrawals' => $withdrawals,
            'quarantined'        => $summary['active_quarantine'] !== null,
            // A COUNT, never a claim. Zero means the history is unknown to
            // this system, not that the animal is unvaccinated — the same
            // distinction the veterinary context builder is careful about.
            'vaccinations_recorded' => (int) $summary['vaccination_count'],
        ];
    }
}

/**
 * What was recorded for one animal recently, oldest concerns first.
 */
final class AiToolGetAnimalHealthHistory extends AiTool
{
    public function name(): string { return 'get_animal_health_history'; }

    public function description(): string
    {
        return 'Recent recorded clinical history for one animal on the active farm: '
            . 'observations, medications administered and vaccinations given. '
            . 'Only what is recorded in this system; absence means unknown, not negative.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'fields' => [
            'animal_id' => ['type' => 'integer', 'required' => true, 'min' => 1],
            'limit'     => ['type' => 'integer', 'min' => 1, 'max' => 20],
        ]];
    }

    public function requiredPermission(): string { return 'health.view'; }

    protected function run(PDO $conn, array $input): ?array
    {
        $animalId = (int) $input['animal_id'];
        $limit = isset($input['limit']) ? (int) $input['limit'] : 10;

        $animal = animalFind($conn, $animalId, true);
        if ($animal === null) {
            return null;
        }
        $animalId = (int) $animal['cow_id'];

        $observations = [];
        foreach (healthObservations($conn, $animalId, $limit) as $observation) {
            $observations[] = [
                'type'        => DFMS_OBSERVATION_TYPES[$observation['observation_type']]['label']
                    ?? (string) $observation['observation_type'],
                'value'       => healthFormatObservation($observation),
                'observed_at' => substr((string) $observation['observed_at'], 0, 16),
            ];
        }

        $medications = [];
        foreach (healthMedicationHistory($conn, $animalId, $limit) as $medication) {
            $medications[] = [
                'medication'     => (string) $medication['medication_name'],
                'dose'           => (float) $medication['dose'],
                'dose_unit'      => (string) $medication['dose_unit'],
                'route'          => (string) $medication['route'],
                'administered_on' => substr((string) $medication['administered_at'], 0, 10),
            ];
        }

        $vaccinations = [];
        foreach (healthVaccinations($conn, $animalId, $limit) as $vaccination) {
            if ($vaccination['administered_on'] === null) {
                continue;   // scheduled, not given — reporting it as history would be a lie
            }
            $vaccinations[] = [
                'vaccine'        => (string) $vaccination['vaccine_name'],
                'administered_on' => (string) $vaccination['administered_on'],
                'disease_target' => $vaccination['disease_target'] !== null && $vaccination['disease_target'] !== ''
                    ? (string) $vaccination['disease_target'] : null,
            ];
        }

        return [
            'animal_id'    => $animalId,
            'tag'          => (string) $animal['unique_id'],
            'observations' => $observations,
            'medications'  => $medications,
            'vaccinations' => $vaccinations,
        ];
    }
}

/**
 * Open alerts on the active farm.
 *
 * Reads through tenantQuery(), which applies the farm scope itself — the
 * alias-and-{scope} form is the only way a query is written in this codebase,
 * precisely so that "did someone forget the farm_id" is not a question anyone
 * has to ask of a new query.
 */
final class AiToolGetRecentAlerts extends AiTool
{
    public function name(): string { return 'get_recent_alerts'; }

    public function description(): string
    {
        return 'Unread alerts on the active farm, most recent first. '
            . 'Optionally filtered to one severity.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'fields' => [
            'limit'    => ['type' => 'integer', 'min' => 1, 'max' => 25],
            'severity' => ['type' => 'enum', 'values' => ['critical', 'warning', 'info']],
        ]];
    }

    public function requiredPermission(): string { return 'alerts.view'; }

    protected function run(PDO $conn, array $input): ?array
    {
        // Cast and clamped before it reaches the query. The LIMIT is
        // interpolated because no placeholder exists for it, which is exactly
        // why it is forced through (int) after schema validation rather than
        // trusted from either.
        $limit = isset($input['limit']) ? max(1, min(25, (int) $input['limit'])) : 10;

        $where = '';
        $params = [];
        if (isset($input['severity'])) {
            $where = ' AND a.severity = ?';
            $params[] = (string) $input['severity'];
        }

        $rows = tenantQuery(
            $conn,
            "SELECT a.alert_id, a.alert_type, a.severity, a.description, a.alert_time,
                    a.entity_type, a.entity_id
               FROM alerts a
              WHERE {scope} AND a.is_read = 0" . $where . "
              ORDER BY a.alert_time DESC
              LIMIT " . (int) $limit,
            $params,
            'a'
        );

        $alerts = [];
        foreach ($rows as $row) {
            $alerts[] = [
                'type'        => (string) $row['alert_type'],
                'severity'    => (string) $row['severity'],
                'description' => (string) $row['description'],
                'raised_at'   => substr((string) $row['alert_time'], 0, 16),
                'entity_type' => $row['entity_type'] !== null ? (string) $row['entity_type'] : null,
                'entity_id'   => $row['entity_id'] !== null ? (int) $row['entity_id'] : null,
            ];
        }
        return ['alerts' => $alerts, 'count' => count($alerts)];
    }
}

/**
 * Herd-level counters for the active farm.
 *
 * Requires health.view rather than cows.view even though it reports herd size.
 * health.view's role list is a strict subset of cows.view's (it excludes
 * accountant), so the stricter permission is the safe one for a payload that
 * mixes both — and picking the looser of two would be the kind of quiet
 * widening this file exists to prevent.
 */
final class AiToolGetFarmSummary extends AiTool
{
    public function name(): string { return 'get_farm_summary'; }

    public function description(): string
    {
        return 'Counts for the active farm: animals on the herd, and how many have '
            . 'open health cases, active treatments, quarantines or withdrawal '
            . 'restrictions. Counts only, no individual records.';
    }

    public function inputSchema(): array
    {
        // No arguments. The schema is still enforced, so a model that invents
        // one — "farm_id", say — is refused rather than silently ignored.
        return ['type' => 'object', 'fields' => []];
    }

    public function requiredPermission(): string { return 'health.view'; }

    protected function run(PDO $conn, array $input): ?array
    {
        $counters = healthFarmCounters($conn);

        $herd = (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM cows c WHERE {scope} AND c.deleted_at IS NULL",
            [], 0, 'c'
        );

        return [
            'animals'           => $herd,
            'active_cases'      => (int) $counters['active_cases'],
            'urgent_cases'      => (int) $counters['urgent_cases'],
            'under_treatment'   => (int) $counters['under_treatment'],
            'quarantined'       => (int) $counters['quarantined'],
            'withdrawals'       => (int) $counters['withdrawals'],
            'needs_attention'   => (int) $counters['needs_attention'],
            'vaccinations_due'  => (int) $counters['vaccinations_due'],
        ];
    }
}

// ===========================================================================
// THE REGISTRY
// ===========================================================================

/**
 * Every tool that exists.
 *
 * An explicit list, exactly like the API route table in api/index.php and for
 * the same reason: a registry built by scanning would turn "someone added a
 * class" into "a model gained a new capability", which is a security decision
 * nobody reviewed.
 *
 * @return array<string, AiTool>
 */
function aiToolRegistry(): array {
    static $tools = null;
    if ($tools === null) {
        $tools = [];
        foreach ([
            new AiToolGetAnimal(),
            new AiToolGetAnimalHealthSummary(),
            new AiToolGetAnimalHealthHistory(),
            new AiToolGetRecentAlerts(),
            new AiToolGetFarmSummary(),
        ] as $tool) {
            $tools[$tool->name()] = $tool;
        }
    }
    return $tools;
}

/** A tool by name, or null. A model-supplied name reaches only this function. */
function aiToolFind(string $name): ?AiTool {
    return aiToolRegistry()[$name] ?? null;
}

/**
 * Execute a tool by name.
 *
 * THE ONLY ENTRY POINT a model-influenced request should use. An unknown name
 * is refused and audited: a request for a tool that does not exist is either a
 * hallucination worth knowing about or an attempt worth recording, and both
 * deserve a line in the security log.
 *
 * @param array<string, mixed> $input
 * @return array{ok: bool, tool: string, data: ?array, reason: ?string,
 *               message: ?string, errors: list<string>}
 */
function aiToolExecute(PDO $conn, string $name, array $input): array {
    $tool = aiToolFind($name);
    if ($tool === null) {
        auditSecurityEvent('ai.tool_unknown', [
            // The name is recorded truncated, because it came from a model and
            // an audit payload is not the place to store an arbitrary blob.
            'requested_tool' => mb_substr($name, 0, 64, 'UTF-8'),
        ]);
        return [
            'ok' => false, 'tool' => mb_substr($name, 0, 64, 'UTF-8'), 'data' => null,
            'reason' => 'unknown_tool', 'message' => aiToolFailureMessage('unknown_tool'),
            'errors' => [],
        ];
    }
    return $tool->execute($conn, $input);
}

/**
 * Definitions of every registered tool.
 *
 * @return list<array{name: string, description: string, permission: string, input_schema: array<string, mixed>}>
 */
function aiToolDefinitions(): array {
    $definitions = [];
    foreach (aiToolRegistry() as $tool) {
        $definitions[] = $tool->definition();
    }
    return $definitions;
}

/**
 * Definitions of the tools the CURRENT user may actually run.
 *
 * What a future phase would offer a model, so it is never told about a
 * capability the person on whose behalf it is running does not have. This is a
 * convenience, NOT the security boundary — execute() re-checks the permission
 * regardless, because a model can ask for a tool it was never offered.
 *
 * @return list<array{name: string, description: string, permission: string, input_schema: array<string, mixed>}>
 */
function aiToolsAvailable(): array {
    $available = [];
    foreach (aiToolRegistry() as $tool) {
        $permission = $tool->requiredPermission();
        if (array_key_exists($permission, DFMS_PERMISSIONS) && hasPermission($permission)) {
            $available[] = $tool->definition();
        }
    }
    return $available;
}
