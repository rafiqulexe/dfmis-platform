<?php
// ============================================================================
// ANIMAL PROFILE — the Animal Digital Twin
//
// One page, server-rendered tabs (?tab=). No frontend framework, no client
// router: each tab is a normal URL, so it is linkable, bookmarkable, works
// without JavaScript and degrades to a plain document.
//
// The profile SUMMARISES and LINKS. It does not duplicate the health or file
// modules — those own their detail and are referenced.
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
// Phase 4 — the Health tab renders from healthAnimalSummary() and the health
// registries. animalProfile() loads this file lazily too; requiring it here as
// well makes the page's dependency explicit rather than incidental.
require_once '../../includes/health.php';

requireAuth('cows.view');

$animalId = dfms_route_id('id');
$conn = getDBConnection();

// 404s for another tenant's animal, indistinguishable from "does not exist".
$profile = animalProfile($conn, $animalId);
$animal = $profile['animal'];

$v = new DfmsValidator($_GET);
$tab = $v->enum('tab', ['overview', 'timeline', 'identity', 'parentage', 'health', 'documents', 'photos'], ['default' => 'overview']);
if ($v->fails()) {
    $tab = 'overview';
}

$timelinePage = 1;
$timeline = ['items' => [], 'total' => 0];
if ($tab === 'timeline') {
    $pv = new DfmsValidator($_GET);
    $timelinePage = $pv->integer('page', ['nullable' => true, 'min' => 1, 'max' => 10000]) ?? 1;
    $timeline = animalTimeline($conn, $animalId, $timelinePage, 25);
}

$displayName = animalDisplayName($animal);
$statusMeta = DFMS_LIFECYCLE_STATUSES[$animal['lifecycle_status']] ?? ['label' => $animal['lifecycle_status'], 'tone' => 'neutral'];

$page_title = $displayName;
$page_breadcrumb = [
    ['label' => 'Livestock', 'url' => BASE_URL . 'modules/cows/'],
    ['label' => $displayName],
];
require_once '../../includes/header.php';

// Builds a tab URL preserving the animal id.
function animal_tab_url(int $animalId, string $tab): string {
    return BASE_URL . 'modules/cows/view.php?id=' . $animalId . '&tab=' . $tab;
}
?>

