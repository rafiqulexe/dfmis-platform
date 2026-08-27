<?php
// ============================================================================
// FEATURE FLAGS
//
// Controlled rollout for modules that do not exist yet. This is PREPARATION —
// no flag here turns on a working feature, because none of those features are
// built. What it provides is a safe place for a later phase to gate itself.
//
// RESOLUTION (highest wins), mirroring the settings hierarchy so there is one
// mental model rather than two:
//
//     Farm flag           feature_flags(scope='farm', scope_id=<farm_id>)
//         ↓
//     Organization flag   feature_flags(scope='organization', scope_id=<org_id>)
//         ↓
//     Platform flag       feature_flags(scope='platform', scope_id=0)
//         ↓
//     Registry default    DFMS_FEATURE_REGISTRY below
//
// A flag row is an explicit ON or OFF at that level; absence means "defer to
// the level below". This is why the resolver checks for row EXISTENCE, not
// truthiness — a row with enabled=0 at farm level must override an enabled=1
// at organization level, which a naive `if ($enabled)` would get wrong.
//
// ---------------------------------------------------------------------------
// SECURITY BOUNDARY — read this before using a flag.
//
// A feature flag answers "is this feature switched on?" and NOTHING else. It
// is not an authorization mechanism and must never be used as one:
//
//     if (dfms_feature_enabled('advanced_reporting')) { … }   // WRONG alone
//
//     requireAuth('reports.view');                            // authorization
//     if (dfms_feature_enabled('advanced_reporting')) { … }   // then rollout
//
// Flags are resolved AFTER authentication, authorization and tenant scoping,
// never instead of them. dfms_feature_enabled() reads flags only for the
// ACTIVE tenant, so a flag cannot be used to reach another organization's
// configuration either.
// ============================================================================

require_once __DIR__ . '/tenant.php';

/**
 * Every flag the platform knows about.
 *
 * A flag not listed here cannot be set or read — an unregistered key is a
 * typo, and silently accepting it means a gate that never opens (or, worse,
 * one that never closes). `default` is the value when no row exists at any
 * scope.
 *
 * All future-phase flags default to FALSE: an unbuilt feature must be off
 * everywhere until someone deliberately switches it on.
 */
const DFMS_FEATURE_REGISTRY = [
    'future_ai_copilot' => [
        'label'       => 'AI Copilot',
        'description' => 'Conversational assistant over farm data. Phase 7 — not implemented.',
        'default'     => false,
        'phase'       => 7,
    ],
    'future_disease_detection' => [
        'label'       => 'AI Disease Detection',
        'description' => 'Image-based diagnostic support. Phase 6 — not implemented.',
        'default'     => false,
        'phase'       => 6,
    ],
    'future_iot' => [
        'label'       => 'IoT & Sensor Ingestion',
        'description' => 'Device telemetry and wearables. Phase 11 — not implemented.',
        'default'     => false,
        'phase'       => 11,
    ],
    'advanced_reporting' => [
        'label'       => 'Advanced Reporting',
        'description' => 'Report builder, scheduled exports and benchmarking. Phase 10 — not implemented.',
        'default'     => false,
        'phase'       => 10,
    ],
    'multi_farm_switching' => [
        'label'       => 'Multi-farm Switching',
        'description' => 'Farm switcher in the top bar. Built in Phase 1; on by default.',
        'default'     => true,
        'phase'       => 1,
    ],
];

function dfms_feature_registry(): array {
    return DFMS_FEATURE_REGISTRY;
}

function dfms_is_registered_feature(string $key): bool {
    return isset(DFMS_FEATURE_REGISTRY[$key]);
}

// ---------------------------------------------------------------------------
// Resolution
// ---------------------------------------------------------------------------

/**
 * All flag rows relevant to the active tenant, loaded in ONE query and cached
 * per request. Indexed as [scope][scope_id][flag_key] => bool.
 *
 * Loading every relevant row at once matters: resolving five flags on a page
 * should not be five round trips.
 */
function dfms_feature_rows(bool $reset = false): array {
    static $cache = null;

    // Resetting must set the cache back to NULL, not to an empty structure.
    // An empty array is still "loaded" as far as the guard below is concerned,
    // so clearing it that way would permanently serve no flags at all.
    if ($reset) {
        $cache = null;
        return [];
    }
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['platform' => [], 'organization' => [], 'farm' => []];

    $farmId = currentFarmId();
    $orgId = currentOrganizationId();

    try {
        $conn = getDBConnection();
        // Platform rows always; organization and farm rows only for the
        // ACTIVE tenant — this query can never see another tenant's flags.
        $sql = "SELECT flag_key, scope, scope_id, enabled FROM feature_flags
                 WHERE (scope = 'platform' AND scope_id = 0)";
        $params = [];
        if ($orgId !== null) {
            $sql .= " OR (scope = 'organization' AND scope_id = ?)";
            $params[] = $orgId;
        }
        if ($farmId !== null) {
            $sql .= " OR (scope = 'farm' AND scope_id = ?)";
            $params[] = $farmId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$row['scope']][(int) $row['scope_id']][$row['flag_key']] = (bool) $row['enabled'];
        }
    } catch (Throwable $e) {
        // A missing or unreadable flag table must not take the application
        // down. Every flag then falls back to its registry default, which for
        // unbuilt features is OFF — the safe direction.
        error_log('Feature flag load failed: ' . $e->getMessage());
    }
    return $cache;
}

