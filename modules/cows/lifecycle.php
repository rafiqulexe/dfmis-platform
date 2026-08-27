<?php
// ============================================================================
// ANIMAL LIFECYCLE — record a status transition
//
// Sold, transferred, died, retired, archived — or corrected back to active.
// The transition rules live in includes/livestock.php so this page cannot
// invent its own.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/settings.php';
require_once '../../includes/livestock.php';

requireAuth('animal.lifecycle');

$animalId = dfms_route_id('id');
$conn = getDBConnection();

// Archived animals are included: correcting a wrongly archived animal back to
// active is exactly what this page is for.
$animal = animalFindOr404($conn, $animalId, true);
$current = $animal['lifecycle_status'] ?? 'active';
$allowed = dfms_allowed_transitions($current);

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    // Only transitions valid FROM the current state are accepted — the same
    // list the form offers, re-checked server-side.
    $newStatus = $v->enum('new_status', array_keys($allowed));
    $eventDate = $v->date('event_date', ['nullable' => true]) ?? farmToday();
    $reason = $v->string('reason', 0, 150, ['nullable' => true]);
    $notes = $v->text('notes', 2000, ['nullable' => true]);

    if ($v->fails()) {
        $error_message = $v->firstError();
    } else {
        try {
            animalChangeLifecycleStatus($conn, (int) $animal['cow_id'], $newStatus, [
                'event_date' => $eventDate,
                'reason'     => $reason,
                'notes'      => $notes,
            ]);
            redirectWith('modules/cows/view.php?id=' . (int) $animal['cow_id'],
                'success', 'Status updated to ' . (DFMS_LIFECYCLE_STATUSES[$newStatus]['label'] ?? $newStatus) . '.');
        } catch (InvalidArgumentException $e) {
            // Our own rule message — safe to show verbatim.
            $error_message = $e->getMessage();
        } catch (PDOException $e) {
            $error_message = safeError($e, 'Could not update the status');
        }
    }
}

$history = animalLifecycleHistory($conn, (int) $animal['cow_id']);
$displayName = animalDisplayName($animal);

$page_title = 'Change status';
$page_breadcrumb = [
    ['label' => 'Livestock', 'url' => BASE_URL . 'modules/cows/'],
    ['label' => $displayName, 'url' => BASE_URL . 'modules/cows/view.php?id=' . (int) $animal['cow_id']],
    ['label' => 'Status'],
];
require_once '../../includes/header.php';
?>

<?php dfms_page_header('Change status',
    $displayName . ' is currently ' . strtolower(DFMS_LIFECYCLE_STATUSES[$current]['label'] ?? $current) . '.'); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<section class="dfms-section">
    <div class="dfms-card">
        <?php if (empty($allowed)): ?>
            <?php dfms_alert('This animal has no available status changes.', 'info'); ?>
        <?php else: ?>
            <form method="POST" action="">
                <?php csrf_field(); ?>

                <div class="dfms-field">
                    <label for="new_status">New status</label>
                    <select id="new_status" name="new_status" required aria-describedby="status-help">
                        <?php foreach ($allowed as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small id="status-help" class="dfms-text-muted">
                        <?php if ($current === 'active'): ?>
                            Recording a terminal status removes the animal from the working herd.
                            Its history is kept in full.
                        <?php else: ?>
                            This animal is no longer active. It can be returned to active if the
                            status was recorded in error — the correction is kept in the history
                            rather than replacing it.
                        <?php endif; ?>
                    </small>
                </div>

                <div class="dfms-field">
                    <label for="event_date">Date of change</label>
                    <input id="event_date" name="event_date" type="date"
                           value="<?php echo e(farmToday()); ?>" max="<?php echo e(farmToday()); ?>" required>
                </div>

                <div class="dfms-field">
                    <label for="reason">Reason <span class="dfms-text-muted">(optional)</span></label>
                    <input id="reason" name="reason" type="text" autocomplete="off" maxlength="150"
                           placeholder="e.g. Sold at market…">
                </div>

                <div class="dfms-field">
                    <label for="notes">Notes <span class="dfms-text-muted">(optional)</span></label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea>
                </div>

                <div style="display:flex;gap:.75rem;">
                    <button type="submit" class="dfms-btn dfms-btn-primary">Record status change</button>
                    <a class="dfms-btn dfms-btn-secondary"
                       href="<?php echo BASE_URL; ?>modules/cows/view.php?id=<?php echo (int) $animal['cow_id']; ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Status history</h2>
            <p>Append-only. A correction is recorded as a further change, never by editing the past.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php if (empty($history)): ?>
            <?php dfms_empty_state('No status changes recorded',
                'This animal has been active since it was registered.'); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Date'], ['label' => 'From'], ['label' => 'To'],
                                   ['label' => 'Reason'], ['label' => 'Recorded by']]); ?>
            <?php foreach ($history as $event): ?>
                <tr>
                    <?php
                    dfms_cell('Date', e(fmt_date($event['event_date'])));
                    dfms_cell('From', e(DFMS_LIFECYCLE_STATUSES[$event['previous_status']]['label'] ?? ($event['previous_status'] ?: '—')));
                    dfms_cell('To', dfms_status_badge_html($event['new_status']));
                    dfms_cell('Reason', e($event['reason'] ?: '—'));
                    dfms_cell('Recorded by', e($event['username'] ?? 'system'));
                    ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
