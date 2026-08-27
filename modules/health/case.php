<?php
// ============================================================================
// HEALTH CASE DETAIL — the clinical workspace for one episode.
//
// Server-rendered sections with PROGRESSIVE DISCLOSURE: the record is always
// visible, and each "add" form lives inside a collapsed <details> so the page
// opens as a readable clinical summary rather than a wall of empty inputs.
// <details> is native HTML — no framework, no JavaScript, and it still works
// with the keyboard and a screen reader when scripting is off.
//
// Symptoms and diagnoses are added inline because they are two or three fields
// each. Medication, quarantine, treatment and lab work have their own pages:
// they are longer, and medication in particular consumes stock and creates a
// food-safety restriction, which deserves its own screen and its own review.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.view');

$conn = getDBConnection();
$caseId = dfms_route_id('id');
$error = '';

// 404s for another tenant's case, indistinguishable from "does not exist".
$detail = healthCaseDetail($conn, $caseId);
$case = $detail['case'];
$animal = $detail['animal'];

$canCreate = hasPermission('health.create');
$canMedicate = hasPermission('health.medicate');
$canObserve = hasPermission('health.observe');
$lifecycleBlocked = healthLifecycleBlocks($animal);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    requirePermission('health.create');

    $v = new DfmsValidator($_POST);
    $action = $v->enum('action', ['add_symptom', 'add_diagnosis', 'update_diagnosis', 'update_case']);

    try {
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif ($action === 'add_symptom') {
            $code = $v->enum('symptom_code', array_keys(DFMS_SYMPTOMS));
            $label = $v->string('symptom_label', 0, 120, ['nullable' => true]);
            $severity = $v->enum('severity', array_keys(DFMS_SYMPTOM_SEVERITIES), ['default' => 'mild']);
            $onset = $v->datetime('onset_at', ['nullable' => true]);
            $notes = $v->text('notes', 500, ['nullable' => true]);
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthAddSymptom($conn, $caseId, [
                    'symptom_code'  => $code,
                    'symptom_label' => $label,
                    'severity'      => $severity,
                    'onset_at'      => $onset,
                    'notes'         => $notes,
                ]);
                redirectWith('modules/health/case.php?id=' . $caseId, 'success', 'Symptom recorded.');
            }
        } elseif ($action === 'add_diagnosis') {
            $code = $v->enum('condition_code', array_keys(DFMS_CONDITIONS));
            $label = $v->string('condition_label', 0, 150, ['nullable' => true]);
            $status = $v->enum('status', array_keys(DFMS_DIAGNOSIS_STATUSES), ['default' => 'suspected']);
            $confidence = $v->enum('confidence', array_keys(DFMS_DIAGNOSIS_CONFIDENCE), ['nullable' => true]);
            $diagnosedOn = $v->date('diagnosed_on', ['max' => farmToday()]);
            $consultationId = $v->id('consultation_id', ['nullable' => true]);
            $notes = $v->text('diagnosis_notes', 5000, ['nullable' => true]);
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthAddDiagnosis($conn, $caseId, [
                    'condition_code'  => $code,
                    'condition_label' => $label,
                    'status'          => $status,
                    'confidence'      => $confidence,
                    'diagnosed_on'    => $diagnosedOn,
                    'consultation_id' => $consultationId,
                    'notes'           => $notes,
                ]);
                redirectWith('modules/health/case.php?id=' . $caseId, 'success', 'Diagnosis recorded.');
            }
        } elseif ($action === 'update_diagnosis') {
            $diagnosisId = $v->id('diagnosis_id');
            $status = $v->enum('diagnosis_status', array_keys(DFMS_DIAGNOSIS_STATUSES));
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthUpdateDiagnosisStatus($conn, (int) $diagnosisId, (string) $status);
                redirectWith('modules/health/case.php?id=' . $caseId, 'success', 'Diagnosis updated.');
            }
        } else {
            $status = $v->enum('case_status', array_keys(DFMS_CASE_STATUSES));
            $outcome = $v->enum('outcome', array_keys(DFMS_CASE_OUTCOMES), ['nullable' => true]);
            $severity = $v->enum('case_severity', DFMS_CASE_SEVERITIES, ['nullable' => true]);
            $followUpOn = $v->date('follow_up_on', ['nullable' => true, 'allow_future' => true]);
            $healthStatus = $v->enum('health_status', array_keys(DFMS_HEALTH_STATUSES), ['nullable' => true]);
            $closedOn = $v->date('closed_on', ['nullable' => true, 'max' => farmToday()]);
            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthUpdateCase($conn, $caseId, [
                    'status'        => $status,
                    'outcome'       => $outcome,
                    'severity'      => $severity ?? $case['severity'],
                    'follow_up_on'  => $followUpOn,
                    'closed_on'     => $closedOn,
                    'health_status' => $healthStatus,
                ]);
                redirectWith('modules/health/case.php?id=' . $caseId, 'success', 'Case updated.');
            }
        }
    } catch (HealthRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save that change');
    }

    // Reload so the page reflects any partial state after a failure.
    $detail = healthCaseDetail($conn, $caseId);
    $case = $detail['case'];
}

