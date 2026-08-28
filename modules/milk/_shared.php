<?php
// ============================================================================
// MILK / PRODUCTION MODULE — shared bootstrap and render helpers.
//
// Follows the Phase 4 health module exactly: one place for the require list
// and the shared pickers, and NO business rules. Every production rule lives
// in includes/production.php, which is the single place one is written.
//
// DIRECT EXECUTION GUARD
// This file is a partial, not a page. Reaching it directly would run a file
// that has deliberately not called requireAuth() — the including page does
// that, because the required permission differs per page.
// ============================================================================

if (!defined('DFMS_MILK_PAGE')) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/repository.php';
require_once __DIR__ . '/../../includes/validation.php';
require_once __DIR__ . '/../../includes/settings.php';
require_once __DIR__ . '/../../includes/units.php';
require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/health.php';
require_once __DIR__ . '/../../includes/production.php';

/**
 * Animals a milking can be recorded against.
 *
 * Terminal-lifecycle animals are EXCLUDED because includes/production.php
 * refuses to write against them — offering a choice the next screen rejects is
 * a worse experience than not offering it. One rule, enforced in the domain
 * and reflected in the UI, rather than two rules that can disagree.
 *
 * Males are NOT excluded. Species vary, and deciding which animals can produce
 * milk from a sex column is exactly the kind of assumption this multi-species
 * codebase avoids; the farm knows its herd better than a filter does.
 *
 * @return list<array<string, mixed>>
 */
function productionSelectableAnimals(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT cow_id, unique_id, name, species, lactation_status
           FROM cows
          WHERE {scope} AND deleted_at IS NULL AND lifecycle_status = 'active'
          ORDER BY unique_id"
    );
}

/**
 * Animals under an active milk withdrawal today, keyed by cow_id.
 *
 * Read from the Phase 4 health domain rather than recomputed here — there is
 * one answer to "is this animal's milk restricted" and it belongs to the
 * health module. Used to warn at the point of recording.
 *
 * @return array<int, array<string, mixed>>
 */
function productionMilkWithdrawalsByAnimal(PDO $conn): array {
    $byAnimal = [];
    foreach (healthFarmActiveWithdrawals($conn, 'milk') as $withdrawal) {
        $byAnimal[(int) $withdrawal['cow_id']] = $withdrawal;
    }
    return $byAnimal;
}

/** Styles scoped to this module, matching the health/AI module convention. */
function productionScopedStyles(): void {
    static $emitted = false;
    if ($emitted) {
        return;
    }
    $emitted = true;
    ?>
<style>
    .dfms-production-scope .prod-tabs {
        display: flex; gap: var(--space-4); border-bottom: 1px solid var(--border);
        margin-bottom: var(--space-5); overflow-x: auto; -webkit-overflow-scrolling: touch;
    }
    .dfms-production-scope .prod-tab {
        padding: var(--space-3) 0; border-bottom: 2px solid transparent; white-space: nowrap;
        color: var(--text-muted); text-decoration: none; font-size: var(--text-sm);
        /* Removes the ~300ms double-tap delay on touch devices. */
        touch-action: manipulation;
        /* A comfortable touch target on a phone held in a milking parlour. */
        min-height: 44px; display: inline-flex; align-items: center;
    }
    .dfms-production-scope .prod-tab:hover { color: var(--text-strong); }
    .dfms-production-scope .prod-tab[aria-current="page"] {
        color: var(--primary); border-bottom-color: var(--primary); font-weight: 600;
    }
    .dfms-production-scope .prod-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    .dfms-production-scope .prod-metrics {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: var(--space-4); margin-bottom: var(--space-6);
    }
    .dfms-production-scope .prod-filters {
        display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: flex-end;
        margin-bottom: var(--space-4);
    }
    .dfms-production-scope .prod-filters .dfms-field { margin-bottom: 0; min-width: 9rem; flex: 1 1 9rem; }
    .dfms-production-scope .prod-note {
        border-left: 3px solid var(--info, #2563eb); padding: var(--space-3) var(--space-4);
        background: var(--info-bg, #eff6ff); color: var(--info-fg, #1e3a8a);
        border-radius: var(--radius-sm); font-size: var(--text-sm); margin-bottom: var(--space-5);
    }
    .dfms-production-scope .prod-warn {
        border-left: 3px solid var(--warning); padding: var(--space-3) var(--space-4);
        background: var(--warning-bg); color: var(--warning-fg);
        border-radius: var(--radius-sm); font-size: var(--text-sm); margin-bottom: var(--space-5);
    }
    @media (max-width: 640px) {
        .dfms-production-scope .prod-filters .dfms-field { flex-basis: 100%; }
    }
</style>
    <?php
}

/**
 * The standing note explaining the two kinds of record.
 *
 * Shown wherever both appear, because a farm total and a sum of per-animal
 * readings are different measurements and a reader who assumes otherwise will
 * conclude the system is wrong when the two do not match.
 */
function productionSourceNote(): string {
    return 'Farm daily totals and per-animal records are recorded separately and are not '
        . 'expected to match. A farm total is what the bulk tank measured; per-animal figures '
        . 'cover only the animals that were recorded individually.';
}