function dfms_reset_feature_cache(): void {
    dfms_feature_rows(true);
}

/**
 * Is a feature on for the active tenant?
 *
 * Never a substitute for an authorization check — see the security note at the
 * top of this file.
 */
function dfms_feature_enabled(string $key): bool {
    if (!dfms_is_registered_feature($key)) {
        error_log("dfms_feature_enabled(): unregistered feature '$key' — treating as off.");
        return false;
    }

    $rows = dfms_feature_rows();
    $farmId = currentFarmId();
    $orgId = currentOrganizationId();

    // array_key_exists, not isset/truthiness: an explicit `false` at a higher
    // scope must win over `true` at a lower one.
    if ($farmId !== null && array_key_exists($key, $rows['farm'][$farmId] ?? [])) {
        return $rows['farm'][$farmId][$key];
    }
    if ($orgId !== null && array_key_exists($key, $rows['organization'][$orgId] ?? [])) {
        return $rows['organization'][$orgId][$key];
    }
    if (array_key_exists($key, $rows['platform'][0] ?? [])) {
        return $rows['platform'][0][$key];
    }
    return (bool) DFMS_FEATURE_REGISTRY[$key]['default'];
}

/**
 * Where a flag's effective value came from, for the settings UI.
 * @return array{enabled: bool, source: string}  source: farm|organization|platform|default
 */
function dfms_feature_state(string $key): array {
    if (!dfms_is_registered_feature($key)) {
        return ['enabled' => false, 'source' => 'unregistered'];
    }
    $rows = dfms_feature_rows();
    $farmId = currentFarmId();
    $orgId = currentOrganizationId();

    if ($farmId !== null && array_key_exists($key, $rows['farm'][$farmId] ?? [])) {
        return ['enabled' => $rows['farm'][$farmId][$key], 'source' => 'farm'];
    }
    if ($orgId !== null && array_key_exists($key, $rows['organization'][$orgId] ?? [])) {
        return ['enabled' => $rows['organization'][$orgId][$key], 'source' => 'organization'];
    }
    if (array_key_exists($key, $rows['platform'][0] ?? [])) {
        return ['enabled' => $rows['platform'][0][$key], 'source' => 'platform'];
    }
    return ['enabled' => (bool) DFMS_FEATURE_REGISTRY[$key]['default'], 'source' => 'default'];
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/**
 * Set (or clear) a flag at one scope.
 *
 * $enabled === null CLEARS the override, so the flag falls through to the next
 * level down. Without a clear operation an override could never be undone,
 * only inverted.
 *
 * NO authorization is performed here — the caller must have already gone
 * through requireAuth() / requireOrganizationPermission(). Authorization lives
 * in one place (Phase 1) and is not duplicated per subsystem.
 *
 * The scope id is NOT taken from the caller for organization/farm scope: it is
 * read from the active tenant context, so this function cannot be used to
 * write a flag onto another tenant.
 */
function dfms_set_feature(string $key, string $scope, ?bool $enabled): bool {
    if (!dfms_is_registered_feature($key)) {
        throw new InvalidArgumentException("Unregistered feature: $key");
    }
    if (!in_array($scope, ['platform', 'organization', 'farm'], true)) {
        throw new InvalidArgumentException("Invalid feature scope: $scope");
    }

    if ($scope === 'farm') {
        $scopeId = currentFarmId();
    } elseif ($scope === 'organization') {
        $scopeId = currentOrganizationId();
    } else {
        $scopeId = 0;
    }
    if ($scopeId === null) {
        throw new RuntimeException("No tenant context for feature scope '$scope'.");
    }

    $conn = getDBConnection();
    $before = dfms_feature_state($key);

    if ($enabled === null) {
        $stmt = $conn->prepare("DELETE FROM feature_flags WHERE flag_key = ? AND scope = ? AND scope_id = ?");
        $stmt->execute([$key, $scope, $scopeId]);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO feature_flags (flag_key, scope, scope_id, enabled, updated_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by)"
        );
        $stmt->execute([$key, $scope, $scopeId, $enabled ? 1 : 0, currentUserId()]);
    }

    dfms_reset_feature_cache();

    if (function_exists('auditLog')) {
        auditLog('feature.configure', 'feature_flag', null,
            ['flag' => $key, 'enabled' => $before['enabled'], 'source' => $before['source']],
            ['flag' => $key, 'scope' => $scope, 'scope_id' => $scopeId,
             'enabled' => $enabled === null ? 'inherited' : $enabled]
        );
    }
    return true;
}