$statusMeta = DFMS_CASE_STATUSES[$case['status']] ?? ['label' => $case['status'], 'tone' => 'neutral'];
$displayName = animalDisplayName($animal);

$page_title = $case['case_reference'] . ' — ' . $case['title'];
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => $case['case_reference']],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .case-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: var(--space-4);
        margin-bottom: var(--space-5);
    }
    .case-meta dt { font-size: var(--text-xs); color: var(--text-muted); text-transform: uppercase; letter-spacing: var(--tracking-wide); }
    .case-meta dd { margin-top: var(--space-1); font-size: var(--text-md); font-weight: 500; }
    .case-add { margin-top: var(--space-4); border-top: 1px solid var(--border); padding-top: var(--space-4); }
    .case-add > summary {
        cursor: pointer;
        font-weight: 600;
        font-size: var(--text-sm);
        color: var(--primary);
        padding: var(--space-2) 0;
        list-style: revert;
    }
    .case-add > summary:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: var(--radius-sm); }
    .case-add[open] > summary { margin-bottom: var(--space-4); }
    .case-quick { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-5); }
    .case-blocked {
        border-left: 3px solid var(--danger);
        background: var(--danger-bg);
        color: var(--danger-fg);
        padding: var(--space-3) var(--space-4);
        border-radius: var(--radius-sm);
        font-size: var(--text-sm);
        margin-bottom: var(--space-5);
    }
    @media (max-width: 640px) {
        .case-quick .dfms-btn { flex: 1 1 100%; justify-content: center; }
    }
</style>

<div class="dfms-page-header">
    <div>
        <p class="eyebrow"><?php echo e($case['case_reference']); ?> ·
            <?php echo e(DFMS_CASE_TYPES[$case['case_type']] ?? $case['case_type']); ?></p>
        <h1><?php echo e($case['title']); ?></h1>
        <p>
            <a href="<?php echo e(BASE_URL); ?>modules/cows/view.php?id=<?php echo (int) $animal['cow_id']; ?>&amp;tab=health"><?php echo e($displayName); ?></a>
            · <?php echo e(dfms_species_label($animal['species'])); ?>
            · opened <?php echo e(fmt_date($case['opened_on'])); ?>
        </p>
    </div>
    <div class="dfms-page-actions">
        <?php echo dfms_badge_html($statusMeta['label'], $statusMeta['tone'], true); ?>
        <?php echo dfms_badge_html(ucfirst($case['severity']),
            $case['severity'] === 'urgent' ? 'danger' : ($case['severity'] === 'moderate' ? 'warning' : 'neutral')); ?>
        <?php if ((int) $case['is_contagious'] === 1): ?>
            <?php echo dfms_badge_html('Treated as contagious', 'danger'); ?>
        <?php endif; ?>
    </div>
