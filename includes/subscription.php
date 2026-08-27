<?php
// ============================================================================
// SUBSCRIPTION FOUNDATION
//
// SCOPE — read this first.
// There is NO billing here. No payment provider, no invoicing, no charging, no
// dunning, no proration. This file describes what a plan IS and what state an
// organization's subscription is in, so that a later phase can attach billing
// to a model that already exists rather than inventing one under pressure.
//
// EXTENDS, DOES NOT DUPLICATE.
// organizations.plan and organizations.status existed before this phase.
// Migration 006 widened both enums (adding 'free', 'past_due', 'expired') and
// added trial_ends_at / current_period_ends_at. No parallel subscriptions
// table was created, because an organization has exactly one subscription and
// a second table would just be a slower way to read the same row.
//
// NOBODY IS RESTRICTED BY THIS PHASE.
// dfms_plan_allows() is advisory. It is not wired into any existing feature,
// and every current user keeps exactly the access they had. Enforcement is a
// deliberate, separate decision for a later phase — turning limits on is a
// commercial choice, not a refactor.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/settings.php';

/**
 * Plan catalogue.
 *
 * Limits are expressed as -1 for "unlimited". They are DEFAULTS describing the
 * intended shape of each tier, not contractual commitments — commercial terms
 * change far more often than code, so a later phase should move these into the
 * database where an operator can edit them without a deploy. Keeping them in
 * PHP now avoids a table nobody can yet administer.
 */
const DFMS_PLANS = [
    'free' => [
        'label'       => 'Free',
        'description' => 'Evaluation tier.',
        'limits'      => ['farms' => 1, 'animals' => 25, 'users' => 2, 'ai_calls_month' => 0],
        'rank'        => 0,
    ],
    'starter' => [
        'label'       => 'Starter',
        'description' => 'A single working farm.',
        'limits'      => ['farms' => 1, 'animals' => 250, 'users' => 5, 'ai_calls_month' => 100],
        'rank'        => 1,
    ],
    'professional' => [
        'label'       => 'Professional',
        'description' => 'Multiple farms in one organization.',
        'limits'      => ['farms' => 10, 'animals' => 5000, 'users' => 50, 'ai_calls_month' => 1000],
        'rank'        => 2,
    ],
    'enterprise' => [
        'label'       => 'Enterprise',
        'description' => 'Unlimited scale, negotiated terms.',
        'limits'      => ['farms' => -1, 'animals' => -1, 'users' => -1, 'ai_calls_month' => -1],
        'rank'        => 3,
    ],
];

/**
 * Subscription lifecycle states.
 *
 * `grants_access` records whether an organization in this state should be able
 * to USE the product. Nothing consumes it yet — it exists so that when access
 * gating is switched on, the policy is already written down in one place
 * instead of being invented inline at each call site.
 */
const DFMS_SUBSCRIPTION_STATUSES = [
    'active'    => ['label' => 'Active',            'grants_access' => true],
    'trial'     => ['label' => 'Trial',             'grants_access' => true],
    'past_due'  => ['label' => 'Payment overdue',   'grants_access' => true],
    'suspended' => ['label' => 'Suspended',         'grants_access' => false],
    'cancelled' => ['label' => 'Cancelled',         'grants_access' => false],
    'expired'   => ['label' => 'Expired',           'grants_access' => false],
];

function dfms_plans(): array {
    return DFMS_PLANS;
}

function dfms_is_valid_plan(?string $plan): bool {
    return $plan !== null && isset(DFMS_PLANS[$plan]);
}

function dfms_is_valid_subscription_status(?string $status): bool {
    return $status !== null && isset(DFMS_SUBSCRIPTION_STATUSES[$status]);
}

/**
 * The active organization's subscription, with everything a UI needs to
 * describe it. Returns null when there is no tenant context.
 */
