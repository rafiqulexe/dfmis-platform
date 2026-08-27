<?php
// ============================================================================
// NOTIFICATION PREFERENCES
//
// SCOPE — this is NOT a notification system.
// Nothing here sends anything. No email, no SMS, no push, no queue, no
// provider integration. What this file provides is the PREFERENCE model: which
// channels a user wants a given category of event delivered through. Delivery
// is a later phase, and it will read these preferences rather than inventing
// its own.
//
// ALERTS vs NOTIFICATIONS — the distinction this phase draws
//
//   ALERT         A business event on a farm that needs attention.
//                 Lives in `alerts`. Belongs to the FARM, not to a person.
//                 Example: "Antibiotic stock is low."
//                 Already built, working, and UNCHANGED by this phase.
//
//   NOTIFICATION  The delivery of information to a PERSON through a channel.
//                 An alert may produce zero, one or several notifications
//                 depending on who is watching and how they want to hear.
//                 Not built. This file models only the preference for it.
//
// Conflating the two is why notification systems become impossible to reason
// about: the alert must exist and persist regardless of whether anyone was
// successfully emailed about it.
// ============================================================================

require_once __DIR__ . '/tenant.php';

/**
 * Event categories a user can express a preference about.
 *
 * These map onto the business events DFMIS already produces, so the model is
 * grounded in what exists rather than in a speculative taxonomy.
 */
const DFMS_NOTIFICATION_CATEGORIES = [
    'health' => [
        'label'       => 'Health & veterinary',
        'description' => 'Urgent health events, treatments and vet follow-ups.',
    ],
    'inventory' => [
        'label'       => 'Inventory',
        'description' => 'Low stock and reorder warnings.',
    ],
    'production' => [
        'label'       => 'Production',
        'description' => 'Milk yield anomalies and missing daily records.',
    ],
    'system' => [
        'label'       => 'System & account',
        'description' => 'Sign-in activity, membership and settings changes.',
    ],
];

/**
 * Delivery channels.
 *
 * `available` records whether the channel can actually deliver TODAY. All are
 * false except in_app, and in_app is itself only partially true — the alerts
 * page and the topbar badge are the current in-app surface.
 *
 * Marking the unbuilt channels explicitly is deliberate: a preferences screen
 * that lets someone switch on SMS which then silently never arrives is worse
 * than one that says "not yet available".
 */
const DFMS_NOTIFICATION_CHANNELS = [
    'in_app' => ['label' => 'In-app',  'available' => true,  'default' => true],
    'email'  => ['label' => 'Email',   'available' => false, 'default' => false],
    'sms'    => ['label' => 'SMS',     'available' => false, 'default' => false],
    'push'   => ['label' => 'Push',    'available' => false, 'default' => false],
];

function dfms_notification_categories(): array {
    return DFMS_NOTIFICATION_CATEGORIES;
}

function dfms_notification_channels(): array {
    return DFMS_NOTIFICATION_CHANNELS;
}

function dfms_is_valid_notification_category(?string $category): bool {
    return $category !== null && isset(DFMS_NOTIFICATION_CATEGORIES[$category]);
}

function dfms_is_valid_notification_channel(?string $channel): bool {
    return $channel !== null && isset(DFMS_NOTIFICATION_CHANNELS[$channel]);
}

/**
 * Every preference for the current user on the active farm, as
 * [category][channel] => bool.
 *
 * The table stores DEVIATIONS ONLY. A category/channel pair with no row uses
 * the channel's registry default, so adding a new category later does not
 * require backfilling a row for every user on every farm.
 */
function dfms_notification_preferences(?int $adminId = null, ?int $farmId = null): array {
    $adminId = $adminId ?? currentUserId();
    $farmId = $farmId ?? currentFarmId();

    // Start from defaults so the caller always receives a complete matrix.
    $preferences = [];
    foreach (DFMS_NOTIFICATION_CATEGORIES as $category => $_) {
        foreach (DFMS_NOTIFICATION_CHANNELS as $channel => $meta) {
            $preferences[$category][$channel] = (bool) $meta['default'];
        }
    }

    if ($adminId === null || $farmId === null) {
        return $preferences;
    }

    try {
        $conn = getDBConnection();
        // Scoped to the current user AND the active farm — a user cannot read
        // another person's preferences, nor their own on a farm they are not
        // currently working in.
        $stmt = $conn->prepare(
            "SELECT category, channel, enabled FROM notification_preferences
              WHERE admin_id = ? AND farm_id = ?"
        );
        $stmt->execute([$adminId, $farmId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($preferences[$row['category']][$row['channel']])) {
                $preferences[$row['category']][$row['channel']] = (bool) $row['enabled'];
            }
        }
    } catch (Throwable $e) {
        error_log('Notification preference load failed: ' . $e->getMessage());
    }
    return $preferences;
}

/**
 * Would this user, on this farm, want $category delivered via $channel?
 *
 * A channel that cannot deliver yet always answers false regardless of the
 * stored preference — the preference is retained, but an unavailable channel
 * never reports itself as ready.
 */
function dfms_wants_notification(string $category, string $channel, ?int $adminId = null, ?int $farmId = null): bool {
    if (!dfms_is_valid_notification_category($category) || !dfms_is_valid_notification_channel($channel)) {
        return false;
    }
    if (!DFMS_NOTIFICATION_CHANNELS[$channel]['available']) {
        return false;
    }
    $preferences = dfms_notification_preferences($adminId, $farmId);
    return $preferences[$category][$channel] ?? false;
}

/**
 * Store one preference for the CURRENT user on the ACTIVE farm.
 *
 * The user id and farm id are taken from the tenant context, never from the
 * caller, so this cannot be used to change someone else's preferences or to
 * write a row against another farm.
 */
function dfms_set_notification_preference(string $category, string $channel, bool $enabled): bool {
    if (!dfms_is_valid_notification_category($category)) {
        throw new InvalidArgumentException("Unknown notification category: $category");
    }
    if (!dfms_is_valid_notification_channel($channel)) {
        throw new InvalidArgumentException("Unknown notification channel: $channel");
    }

    $adminId = currentUserId();
    $farmId = currentFarmId();
    if ($adminId === null || $farmId === null) {
        throw new RuntimeException('No tenant context for notification preferences.');
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "INSERT INTO notification_preferences (admin_id, farm_id, category, channel, enabled)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
    );
    $stmt->execute([$adminId, $farmId, $category, $channel, $enabled ? 1 : 0]);

    if (function_exists('auditLog')) {
        auditLog('notification.preference_update', 'notification_preference', null, null, [
            'category' => $category,
            'channel'  => $channel,
            'enabled'  => $enabled,
        ]);
    }
    return true;
}