</div>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if ($lifecycleBlocked): ?>
    <p class="case-blocked"><?php echo e(healthLifecycleBlockMessage($animal)); ?></p>
<?php endif; ?>

<?php if (!$lifecycleBlocked): ?>
<div class="case-quick">
    <?php if ($canCreate): ?>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/treatment.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Start a treatment</a>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/consultation.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Record a vet consultation</a>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/lab.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Record a lab test</a>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/quarantine.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Start quarantine</a>
    <?php endif; ?>
    <?php if ($canMedicate): ?>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/medication.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Administer medication</a>
    <?php endif; ?>
    <?php if ($canObserve): ?>
        <a class="dfms-btn dfms-btn-secondary dfms-btn-sm"
           href="<?php echo e(BASE_URL); ?>modules/health/observation.php?cow_id=<?php echo (int) $animal['cow_id']; ?>&amp;case_id=<?php echo (int) $case['case_id']; ?>">Record an observation</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<section class="dfms-section">
    <div class="dfms-card">
        <div class="dfms-card-body">
            <dl class="case-meta">
                <div><dt>Status</dt><dd><?php echo e($statusMeta['label']); ?></dd></div>
                <div><dt>Opened</dt><dd><?php echo e(fmt_date($case['opened_on'])); ?></dd></div>
                <div><dt>Follow-up</dt><dd><?php echo $case['follow_up_on'] ? e(fmt_date($case['follow_up_on'])) : '<span class="dfms-muted">None set</span>'; ?></dd></div>
                <div><dt>Closed</dt><dd><?php echo $case['closed_on'] ? e(fmt_date($case['closed_on'])) : '<span class="dfms-muted">Still open</span>'; ?></dd></div>
                <div><dt>Outcome</dt><dd><?php echo $case['outcome'] ? e(DFMS_CASE_OUTCOMES[$case['outcome']] ?? $case['outcome']) : '<span class="dfms-muted">Not yet recorded</span>'; ?></dd></div>
            </dl>
            <?php if ($case['notes']): ?>
                <p style="white-space:pre-wrap;margin:0;"><?php echo e($case['notes']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== SYMPTOMS ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Symptoms</h2>
            <p>Signs observed on this animal during this episode.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php if (empty($detail['symptoms'])): ?>
                <p class="dfms-muted" style="margin:0;">No symptoms recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Symptom'], ['label' => 'Severity'], ['label' => 'Onset'], ['label' => 'Notes']]); ?>
                <?php foreach ($detail['symptoms'] as $symptom): ?>
                    <tr>
                        <?php
                        dfms_cell('Symptom', '<span class="dfms-cell-strong">' . e(dfms_symptom_label($symptom)) . '</span>'
                            . ($symptom['symptom_code'] === 'other' ? ' ' . dfms_badge_html('Uncoded', 'neutral') : ''));
                        dfms_cell('Severity', dfms_badge_html(
                            DFMS_SYMPTOM_SEVERITIES[$symptom['severity']] ?? $symptom['severity'],
                            $symptom['severity'] === 'severe' ? 'danger' : ($symptom['severity'] === 'moderate' ? 'warning' : 'neutral')));
                        dfms_cell('Onset', $symptom['onset_at'] ? e(fmt_instant($symptom['onset_at'])) : '<span class="dfms-muted">Unknown</span>');
                        dfms_cell('Notes', e($symptom['notes'] ?: '—'));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <?php if ($canCreate && !$lifecycleBlocked): ?>
            <details class="case-add">
                <summary>Add a symptom</summary>
                <form method="POST" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_symptom">
                    <div class="dfms-form-grid">
                        <?php
                        healthSelectField('symptom_code', 'Symptom', DFMS_SYMPTOMS, null, [
                            'required'    => true,
                            'placeholder' => 'Select a symptom',
                            'id'          => 'symptom_code',
                        ]);
                        healthInputField('symptom_label', 'If "Other", describe it', 'text', '', [
                            'maxlength' => 120,
                            'hint'      => 'Required only when the symptom above is "Other".',
                        ]);
                        healthSelectField('severity', 'Severity', DFMS_SYMPTOM_SEVERITIES, 'mild', ['required' => true]);
                        healthInputField('onset_at', 'When it started', 'datetime-local', '', [
                            'hint' => 'Leave blank if the onset is genuinely unknown — a guess is worse than a gap.',
                        ]);
                        ?>
                    </div>
                    <?php healthTextareaField('notes', 'Notes', '', ['rows' => 2, 'maxlength' => 500]); ?>
                    <div class="dfms-form-actions">
                        <button type="submit" class="dfms-btn dfms-btn-primary dfms-btn-sm">Add symptom</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== DIAGNOSES ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Diagnoses</h2>
            <p>Including possibilities that were considered and ruled out — that is part of the record, not clutter.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php healthClinicalNotice(); ?>
            <?php if (empty($detail['diagnoses'])): ?>
                <p class="dfms-muted" style="margin:0;">No diagnosis recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Condition'], ['label' => 'Status'], ['label' => 'Confidence'],
                    ['label' => 'Date'], ['label' => 'Recorded by'], ['label' => ''],
                ]); ?>
                <?php foreach ($detail['diagnoses'] as $diagnosis): ?>
                    <?php $dStatus = DFMS_DIAGNOSIS_STATUSES[$diagnosis['status']] ?? ['label' => $diagnosis['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Condition', '<span class="dfms-cell-strong">' . e(dfms_condition_label($diagnosis)) . '</span>'
                            . (dfms_condition_is_contagious($diagnosis['condition_code'])
                                ? '<br>' . dfms_badge_html('Registry marks this transmissible', 'warning') : ''));
                        dfms_cell('Status', dfms_badge_html($dStatus['label'], $dStatus['tone'], true));
                        dfms_cell('Confidence', $diagnosis['confidence']
                            ? e(DFMS_DIAGNOSIS_CONFIDENCE[$diagnosis['confidence']])
                            : '<span class="dfms-muted">Not stated</span>');
                        dfms_cell('Date', e(fmt_date($diagnosis['diagnosed_on'])));
                        dfms_cell('Recorded by', e($diagnosis['vet_name'] ?: ($diagnosis['username'] ?: '—')));
                        if ($canCreate && !$lifecycleBlocked && $diagnosis['status'] !== 'ruled_out') {
                            $options = '';
                            foreach (DFMS_DIAGNOSIS_STATUSES as $key => $meta) {
                                if ($key === $diagnosis['status']) {
                                    continue;
                                }
                                $options .= '<option value="' . e($key) . '">' . e($meta['label']) . '</option>';
                            }
                            dfms_cell('', '<form method="POST" style="display:flex;gap:.35rem;">'
                                . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
                                . '<input type="hidden" name="action" value="update_diagnosis">'
                                . '<input type="hidden" name="diagnosis_id" value="' . (int) $diagnosis['diagnosis_id'] . '">'
                                . '<label class="dfms-visually-hidden" for="ds' . (int) $diagnosis['diagnosis_id'] . '">'
                                . 'Change status of ' . e(dfms_condition_label($diagnosis)) . '</label>'
                                . '<select class="dfms-select" id="ds' . (int) $diagnosis['diagnosis_id'] . '" name="diagnosis_status" style="min-width:9rem;">'
                                . $options . '</select>'
                                . '<button type="submit" class="dfms-btn dfms-btn-ghost dfms-btn-sm">Update</button>'
                                . '</form>', 'dfms-cell-actions');
                        } else {
                            dfms_cell('', '', 'dfms-cell-actions');
                        }
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <?php if ($canCreate && !$lifecycleBlocked): ?>
            <details class="case-add">
                <summary>Record a diagnosis</summary>
                <form method="POST" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_diagnosis">
                    <div class="dfms-form-grid">
                        <?php
                        $conditionOptions = [];
                        foreach (DFMS_CONDITIONS as $key => $meta) {
                            $conditionOptions[$key] = $meta['label'];
                        }
                        healthSelectField('condition_code', 'Condition', $conditionOptions, null, [
                            'required' => true, 'placeholder' => 'Select a condition',
                        ]);
                        healthInputField('condition_label', 'If "Other", name it', 'text', '', ['maxlength' => 150]);
                        healthSelectField('status', 'Status', array_map(static function (array $m): string {
                            return $m['label'];
                        }, DFMS_DIAGNOSIS_STATUSES), 'suspected', [
                            'required' => true,
                            'hint'     => 'Suspected is the honest default. A diagnosis is never rewritten — mark it ruled out instead.',
                        ]);
                        healthSelectField('confidence', 'Your confidence', DFMS_DIAGNOSIS_CONFIDENCE, null, [
                            'placeholder' => 'Not stated',
                            'hint'        => 'Your own assessment. DFMIS never computes or scores this.',
                        ]);
                        healthInputField('diagnosed_on', 'Date', 'date', farmToday(), [
                            'required' => true, 'max' => farmToday(),
                        ]);
                        $consultOptions = [];
                        foreach ($detail['consultations'] as $consultation) {
                            $consultOptions[(int) $consultation['consultation_id']] =
                                $consultation['vet_name'] . ' — ' . substr((string) $consultation['consulted_at'], 0, 10);
                        }
                        if ($consultOptions) {
                            healthSelectField('consultation_id', 'From which consultation', $consultOptions, null, [
                                'placeholder' => 'Not from a recorded consultation',
                            ]);
                        }
                        ?>
                    </div>
                    <?php healthTextareaField('diagnosis_notes', 'Notes', '', ['rows' => 3, 'maxlength' => 5000]); ?>
                    <div class="dfms-form-actions">
                        <button type="submit" class="dfms-btn dfms-btn-primary dfms-btn-sm">Record diagnosis</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== TREATMENTS & MEDICATION ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Treatments and medication</h2>
            <p>Completed and cancelled courses stay visible — treatment history is never rewritten.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <?php if (empty($detail['treatments'])): ?>
                <p class="dfms-muted">No treatment recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Treatment'], ['label' => 'Type'], ['label' => 'Started'],
                    ['label' => 'Ended'], ['label' => 'Status'], ['label' => 'Outcome'],
                ]); ?>
                <?php foreach ($detail['treatments'] as $treatment): ?>
                    <?php $tStatus = DFMS_TREATMENT_STATUSES[$treatment['status']] ?? ['label' => $treatment['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Treatment', '<span class="dfms-cell-strong">' . e($treatment['name']) . '</span>');
                        dfms_cell('Type', e(DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? $treatment['treatment_type']));
                        dfms_cell('Started', e(fmt_date($treatment['started_on'])));
                        dfms_cell('Ended', $treatment['ended_on'] ? e(fmt_date($treatment['ended_on'])) : '<span class="dfms-muted">—</span>');
                        dfms_cell('Status', dfms_badge_html($tStatus['label'], $tStatus['tone'], true));
                        dfms_cell('Outcome', $treatment['outcome']
                            ? e(DFMS_TREATMENT_OUTCOMES[$treatment['outcome']] ?? $treatment['outcome'])
                            : '<span class="dfms-muted">—</span>');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <h3 style="font-size:var(--text-md);margin:var(--space-5) 0 var(--space-3);">Medication given</h3>
            <?php if (empty($detail['medications'])): ?>
                <p class="dfms-muted" style="margin:0;">No medication has been administered under this case.</p>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Medication'], ['label' => 'Dose'], ['label' => 'Route'],
                    ['label' => 'Batch'], ['label' => 'Given'],
                ]); ?>
                <?php foreach ($detail['medications'] as $medication): ?>
                    <tr>
                        <?php
                        dfms_cell('Medication', '<span class="dfms-cell-strong">' . e($medication['medication_name']) . '</span>');
                        dfms_cell('Dose', e(fmt_number((float) $medication['dose'], 2) . ' '
                            . (DFMS_DOSE_UNITS[$medication['dose_unit']] ?? $medication['dose_unit'])), 'health-figure');
                        dfms_cell('Route', e(DFMS_ADMIN_ROUTES[$medication['route']] ?? $medication['route']));
                        dfms_cell('Batch', e($medication['batch_lot'] ?: '—'));
                        dfms_cell('Given', e(fmt_instant($medication['administered_at'])));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== OBSERVATIONS, CONSULTATIONS, LAB, QUARANTINE ===================== -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div><h2>Clinical record</h2><p>Observations, consultations, laboratory work and isolation for this case.</p></div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <h3 style="font-size:var(--text-md);margin:0 0 var(--space-3);">Observations</h3>
            <?php if (empty($detail['observations'])): ?>
                <p class="dfms-muted">None recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Measurement'], ['label' => 'Value'], ['label' => 'When'], ['label' => 'By']]); ?>
                <?php foreach ($detail['observations'] as $observation): ?>
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

            <h3 style="font-size:var(--text-md);margin:var(--space-5) 0 var(--space-3);">Veterinary consultations</h3>
            <?php if (empty($detail['consultations'])): ?>
                <p class="dfms-muted">None recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Veterinarian'], ['label' => 'Type'], ['label' => 'When'], ['label' => 'Findings'], ['label' => 'Follow-up']]); ?>
                <?php foreach ($detail['consultations'] as $consultation): ?>
                    <tr>
                        <?php
                        dfms_cell('Veterinarian', '<span class="dfms-cell-strong">' . e($consultation['vet_name']) . '</span>'
                            . ($consultation['vet_practice'] ? '<br><span class="dfms-text-sm">' . e($consultation['vet_practice']) . '</span>' : ''));
                        dfms_cell('Type', e(DFMS_CONSULTATION_TYPES[$consultation['consultation_type']] ?? $consultation['consultation_type']));
                        dfms_cell('When', e(fmt_instant($consultation['consulted_at'])));
                        dfms_cell('Findings', e($consultation['findings'] ?: '—'));
                        dfms_cell('Follow-up', $consultation['follow_up_on'] ? e(fmt_date($consultation['follow_up_on'])) : '<span class="dfms-muted">—</span>');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <h3 style="font-size:var(--text-md);margin:var(--space-5) 0 var(--space-3);">Laboratory tests</h3>
            <?php if (empty($detail['lab_tests'])): ?>
                <p class="dfms-muted">None recorded for this case.</p>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Test'], ['label' => 'Laboratory'], ['label' => 'Sampled'], ['label' => 'Result'], ['label' => 'Report']]); ?>
                <?php foreach ($detail['lab_tests'] as $test): ?>
                    <?php $files = healthLabTestFiles($conn, (int) $test['lab_test_id']); ?>
                    <tr>
                        <?php
                        dfms_cell('Test', '<span class="dfms-cell-strong">' . e($test['test_name']) . '</span><br>'
                            . '<span class="dfms-text-sm">' . e(DFMS_LAB_TEST_TYPES[$test['test_type']] ?? $test['test_type']) . '</span>');
                        dfms_cell('Laboratory', e($test['laboratory_name'] ?: '—'));
                        dfms_cell('Sampled', e(fmt_date($test['sample_taken_on'])));
                        $resultText = $test['result_status'] === 'pending'
                            ? dfms_badge_html('Pending', 'neutral')
                            : ($test['result_code']
                                ? dfms_badge_html(DFMS_LAB_RESULT_CODES[$test['result_code']] ?? $test['result_code'],
                                    in_array($test['result_code'], ['positive', 'abnormal'], true) ? 'danger' : 'success')
                                : e((string) $test['result_numeric'] . ' ' . (string) $test['result_unit']));
                        dfms_cell('Result', $resultText);
                        $links = '';
                        foreach ($files as $file) {
                            $links .= '<a href="' . e(BASE_URL . 'modules/files/serve.php?id=' . (int) $file['file_id']) . '">'
                                . e($file['original_name'] ?: 'Report') . '</a><br>';
                        }
                        dfms_cell('Report', $links ?: '<span class="dfms-muted">—</span>');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>

            <h3 style="font-size:var(--text-md);margin:var(--space-5) 0 var(--space-3);">Quarantine</h3>
            <?php if (empty($detail['quarantines'])): ?>
                <p class="dfms-muted" style="margin:0;">This animal has not been isolated under this case.</p>
            <?php else: ?>
                <?php dfms_table_open([['label' => 'Reason'], ['label' => 'Location'], ['label' => 'Started'], ['label' => 'Ended'], ['label' => 'Status']]); ?>
                <?php foreach ($detail['quarantines'] as $quarantine): ?>
                    <tr>
                        <?php
                        dfms_cell('Reason', e($quarantine['reason']));
                        dfms_cell('Location', e($quarantine['location_name'] ?: 'Not recorded'));
                        dfms_cell('Started', e(fmt_instant($quarantine['started_at'])));
                        dfms_cell('Ended', $quarantine['ended_at'] ? e(fmt_instant($quarantine['ended_at'])) : '<span class="dfms-muted">—</span>');
                        dfms_cell('Status', dfms_badge_html(ucfirst($quarantine['status']),
                            $quarantine['status'] === 'active' ? 'danger' : 'neutral', true));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================== CASE STATUS ===================== -->
