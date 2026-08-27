<?php
// ============================================================================
// HEALTH MODULE — shared bootstrap and render helpers.
//
// Every page in modules/health/ includes this instead of repeating twelve
// require_once lines and its own copy of the animal picker. It defines NO
// business rules: those all live in includes/health.php, which is the single
// place a health rule is written.
//
// DIRECT EXECUTION GUARD
// This file is a partial, not a page. Reaching it directly would run a file
// that has deliberately not called requireAuth() — the including page does
// that, because the required permission differs per page.
// ============================================================================

if (!defined('DFMS_HEALTH_PAGE')) {
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
require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/health.php';

/**
 * Animals this farm can record health against.
 *
 * Terminal-lifecycle animals are EXCLUDED because includes/health.php refuses
 * to write against them — offering a choice the next screen rejects is a
 * worse experience than not offering it. The same rule, enforced once and
 * reflected in the UI rather than duplicated as a second rule.
 */
function healthSelectableAnimals(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT cow_id, unique_id, name, species, health_status
           FROM cows
          WHERE {scope} AND deleted_at IS NULL AND lifecycle_status = 'active'
          ORDER BY unique_id"
    );
}

/** Open cases for one animal, for the "attach this to a case" pickers. */
function healthOpenCasesFor(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT case_id, case_reference, title, status FROM health_cases
          WHERE {scope} AND cow_id = ? AND status IN ('open','monitoring','treating')
          ORDER BY opened_on DESC",
        [$animalId],
        'health_cases'
    );
}

/** The module's tab bar. Each tab is a plain URL — linkable and JS-free. */
function healthRenderTabs(string $active): void {
    $tabs = [
        'cases'        => 'Cases',
        'treatments'   => 'Treatments',
        'vaccinations' => 'Vaccinations',
        'quarantine'   => 'Quarantine',
        'withdrawals'  => 'Withdrawals',
        'events'       => 'Event log',
    ];
    echo '<nav class="dfms-tabs" aria-label="Health sections">';
    foreach ($tabs as $key => $label) {
        $isActive = $key === $active;
        echo '<a class="dfms-tab' . ($isActive ? ' active' : '') . '"'
            . ' href="' . e(BASE_URL . 'modules/health/?tab=' . $key) . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }
    echo '</nav>';
}

/** A labelled <select> built from a key => label registry. */
function healthSelectField(string $name, string $label, array $options, $selected = null, array $config = []): void {
    $id = $config['id'] ?? $name;
    $required = !empty($config['required']);
    echo '<div class="dfms-field">';
    echo '<label class="dfms-label" for="' . e($id) . '">' . e($label);
    if ($required) {
        echo '<span class="dfms-required" aria-hidden="true">*</span>';
    }
    echo '</label>';
    echo '<select class="dfms-select" id="' . e($id) . '" name="' . e($name) . '"'
        . ($required ? ' required' : '')
        . (!empty($config['describedby']) ? ' aria-describedby="' . e($config['describedby']) . '"' : '')
        . (!empty($config['onchange']) ? ' onchange="' . e($config['onchange']) . '"' : '')
        . '>';
    if (array_key_exists('placeholder', $config)) {
        echo '<option value="">' . e($config['placeholder']) . '</option>';
    }
    foreach ($options as $value => $optionLabel) {
        echo '<option value="' . e($value) . '"'
            . ((string) $value === (string) $selected ? ' selected' : '') . '>'
            . e($optionLabel) . '</option>';
    }
    echo '</select>';
    if (!empty($config['hint'])) {
        echo '<p class="dfms-hint" id="' . e($config['describedby'] ?? ($id . '-hint')) . '">' . e($config['hint']) . '</p>';
    }
    echo '</div>';
}

/** A labelled input. `type` covers text/date/datetime-local/number. */
function healthInputField(string $name, string $label, string $type = 'text', $value = null, array $config = []): void {
    $id = $config['id'] ?? $name;
    $required = !empty($config['required']);
    echo '<div class="dfms-field">';
    echo '<label class="dfms-label" for="' . e($id) . '">' . e($label);
    if ($required) {
        echo '<span class="dfms-required" aria-hidden="true">*</span>';
    }
    echo '</label>';
    echo '<input class="dfms-input" type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '"';
    if ($value !== null && $value !== '') {
        echo ' value="' . e($value) . '"';
    }
    // `spellcheck` is passed through so identifier-shaped fields (batch numbers,
    // licence numbers, laboratory reference ranges) can turn it off. A red
    // squiggle under a batch number is noise, and mobile autocorrect actively
    // corrupts one.
    foreach (['min', 'max', 'step', 'maxlength', 'placeholder', 'inputmode', 'autocomplete', 'pattern', 'spellcheck'] as $attribute) {
        if (isset($config[$attribute])) {
            echo ' ' . $attribute . '="' . e($config[$attribute]) . '"';
        }
    }
    if (!empty($config['hint'])) {
        echo ' aria-describedby="' . e($id) . '-hint"';
    }
    echo ($required ? ' required' : '') . '>';
    if (!empty($config['hint'])) {
        echo '<p class="dfms-hint" id="' . e($id) . '-hint">' . e($config['hint']) . '</p>';
    }
    echo '</div>';
}

/** A labelled textarea. */
function healthTextareaField(string $name, string $label, $value = null, array $config = []): void {
    $id = $config['id'] ?? $name;
    $required = !empty($config['required']);
    echo '<div class="dfms-field">';
    echo '<label class="dfms-label" for="' . e($id) . '">' . e($label);
    if ($required) {
        echo '<span class="dfms-required" aria-hidden="true">*</span>';
    }
    echo '</label>';
    echo '<textarea class="dfms-textarea" id="' . e($id) . '" name="' . e($name) . '"'
        . ' rows="' . (int) ($config['rows'] ?? 3) . '"'
        . (isset($config['maxlength']) ? ' maxlength="' . (int) $config['maxlength'] . '"' : '')
        . (!empty($config['hint']) ? ' aria-describedby="' . e($id) . '-hint"' : '')
        . ($required ? ' required' : '') . '>' . e((string) $value) . '</textarea>';
    if (!empty($config['hint'])) {
        echo '<p class="dfms-hint" id="' . e($id) . '-hint">' . e($config['hint']) . '</p>';
    }
    echo '</div>';
}

/** The animal picker, used by every record-something page. */
function healthAnimalField(array $animals, $selected = null, array $config = []): void {
    $options = [];
    foreach ($animals as $animal) {
        $options[(int) $animal['cow_id']] = $animal['unique_id']
            . ($animal['name'] ? ' — ' . $animal['name'] : '');
    }
    healthSelectField('cow_id', $config['label'] ?? 'Animal', $options, $selected, [
        'required'    => true,
        'placeholder' => 'Select an animal',
        'hint'        => $config['hint'] ?? 'Only animals with an active lifecycle status can receive new health records.',
    ]);
}

/** Status badge for a health status, using the shared tone mapping. */
function healthStatusBadge(?string $status): string {
    return dfms_badge_html(dfms_health_status_label($status), dfms_health_status_tone($status), true);
}

/**
 * The standing disclaimer shown wherever a clinical judgement is recorded.
 *
 * DFMIS records what people decide; it does not decide. Saying so on the pages
 * where diagnoses and treatments are entered is a deliberate product choice,
 * not boilerplate.
 */
function healthClinicalNotice(): void {
    echo '<p class="dfms-hint" style="margin-bottom:var(--space-4)">'
        . 'DFMIS records veterinary decisions; it does not make them. '
        . 'Everything below is stored exactly as entered, attributed to you, and never interpreted by the system.'
        . '</p>';
}