function dfms_current_subscription(?int $organizationId = null): ?array {
    $org = dfms_organization_row($organizationId);
    if ($org === null) {
        return null;
    }

    $plan = dfms_is_valid_plan($org['plan']) ? $org['plan'] : 'starter';
    $status = dfms_is_valid_subscription_status($org['status']) ? $org['status'] : 'active';

    return [
        'organization_id'        => (int) $org['organization_id'],
        'plan'                   => $plan,
        'plan_label'             => DFMS_PLANS[$plan]['label'],
        'plan_description'       => DFMS_PLANS[$plan]['description'],
        'limits'                 => DFMS_PLANS[$plan]['limits'],
        'status'                 => $status,
        'status_label'           => DFMS_SUBSCRIPTION_STATUSES[$status]['label'],
        'grants_access'          => DFMS_SUBSCRIPTION_STATUSES[$status]['grants_access'],
        'trial_ends_at'          => $org['trial_ends_at'],
        'current_period_ends_at' => $org['current_period_ends_at'],
    ];
}

/**
 * The numeric limit for a resource on the active plan.
 * Returns -1 for unlimited, or -1 when the plan is unknown — an unknown plan
 * must not accidentally impose the strictest limit on a paying customer.
 */
function dfms_plan_limit(string $resource, ?int $organizationId = null): int {
    $subscription = dfms_current_subscription($organizationId);
    if ($subscription === null) {
        return -1;
    }
    return $subscription['limits'][$resource] ?? -1;
}

/**
 * Advisory check: would $count fit within the plan's limit for $resource?
 *
 * ADVISORY ONLY. Nothing in DFMIS calls this to deny an action today, and this
 * phase deliberately does not wire it in. It exists so a later phase can add
 * enforcement at chosen call sites with a migration path (warn first, then
 * block) rather than a hard cutover that locks existing users out.
 */
function dfms_plan_allows(string $resource, int $count, ?int $organizationId = null): bool {
    $limit = dfms_plan_limit($resource, $organizationId);
    return $limit < 0 || $count <= $limit;
}

/**
 * Change an organization's plan and/or status.
 *
 * No authorization here — the caller uses requireOrganizationPermission('org.billing').
 * Authorization lives in one place (Phase 1) and is not re-implemented per
 * subsystem.
 */
function dfms_update_subscription(int $organizationId, array $values): bool {
    $sets = [];
    $params = [];
    $new = [];

    if (array_key_exists('plan', $values)) {
        if (!dfms_is_valid_plan($values['plan'])) {
            throw new InvalidArgumentException('Invalid plan.');
        }
        $sets[] = 'plan = ?';
        $params[] = $values['plan'];
        $new['plan'] = $values['plan'];
    }
    if (array_key_exists('status', $values)) {
        if (!dfms_is_valid_subscription_status($values['status'])) {
            throw new InvalidArgumentException('Invalid subscription status.');
        }
        $sets[] = 'status = ?';
        $params[] = $values['status'];
        $new['status'] = $values['status'];
    }
    foreach (['trial_ends_at', 'current_period_ends_at'] as $dateField) {
        if (!array_key_exists($dateField, $values)) {
            continue;
        }
        $sets[] = "$dateField = ?";
        $params[] = $values[$dateField];   // already validated by the caller
        $new[$dateField] = $values[$dateField];
    }

    if (!$sets) {
        return false;
    }

    $before = dfms_current_subscription($organizationId);
    $params[] = $organizationId;

    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE organizations SET " . implode(', ', $sets) . " WHERE organization_id = ?");
    $stmt->execute($params);

    dfms_reset_settings_cache();

    if (function_exists('auditLog')) {
        auditLog('subscription.update', 'organization', $organizationId,
            $before === null ? null : [
                'plan'   => $before['plan'],
                'status' => $before['status'],
                'trial_ends_at' => $before['trial_ends_at'],
                'current_period_ends_at' => $before['current_period_ends_at'],
            ],
            $new
        );
    }
    return true;
}