<?php if ($canCreate && !$lifecycleBlocked): ?>
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Update the case</h2>
            <p>Resolving or closing a case requires an outcome. "Outcome unknown" is a valid, honest answer.</p>
        </div>
    </div>
    <div class="dfms-card">
        <div class="dfms-card-body">
            <form method="POST" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update_case">
                <div class="dfms-form-grid">
                    <?php
                    healthSelectField('case_status', 'Status', array_map(static function (array $m): string {
                        return $m['label'];
                    }, DFMS_CASE_STATUSES), $case['status'], ['required' => true]);

                    healthSelectField('outcome', 'Outcome', DFMS_CASE_OUTCOMES, $case['outcome'], [
                        'placeholder' => 'Not yet known',
                        'hint'        => 'Required when resolving or closing.',
                    ]);

                    healthSelectField('case_severity', 'Severity', [
                        'routine' => 'Routine', 'moderate' => 'Moderate', 'urgent' => 'Urgent',
                    ], $case['severity']);

                    healthInputField('follow_up_on', 'Follow-up date', 'date', $case['follow_up_on'], [
                        'hint' => 'Clear this once the case is closed.',
                    ]);

                    healthInputField('closed_on', 'Closed on', 'date', $case['closed_on'], [
                        'max'  => farmToday(),
                        'hint' => 'Defaults to today when resolving or closing.',
                    ]);

                    healthSelectField('health_status', 'Set the animal\'s health status to',
                        array_map(static function (array $m): string { return $m['label']; }, DFMS_HEALTH_STATUSES),
                        '', [
                            'placeholder' => 'Leave unchanged',
                            'hint'        => 'Closing a case never changes the animal\'s status on its own.',
                        ]);
                    ?>
                </div>
                <div class="dfms-form-actions">
                    <button type="submit" class="dfms-btn dfms-btn-primary">Save case</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
