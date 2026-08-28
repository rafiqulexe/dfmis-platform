<?php
// ============================================================================
// FEEDING MODULE — shared bootstrap and render helpers.
//
// No business rules here. Every feed rule lives in includes/feed.php and every
// stock rule in includes/inventory.php, so there is exactly one place each is
// written.
//
// DIRECT EXECUTION GUARD — this is a partial, not a page.
// ============================================================================

if (!defined('DFMS_FEEDING_PAGE')) {
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
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/feed.php';

/**
 * Locations feed can be recorded against.
 *
 * `locations` is the existing grouping in this codebase (migration 004). No
 * animal-group table was invented for Phase 6 — location IS the group.
 *
 * @return list<array<string, mixed>>
 */
function feedSelectableLocations(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT location_id, name, location_type FROM locations
          WHERE {scope} AND deleted_at IS NULL ORDER BY name"
    );
}

/** @return list<array<string, mixed>> */
function feedSelectableAnimals(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT cow_id, unique_id, name FROM cows
          WHERE {scope} AND deleted_at IS NULL AND lifecycle_status = 'active'
          ORDER BY unique_id"
    );
}

/** Styles scoped to this module, matching the health/production convention. */
function feedScopedStyles(): void {
    static $emitted = false;
    if ($emitted) {
        return;
    }
    $emitted = true;
    ?>
<style>
    .dfms-feed-scope .feed-tabs {
        display: flex; gap: var(--space-4); border-bottom: 1px solid var(--border);
        margin-bottom: var(--space-5); overflow-x: auto; -webkit-overflow-scrolling: touch;
    }
    .dfms-feed-scope .feed-tab {
        padding: var(--space-3) 0; border-bottom: 2px solid transparent; white-space: nowrap;
        color: var(--text-muted); text-decoration: none; font-size: var(--text-sm);
        touch-action: manipulation; min-height: 44px; display: inline-flex; align-items: center;
    }
    .dfms-feed-scope .feed-tab:hover { color: var(--text-strong); }
    .dfms-feed-scope .feed-tab[aria-current="page"] {
        color: var(--primary); border-bottom-color: var(--primary); font-weight: 600;
    }
    .dfms-feed-scope .feed-tab:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .dfms-feed-scope .feed-filters {
        display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: flex-end;
        margin-bottom: var(--space-4);
    }
    .dfms-feed-scope .feed-filters .dfms-field { margin-bottom: 0; min-width: 9rem; flex: 1 1 9rem; }
    .dfms-feed-scope .feed-note {
        border-left: 3px solid var(--info, #2563eb); padding: var(--space-3) var(--space-4);
        background: var(--info-bg, #eff6ff); color: var(--info-fg, #1e3a8a);
        border-radius: var(--radius-sm); font-size: var(--text-sm); margin-bottom: var(--space-5);
    }
    .dfms-feed-scope .feed-metrics {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: var(--space-4); margin-bottom: var(--space-6);
    }
    @media (max-width: 640px) {
        .dfms-feed-scope .feed-filters .dfms-field { flex-basis: 100%; }
    }
</style>
    <?php
}

/** How a consumption event's target reads in a list. */
function feedEventTargetLabel(array $event): string {
    switch ((string) $event['scope_type']) {
        case 'location':
            return $event['location_name'] !== null
                ? (string) $event['location_name'] : 'A location (since removed)';
        case 'animal':
            if ($event['animal_tag'] === null) {
                return 'An animal (since removed)';
            }
            return (string) $event['animal_tag']
                . ($event['animal_name'] ? ' — ' . (string) $event['animal_name'] : '');
        default:
            return 'Whole farm';
    }
}
