<?php
// ============================================================================
// CHANGE AN ANIMAL'S HEALTH STATUS.
//
// Every change writes a health_status_events row in the same transaction as the
// column update, so the current status can never disagree with its own history.
// That is the Phase 3 lifecycle pattern, applied to health.
//
// LIFECYCLE IS AUTHORITATIVE. An animal recorded as sold, transferred, deceased
// or retired refuses every health change — a deceased animal cannot become
// "recovered". The correction path is the Phase 3 lifecycle page, which leaves
// the original mistake visible rather than erasing it.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.create');

$conn = getDBConnection();
$animalId = dfms_route_id('id');
$error = '';

// 404s for another tenant's animal, indistinguishable from "does not exist".
$animal = animalFindOr404($conn, $animalId, true);
$blocked = healthLifecycleBlocks($animal);
$history = healthStatusHistory($conn, $animalId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $newStatus = $v->enum('health_status', array_keys(DFMS_HEALTH_STATUSES));
    $reason = $v->string('reason', 0, 150, ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            healthSetStatus($conn, $animalId, (string) $newStatus, [
                'reason' => $reason,
                'notes'  => $notes,
            ]);
            redirectWith('modules/cows/view.php?id=' . $animalId . '&tab=health', 'success',
                'Health status updated to ' . dfms_health_status_label((string) $newStatus) . '.');
        } catch (HealthRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not update the health status');
        }
    }

    $animal = animalFindOr404($conn, $animalId, true);
    $history = healthStatusHistory($conn, $animalId);
}

$displayName = animalDisplayName($animal);
$current = $animal['health_status'] ?? 'healthy';

$page_title = 'Health status — ' . $displayName;
$page_breadcrumb = [
    ['label' => 'Livestock', 'url' => BASE_URL . 'modules/cows/'],
    ['label' => $displayName, 'url' => BASE_URL . 'modules/cows/view.php?id=' . $animalId],
    ['label' => 'Health status'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .status-blocked {
        border-left: 3px solid var(--danger);
        background: var(--danger-bg);
        color: var(--danger-fg);
        padding: var(--space-3) var(--space-4);
        border-radius: var(--radius-sm);
        font-size: var(--text-sm);
        margin-bottom: var(--space-5);
    }
    .status-history { list-style: none; margin: 0; padding: 0; }
    .status-history li {
        display: grid;
        grid-template-columns: 10rem 1fr;
        gap: var(--space-4);
        padding: var(--space-3) 0;
        border-top: 1px solid var(--border);
    }
    .status-history li:first-child { border-top: none; }
    .status-history time { font-size: var(--text-sm); color: var(--text-muted); font-variant-numeric: tabular-nums; }
    @media (max-width: 640px) {
        .status-history li { grid-template-columns: 1fr; gap: var(--space-1); }
    }
</style>

<div class="dfms-page-header">
    <div>
        <p class="eyebrow"><?php echo e(dfms_species_label($animal['species'])); ?></p>
        <h1>Health status</h1>
        <p><a href="<?php echo e(BASE_URL); ?>modules/cows/view.php?id=<?php echo (int) $animalId; ?>&amp;tab=health"><?php echo e($displayName); ?></a></p>
    </div>
    <div class="dfms-page-actions"><?php echo healthStatusBadge($current); ?></div>
</div>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if ($blocked): ?>
    <p class="status-blocked"><?php echo e(healthLifecycleBlockMessage($animal)); ?></p>
<?php else: ?>
<section class="dfms-section">
    <div class="dfms-card">
        <div class="dfms-card-header">
            <h2 class="dfms-card-title">Change the status</h2>
            <p class="dfms-card-subtitle">Every change is recorded with who made it and why. Nothing is overwritten.</p>
        </div>
        <div class="dfms-card-body">
            <form method="POST" novalidate>
                <?php csrf_field(); ?>
                <div class="dfms-form-grid">
                    <?php
                    // Only genuinely different statuses are offered — the current
                    // one is excluded because a no-op would write a history row
                    // recording that nothing happened.
                    $options = [];
                    foreach (DFMS_HEALTH_STATUSES as $key => $meta) {
                        if ($key === $current) {
                            continue;
                        }
                        $options[$key] = $meta['label'] . ' — ' . $meta['description'];
                    }
                    healthSelectField('health_status', 'New status', $options, null, [
                        'required'    => true,
                        'placeholder' => 'Select a status',
                    ]);

                    healthInputField('reason', 'Reason', 'text', '', [
                        'maxlength' => 150,
                        'hint'      => 'A short note on why. Shown in the animal\'s timeline.',
                    ]);
                    ?>
                </div>
                <?php healthTextareaField('notes', 'Notes', '', ['rows' => 3, 'maxlength' => 5000]); ?>
                <div class="dfms-form-actions">
                    <a class="dfms-btn dfms-btn-secondary"
                       href="<?php echo e(BASE_URL); ?>modules/cows/view.php?id=<?php echo (int) $animalId; ?>&amp;tab=health">Cancel</a>
                    <button type="submit" class="dfms-btn dfms-btn-primary">Update status</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="dfms-section">
    <div class="dfms-section-header">
        <div><h2>Status history</h2><p>Append-only. A change recorded in error is corrected by recording another.</p></div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php if (empty($history)): ?>
                <p class="dfms-muted" style="margin:0;">
                    No status change has been recorded for this animal. Its current status is the value it has
                    carried since before Phase 4 — unknown history stays unknown.
                </p>
            <?php else: ?>
                <ul class="status-history">
                    <?php foreach ($history as $entry): ?>
                        <li>
                            <time datetime="<?php echo e($entry['effective_at']); ?>"><?php echo e(fmt_instant($entry['effective_at'])); ?></time>
                            <div>
                                <div>
                                    <?php if ($entry['previous_status'] !== null): ?>
                                        <?php echo e(dfms_health_status_label($entry['previous_status'])); ?>
                                        <span aria-hidden="true">&rarr;</span>
                                        <span class="dfms-visually-hidden">changed to</span>
                                    <?php endif; ?>
                                    <?php echo healthStatusBadge($entry['new_status']); ?>
                                </div>
                                <?php if ($entry['reason'] || $entry['notes']): ?>
                                    <p class="dfms-text-sm" style="margin:.3rem 0 0;color:var(--text-muted);">
                                        <?php echo e(trim((string) $entry['reason'] . ' ' . (string) $entry['notes'])); ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($entry['username']): ?>
                                    <p class="dfms-text-sm" style="margin:.2rem 0 0;color:var(--text-muted);">
                                        Recorded by <?php echo e($entry['username']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
