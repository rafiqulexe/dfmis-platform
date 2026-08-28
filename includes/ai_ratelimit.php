<?php
// ============================================================================
// AI RATE LIMITING — abuse protection, not billing.
//
// WHAT THIS IS FOR, AND WHAT IT IS NOT FOR
//
// The MONTHLY plan allowance in includes/ai_service.php answers "has this
// organization bought this much AI". It is a commercial limit, checked once
// per call, over a month-long window. It is not, and cannot be, a defence
// against a script: an enterprise plan is unlimited, and a starter plan's 100
// calls can be burned in four seconds by a loop.
//
// This file answers a different question: "is this a plausible rate for a
// person using a farm management system". A stockperson asks the assistant a
// question, reads the answer, and asks another. Twelve in a minute is already
// generous. Two hundred in a minute is a bug in someone's client or an attempt
// to run up a shared bill.
//
// The two limits are INDEPENDENT and both apply. Neither replaces the other.
//
// ---------------------------------------------------------------------------
// WHY THERE IS NO NEW TABLE
//
// ai_usage already records, per row, the organization, the user, the status
// and the timestamp of every call that reached a provider. That IS the rate
// ledger. Adding a counters table would mean two sources of truth about how
// much AI has been used, kept in sync by hand, and the one this file consulted
// would be the one nobody reconciles.
//
// Only rows with status 'success' or 'error' are counted — that is, attempts
// that actually reached the provider. 'blocked' rows are excluded deliberately:
// they record a request that was REFUSED and cost nothing, and counting them
// would mean a user who hits the limit is then held there by the very rows the
// limit created.
//
// ---------------------------------------------------------------------------
// WHY IT CANNOT BE BYPASSED FROM THE CLIENT
//
// Nothing here reads a request parameter, a header or a cookie. The identity
// comes from currentOrganizationId() and currentUserId(), which are derived
// from the server-side session and the membership tables. The counts come from
// the database. There is no input to tamper with.
//
// ---------------------------------------------------------------------------
// WHY IT FAILS OPEN
//
// If ai_usage cannot be read, this returns "allowed" and logs loudly — the
// same decision dfms_login_throttled() makes for the same reason. A limiter
// that fails CLOSED converts a transient database problem into a total outage
// of a feature, and the monthly plan gate (which runs first and reads the same
// table) would have failed too, so nothing is spent unmetered that would not
// already have been.
// ============================================================================

require_once __DIR__ . '/tenant.php';

/**
 * Defaults, chosen from how the product is actually used rather than from a
 * round number. A consultation involves reading a clinical answer; a person
 * doing that cannot sustain one every five seconds for an hour.
 */
const DFMS_AI_RATE_DEFAULTS = [
    'user_per_minute' => 12,
    'user_per_hour'   => 120,
    // Higher than the per-user limit, because a large farm legitimately has
    // several people consulting at once, and lower than user_per_minute times
    // the plausible number of simultaneous users, because a whole organization
    // sustaining 40 AI calls a minute is not a farm, it is a script.
    'org_per_minute'  => 40,
];

const DFMS_AI_RATE_BOUNDS = [
    'user_per_minute' => [1, 600],
    'user_per_hour'   => [1, 10000],
    'org_per_minute'  => [1, 2000],
];

/**
 * The limits in force, with validated environment overrides.
 *
 * Server-side configuration for the same reason includes/ai_config.php is: a
 * tenant cannot be allowed to raise the ceiling on a shared, metered resource.
 *
 * @return array{user_per_minute: int, user_per_hour: int, org_per_minute: int}
 */
function aiRateLimits(bool $reset = false): array {
    static $limits = null;
    if ($reset) {
        $limits = null;
    }
    if ($limits !== null) {
        return $limits;
    }

    $limits = DFMS_AI_RATE_DEFAULTS;
    $variables = [
        'AI_RATE_USER_PER_MINUTE' => 'user_per_minute',
        'AI_RATE_USER_PER_HOUR'   => 'user_per_hour',
        'AI_RATE_ORG_PER_MINUTE'  => 'org_per_minute',
    ];
    foreach ($variables as $variable => $key) {
        $raw = getenv($variable);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        [$min, $max] = DFMS_AI_RATE_BOUNDS[$key];
        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
            error_log("AI rate limit: ignoring $variable — not a whole number in {$min} to {$max}. Using the default.");
            continue;
        }
        $limits[$key] = (int) $raw;
    }
    return $limits;
}

/**
 * Is another AI call allowed right now?
 *
 * @param int  $organizationId  the tenant, from the session — never from input
 * @param ?int $adminId         the user, or null for a system-initiated call
 *
 * @return array{allowed: bool, scope: ?string, limit: ?int, used: ?int, retry_after: int}
 *         `scope` names WHICH limit tripped ('user_per_minute' etc.), so the
 *         log and the tests can tell one from another. `retry_after` is a
 *         whole number of seconds and is only meaningful when denied.
 */
function aiRateLimitCheck(PDO $conn, int $organizationId, ?int $adminId): array {
    $limits = aiRateLimits();
    $allowed = ['allowed' => true, 'scope' => null, 'limit' => null, 'used' => null, 'retry_after' => 0];

    try {
        // ONE query for all three windows. Three round trips to answer one
        // question would put the limiter's own cost on every AI request.
        //
        // The outer window is an hour; the minute counts are conditional sums
        // inside it. `status IN ('success','error')` is the "actually reached
        // the provider" filter described in the header.
        $stmt = $conn->prepare(
            "SELECT
                SUM(created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS org_minute,
                SUM(admin_id = :admin AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS user_minute,
                SUM(admin_id = :admin2) AS user_hour
               FROM ai_usage
              WHERE organization_id = :org
                AND status IN ('success','error')
                AND created_at > DATE_SUB(NOW(), INTERVAL 3600 SECOND)"
        );
        $stmt->execute([
            ':admin'  => $adminId,
            ':admin2' => $adminId,
            ':org'    => $organizationId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Fails OPEN, loudly. See the header for why.
        error_log('AI rate limit check failed: ' . $e->getMessage());
        return $allowed;
    }

    if (!is_array($row)) {
        return $allowed;
    }

    $orgMinute  = (int) ($row['org_minute'] ?? 0);
    $userMinute = (int) ($row['user_minute'] ?? 0);
    $userHour   = (int) ($row['user_hour'] ?? 0);

    // Checked narrowest first, so the reported scope is the most specific one
    // the caller tripped rather than whichever happened to be tested first.
    $checks = [
        ['user_per_minute', $userMinute, $limits['user_per_minute'], 60],
        ['org_per_minute',  $orgMinute,  $limits['org_per_minute'],  60],
        ['user_per_hour',   $userHour,   $limits['user_per_hour'],   3600],
    ];
    foreach ($checks as [$scope, $used, $limit, $window]) {
        // A null admin_id cannot trip a per-user limit: SUM(NULL = NULL) is
        // null, and counting a system call against "the user" would be wrong
        // anyway. The organization limit still applies to it.
        if ($adminId === null && str_starts_with($scope, 'user_')) {
            continue;
        }
        if ($used >= $limit) {
            return [
                'allowed'     => false,
                'scope'       => $scope,
                'limit'       => $limit,
                'used'        => $used,
                // The honest upper bound: the oldest call in the window ages
                // out at some point within it. Reporting the full window
                // rather than computing the exact expiry keeps this to one
                // query and never advises a client to retry too early.
                'retry_after' => $window,
            ];
        }
    }

    return $allowed;
}