<style>
    /* Scoped to this page. Uses the existing DFM design tokens rather than
       introducing a parallel palette. */
    .animal-tabs {
        display: flex; gap: .25rem; flex-wrap: wrap;
        border-bottom: 1px solid var(--border, #d8e0da);
        margin-bottom: var(--space-6, 1.5rem);
    }
    .animal-tab {
        padding: .6rem 1rem; border-bottom: 2px solid transparent;
        color: var(--text-muted, #59685f); text-decoration: none;
        font-size: .93rem; font-weight: 500; white-space: nowrap;
    }
    /* Removes the ~300ms double-tap delay on touch devices, so a tab responds
       immediately on a phone in a barn. */
    .animal-tab { touch-action: manipulation; }
    .animal-tab:hover { color: var(--text-strong, #141c18); }
    .animal-tab[aria-current="page"] {
        color: var(--primary, #1f6f4a);
        border-bottom-color: var(--primary, #1f6f4a);
    }
    .animal-tab:focus-visible { outline: 2px solid var(--primary, #1f6f4a); outline-offset: 2px; }

    .animal-identity { display: flex; gap: 1.25rem; align-items: flex-start; flex-wrap: wrap; }
    .animal-avatar {
        width: 84px; height: 84px; border-radius: 8px; object-fit: cover;
        background: var(--rule-soft, #e7ece8); display: grid; place-items: center; flex: none;
    }
    .animal-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
    .animal-fact dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted, #59685f); }
    .animal-fact dd { margin: .15rem 0 0; font-size: .95rem; color: var(--text-strong, #141c18); }

    .timeline { list-style: none; padding: 0; margin: 0; }
    .timeline li {
        display: grid; grid-template-columns: 6.5rem 1fr; gap: 1rem;
        padding: .9rem 0; border-top: 1px solid var(--rule-soft, #e7ece8);
    }
    .timeline li:first-child { border-top: none; }
    .timeline-date { font-size: .82rem; color: var(--text-muted, #59685f); font-variant-numeric: tabular-nums; }
    .timeline-title { font-weight: 600; font-size: .95rem; }
    .timeline-detail { font-size: .88rem; color: var(--text-muted, #59685f); margin-top: .15rem; }
    .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; }
    .photo-grid img { width: 100%; height: 150px; object-fit: cover; border-radius: 6px; }

    @media (max-width: 640px) {
        .timeline li { grid-template-columns: 1fr; gap: .2rem; }
    }
</style>

<?php
$profilePhoto = null;
foreach ($profile['photos'] as $photo) {
    if (($photo['document_type'] ?? null) === 'profile' || $profilePhoto === null) {
        $profilePhoto = $photo;
    }
}
?>

<div class="dfms-page-header">
    <div>
        <p class="eyebrow"><?php echo e(dfms_species_label($animal['species'])); ?> ·
            <?php echo e(dfms_animal_sex_label($animal['species'], $animal['sex'])); ?></p>
        <h1><?php echo e($displayName); ?></h1>
        <p>Tag <?php echo e($animal['unique_id']); ?>
            <?php if ($animal['breed']): ?> · <?php echo e($animal['breed']); ?><?php endif; ?>
            · <?php echo e(animalAgeLabel($animal['date_of_birth'])); ?>
        </p>
    </div>
    <div class="dfms-page-actions">
        <?php echo dfms_status_badge_html($animal['lifecycle_status']); ?>
        <?php if (hasPermission('cows.edit')): ?>
            <a href="<?php echo BASE_URL; ?>modules/cows/edit.php?id=<?php echo (int) $animal['cow_id']; ?>"
               class="dfms-btn dfms-btn-secondary">Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php dfms_render_flashes(); ?>

<nav class="animal-tabs" aria-label="Animal sections">
    <?php
    $tabs = [
        'overview'   => 'Overview',
        'timeline'   => 'Timeline',
        'identity'   => 'Identity',
        'parentage'  => 'Parentage',
        'health'     => 'Health',
        'documents'  => 'Documents',
        'photos'     => 'Photos',
    ];
    foreach ($tabs as $key => $label):
        $isActive = $tab === $key;
    ?>
        <a class="animal-tab" href="<?php echo e(animal_tab_url((int) $animal['cow_id'], $key)); ?>"
           <?php echo $isActive ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
    <!-- ===================== OVERVIEW ===================== -->
    <section class="dfms-section">
        <div class="dfms-card">
            <div class="animal-identity">
                <?php if ($profilePhoto !== null): ?>
                    <img class="animal-avatar"
                         src="<?php echo BASE_URL; ?>modules/files/serve.php?id=<?php echo (int) $profilePhoto['file_id']; ?>"
                         alt="Photo of <?php echo e($displayName); ?>">
                <?php else: ?>
                    <span class="animal-avatar" aria-hidden="true"><?php echo dfms_icon('livestock'); ?></span>
                <?php endif; ?>

                <dl class="animal-facts" style="flex:1;">
                    <div class="animal-fact"><dt>Tag</dt><dd><?php echo e($animal['unique_id']); ?></dd></div>
                    <div class="animal-fact"><dt>Species</dt><dd><?php echo e(dfms_species_label($animal['species'])); ?></dd></div>
                    <div class="animal-fact"><dt>Breed</dt><dd><?php echo e($animal['breed'] ?: '—'); ?></dd></div>
                    <div class="animal-fact"><dt>Sex</dt><dd><?php echo e(dfms_animal_sex_label($animal['species'], $animal['sex'])); ?></dd></div>
                    <div class="animal-fact"><dt>Date of birth</dt><dd><?php echo e(fmt_date($animal['date_of_birth'])); ?></dd></div>
                    <div class="animal-fact"><dt>Age</dt><dd><?php echo e(animalAgeLabel($animal['date_of_birth'])); ?></dd></div>
                    <div class="animal-fact"><dt>Location</dt>
                        <dd><?php echo e($profile['location']['name'] ?? 'Unassigned'); ?></dd></div>
                    <div class="animal-fact"><dt>Ownership</dt>
                        <dd><?php
                            $ownership = $profile['ownership'];
                            echo e($ownership ? (DFMS_OWNERSHIP_TYPES[$ownership['ownership_type']] ?? $ownership['ownership_type']) : 'Not recorded');
                        ?></dd></div>
                </dl>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-two-col">
            <div class="dfms-card">
                <div class="dfms-stat-label" style="margin-bottom:.75rem;">Current status</div>
                <dl class="animal-facts">
                    <div class="animal-fact"><dt>Lifecycle</dt><dd><?php echo dfms_status_badge_html($animal['lifecycle_status']); ?></dd></div>
                    <div class="animal-fact"><dt>Health</dt><dd><?php echo dfms_status_badge_html($animal['health_status']); ?></dd></div>
                    <?php if (!empty(DFMS_SPECIES[$animal['species']]['dairy_capable'])): ?>
                        <div class="animal-fact"><dt>Reproductive</dt><dd><?php echo dfms_status_badge_html($animal['reproductive_status']); ?></dd></div>
                        <div class="animal-fact"><dt>Lactation</dt><dd><?php echo dfms_status_badge_html($animal['lactation_status']); ?></dd></div>
                    <?php endif; ?>
                </dl>

                <?php if (hasPermission('animal.lifecycle')): ?>
                    <div style="margin-top:1.25rem;">
                        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/cows/lifecycle.php?id=<?php echo (int) $animal['cow_id']; ?>">
                            Change status
                        </a>
                        <?php if (hasPermission('animal.transfer')): ?>
                            <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
                               href="<?php echo BASE_URL; ?>modules/cows/transfer.php?id=<?php echo (int) $animal['cow_id']; ?>">
                                Move or transfer
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dfms-card">
                <div class="dfms-stat-label" style="margin-bottom:.75rem;">Acquisition</div>
                <dl class="animal-facts">
                    <div class="animal-fact"><dt>How acquired</dt>
                        <dd><?php echo e(DFMS_ACQUISITION_TYPES[$animal['acquisition_type']] ?? 'Not recorded'); ?></dd></div>
                    <div class="animal-fact"><dt>Acquired on</dt><dd><?php echo e(fmt_date($animal['acquisition_date'])); ?></dd></div>
                    <div class="animal-fact"><dt>Source</dt><dd><?php echo e($animal['acquisition_source'] ?: '—'); ?></dd></div>
                    <div class="animal-fact"><dt>Birth type</dt>
                        <dd><?php echo e(DFMS_BIRTH_TYPES[$animal['birth_type']] ?? '—'); ?></dd></div>
                </dl>
                <?php if ($animal['acquisition_type'] === null): ?>
                    <p class="dfms-text-muted" style="font-size:.85rem;margin-top:1rem;">
                        Acquisition details were not captured for animals registered before this
                        was recorded. They are left blank rather than guessed.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($animal['notes']): ?>
        <section class="dfms-section">
            <div class="dfms-card">
                <div class="dfms-stat-label" style="margin-bottom:.5rem;">Notes</div>
                <p><?php echo nl2br(e($animal['notes'])); ?></p>
            </div>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'timeline'): ?>
    <!-- ===================== TIMELINE ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Timeline</h2>
                <p>Everything recorded about this animal, newest first.</p>
            </div>
        </div>
        <div class="dfms-card">
            <?php if (empty($timeline['items'])): ?>
                <?php dfms_empty_state('Nothing recorded yet',
                    'Status changes, movements, health events, documents and photos will appear here.'); ?>
            <?php else: ?>
                <ul class="timeline">
                    <?php foreach ($timeline['items'] as $event): ?>
                        <li>
                            <span class="timeline-date"><?php echo e(fmt_date($event['date'])); ?></span>
                            <span>
                                <span class="timeline-title">
                                    <?php echo dfms_badge_html(ucfirst($event['type']), $event['tone']); ?>
                                    <?php echo e($event['title']); ?>
                                </span>
                                <?php if (!empty($event['detail'])): ?>
                                    <span class="timeline-detail"><?php echo e($event['detail']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($event['actor'])): ?>
                                    <span class="timeline-detail">Recorded by <?php echo e($event['actor']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($event['link'])): ?>
                                    <a class="timeline-detail" href="<?php echo e(BASE_URL . $event['link']); ?>">View →</a>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php
                $totalPages = max(1, (int) ceil($timeline['total'] / 25));
                dfms_pagination($timelinePage, $totalPages,
                    BASE_URL . 'modules/cows/view.php?id=' . (int) $animal['cow_id'] . '&tab=timeline',
                    $timeline['total']);
                ?>
            <?php endif; ?>

            <p class="dfms-text-muted" style="font-size:.85rem;margin-top:1rem;">
                Milk production is not shown per animal: production is currently recorded as a
                farm-level daily total, so there is no per-animal figure to attribute.
            </p>
        </div>
    </section>

<?php elseif ($tab === 'identity'): ?>
    <!-- ===================== IDENTITY ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Identifiers</h2>
                <p>Ear tags, RFID, registration numbers and other identifiers for this animal.</p>
            </div>
        </div>
        <div class="dfms-card">
            <?php if (empty($profile['identifiers'])): ?>
                <?php dfms_empty_state('No additional identifiers',
                    'The farm tag ' . $animal['unique_id'] . ' is this animal\'s primary identifier.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Type'], ['label' => 'Value'], ['label' => 'Authority'],
                    ['label' => 'Issued'], ['label' => 'Status'], ['label' => '', 'align' => 'right'],
                ]); ?>
                <?php foreach ($profile['identifiers'] as $identifier): ?>
                    <tr>
                        <?php
                        dfms_cell('Type', e(DFMS_IDENTIFIER_TYPES[$identifier['identifier_type']]['label'] ?? $identifier['identifier_type']));
                        dfms_cell('Value', '<code>' . e($identifier['identifier_value']) . '</code>');
                        dfms_cell('Authority', e($identifier['issuing_authority'] ?: '—'));
                        dfms_cell('Issued', e(fmt_date($identifier['issued_on'])));
                        dfms_cell('Status', dfms_badge_html(ucfirst($identifier['status']),
                            $identifier['status'] === 'active' ? 'success' : 'neutral'));
                        ?>
                        <td class="dfms-cell-actions">
                            <?php if (hasPermission('cows.edit')): ?>
                                <form method="POST" action="<?php echo BASE_URL; ?>modules/cows/identifiers.php" style="display:inline;"
                                      onsubmit="return confirm('Remove this identifier?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="animal_id" value="<?php echo (int) $animal['cow_id']; ?>">
                                    <input type="hidden" name="identifier_id" value="<?php echo (int) $identifier['identifier_id']; ?>">
                                    <button type="submit" class="dfms-btn dfms-btn-secondary dfms-btn-sm">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if (hasPermission('cows.edit')): ?>
        <section class="dfms-section">
            <div class="dfms-card">
                <h2 style="font-size:1.05rem;margin-bottom:1rem;">Add an identifier</h2>
                <form method="POST" action="<?php echo BASE_URL; ?>modules/cows/identifiers.php">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="animal_id" value="<?php echo (int) $animal['cow_id']; ?>">

                    <div class="dfms-two-col">
                        <div class="dfms-field">
                            <label for="identifier_type">Identifier type</label>
                            <select id="identifier_type" name="identifier_type" required>
                                <?php foreach (DFMS_IDENTIFIER_TYPES as $key => $meta): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($meta['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dfms-field">
                            <label for="identifier_value">Value</label>
                            <input id="identifier_value" name="identifier_value" type="text" autocomplete="off" spellcheck="false" maxlength="100" required>
                        </div>
                        <div class="dfms-field">
                            <label for="issuing_authority">Issuing authority <span class="dfms-text-muted">(optional)</span></label>
                            <input id="issuing_authority" name="issuing_authority" type="text" autocomplete="off" maxlength="150">
                        </div>
                        <div class="dfms-field">
                            <label for="issued_on">Issued on <span class="dfms-text-muted">(optional)</span></label>
                            <input id="issued_on" name="issued_on" type="date">
                        </div>
                    </div>
                    <button type="submit" class="dfms-btn dfms-btn-primary">Add identifier</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'parentage'): ?>
    <!-- ===================== PARENTAGE ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Parentage</h2>
                <p>A parent may be an animal in DFMIS or an external animal recorded by name.</p>
            </div>
        </div>
        <div class="dfms-two-col">
            <?php foreach ([['dam', 'Mother (dam)'], ['sire', 'Father (sire)']] as [$role, $label]): ?>
                <div class="dfms-card">
                    <div class="dfms-stat-label" style="margin-bottom:.6rem;"><?php echo e($label); ?></div>
                    <?php $parent = $profile['parents'][$role]; ?>
                    <?php if ($parent !== null): ?>
                        <p>
                            <a href="<?php echo BASE_URL; ?>modules/cows/view.php?id=<?php echo (int) $parent['cow_id']; ?>">
                                <?php echo e(animalDisplayName($parent)); ?>
                            </a>
                            <span class="dfms-text-muted">· tag <?php echo e($parent['unique_id']); ?></span>
                        </p>
                        <p class="dfms-text-muted" style="font-size:.85rem;">Managed in DFMIS</p>
                    <?php elseif (!empty($profile['parents'][$role . '_external'])): ?>
                        <p><?php echo e($profile['parents'][$role . '_external']); ?></p>
                        <p class="dfms-text-muted" style="font-size:.85rem;">External — not managed in DFMIS</p>
                    <?php else: ?>
                        <p class="dfms-text-muted">Not recorded</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Offspring</h2>
                <p><?php echo count($profile['offspring']); ?> recorded on this farm.</p>
            </div>
        </div>
        <div class="dfms-card">
            <?php if (empty($profile['offspring'])): ?>
                <?php dfms_empty_state('No offspring recorded',
                    'Offspring appear here when another animal names this one as a parent.'); ?>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Tag'], ['label' => 'Name'], ['label' => 'Sex'], ['label' => 'Born']]); ?>
                <?php foreach ($profile['offspring'] as $child): ?>
                    <tr>
                        <?php
                        dfms_cell('Tag', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $child['cow_id']) . '">'
                            . e($child['unique_id']) . '</a>');
                        dfms_cell('Name', e($child['name'] ?: '—'));
                        dfms_cell('Sex', e(dfms_animal_sex_label($child['species'], $child['sex'])));
                        dfms_cell('Born', e(fmt_date($child['date_of_birth'])));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($animal['genetic_notes']): ?>
        <section class="dfms-section">
            <div class="dfms-card">
                <div class="dfms-stat-label" style="margin-bottom:.5rem;">Genetics notes</div>
                <p><?php echo nl2br(e($animal['genetic_notes'])); ?></p>
                <p class="dfms-text-muted" style="font-size:.85rem;margin-top:.75rem;">
                    Free-form reference only. Structured genetics and breeding analysis are a later phase.
                </p>
            </div>
        </section>
    <?php endif; ?>

<?php elseif ($tab === 'health'): ?>
    <!-- ===================== HEALTH (Phase 4) ===================== -->
    <?php
    // Everything below comes from healthAnimalSummary(), the same function the
    // health module and the API use, so the three surfaces cannot disagree
    // about whether this animal is under treatment or restriction.
    $health = $profile['health'];
    $canHealthCreate = hasPermission('health.create');
    $canHealthObserve = hasPermission('health.observe');
    $canHealthMedicate = hasPermission('health.medicate');
    $animalIdInt = (int) $animal['cow_id'];
    ?>
    <?php healthScopedStyles(); ?>
    <div class="dfms-health-scope">

    <!-- Current standing: the things someone needs to know before touching
         this animal, above everything else on the tab. -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Current standing</h2>
                <p>What applies to this animal right now.</p>
            </div>
            <div class="dfms-page-actions">
                <?php if ($canHealthCreate && !$health['lifecycle_blocks']): ?>
                    <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
                       href="<?php echo BASE_URL; ?>modules/health/status.php?id=<?php echo $animalIdInt; ?>">Change status</a>
                    <a class="dfms-btn dfms-btn-primary dfms-btn-sm"
                       href="<?php echo BASE_URL; ?>modules/health/case_new.php?cow_id=<?php echo $animalIdInt; ?>">Open a case</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($health['lifecycle_blocks']): ?>
            <p style="border-left:3px solid var(--danger);background:var(--danger-bg);color:var(--danger-fg);padding:.75rem 1rem;border-radius:var(--radius-sm);font-size:var(--text-sm);">
                <?php echo e(healthLifecycleBlockMessage($animal)); ?>
            </p>
        <?php endif; ?>

        <div class="dfms-card">
            <div class="dfms-card-body">
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
                    <?php echo dfms_badge_html(dfms_health_status_label($health['health_status']),
                        dfms_health_status_tone($health['health_status']), true); ?>
                    <?php if ($health['active_quarantine'] !== null): ?>
                        <?php echo dfms_badge_html('In quarantine', 'danger', true); ?>
                    <?php endif; ?>
                    <?php foreach ($health['active_withdrawals'] as $withdrawal): ?>
                        <?php echo dfms_badge_html(
                            (DFMS_WITHDRAWAL_TYPES[$withdrawal['restriction_type']]['label'] ?? $withdrawal['restriction_type'])
                            . ' withheld · ' . healthWithdrawalDaysRemaining($withdrawal) . 'd left', 'warning', true); ?>
                    <?php endforeach; ?>
                    <?php if (empty($health['active_withdrawals']) && $health['active_quarantine'] === null
                              && empty($health['active_cases'])): ?>
                        <?php echo dfms_badge_html('No active health concerns', 'success'); ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($health['active_withdrawals'])): ?>
                    <p class="dfms-hint" style="margin-bottom:1rem;">
                        Withdrawal end dates are inclusive. DFMIS records these restrictions per animal but cannot
                        verify them against production, because milk is recorded as a farm-level daily total.
                    </p>
                <?php endif; ?>

                <?php if ($health['active_quarantine'] !== null): ?>
                    <p style="margin:0 0 1rem;">
                        <strong>Isolated since <?php echo e(fmt_instant($health['active_quarantine']['started_at'])); ?></strong>
                        — <?php echo e($health['active_quarantine']['reason']); ?>
                        <?php if ($health['active_quarantine']['location_name']): ?>
                            (<?php echo e($health['active_quarantine']['location_name']); ?>)
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if (!$health['lifecycle_blocks']): ?>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                    <?php if ($canHealthObserve): ?>
                        <a class="dfms-btn dfms-btn-ghost dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/health/observation.php?cow_id=<?php echo $animalIdInt; ?>">Record observation</a>
                    <?php endif; ?>
                    <?php if ($canHealthMedicate): ?>
                        <a class="dfms-btn dfms-btn-ghost dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/health/medication.php?cow_id=<?php echo $animalIdInt; ?>">Give medication</a>
                        <a class="dfms-btn dfms-btn-ghost dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/health/vaccination.php?cow_id=<?php echo $animalIdInt; ?>">Vaccination</a>
                    <?php endif; ?>
                    <?php if ($canHealthCreate): ?>
                        <a class="dfms-btn dfms-btn-ghost dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/health/consultation.php?cow_id=<?php echo $animalIdInt; ?>">Vet consultation</a>
                        <a class="dfms-btn dfms-btn-ghost dfms-btn-sm"
                           href="<?php echo BASE_URL; ?>modules/health/quarantine.php?cow_id=<?php echo $animalIdInt; ?>">Quarantine</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Active cases -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div><h2>Active cases</h2><p>Open episodes of illness or injury.</p></div>
        </div>
        <div class="dfms-card">
            <?php if (empty($health['active_cases'])): ?>
                <?php dfms_empty_state('No active cases', 'Nothing is currently being followed up for this animal.'); ?>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Case'], ['label' => 'Type'], ['label' => 'Severity'],
                    ['label' => 'Status'], ['label' => 'Opened'], ['label' => '']]); ?>
                <?php foreach ($health['active_cases'] as $case): ?>
                    <?php $cMeta = DFMS_CASE_STATUSES[$case['status']] ?? ['label' => $case['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Case', '<span class="dfms-cell-strong">' . e($case['case_reference']) . '</span><br>'
                            . '<span class="dfms-text-sm">' . e($case['title']) . '</span>');
                        dfms_cell('Type', e(DFMS_CASE_TYPES[$case['case_type']] ?? $case['case_type']));
                        dfms_cell('Severity', dfms_badge_html(ucfirst($case['severity']),
                            $case['severity'] === 'urgent' ? 'danger' : ($case['severity'] === 'moderate' ? 'warning' : 'neutral')));
                        dfms_cell('Status', dfms_badge_html($cMeta['label'], $cMeta['tone'], true));
                        dfms_cell('Opened', e(fmt_date($case['opened_on'])));
                        dfms_cell('', '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                            . e(BASE_URL . 'modules/health/case.php?id=' . (int) $case['case_id'])
                            . '">Open<span class="dfms-visually-hidden"> case ' . e($case['case_reference']) . '</span></a>',
                            'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Treatments in progress -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div><h2>Treatments in progress</h2><p>Completed and cancelled courses remain in the timeline.</p></div>
        </div>
        <div class="dfms-card">
            <?php if (empty($health['active_treatments'])): ?>
                <?php dfms_empty_state('No treatment in progress', 'This animal is not currently under treatment.'); ?>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Treatment'], ['label' => 'Type'], ['label' => 'Started'],
                    ['label' => 'Status'], ['label' => '']]); ?>
                <?php foreach ($health['active_treatments'] as $treatment): ?>
                    <?php $tMeta = DFMS_TREATMENT_STATUSES[$treatment['status']] ?? ['label' => $treatment['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Treatment', '<span class="dfms-cell-strong">' . e($treatment['name']) . '</span>');
                        dfms_cell('Type', e(DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? $treatment['treatment_type']));
                        dfms_cell('Started', e(fmt_date($treatment['started_on'])));
                        dfms_cell('Status', dfms_badge_html($tMeta['label'], $tMeta['tone'], true));
                        dfms_cell('', $canHealthCreate && !$health['lifecycle_blocks']
                            ? '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                              . e(BASE_URL . 'modules/health/treatment.php?close=' . (int) $treatment['treatment_id'])
                              . '">Close out<span class="dfms-visually-hidden"> ' . e($treatment['name']) . '</span></a>'
                            : '', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Vaccination -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div><h2>Vaccination</h2><p>Upcoming doses. A scheduled dose is never marked given automatically.</p></div>
        </div>
        <div class="dfms-card">
            <div class="dfms-card-body">
                <?php if ($health['vaccination_count'] === 0): ?>
                    <!-- The honest wording. "No records" is not the same claim as
                         "unvaccinated", and stating the second would be inventing
                         medical history this system does not have. -->
                    <p class="dfms-muted" style="margin:0 0 1rem;">
                        No vaccination has been recorded for this animal in DFMIS. That means its vaccination history
                        is <strong>unknown</strong> here — not that it is unvaccinated.
                    </p>
                <?php else: ?>
                    <p class="dfms-text-sm" style="margin:0 0 1rem;color:var(--text-muted);">
                        <?php echo e($health['vaccination_count']); ?> dose<?php echo $health['vaccination_count'] === 1 ? '' : 's'; ?>
                        recorded. The full history is on the Timeline tab.
                    </p>
                <?php endif; ?>

                <?php if (empty($health['upcoming_vaccinations'])): ?>
                    <p class="dfms-muted" style="margin:0;">Nothing scheduled.</p>
                <?php else: ?>
                    <?php dfms_table_open([['label' => 'Vaccine'], ['label' => 'Protects against'], ['label' => 'Scheduled'], ['label' => '']]); ?>
                    <?php foreach ($health['upcoming_vaccinations'] as $vaccination): ?>
                        <tr>
                            <?php
                            dfms_cell('Vaccine', '<span class="dfms-cell-strong">' . e($vaccination['vaccine_name']) . '</span>');
                            dfms_cell('Protects against', e($vaccination['disease_target'] ?: '—'));
                            dfms_cell('Scheduled', e(fmt_date($vaccination['scheduled_on'])));
                            dfms_cell('', $canHealthMedicate && !$health['lifecycle_blocks']
                                ? '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                                  . e(BASE_URL . 'modules/health/vaccination.php?given=' . (int) $vaccination['vaccination_id'])
                                  . '">Mark given<span class="dfms-visually-hidden"> — ' . e($vaccination['vaccine_name']) . '</span></a>'
                                : '', 'dfms-cell-actions');
                            ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php dfms_table_close(); ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Recent observations -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div><h2>Recent observations</h2><p>Values are shown in this farm's measurement system.</p></div>
        </div>
        <div class="dfms-card">
            <?php if (empty($health['recent_observations'])): ?>
                <?php dfms_empty_state('No observations', 'Temperatures, weights and clinical signs appear here once recorded.'); ?>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Measurement'], ['label' => 'Value'], ['label' => 'When'], ['label' => 'By']]); ?>
                <?php foreach ($health['recent_observations'] as $observation): ?>
                    <tr>
                        <?php
                        dfms_cell('Measurement', e(DFMS_OBSERVATION_TYPES[$observation['observation_type']]['label']
                            ?? $observation['observation_type']));
                        dfms_cell('Value', '<span class="dfms-cell-strong">' . e(healthFormatObservation($observation)) . '</span>');
                        dfms_cell('When', e(fmt_instant($observation['observed_at'])));
                        dfms_cell('By', e($observation['username'] ?: '—'));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Laboratory -->
    <?php if (!empty($health['recent_lab_tests'])): ?>
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div><h2>Laboratory tests</h2><p>Reports are served only through the access-controlled file handler.</p></div>
        </div>
        <div class="dfms-card">
            <?php dfms_table_open([['label' => 'Test'], ['label' => 'Laboratory'], ['label' => 'Sampled'], ['label' => 'Result']]); ?>
            <?php foreach ($health['recent_lab_tests'] as $test): ?>
                <tr>
                    <?php
                    dfms_cell('Test', '<span class="dfms-cell-strong">' . e($test['test_name']) . '</span>');
                    dfms_cell('Laboratory', e($test['laboratory_name'] ?: '—'));
                    dfms_cell('Sampled', e(fmt_date($test['sample_taken_on'])));
                    dfms_cell('Result', $test['result_status'] === 'pending'
                        ? dfms_badge_html('Pending', 'neutral')
                        : ($test['result_code']
                            ? dfms_badge_html(DFMS_LAB_RESULT_CODES[$test['result_code']] ?? $test['result_code'],
                                in_array($test['result_code'], ['positive', 'abnormal'], true) ? 'danger' : 'success')
                            : e(trim((string) $test['result_numeric'] . ' ' . (string) $test['result_unit']) ?: '—')));
                    ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- The pre-Phase-4 free-form event log, preserved. -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Health event log</h2>
                <p>Free-form dated notes. The five most recent.</p>
            </div>
            <?php if (hasPermission('health.create')): ?>
                <a class="dfms-btn dfms-btn-secondary dfms-btn-sm" href="<?php echo BASE_URL; ?>modules/health/add.php">Log event</a>
            <?php endif; ?>
        </div>
        <div class="dfms-card">
            <?php if (empty($profile['recentHealth'])): ?>
                <?php dfms_empty_state('No health events', 'Nothing has been logged for this animal yet.'); ?>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Date'], ['label' => 'Type'], ['label' => 'Severity'], ['label' => 'Description']]); ?>
                <?php foreach ($profile['recentHealth'] as $event): ?>
                    <tr>
                        <?php
                        dfms_cell('Date', e(fmt_date($event['event_date'])));
                        dfms_cell('Type', e($event['event_type']));
                        dfms_cell('Severity', dfms_badge_html(ucfirst($event['severity']),
                            $event['severity'] === 'urgent' ? 'danger' : ($event['severity'] === 'moderate' ? 'warning' : 'neutral')));
                        dfms_cell('Description', e($event['description'] ?: '—'));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
                <p style="margin-top:1rem;">
                    <a href="<?php echo BASE_URL; ?>modules/health/">View the full health module &rarr;</a>
                </p>
            <?php endif; ?>
        </div>
    </section>

    </div>

<?php elseif ($tab === 'documents' || $tab === 'photos'): ?>
    <!-- ===================== DOCUMENTS / PHOTOS ===================== -->
    <?php
    $isPhotos = $tab === 'photos';
    $files = $isPhotos ? $profile['photos'] : $profile['documents'];
    ?>
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2><?php echo $isPhotos ? 'Photos' : 'Documents'; ?></h2>
                <p>
                    Stored outside the web root and served only through an access-controlled handler.
                    <?php if ($isPhotos): ?>
                        Photos are structured data for future analysis — no image analysis is performed today.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="dfms-card">
            <?php if (empty($files)): ?>
                <?php dfms_empty_state($isPhotos ? 'No photos yet' : 'No documents yet',
                    $isPhotos ? 'Upload a photo below.' : 'Upload a registration or health document below.'); ?>
            <?php elseif ($isPhotos): ?>
                <div class="photo-grid">
                    <?php foreach ($files as $file): ?>
                        <figure style="margin:0;">
                            <img src="<?php echo BASE_URL; ?>modules/files/serve.php?id=<?php echo (int) $file['file_id']; ?>"
                                 alt="<?php echo e($file['caption'] ?: ('Photo of ' . $displayName)); ?>">
                            <figcaption class="dfms-text-muted" style="font-size:.82rem;margin-top:.35rem;">
                                <?php echo e($file['caption'] ?: $file['original_name']); ?><br>
                                <?php echo e(fmt_date(substr((string) $file['created_at'], 0, 10))); ?>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Type'], ['label' => 'File'], ['label' => 'Added'], ['label' => '', 'align' => 'right']]); ?>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <?php
                        dfms_cell('Type', e(DFMS_DOCUMENT_TYPES[$file['document_type']] ?? 'Other'));
                        dfms_cell('File', e($file['caption'] ?: $file['original_name']));
                        dfms_cell('Added', e(fmt_date(substr((string) $file['created_at'], 0, 10))));
                        dfms_cell('', '<a class="dfms-btn dfms-btn-secondary dfms-btn-sm" href="'
                            . e(BASE_URL . 'modules/files/serve.php?id=' . (int) $file['file_id']) . '">Open</a>',
                            'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if (hasPermission('files.upload')): ?>
        <section class="dfms-section">
            <div class="dfms-card">
                <h2 style="font-size:1.05rem;margin-bottom:1rem;">
                    Upload <?php echo $isPhotos ? 'a photo' : 'a document'; ?>
                </h2>
                <form method="POST" action="<?php echo BASE_URL; ?>modules/cows/files.php" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="animal_id" value="<?php echo (int) $animal['cow_id']; ?>">
                    <input type="hidden" name="category" value="<?php echo $isPhotos ? 'animal_photos' : 'documents'; ?>">

                    <div class="dfms-field">
                        <label for="upload">Image file</label>
                        <input id="upload" name="upload" type="file" accept="image/jpeg,image/png,image/webp" required
                               aria-describedby="upload-help">
                        <small id="upload-help" class="dfms-text-muted">
                            JPEG, PNG or WebP, up to 8 MB. The file type is verified from its contents,
                            not its name.
                        </small>
                    </div>

                    <?php if (!$isPhotos): ?>
                        <div class="dfms-field">
                            <label for="document_type">Document type</label>
                            <select id="document_type" name="document_type" required>
                                <?php foreach (DFMS_DOCUMENT_TYPES as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="dfms-field">
                            <label for="document_type">Photo category</label>
                            <select id="document_type" name="document_type" required>
                                <?php foreach (DFMS_PHOTO_CATEGORIES as $key => $label): ?>
                                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="dfms-field">
                        <label for="caption">Caption <span class="dfms-text-muted">(optional)</span></label>
                        <input id="caption" name="caption" type="text" autocomplete="off" maxlength="255">
                    </div>

                    <button type="submit" class="dfms-btn dfms-btn-primary">Upload</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
