<?php
// ============================================================================
// VETERINARY & ANIMAL HEALTH DOMAIN
//
// The single place where health vocabulary and health rules live. Pages and
// API handlers call these functions; they do not re-derive a rule, and they do
// not write a health table directly.
//
// WHAT THIS FILE IS NOT
// It is not a diagnostic engine. Nothing here decides what is wrong with an
// animal, scores a probability, or recommends a treatment. Every clinical
// judgement in the system was entered by a person and is stored with that
// person's own stated confidence. This layer validates, records and retrieves
// — veterinary judgement stays with the veterinarian.
//
// SPECIES NEUTRALITY
// Phase 3 made the animal record multi-species. The health model follows: a
// case, an observation, a symptom, a treatment and a quarantine are the same
// concepts for cattle, buffalo, goats and poultry. The only species-shaped
// thing in this file is the withdrawal RESTRICTION TYPE list, because a goat
// does not produce eggs — and that is data, not a branch.
//
// TENANT SAFETY
// Every read goes through tenantQuery()/tenantFind() and every write through
// tenantInsert()/tenantExec(). No function here accepts a farm_id, and none
// can be made to cross a tenant boundary by passing it a foreign id: a foreign
// animal resolves to null exactly as a missing one does.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/units.php';
require_once __DIR__ . '/livestock.php';
// A HARD dependency, deliberately not a function_exists() guard.
//
// Every clinical mutation in this file must be audited — who administered
// what, to which animal, and which food-safety restriction it created. A
// conditional call would mean that loading this file without audit.php
// silently produces unaudited medication records, which is precisely the
// failure nobody would notice until an investigation needed the trail.
// Requiring it makes the dependency real and the omission impossible.
require_once __DIR__ . '/audit.php';

// ===========================================================================
// HEALTH STATUS
//
// The four original values are UNCHANGED. Migration 008 appended two more.
// `at_risk` and `treatment` keep their stored values and gain clearer labels —
// renaming the stored value would have reinterpreted every existing row.
//
// `deceased` is deliberately absent: death is a LIFECYCLE state
// (cows.lifecycle_status) and duplicating it here would create two columns
// that can disagree about whether an animal is alive.
// ===========================================================================
const DFMS_HEALTH_STATUSES = [
    'healthy'     => ['label' => 'Healthy',           'tone' => 'success', 'attention' => false,
                      'description' => 'No known health concern.'],
    'at_risk'     => ['label' => 'Under observation', 'tone' => 'warning', 'attention' => true,
                      'description' => 'Being watched for a developing problem.'],
    'sick'        => ['label' => 'Sick',              'tone' => 'danger',  'attention' => true,
                      'description' => 'An active health problem with no treatment recorded yet.'],
    'treatment'   => ['label' => 'Under treatment',   'tone' => 'info',    'attention' => true,
                      'description' => 'Receiving an active course of treatment.'],
    'quarantined' => ['label' => 'Quarantined',       'tone' => 'danger',  'attention' => true,
                      'description' => 'Isolated from the rest of the herd.'],
    'recovered'   => ['label' => 'Recovered',         'tone' => 'success', 'attention' => false,
                      'description' => 'A previous episode has resolved.'],
];

function dfms_health_statuses(): array {
    return DFMS_HEALTH_STATUSES;
}

function dfms_is_valid_health_status(?string $status): bool {
    return $status !== null && isset(DFMS_HEALTH_STATUSES[$status]);
}

function dfms_health_status_label(?string $status): string {
    return DFMS_HEALTH_STATUSES[$status]['label'] ?? 'Unknown';
}

function dfms_health_status_tone(?string $status): string {
    return DFMS_HEALTH_STATUSES[$status]['tone'] ?? 'neutral';
}

/**
 * Health statuses that mean "someone should look at this animal".
 * Used by the module counters and the alert sync, so the definition of
 * "needs attention" exists once.
 */
function dfms_health_attention_statuses(): array {
    return array_keys(array_filter(DFMS_HEALTH_STATUSES, static function (array $meta): bool {
        return $meta['attention'];
    }));
}

/**
 * Small interface refinements scoped to Phase 4 health surfaces.
 *
 * SCOPED ON PURPOSE. Every rule below is namespaced under .dfms-health-scope
 * so nothing here can alter a Phase 1–3 page. The existing design system in
 * assets/css/style.css remains authoritative and is untouched.
 *
 * What each rule fixes, from the Web Interface Guidelines review:
 *   touch-action      removes the 300ms double-tap zoom delay on the action
 *                     links a stockperson taps in a barn
 *   overflow-wrap     a long clinical note or an unbroken batch number must
 *                     wrap inside its cell rather than force the page sideways
 *   tabular-nums      dose and day columns are compared down the column, so
 *                     the digits must line up
 *   text-wrap         stops a one-word widow on the section headings
 */
function healthScopedStyles(): void {
    echo '<style>'
        . '.dfms-health-scope .dfms-btn,.dfms-health-scope .dfms-tab{touch-action:manipulation}'
        . '.dfms-health-scope td,.dfms-health-scope .dfms-table td{overflow-wrap:anywhere}'
        . '.dfms-health-scope td.health-figure{font-variant-numeric:tabular-nums;white-space:nowrap}'
        . '.dfms-health-scope h1,.dfms-health-scope h2,.dfms-health-scope h3{text-wrap:balance}'
        . '</style>';
}

// ===========================================================================
// CASES
// ===========================================================================
const DFMS_CASE_TYPES = [
    'illness'     => 'Illness',
    'injury'      => 'Injury',
    'infection'   => 'Infection',
    'parasitic'   => 'Parasitic',
    'metabolic'   => 'Metabolic / nutritional',
    'reproductive'=> 'Reproductive',
    'lameness'    => 'Lameness',
    'mastitis'    => 'Mastitis',
    'respiratory' => 'Respiratory',
    'digestive'   => 'Digestive',
    'routine'     => 'Routine check',
    'other'       => 'Other',
];

const DFMS_CASE_STATUSES = [
    'open'       => ['label' => 'Open',       'tone' => 'danger',  'active' => true],
    'monitoring' => ['label' => 'Monitoring', 'tone' => 'warning', 'active' => true],
    'treating'   => ['label' => 'Treating',   'tone' => 'info',    'active' => true],
    'resolved'   => ['label' => 'Resolved',   'tone' => 'success', 'active' => false],
    'closed'     => ['label' => 'Closed',     'tone' => 'neutral', 'active' => false],
];

// Outcomes a person can honestly record. 'unknown' is a first-class choice,
// not an omission: many real episodes end without a definitive answer, and a
// system that refuses to accept that will be given a fabricated one instead.
const DFMS_CASE_OUTCOMES = [
    'recovered'  => 'Recovered',
    'improved'   => 'Improved',
    'chronic'    => 'Became chronic',
    'culled'     => 'Animal culled',
    'died'       => 'Animal died',
    'unresolved' => 'Unresolved',
    'unknown'    => 'Outcome unknown',
];

const DFMS_CASE_SEVERITIES = ['routine', 'moderate', 'urgent'];

function dfms_case_types(): array   { return DFMS_CASE_TYPES; }
function dfms_case_statuses(): array { return DFMS_CASE_STATUSES; }
function dfms_case_outcomes(): array { return DFMS_CASE_OUTCOMES; }

function dfms_case_is_active(?string $status): bool {
    return $status !== null && !empty(DFMS_CASE_STATUSES[$status]['active']);
}

/** The statuses that count as an open episode, for counters and filters. */
function dfms_active_case_statuses(): array {
    return array_keys(array_filter(DFMS_CASE_STATUSES, static function (array $meta): bool {
        return $meta['active'];
    }));
}

// ===========================================================================
// CLINICAL OBSERVATIONS
//
// Each type declares HOW it is measured, which is what lets one table hold
// both a temperature and an appetite score without either becoming free text:
//
//   kind   a DFMS measurement kind ('temperature', 'mass') — the value is
//          numeric and stored in that kind's CANONICAL unit.
//   scale  a coded vocabulary — the value is one of these keys, nothing else.
//
// A type has exactly one of the two. `unit` on a numeric type without a DFMS
// kind (heart rate in bpm) is a plain label with no conversion, and that is
// stated rather than pretended: bpm has no other unit to convert to.
// ===========================================================================
const DFMS_OBSERVATION_TYPES = [
    'body_temperature' => [
        'label' => 'Body temperature', 'kind' => 'temperature', 'unit' => 'c',
        'min' => 20.0, 'max' => 50.0,
        'hint' => 'Entered and shown in the farm\'s measurement system; stored in °C.',
    ],
    'weight' => [
        'label' => 'Weight', 'kind' => 'mass', 'unit' => 'kg',
        'min' => 0.1, 'max' => 2000.0,
        'hint' => 'Entered and shown in the farm\'s measurement system; stored in kg.',
    ],
    'heart_rate' => [
        'label' => 'Heart rate', 'kind' => null, 'unit' => 'bpm',
        'min' => 10.0, 'max' => 300.0,
        'hint' => 'Beats per minute. No unit conversion exists for this measurement.',
    ],
    'respiratory_rate' => [
        'label' => 'Respiratory rate', 'kind' => null, 'unit' => 'bpm',
        'min' => 2.0, 'max' => 200.0,
        'hint' => 'Breaths per minute.',
    ],
    'rumination_minutes' => [
        'label' => 'Rumination', 'kind' => null, 'unit' => 'min/day',
        'min' => 0.0, 'max' => 1440.0,
        'hint' => 'Minutes per day.',
    ],
    'body_condition_score' => [
        'label' => 'Body condition score', 'kind' => null, 'unit' => 'score',
        'min' => 1.0, 'max' => 5.0,
        'hint' => 'On the 1–5 scale. Record the scale you used in the notes if it differs.',
    ],
    'appetite' => [
        'label' => 'Appetite', 'kind' => null, 'unit' => null,
        'scale' => ['normal' => 'Normal', 'reduced' => 'Reduced', 'poor' => 'Poor', 'none' => 'Not eating'],
    ],
    'hydration' => [
        'label' => 'Hydration', 'kind' => null, 'unit' => null,
        'scale' => ['normal' => 'Normal', 'mild' => 'Mildly dehydrated', 'moderate' => 'Moderately dehydrated', 'severe' => 'Severely dehydrated'],
    ],
    'mobility' => [
        'label' => 'Mobility', 'kind' => null, 'unit' => null,
        'scale' => ['normal' => 'Normal', 'stiff' => 'Stiff', 'lame' => 'Lame', 'non_weight_bearing' => 'Not weight-bearing', 'recumbent' => 'Unable to stand'],
    ],
    'demeanour' => [
        'label' => 'Demeanour', 'kind' => null, 'unit' => null,
        'scale' => ['bright' => 'Bright and alert', 'quiet' => 'Quiet', 'dull' => 'Dull', 'depressed' => 'Depressed', 'unresponsive' => 'Unresponsive'],
    ],
    'faecal_consistency' => [
        'label' => 'Faecal consistency', 'kind' => null, 'unit' => null,
        'scale' => ['normal' => 'Normal', 'loose' => 'Loose', 'watery' => 'Watery', 'hard' => 'Hard', 'blood_present' => 'Blood present'],
    ],
    'udder_condition' => [
        'label' => 'Udder condition', 'kind' => null, 'unit' => null,
        'scale' => ['normal' => 'Normal', 'swollen' => 'Swollen', 'hot' => 'Hot', 'painful' => 'Painful', 'abnormal_milk' => 'Abnormal milk'],
    ],
    'visible_abnormality' => [
        'label' => 'Visible abnormality', 'kind' => null, 'unit' => null,
        'scale' => ['none' => 'None seen', 'discharge' => 'Discharge', 'swelling' => 'Swelling', 'wound' => 'Wound', 'skin_lesion' => 'Skin lesion', 'other' => 'Other — see notes'],
    ],
];

function dfms_observation_types(): array {
    return DFMS_OBSERVATION_TYPES;
}

function dfms_is_valid_observation_type(?string $type): bool {
    return $type !== null && isset(DFMS_OBSERVATION_TYPES[$type]);
}

/** True when this observation type records a number rather than a code. */
function dfms_observation_is_numeric(string $type): bool {
    return dfms_is_valid_observation_type($type) && !isset(DFMS_OBSERVATION_TYPES[$type]['scale']);
}

/** The coded vocabulary for a qualitative observation type, or []. */
function dfms_observation_scale(string $type): array {
    return DFMS_OBSERVATION_TYPES[$type]['scale'] ?? [];
}

/**
 * Render one observation for display, converting numeric values out of
 * canonical storage into the farm's measurement system.
 *
 * An observation whose stored unit is not the canonical unit its type declares
 * is shown RAW with its stored unit and is never converted — silently applying
 * the wrong factor would produce a plausible number that is wrong, and a
 * temperature is a clinical decision input.
 */
function healthFormatObservation(array $observation): string {
    $type = (string) $observation['observation_type'];
    $meta = DFMS_OBSERVATION_TYPES[$type] ?? null;

    if ($observation['value_code'] !== null && $observation['value_code'] !== '') {
        $scale = dfms_observation_scale($type);
        return $scale[$observation['value_code']] ?? (string) $observation['value_code'];
    }

    if ($observation['value_numeric'] === null) {
        return '—';
    }

    $value = (float) $observation['value_numeric'];
    $storedUnit = $observation['unit'] ?? null;

    // Convertible only when the type declares a DFMS measurement kind AND the
    // stored unit really is that kind's canonical unit.
    if ($meta !== null && !empty($meta['kind'])) {
        $kind = (string) $meta['kind'];
        if ($storedUnit === dfms_canonical_unit($kind)) {
            return fmt_measure($value, $kind);
        }
        // Stored in something unexpected: report it as stored, flagged, rather
        // than converting on an assumption.
        return fmt_number($value, 2) . ' ' . (string) $storedUnit . ' (unconverted)';
    }

    $decimals = $value == (int) $value ? 0 : 1;
    return fmt_number($value, $decimals) . ($storedUnit ? ' ' . $storedUnit : '');
}

// ===========================================================================
// SYMPTOMS
//
// A pragmatic registry of signs a stockperson or vet can record without
// training in a formal coding system, plus 'other' for anything outside it.
// It is intentionally NOT presented as an international standard: aligning to
// one is real work with real licensing questions and belongs in a later phase.
// The codes are stable, so a future mapping onto a standard vocabulary is a
// translation table rather than a data migration.
// ===========================================================================
const DFMS_SYMPTOMS = [
    'fever'              => 'Fever',
    'appetite_loss'      => 'Loss of appetite',
    'weight_loss'        => 'Weight loss',
    'lethargy'           => 'Lethargy',
    'lameness'           => 'Lameness',
    'coughing'           => 'Coughing',
    'nasal_discharge'    => 'Nasal discharge',
    'laboured_breathing' => 'Laboured breathing',
    'diarrhoea'          => 'Diarrhoea',
    'constipation'       => 'Constipation',
    'bloat'              => 'Bloat',
    'dehydration'        => 'Dehydration',
    'udder_swelling'     => 'Udder swelling',
    'abnormal_milk'      => 'Abnormal milk',
    'milk_drop'          => 'Sudden drop in milk',
    'skin_lesion'        => 'Skin lesion',
    'hair_loss'          => 'Hair loss',
    'eye_discharge'      => 'Eye discharge',
    'salivation'         => 'Excessive salivation',
    'swelling'           => 'Swelling',
    'wound'              => 'Wound',
    'abortion'           => 'Abortion',
    'retained_placenta'  => 'Retained placenta',
    'recumbency'         => 'Unable to stand',
    'tremor'             => 'Tremors / convulsions',
    'other'              => 'Other (describe)',
];

const DFMS_SYMPTOM_SEVERITIES = [
    'mild'     => 'Mild',
    'moderate' => 'Moderate',
    'severe'   => 'Severe',
];

function dfms_symptoms(): array { return DFMS_SYMPTOMS; }

function dfms_is_valid_symptom(?string $code): bool {
    return $code !== null && isset(DFMS_SYMPTOMS[$code]);
}

/** Display name for a symptom row, honouring the free-text 'other' escape. */
function dfms_symptom_label(array $symptom): string {
    if ($symptom['symptom_code'] === 'other') {
        return (string) ($symptom['symptom_label'] ?: 'Other');
    }
    return DFMS_SYMPTOMS[$symptom['symptom_code']] ?? (string) $symptom['symptom_code'];
}

// ===========================================================================
// CONDITIONS / DIAGNOSES
//
// Same design as symptoms: a stable code list with an 'other' escape hatch.
// Codes are grouped so disease surveillance can aggregate without the UI
// hard-coding which condition belongs to which group.
// ===========================================================================
const DFMS_CONDITIONS = [
    'mastitis'            => ['label' => 'Mastitis',                 'group' => 'udder',        'contagious' => true],
    'metritis'            => ['label' => 'Metritis',                 'group' => 'reproductive', 'contagious' => false],
    'milk_fever'          => ['label' => 'Milk fever (hypocalcaemia)','group' => 'metabolic',   'contagious' => false],
    'ketosis'             => ['label' => 'Ketosis',                  'group' => 'metabolic',    'contagious' => false],
    'acidosis'            => ['label' => 'Ruminal acidosis',         'group' => 'digestive',    'contagious' => false],
    'bloat'               => ['label' => 'Bloat',                    'group' => 'digestive',    'contagious' => false],
    'pneumonia'           => ['label' => 'Pneumonia',                'group' => 'respiratory',  'contagious' => true],
    'brd'                 => ['label' => 'Bovine respiratory disease','group' => 'respiratory', 'contagious' => true],
    'foot_rot'            => ['label' => 'Foot rot',                 'group' => 'lameness',     'contagious' => true],
    'sole_ulcer'          => ['label' => 'Sole ulcer',               'group' => 'lameness',     'contagious' => false],
    'digital_dermatitis'  => ['label' => 'Digital dermatitis',       'group' => 'lameness',     'contagious' => true],
    'fmd'                 => ['label' => 'Foot and mouth disease',   'group' => 'notifiable',   'contagious' => true],
    'lsd'                 => ['label' => 'Lumpy skin disease',       'group' => 'notifiable',   'contagious' => true],
    'anthrax'             => ['label' => 'Anthrax',                  'group' => 'notifiable',   'contagious' => true],
    'brucellosis'         => ['label' => 'Brucellosis',              'group' => 'notifiable',   'contagious' => true],
    'tuberculosis'        => ['label' => 'Tuberculosis',             'group' => 'notifiable',   'contagious' => true],
    'hs'                  => ['label' => 'Haemorrhagic septicaemia', 'group' => 'notifiable',   'contagious' => true],
    'blackleg'            => ['label' => 'Blackleg',                 'group' => 'infectious',   'contagious' => true],
    'internal_parasites'  => ['label' => 'Internal parasites',       'group' => 'parasitic',    'contagious' => false],
    'external_parasites'  => ['label' => 'External parasites',       'group' => 'parasitic',    'contagious' => true],
    'ringworm'            => ['label' => 'Ringworm',                 'group' => 'skin',         'contagious' => true],
    'injury'              => ['label' => 'Traumatic injury',         'group' => 'injury',       'contagious' => false],
    'dystocia'            => ['label' => 'Dystocia',                 'group' => 'reproductive', 'contagious' => false],
    'retained_placenta'   => ['label' => 'Retained placenta',        'group' => 'reproductive', 'contagious' => false],
    'nutritional'         => ['label' => 'Nutritional deficiency',   'group' => 'metabolic',    'contagious' => false],
    'poisoning'           => ['label' => 'Poisoning',                'group' => 'toxic',        'contagious' => false],
    'other'               => ['label' => 'Other (describe)',         'group' => 'other',        'contagious' => false],
];

const DFMS_DIAGNOSIS_STATUSES = [
    'suspected' => ['label' => 'Suspected', 'tone' => 'warning'],
    'confirmed' => ['label' => 'Confirmed', 'tone' => 'danger'],
    'ruled_out' => ['label' => 'Ruled out', 'tone' => 'neutral'],
    'resolved'  => ['label' => 'Resolved',  'tone' => 'success'],
];

const DFMS_DIAGNOSIS_CONFIDENCE = [
    'low'      => 'Low',
    'moderate' => 'Moderate',
    'high'     => 'High',
];

function dfms_conditions(): array { return DFMS_CONDITIONS; }

function dfms_is_valid_condition(?string $code): bool {
    return $code !== null && isset(DFMS_CONDITIONS[$code]);
}

function dfms_condition_label(array $diagnosis): string {
    if ($diagnosis['condition_code'] === 'other') {
        return (string) ($diagnosis['condition_label'] ?: 'Other');
    }
    return DFMS_CONDITIONS[$diagnosis['condition_code']]['label'] ?? (string) $diagnosis['condition_code'];
}

/** Conditions the registry marks as transmissible — drives the isolation hint. */
function dfms_condition_is_contagious(?string $code): bool {
    return $code !== null && !empty(DFMS_CONDITIONS[$code]['contagious']);
}

// ===========================================================================
// CONSULTATIONS, TREATMENTS, MEDICATION, QUARANTINE, LAB, WITHDRAWAL
// ===========================================================================
const DFMS_CONSULTATION_TYPES = [
    'on_farm'   => 'On-farm visit',
    'clinic'    => 'Clinic visit',
    'remote'    => 'Remote / video',
    'phone'     => 'Telephone advice',
    'emergency' => 'Emergency call-out',
];

const DFMS_TREATMENT_TYPES = [
    'medication'  => 'Medication course',
    'supportive'  => 'Supportive care',
    'surgical'    => 'Surgical procedure',
    'dietary'     => 'Dietary management',
    'hoof_care'   => 'Hoof care',
    'wound_care'  => 'Wound care',
    'isolation'   => 'Isolation / rest',
    'other'       => 'Other',
];

const DFMS_TREATMENT_STATUSES = [
    'planned'   => ['label' => 'Planned',   'tone' => 'neutral', 'active' => true],
    'active'    => ['label' => 'Active',    'tone' => 'info',    'active' => true],
    'completed' => ['label' => 'Completed', 'tone' => 'success', 'active' => false],
    'cancelled' => ['label' => 'Cancelled', 'tone' => 'neutral', 'active' => false],
];

const DFMS_TREATMENT_OUTCOMES = [
    'resolved'  => 'Resolved',
    'improved'  => 'Improved',
    'no_change' => 'No change',
    'worsened'  => 'Worsened',
    'unknown'   => 'Unknown',
];

// Dose units are a MEDICATION vocabulary, not a DFMS measurement kind: no
// conversion exists or is attempted between ml and mg, because the conversion
// depends on the product's concentration, which this system does not hold.
const DFMS_DOSE_UNITS = [
    'ml'     => 'ml',
    'l'      => 'L',
    'mg'     => 'mg',
    'g'      => 'g',
    'iu'     => 'IU',
    'tablet' => 'tablet(s)',
    'bolus'  => 'bolus(es)',
    'sachet' => 'sachet(s)',
    'dose'   => 'dose(s)',
];

const DFMS_ADMIN_ROUTES = [
    'oral'          => 'Oral',
    'im'            => 'Intramuscular',
    'iv'            => 'Intravenous',
    'sc'            => 'Subcutaneous',
    'topical'       => 'Topical',
    'intramammary'  => 'Intramammary',
    'intrauterine'  => 'Intrauterine',
    'intranasal'    => 'Intranasal',
    'ophthalmic'    => 'Ophthalmic',
    'other'         => 'Other',
];

const DFMS_QUARANTINE_TYPES = [
    'disease'           => 'Confirmed disease',
    'suspected_disease' => 'Suspected disease',
    'new_arrival'       => 'New arrival',
    'withdrawal'        => 'Medicine withdrawal',
    'precautionary'     => 'Precautionary',
    'other'             => 'Other',
];

const DFMS_LAB_TEST_TYPES = [
    'milk_culture'   => 'Milk culture',
    'bacteriology'   => 'Bacteriology',
    'serology'       => 'Serology',
    'haematology'    => 'Haematology',
    'biochemistry'   => 'Biochemistry',
    'parasitology'   => 'Parasitology',
    'pcr'            => 'PCR / molecular',
    'histopathology' => 'Histopathology',
    'post_mortem'    => 'Post-mortem',
    'antibiogram'    => 'Antibiotic sensitivity',
    'other'          => 'Other',
];

const DFMS_LAB_RESULT_CODES = [
    'positive'     => 'Positive',
    'negative'     => 'Negative',
    'inconclusive' => 'Inconclusive',
    'normal'       => 'Within reference range',
    'abnormal'     => 'Outside reference range',
];

// Restriction types. 'eggs' and 'honey' exist so the model is not dairy-only;
// which ones a farm sees is filtered by its Phase 3 species mix, not hidden by
// a hard-coded dairy assumption.
const DFMS_WITHDRAWAL_TYPES = [
    'milk'  => ['label' => 'Milk',            'species' => ['cattle', 'buffalo', 'goat', 'sheep', 'camel']],
    'meat'  => ['label' => 'Meat / slaughter', 'species' => null],
    'eggs'  => ['label' => 'Eggs',            'species' => ['chicken', 'duck', 'quail', 'turkey']],
    'honey' => ['label' => 'Honey',           'species' => ['bee']],
    'other' => ['label' => 'Other product',   'species' => null],
];

const DFMS_INVENTORY_CATEGORIES = [
    'medication' => 'Medication',
    'vaccine'    => 'Vaccine',
    'feed'       => 'Feed',
    'supplement' => 'Supplement',
    'consumable' => 'Consumable',
    'equipment'  => 'Equipment',
    'other'      => 'Other',
];

function dfms_consultation_types(): array  { return DFMS_CONSULTATION_TYPES; }
function dfms_treatment_types(): array     { return DFMS_TREATMENT_TYPES; }
function dfms_treatment_statuses(): array  { return DFMS_TREATMENT_STATUSES; }
function dfms_dose_units(): array          { return DFMS_DOSE_UNITS; }
function dfms_admin_routes(): array        { return DFMS_ADMIN_ROUTES; }
function dfms_quarantine_types(): array    { return DFMS_QUARANTINE_TYPES; }
function dfms_lab_test_types(): array      { return DFMS_LAB_TEST_TYPES; }
function dfms_inventory_categories(): array { return DFMS_INVENTORY_CATEGORIES; }

function dfms_withdrawal_types(): array {
    return array_map(static function (array $meta): string {
        return $meta['label'];
    }, DFMS_WITHDRAWAL_TYPES);
}

function dfms_is_valid_withdrawal_type(?string $type): bool {
    return $type !== null && isset(DFMS_WITHDRAWAL_TYPES[$type]);
}

/**
 * Restriction types that make sense for a species.
 *
 * A restriction with `species => null` applies to every animal. One with a
 * species list applies only to those. An UNKNOWN species gets the unrestricted
 * types plus nothing else — never the full list, because offering "egg
 * withdrawal" for a cow invites a nonsense record.
 */
function dfms_withdrawal_types_for_species(?string $species): array {
    $available = [];
    foreach (DFMS_WITHDRAWAL_TYPES as $key => $meta) {
        if ($meta['species'] === null || ($species !== null && in_array($species, $meta['species'], true))) {
            $available[$key] = $meta['label'];
        }
    }
    return $available;
}

/**
 * Is a lifecycle state one that must refuse health changes?
 *
 * LIFECYCLE IS AUTHORITATIVE OVER HEALTH. An animal that has been recorded as
 * sold, transferred, deceased or retired is no longer under this farm's
 * clinical management, and writing new health records against it would produce
 * a record that contradicts itself — a deceased animal that is "recovering".
 *
 * The correction path is not to weaken this rule but to use the Phase 3
 * lifecycle page to return the animal to `active`, which leaves the mistake
 * visible in the lifecycle history.
 */
function healthLifecycleBlocks(array $animal): bool {
    $status = $animal['lifecycle_status'] ?? 'active';
    return dfms_is_valid_lifecycle_status($status)
        && !empty(DFMS_LIFECYCLE_STATUSES[$status]['terminal']);
}

/** The user-facing explanation for that refusal. */
function healthLifecycleBlockMessage(array $animal): string {
    $status = $animal['lifecycle_status'] ?? 'active';
    $label = DFMS_LIFECYCLE_STATUSES[$status]['label'] ?? $status;
    return 'This animal is recorded as ' . strtolower((string) $label)
        . '. Health records cannot be added or changed until its lifecycle status is returned to Active.';
}

/**
 * Resolve an animal for a health action, or 404.
 *
 * Every health page and handler starts here, so tenant scope and lifecycle
 * compatibility are checked in exactly one place.
 *
 * @throws RuntimeException when the lifecycle state forbids the change
 */
function healthAnimalOr404(PDO $conn, $animalId, bool $forWrite = true): array {
    $animal = animalFindOr404($conn, (int) $animalId, true);
    if ($forWrite && healthLifecycleBlocks($animal)) {
        throw new HealthRuleViolation(healthLifecycleBlockMessage($animal));
    }
    return $animal;
}

/**
 * A domain rule the user broke, as opposed to a system fault.
 *
 * Kept distinct so its message can be shown verbatim — it describes the
 * caller's own request and contains nothing internal.
 */
class HealthRuleViolation extends RuntimeException {}

// ===========================================================================
// HEALTH STATUS TRANSITIONS
// ===========================================================================

/**
 * May an animal's health status move from $from to $to?
 *
 * Health status is a description of the present, not a lifecycle, so almost
 * any transition is legitimate — an animal genuinely can go from healthy to
 * sick to quarantined to recovered and back. Only two moves are refused:
 *
 *   - a no-op, which would write a history row recording that nothing happened
 *   - anything at all when the lifecycle state is terminal, which is checked
 *     by the caller through healthAnimalOr404() because it needs the animal row
 */
function dfms_health_transition_allowed(?string $from, string $to): bool {
    if (!dfms_is_valid_health_status($to)) {
        return false;
    }
    if ($from === null) {
        return true;
    }
    if (!dfms_is_valid_health_status($from)) {
        return false;
    }
    return $from !== $to;
}

/**
 * Record a health status change and update the animal, in ONE transaction.
 *
 * A status column that disagrees with its own history is worse than either
 * being absent, which is the same reasoning Phase 3 applied to lifecycle.
 *
 * @throws HealthRuleViolation on a refused transition or a terminal lifecycle
 */
function healthSetStatus(PDO $conn, int $animalId, string $newStatus, array $options = []): bool {
    $animal = healthAnimalOr404($conn, $animalId);
    $current = $animal['health_status'] ?? 'healthy';

    if (!dfms_health_transition_allowed($current, $newStatus)) {
        throw new HealthRuleViolation(
            $current === $newStatus
                ? 'This animal is already recorded as ' . strtolower(dfms_health_status_label($newStatus)) . '.'
                : 'That is not a recognised health status.'
        );
    }

    $effectiveAt = $options['effective_at'] ?? dfms_health_now();
    $caseId = isset($options['case_id']) ? (int) $options['case_id'] : null;

    tenantTransaction($conn, function (PDO $conn) use ($animal, $current, $newStatus, $effectiveAt, $caseId, $options) {
        tenantInsert($conn, 'health_status_events', [
            'cow_id'          => (int) $animal['cow_id'],
            'case_id'         => $caseId,
            'previous_status' => $current,
            'new_status'      => $newStatus,
            'reason'          => $options['reason'] ?? null,
            'notes'           => $options['notes'] ?? null,
            'effective_at'    => $effectiveAt,
            'recorded_by'     => currentUserId(),
        ]);

        tenantExec(
            $conn,
            "UPDATE cows SET health_status = ? WHERE cow_id = ? AND {scope}",
            [$newStatus, (int) $animal['cow_id']]
        );
    });

    auditLog('health.status_change', 'animal', (int) $animal['cow_id'],
        ['health_status' => $current],
        ['health_status' => $newStatus, 'reason' => $options['reason'] ?? null, 'case_id' => $caseId]
    );
    return true;
}

/** Current timestamp for health records, on the application's UTC clock. */
function dfms_health_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/**
 * Refuse a clinical timestamp that is meaningfully in the future.
 *
 * DfmsValidator::datetime() normalises the format but places no ceiling on it,
 * so without this a medication could be recorded as administered next month —
 * which would create a withdrawal period that does not start until then and
 * leave the animal unflagged TODAY. That is a food-safety failure, not a
 * cosmetic one, so the check lives in the domain layer where every caller gets
 * it rather than in one form.
 *
 * A small tolerance is allowed because the browser sends the operator's local
 * wall clock while the application stores UTC; a farm several hours ahead would
 * otherwise be unable to record something that just happened. The tolerance is
 * generous enough to cover every real timezone offset and far too small to let
 * a genuinely future date through.
 *
 * @throws HealthRuleViolation
 */
function healthAssertNotFuture(?string $timestamp, string $label): void {
    if ($timestamp === null || $timestamp === '') {
        return;
    }
    // 18 hours covers the full -12..+14 range of real UTC offsets with margin.
    $ceiling = gmdate('Y-m-d H:i:s', time() + (18 * 3600));
    if ($timestamp > $ceiling) {
        throw new HealthRuleViolation($label . ' cannot be in the future.');
    }
}

function healthStatusHistory(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT s.*, u.username
           FROM health_status_events s
           LEFT JOIN admin_users u ON u.admin_id = s.recorded_by
          WHERE {scope} AND s.cow_id = ?
          ORDER BY s.effective_at DESC, s.status_event_id DESC",
        [$animalId],
        's'
    );
}


// ===========================================================================
// CASES
// ===========================================================================

/**
 * Next case reference for the active farm, e.g. HC-2026-0007.
 *
 * Per-farm, so one tenant's case count is not inferable from another's — the
 * same reasoning that made animal identifiers per-farm unique in Phase 3.
 * Generated inside the caller's transaction and protected by the UNIQUE index,
 * so a collision under concurrency fails the insert rather than duplicating.
 */
function healthNextCaseReference(PDO $conn): string {
    $year = substr(farmToday(), 0, 4);
    $prefix = 'HC-' . $year . '-';
    $last = tenantScalar(
        $conn,
        "SELECT MAX(CAST(SUBSTRING(case_reference, " . (strlen($prefix) + 1) . ") AS UNSIGNED))
           FROM health_cases WHERE {scope} AND case_reference LIKE ?",
        [$prefix . '%'],
        0
    );
    return $prefix . str_pad((string) ((int) $last + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Open a clinical case, optionally moving the animal's health status with it.
 *
 * Both writes are in one transaction: a case that exists while the animal it
 * concerns still reads "healthy" is a misleading record, not a partial one.
 *
 * @return int the new case id
 */
function healthOpenCase(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);

    return tenantTransaction($conn, function (PDO $conn) use ($animal, $data): int {
        $caseId = tenantInsert($conn, 'health_cases', [
            'cow_id'         => (int) $animal['cow_id'],
            'case_reference' => healthNextCaseReference($conn),
            'title'          => $data['title'],
            'case_type'      => $data['case_type'],
            'severity'       => $data['severity'],
            'status'         => $data['status'] ?? 'open',
            'opened_on'      => $data['opened_on'],
            'follow_up_on'   => $data['follow_up_on'] ?? null,
            'is_contagious'  => !empty($data['is_contagious']) ? 1 : 0,
            'notes'          => $data['notes'] ?? null,
            'opened_by'      => currentUserId(),
        ]);

        // Only when asked, and only when it is actually a change. Opening a
        // case must not silently overwrite a status someone set deliberately.
        $target = $data['health_status'] ?? null;
        if ($target !== null && dfms_health_transition_allowed($animal['health_status'] ?? null, $target)) {
            healthSetStatus($conn, (int) $animal['cow_id'], $target, [
                'case_id' => $caseId,
                'reason'  => 'Health case opened',
            ]);
        }

        auditCreate('health_case', $caseId, [
            'cow_id'    => (int) $animal['cow_id'],
            'title'     => $data['title'],
            'case_type' => $data['case_type'],
            'severity'  => $data['severity'],
            'opened_on' => $data['opened_on'],
        ]);
        return $caseId;
    });
}

function healthFindCase(PDO $conn, $caseId): ?array {
    return tenantFind($conn, 'health_cases', 'case_id', $caseId);
}

function healthFindCaseOr404(PDO $conn, $caseId): array {
    return tenantFindOr404($conn, 'health_cases', 'case_id', $caseId);
}

/**
 * Update a case's status / outcome / follow-up.
 *
 * Closing a case REQUIRES an outcome, including the explicit 'unknown' —
 * a closed episode with no recorded outcome is a gap in the clinical record.
 *
 * @throws HealthRuleViolation
 */
function healthUpdateCase(PDO $conn, int $caseId, array $data): bool {
    $case = healthFindCaseOr404($conn, $caseId);
    healthAnimalOr404($conn, (int) $case['cow_id']);

    $newStatus = $data['status'];
    if (!isset(DFMS_CASE_STATUSES[$newStatus])) {
        throw new HealthRuleViolation('That is not a recognised case status.');
    }

    $isClosing = !dfms_case_is_active($newStatus);
    $outcome = $data['outcome'] ?? null;
    if ($isClosing && ($outcome === null || !isset(DFMS_CASE_OUTCOMES[$outcome]))) {
        throw new HealthRuleViolation('Record an outcome before resolving or closing a case. "Outcome unknown" is a valid choice.');
    }
    if (!$isClosing) {
        $outcome = null;   // a reopened case has no outcome yet
    }

    // A case cannot close before it opened.
    $closedOn = $isClosing ? ($data['closed_on'] ?? farmToday()) : null;
    if ($closedOn !== null && $closedOn < $case['opened_on']) {
        throw new HealthRuleViolation('A case cannot be closed before the date it was opened.');
    }

    tenantExec(
        $conn,
        "UPDATE health_cases
            SET status = ?, outcome = ?, closed_on = ?, follow_up_on = ?, severity = ?,
                notes = ?, closed_by = ?, updated_at = NOW()
          WHERE case_id = ? AND {scope}",
        [
            $newStatus,
            $outcome,
            $closedOn,
            $data['follow_up_on'] ?? null,
            $data['severity'] ?? $case['severity'],
            $data['notes'] ?? $case['notes'],
            $isClosing ? currentUserId() : null,
            $caseId,
        ]
    );

    auditUpdate('health_case', $caseId,
        ['status' => $case['status'], 'outcome' => $case['outcome'], 'closed_on' => $case['closed_on']],
        ['status' => $newStatus, 'outcome' => $outcome, 'closed_on' => $closedOn]
    );

    // Resolving the last open case is the natural moment to move the animal
    // out of an attention status — but only when asked, never implicitly.
    $target = $data['health_status'] ?? null;
    if ($target !== null) {
        $animal = animalFindOr404($conn, (int) $case['cow_id'], true);
        if (dfms_health_transition_allowed($animal['health_status'] ?? null, $target)) {
            healthSetStatus($conn, (int) $case['cow_id'], $target, [
                'case_id' => $caseId,
                'reason'  => 'Case ' . $case['case_reference'] . ' ' . $newStatus,
            ]);
        }
    }
    return true;
}

// ===========================================================================
// OBSERVATIONS
// ===========================================================================

/**
 * Record a clinical observation.
 *
 * $data['value'] is in the unit the USER entered ($data['input_unit'] for a
 * convertible kind); it is converted to canonical here so nothing downstream
 * has to know who typed it or which measurement system they were using.
 *
 * @throws HealthRuleViolation on an unknown type, unit or out-of-range value
 */
function healthRecordObservation(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);
    $type = (string) ($data['observation_type'] ?? '');

    if (!dfms_is_valid_observation_type($type)) {
        throw new HealthRuleViolation('That is not a recognised observation type.');
    }
    $meta = DFMS_OBSERVATION_TYPES[$type];

    $valueNumeric = null;
    $valueCode = null;
    $unit = null;

    if (dfms_observation_is_numeric($type)) {
        // isset() already excludes null, so an explicit null check here would
        // be dead code — an empty form field arrives as ''.
        if (!isset($data['value']) || $data['value'] === '') {
            throw new HealthRuleViolation('Enter a value for ' . strtolower($meta['label']) . '.');
        }
        $raw = (float) $data['value'];

        if (!empty($meta['kind'])) {
            // Convertible measurement: the input unit must be one this system
            // genuinely knows. An unsupported unit FAILS rather than being
            // treated as canonical — a silently mis-converted body temperature
            // is a clinical hazard, not a formatting quirk.
            $kind = (string) $meta['kind'];
            $inputUnit = strtolower((string) ($data['input_unit'] ?? dfms_display_unit($kind, currentMeasurementSystem())));
            try {
                $valueNumeric = dfms_to_canonical($raw, $kind, $inputUnit);
            } catch (InvalidArgumentException $e) {
                throw new HealthRuleViolation('That unit is not supported for ' . strtolower($meta['label']) . '.');
            }
            $unit = dfms_canonical_unit($kind);
        } else {
            $valueNumeric = $raw;
            $unit = $meta['unit'];
        }

        // Range check runs on the CANONICAL value, because the declared bounds
        // are canonical. A 105 °F fever converts to 40.6 °C and passes; 500 of
        // anything does not.
        if ($valueNumeric < $meta['min'] || $valueNumeric > $meta['max']) {
            throw new HealthRuleViolation(
                $meta['label'] . ' must be between ' . $meta['min'] . ' and ' . $meta['max']
                . ' ' . (string) $unit . '. Check the value and the unit.'
            );
        }
    } else {
        $scale = dfms_observation_scale($type);
        $code = (string) ($data['value_code'] ?? '');
        if (!isset($scale[$code])) {
            throw new HealthRuleViolation('Choose a value for ' . strtolower($meta['label']) . '.');
        }
        $valueCode = $code;
    }

    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);

    $observedAt = (string) ($data['observed_at'] ?? dfms_health_now());
    healthAssertNotFuture($observedAt, 'The time an observation was taken');

    $observationId = tenantInsert($conn, 'health_observations', [
        'cow_id'           => (int) $animal['cow_id'],
        'case_id'          => $caseId,
        'observation_type' => $type,
        'value_numeric'    => $valueNumeric,
        'value_code'       => $valueCode,
        'unit'             => $unit,
        'observed_at'      => $observedAt,
        'observed_by'      => currentUserId(),
        'notes'            => $data['notes'] ?? null,
    ]);

    auditCreate('health_observation', $observationId, [
        'cow_id'           => (int) $animal['cow_id'],
        'observation_type' => $type,
        'value_numeric'    => $valueNumeric,
        'value_code'       => $valueCode,
        'unit'             => $unit,
    ]);
    return $observationId;
}

/**
 * Validate that a case id, if supplied, belongs to this tenant AND to this
 * animal. Returns the id or null.
 *
 * Checking BOTH is the point: a case id from a form could name a real case on
 * the same farm but a different animal, which tenantFind() alone would accept.
 *
 * @throws HealthRuleViolation
 */
function healthResolveCaseId(PDO $conn, $caseId, int $animalId): ?int {
    if ($caseId === null || $caseId === '' || (int) $caseId < 1) {
        return null;
    }
    $case = healthFindCase($conn, $caseId);
    if ($case === null || (int) $case['cow_id'] !== $animalId) {
        throw new HealthRuleViolation('That health case does not belong to this animal.');
    }
    return (int) $case['case_id'];
}

function healthObservations(PDO $conn, int $animalId, int $limit = 20): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT o.*, u.username
           FROM health_observations o
           LEFT JOIN admin_users u ON u.admin_id = o.observed_by
          WHERE {scope} AND o.cow_id = ?
          ORDER BY o.observed_at DESC, o.observation_id DESC
          LIMIT " . (int) $limit,
        [$animalId],
        'o'
    );
}

// ===========================================================================
// SYMPTOMS & DIAGNOSES
// ===========================================================================

/** @throws HealthRuleViolation */
function healthAddSymptom(PDO $conn, int $caseId, array $data): int {
    $case = healthFindCaseOr404($conn, $caseId);
    healthAnimalOr404($conn, (int) $case['cow_id']);

    $code = (string) ($data['symptom_code'] ?? '');
    if (!dfms_is_valid_symptom($code)) {
        throw new HealthRuleViolation('That is not a recognised symptom.');
    }
    $label = $data['symptom_label'] ?? null;
    if ($code === 'other' && ($label === null || trim((string) $label) === '')) {
        throw new HealthRuleViolation('Describe the symptom when choosing "Other".');
    }

    $symptomId = tenantInsert($conn, 'health_symptoms', [
        'case_id'       => $caseId,
        'cow_id'        => (int) $case['cow_id'],
        'symptom_code'  => $code,
        'symptom_label' => $code === 'other' ? $label : null,
        'severity'      => $data['severity'] ?? 'mild',
        'onset_at'      => $data['onset_at'] ?? null,
        'notes'         => $data['notes'] ?? null,
        'recorded_by'   => currentUserId(),
    ]);

    auditCreate('health_symptom', $symptomId, [
        'case_id'      => $caseId,
        'cow_id'       => (int) $case['cow_id'],
        'symptom_code' => $code,
        'severity'     => $data['severity'] ?? 'mild',
    ]);
    return $symptomId;
}

function healthCaseSymptoms(PDO $conn, int $caseId): array {
    return tenantQuery(
        $conn,
        "SELECT s.*, u.username FROM health_symptoms s
           LEFT JOIN admin_users u ON u.admin_id = s.recorded_by
          WHERE {scope} AND s.case_id = ?
          ORDER BY s.created_at DESC, s.symptom_id DESC",
        [$caseId],
        's'
    );
}

/** @throws HealthRuleViolation */
function healthAddDiagnosis(PDO $conn, int $caseId, array $data): int {
    $case = healthFindCaseOr404($conn, $caseId);
    healthAnimalOr404($conn, (int) $case['cow_id']);

    $code = (string) ($data['condition_code'] ?? '');
    if (!dfms_is_valid_condition($code)) {
        throw new HealthRuleViolation('That is not a recognised condition.');
    }
    $label = $data['condition_label'] ?? null;
    if ($code === 'other' && ($label === null || trim((string) $label) === '')) {
        throw new HealthRuleViolation('Describe the condition when choosing "Other".');
    }
    $status = (string) ($data['status'] ?? 'suspected');
    if (!isset(DFMS_DIAGNOSIS_STATUSES[$status])) {
        throw new HealthRuleViolation('That is not a recognised diagnosis status.');
    }

    $consultationId = healthResolveConsultationId($conn, $data['consultation_id'] ?? null, (int) $case['cow_id']);

    $diagnosisId = tenantInsert($conn, 'health_diagnoses', [
        'case_id'         => $caseId,
        'cow_id'          => (int) $case['cow_id'],
        'condition_code'  => $code,
        'condition_label' => $code === 'other' ? $label : null,
        'status'          => $status,
        'confidence'      => isset(DFMS_DIAGNOSIS_CONFIDENCE[$data['confidence'] ?? '']) ? $data['confidence'] : null,
        'diagnosed_on'    => $data['diagnosed_on'],
        'diagnosed_by'    => currentUserId(),
        'consultation_id' => $consultationId,
        'notes'           => $data['notes'] ?? null,
    ]);

    auditCreate('health_diagnosis', $diagnosisId, [
        'case_id'        => $caseId,
        'cow_id'         => (int) $case['cow_id'],
        'condition_code' => $code,
        'status'         => $status,
        'confidence'     => $data['confidence'] ?? null,
    ]);
    return $diagnosisId;
}

/**
 * Change a diagnosis's status — the ONLY field that may change after
 * recording. A diagnosis that was wrong is marked ruled_out; it is never
 * rewritten into a different condition, because that erases what was actually
 * believed at the time.
 *
 * @throws HealthRuleViolation
 */
function healthUpdateDiagnosisStatus(PDO $conn, int $diagnosisId, string $status, ?string $notes = null): bool {
    $diagnosis = tenantFindOr404($conn, 'health_diagnoses', 'diagnosis_id', $diagnosisId);
    healthAnimalOr404($conn, (int) $diagnosis['cow_id']);

    if (!isset(DFMS_DIAGNOSIS_STATUSES[$status])) {
        throw new HealthRuleViolation('That is not a recognised diagnosis status.');
    }
    if ($status === $diagnosis['status']) {
        throw new HealthRuleViolation('That diagnosis is already ' . strtolower(DFMS_DIAGNOSIS_STATUSES[$status]['label']) . '.');
    }

    tenantExec(
        $conn,
        "UPDATE health_diagnoses SET status = ?, notes = ? WHERE diagnosis_id = ? AND {scope}",
        [$status, $notes ?? $diagnosis['notes'], $diagnosisId]
    );
    auditUpdate('health_diagnosis', $diagnosisId, ['status' => $diagnosis['status']], ['status' => $status]);
    return true;
}

function healthCaseDiagnoses(PDO $conn, int $caseId): array {
    return tenantQuery(
        $conn,
        "SELECT d.*, u.username, c.vet_name
           FROM health_diagnoses d
           LEFT JOIN admin_users u ON u.admin_id = d.diagnosed_by
           LEFT JOIN veterinary_consultations c ON c.consultation_id = d.consultation_id AND c.farm_id = d.farm_id
          WHERE {scope} AND d.case_id = ?
          ORDER BY FIELD(d.status, 'confirmed', 'suspected', 'resolved', 'ruled_out'), d.diagnosed_on DESC",
        [$caseId],
        'd'
    );
}

// ===========================================================================
// VETERINARY CONSULTATIONS
// ===========================================================================

/**
 * Record a consultation.
 *
 * The veterinarian is DESCRIPTIVE TEXT. No account is created, none is looked
 * up by name, and nothing here touches authentication. vet_user_id is set only
 * when the caller passes an id that is genuinely a member of this farm, which
 * is verified against farm_users rather than taken on trust.
 */
function healthRecordConsultation(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);
    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);

    $type = (string) ($data['consultation_type'] ?? 'on_farm');
    if (!isset(DFMS_CONSULTATION_TYPES[$type])) {
        throw new HealthRuleViolation('That is not a recognised consultation type.');
    }
    healthAssertNotFuture($data['consulted_at'] ?? null, 'A consultation time');

    $consultationId = tenantInsert($conn, 'veterinary_consultations', [
        'cow_id'            => (int) $animal['cow_id'],
        'case_id'           => $caseId,
        'vet_name'          => $data['vet_name'],
        'vet_practice'      => $data['vet_practice'] ?? null,
        'vet_registration'  => $data['vet_registration'] ?? null,
        'vet_contact'       => $data['vet_contact'] ?? null,
        'vet_user_id'       => healthResolveFarmMember($conn, $data['vet_user_id'] ?? null),
        'consultation_type' => $type,
        'consulted_at'      => $data['consulted_at'],
        'reason'            => $data['reason'] ?? null,
        'findings'          => $data['findings'] ?? null,
        'recommendations'   => $data['recommendations'] ?? null,
        'follow_up_on'      => $data['follow_up_on'] ?? null,
        'cost_amount'       => $data['cost_amount'] ?? null,
        'notes'             => $data['notes'] ?? null,
        'recorded_by'       => currentUserId(),
    ]);

    // Contact details are business contact information, not credentials, but
    // the vet's phone number still does not belong in an audit payload that is
    // read by every farm manager — only the identity of the record is logged.
    auditCreate('veterinary_consultation', $consultationId, [
        'cow_id'            => (int) $animal['cow_id'],
        'case_id'           => $caseId,
        'vet_name'          => $data['vet_name'],
        'consultation_type' => $type,
        'consulted_at'      => $data['consulted_at'],
    ]);
    return $consultationId;
}

/**
 * Confirm a user id is a member of the ACTIVE farm, or return null.
 *
 * Never trusts the submitted id: an arbitrary admin_id would otherwise attach
 * a stranger's identity to this farm's clinical record.
 */
function healthResolveFarmMember(PDO $conn, $userId): ?int {
    if ($userId === null || (int) $userId < 1) {
        return null;
    }
    $farmId = currentFarmId();
    if ($farmId === null) {
        return null;
    }
    $stmt = $conn->prepare("SELECT admin_id FROM farm_users WHERE admin_id = ? AND farm_id = ?");
    $stmt->execute([(int) $userId, $farmId]);
    $found = $stmt->fetchColumn();
    return $found === false ? null : (int) $found;
}

/** @throws HealthRuleViolation */
function healthResolveConsultationId(PDO $conn, $consultationId, int $animalId): ?int {
    if ($consultationId === null || $consultationId === '' || (int) $consultationId < 1) {
        return null;
    }
    $row = tenantFind($conn, 'veterinary_consultations', 'consultation_id', $consultationId);
    if ($row === null || (int) $row['cow_id'] !== $animalId) {
        throw new HealthRuleViolation('That consultation does not belong to this animal.');
    }
    return (int) $row['consultation_id'];
}

function healthConsultations(PDO $conn, int $animalId, int $limit = 20): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT c.* FROM veterinary_consultations c
          WHERE {scope} AND c.cow_id = ?
          ORDER BY c.consulted_at DESC LIMIT " . (int) $limit,
        [$animalId],
        'c'
    );
}

// ===========================================================================
// TREATMENTS
// ===========================================================================

/** @throws HealthRuleViolation */
function healthStartTreatment(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);
    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);

    $type = (string) ($data['treatment_type'] ?? 'medication');
    if (!isset(DFMS_TREATMENT_TYPES[$type])) {
        throw new HealthRuleViolation('That is not a recognised treatment type.');
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!isset(DFMS_TREATMENT_STATUSES[$status])) {
        throw new HealthRuleViolation('That is not a recognised treatment status.');
    }
    if (!empty($data['ended_on']) && $data['ended_on'] < $data['started_on']) {
        throw new HealthRuleViolation('A treatment cannot end before it starts.');
    }

    return tenantTransaction($conn, function (PDO $conn) use ($animal, $caseId, $type, $status, $data): int {
        $treatmentId = tenantInsert($conn, 'health_treatments', [
            'cow_id'              => (int) $animal['cow_id'],
            'case_id'             => $caseId,
            'diagnosis_id'        => healthResolveDiagnosisId($conn, $data['diagnosis_id'] ?? null, (int) $animal['cow_id']),
            'consultation_id'     => healthResolveConsultationId($conn, $data['consultation_id'] ?? null, (int) $animal['cow_id']),
            'treatment_type'      => $type,
            'name'                => $data['name'],
            'protocol'            => $data['protocol'] ?? null,
            'status'              => $status,
            'started_on'          => $data['started_on'],
            'ended_on'            => $data['ended_on'] ?? null,
            'responsible_name'    => $data['responsible_name'] ?? null,
            'responsible_user_id' => healthResolveFarmMember($conn, $data['responsible_user_id'] ?? null),
            'notes'               => $data['notes'] ?? null,
            'recorded_by'         => currentUserId(),
        ]);

        // An active treatment implies the case is being treated. Only ever a
        // widening of the case's own state, never a reversal of a resolution.
        if ($caseId !== null && $status === 'active') {
            tenantExec(
                $conn,
                "UPDATE health_cases SET status = 'treating', updated_at = NOW()
                  WHERE case_id = ? AND status IN ('open','monitoring') AND {scope}",
                [$caseId]
            );
        }

        auditCreate('health_treatment', $treatmentId, [
            'cow_id'         => (int) $animal['cow_id'],
            'case_id'        => $caseId,
            'treatment_type' => $type,
            'name'           => $data['name'],
            'status'         => $status,
            'started_on'     => $data['started_on'],
        ]);
        return $treatmentId;
    });
}

/** @throws HealthRuleViolation */
function healthResolveDiagnosisId(PDO $conn, $diagnosisId, int $animalId): ?int {
    if ($diagnosisId === null || $diagnosisId === '' || (int) $diagnosisId < 1) {
        return null;
    }
    $row = tenantFind($conn, 'health_diagnoses', 'diagnosis_id', $diagnosisId);
    if ($row === null || (int) $row['cow_id'] !== $animalId) {
        throw new HealthRuleViolation('That diagnosis does not belong to this animal.');
    }
    return (int) $row['diagnosis_id'];
}

/** @throws HealthRuleViolation */
function healthResolveTreatmentId(PDO $conn, $treatmentId, int $animalId): ?int {
    if ($treatmentId === null || $treatmentId === '' || (int) $treatmentId < 1) {
        return null;
    }
    $row = tenantFind($conn, 'health_treatments', 'treatment_id', $treatmentId);
    if ($row === null || (int) $row['cow_id'] !== $animalId) {
        throw new HealthRuleViolation('That treatment does not belong to this animal.');
    }
    return (int) $row['treatment_id'];
}

/**
 * Close out a treatment.
 *
 * HISTORY IS NOT OVERWRITTEN: only status, outcome, end date and notes change.
 * The treatment's identity — what was given, when it started, who prescribed it
 * — is fixed once recorded. A cancelled treatment stays visible with its
 * cancellation recorded, because "we started this and stopped" is clinically
 * different from "this never happened".
 *
 * @throws HealthRuleViolation
 */
function healthCompleteTreatment(PDO $conn, int $treatmentId, string $status, array $data = []): bool {
    $treatment = tenantFindOr404($conn, 'health_treatments', 'treatment_id', $treatmentId);
    healthAnimalOr404($conn, (int) $treatment['cow_id']);

    if (!isset(DFMS_TREATMENT_STATUSES[$status])) {
        throw new HealthRuleViolation('That is not a recognised treatment status.');
    }
    if (in_array($treatment['status'], ['completed', 'cancelled'], true)) {
        throw new HealthRuleViolation('This treatment is already ' . $treatment['status'] . ' and its history cannot be rewritten.');
    }

    $isFinal = in_array($status, ['completed', 'cancelled'], true);
    $outcome = $data['outcome'] ?? null;
    if ($status === 'completed' && ($outcome === null || !isset(DFMS_TREATMENT_OUTCOMES[$outcome]))) {
        throw new HealthRuleViolation('Record an outcome when completing a treatment. "Unknown" is a valid choice.');
    }
    if ($status !== 'completed') {
        $outcome = null;
    }

    $endedOn = $isFinal ? ($data['ended_on'] ?? farmToday()) : null;
    if ($endedOn !== null && $endedOn < $treatment['started_on']) {
        throw new HealthRuleViolation('A treatment cannot end before it starts.');
    }

    tenantExec(
        $conn,
        "UPDATE health_treatments
            SET status = ?, outcome = ?, ended_on = ?, notes = ?, updated_at = NOW()
          WHERE treatment_id = ? AND {scope}",
        [$status, $outcome, $endedOn, $data['notes'] ?? $treatment['notes'], $treatmentId]
    );

    auditUpdate('health_treatment', $treatmentId,
        ['status' => $treatment['status'], 'outcome' => $treatment['outcome'], 'ended_on' => $treatment['ended_on']],
        ['status' => $status, 'outcome' => $outcome, 'ended_on' => $endedOn]
    );
    return true;
}

function healthTreatments(PDO $conn, int $animalId, int $limit = 50): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT t.*, c.case_reference
           FROM health_treatments t
           LEFT JOIN health_cases c ON c.case_id = t.case_id AND c.farm_id = t.farm_id
          WHERE {scope} AND t.cow_id = ?
          ORDER BY t.started_on DESC, t.treatment_id DESC LIMIT " . (int) $limit,
        [$animalId],
        't'
    );
}

function healthActiveTreatments(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT t.* FROM health_treatments t
          WHERE {scope} AND t.cow_id = ? AND t.status IN ('planned','active')
          ORDER BY t.started_on DESC",
        [$animalId],
        't'
    );
}

// ===========================================================================
// MEDICATION ADMINISTRATION
//
// The most consequential write in this phase: it can consume inventory AND
// create a food-safety restriction, so it is the one place where a partial
// write would be genuinely dangerous. Everything happens in ONE transaction.
// ===========================================================================

/**
 * Record a medication administration.
 *
 * INVENTORY: consumed only when the caller asks for it AND the product is a
 * real inventory item on this farm. The row is locked FOR UPDATE — the same
 * pattern modules/inventory/update.php already uses — so two concurrent
 * administrations cannot both read the same starting quantity. Stock is never
 * driven below zero; the administration is refused instead, because silently
 * recording negative stock is how a medicine cabinet stops meaning anything.
 *
 * WITHDRAWAL: created from the periods the caller supplies. A period is NEVER
 * defaulted to zero — an unspecified withdrawal is recorded as "not specified"
 * and no restriction is written, which the UI states plainly. Inventing a
 * zero-day withdrawal would be the single most dangerous default in this
 * system.
 *
 * @return int the new administration id
 * @throws HealthRuleViolation
 */
function healthAdministerMedication(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);

    $doseUnit = (string) ($data['dose_unit'] ?? '');
    if (!isset(DFMS_DOSE_UNITS[$doseUnit])) {
        throw new HealthRuleViolation('That is not a recognised dose unit.');
    }
    $route = (string) ($data['route'] ?? '');
    if (!isset(DFMS_ADMIN_ROUTES[$route])) {
        throw new HealthRuleViolation('That is not a recognised administration route.');
    }
    $dose = (float) ($data['dose'] ?? 0);
    if ($dose <= 0) {
        throw new HealthRuleViolation('Enter a dose greater than zero.');
    }

    $administeredAt = (string) ($data['administered_at'] ?? dfms_health_now());
    healthAssertNotFuture($administeredAt, 'The time a medication was given');
    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);
    $treatmentId = healthResolveTreatmentId($conn, $data['treatment_id'] ?? null, (int) $animal['cow_id']);

    // Resolve the inventory item BEFORE the transaction so a bad id fails
    // before anything is written.
    $item = null;
    if (!empty($data['item_id'])) {
        $item = tenantFind($conn, 'inventory_items', 'item_id', $data['item_id']);
        if ($item === null) {
            throw new HealthRuleViolation('That medication is not in this farm\'s inventory.');
        }
    }

    $medicationName = trim((string) ($data['medication_name'] ?? ''));
    if ($medicationName === '' && $item !== null) {
        $medicationName = (string) $item['item_name'];
    }
    if ($medicationName === '') {
        throw new HealthRuleViolation('Name the medication that was administered.');
    }

    $deduct = !empty($data['deduct_stock']) && $item !== null;
    $quantity = $deduct ? (float) ($data['quantity_used'] ?? 0) : 0.0;
    if ($deduct && $quantity <= 0) {
        throw new HealthRuleViolation('Enter the quantity taken from stock, or turn stock deduction off.');
    }

    $withdrawals = healthNormaliseWithdrawalInput($data['withdrawals'] ?? [], $animal);

    return tenantTransaction($conn, function (PDO $conn) use (
        $animal, $item, $medicationName, $dose, $doseUnit, $route, $administeredAt,
        $caseId, $treatmentId, $deduct, $quantity, $withdrawals, $data
    ): int {
        $deducted = false;

        if ($deduct && $item !== null) {
            // Lock the level row. Scoped by farm_id as well as item_id so the
            // lock cannot be taken on another tenant's stock.
            $stmt = $conn->prepare(
                "SELECT inventory_id, quantity FROM inventory_levels
                  WHERE item_id = ? AND farm_id = ? FOR UPDATE"
            );
            $stmt->execute([(int) $item['item_id'], currentFarmId()]);
            $level = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($level === false) {
                throw new HealthRuleViolation('That medication has no stock record on this farm.');
            }
            $remaining = (float) $level['quantity'] - $quantity;
            if ($remaining < 0) {
                throw new HealthRuleViolation(
                    'Not enough stock: ' . fmt_number((float) $level['quantity'], 2) . ' '
                    . (string) $item['unit'] . ' remaining.'
                );
            }

            tenantExec(
                $conn,
                "UPDATE inventory_levels SET quantity = ? WHERE inventory_id = ? AND {scope}",
                [$remaining, (int) $level['inventory_id']]
            );
            $deducted = true;

            // Audited as a stock movement too, so the inventory trail shows why
            // the quantity fell without anyone opening the health module.
            auditLog('stock.adjust', 'inventory_level', (int) $level['inventory_id'],
                ['quantity' => (float) $level['quantity']],
                ['quantity' => $remaining, 'reason' => 'medication administration', 'cow_id' => (int) $animal['cow_id']]
            );
        }

        $administrationId = tenantInsert($conn, 'medication_administrations', [
            'cow_id'               => (int) $animal['cow_id'],
            'treatment_id'         => $treatmentId,
            'case_id'              => $caseId,
            'item_id'              => $item !== null ? (int) $item['item_id'] : null,
            'medication_name'      => $medicationName,
            'batch_lot'            => $data['batch_lot'] ?? null,
            'expiry_date'          => $data['expiry_date'] ?? null,
            'dose'                 => $dose,
            'dose_unit'            => $doseUnit,
            'route'                => $route,
            'frequency'            => $data['frequency'] ?? null,
            'administered_at'      => $administeredAt,
            'administered_by'      => currentUserId(),
            'administered_by_name' => $data['administered_by_name'] ?? null,
            'inventory_deducted'   => $deducted ? 1 : 0,
            'quantity_deducted'    => $deducted ? $quantity : null,
            'notes'                => $data['notes'] ?? null,
        ]);

        foreach ($withdrawals as $withdrawal) {
            healthCreateWithdrawal($conn, (int) $animal['cow_id'], [
                'administration_id' => $administrationId,
                'treatment_id'      => $treatmentId,
                'case_id'           => $caseId,
                'restriction_type'  => $withdrawal['type'],
                'source_label'      => $medicationName,
                'starts_on'         => substr($administeredAt, 0, 10),
                'days'              => $withdrawal['days'],
            ]);
        }

        auditCreate('medication_administration', $administrationId, [
            'cow_id'             => (int) $animal['cow_id'],
            'medication_name'    => $medicationName,
            'dose'               => $dose,
            'dose_unit'          => $doseUnit,
            'route'              => $route,
            'batch_lot'          => $data['batch_lot'] ?? null,
            'inventory_deducted' => $deducted,
            'withdrawals'        => count($withdrawals),
        ]);
        return $administrationId;
    });
}

/**
 * Turn submitted withdrawal input into a validated list.
 *
 * A type with no days, a blank days field or a zero is DROPPED rather than
 * turned into a zero-day restriction: "no withdrawal period was specified" and
 * "this product has no withdrawal period" are different claims, and only a
 * product label can make the second one.
 *
 * @return list<array{type: string, days: int}>
 * @throws HealthRuleViolation
 */
function healthNormaliseWithdrawalInput($input, array $animal): array {
    if (!is_array($input)) {
        return [];
    }
    $allowed = dfms_withdrawal_types_for_species($animal['species'] ?? null);
    $result = [];

    foreach ($input as $type => $days) {
        if ($days === null || $days === '' || !is_scalar($days)) {
            continue;
        }
        if (!preg_match('/^\d{1,4}$/', (string) $days)) {
            throw new HealthRuleViolation('A withdrawal period must be a whole number of days.');
        }
        $days = (int) $days;
        if ($days < 1) {
            continue;
        }
        if ($days > 365) {
            throw new HealthRuleViolation('A withdrawal period longer than a year looks like a typo. Check the value.');
        }
        if (!is_string($type) || !isset($allowed[$type])) {
            throw new HealthRuleViolation('That withdrawal restriction does not apply to this species.');
        }
        $result[] = ['type' => $type, 'days' => $days];
    }
    return $result;
}

function healthMedicationHistory(PDO $conn, int $animalId, int $limit = 50): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT m.*, i.item_name, i.unit AS item_unit, t.name AS treatment_name
           FROM medication_administrations m
           LEFT JOIN inventory_items i ON i.item_id = m.item_id AND i.farm_id = m.farm_id
           LEFT JOIN health_treatments t ON t.treatment_id = m.treatment_id AND t.farm_id = m.farm_id
          WHERE {scope} AND m.cow_id = ?
          ORDER BY m.administered_at DESC, m.administration_id DESC LIMIT " . (int) $limit,
        [$animalId],
        'm'
    );
}

// ===========================================================================
// VACCINATIONS
// ===========================================================================

/**
 * Schedule or record a vaccination.
 *
 * A row created with status 'scheduled' has administered_on NULL and STAYS
 * that way until healthMarkVaccinationGiven() is called by a person. Nothing
 * in this system promotes a schedule to a record on its own — an assumed
 * vaccination is worse than a missing one, because it stops anyone looking.
 *
 * @throws HealthRuleViolation
 */
function healthRecordVaccination(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);

    $status = (string) ($data['status'] ?? 'scheduled');
    if (!in_array($status, ['scheduled', 'administered'], true)) {
        throw new HealthRuleViolation('A vaccination is created either as scheduled or as administered.');
    }
    if ($status === 'scheduled' && empty($data['scheduled_on'])) {
        throw new HealthRuleViolation('A scheduled vaccination needs a scheduled date.');
    }
    if ($status === 'administered' && empty($data['administered_on'])) {
        throw new HealthRuleViolation('Record the date the vaccination was given.');
    }
    if (!empty($data['dose_unit']) && !isset(DFMS_DOSE_UNITS[$data['dose_unit']])) {
        throw new HealthRuleViolation('That is not a recognised dose unit.');
    }
    if (!empty($data['route']) && !isset(DFMS_ADMIN_ROUTES[$data['route']])) {
        throw new HealthRuleViolation('That is not a recognised administration route.');
    }

    $item = null;
    if (!empty($data['item_id'])) {
        $item = tenantFind($conn, 'inventory_items', 'item_id', $data['item_id']);
        if ($item === null) {
            throw new HealthRuleViolation('That vaccine is not in this farm\'s inventory.');
        }
    }

    $withdrawals = $status === 'administered'
        ? healthNormaliseWithdrawalInput($data['withdrawals'] ?? [], $animal)
        : [];

    return tenantTransaction($conn, function (PDO $conn) use ($animal, $data, $status, $item, $withdrawals): int {
        $vaccinationId = tenantInsert($conn, 'vaccination_records', [
            'cow_id'             => (int) $animal['cow_id'],
            'vaccine_name'       => $data['vaccine_name'],
            'disease_target'     => $data['disease_target'] ?? null,
            'item_id'            => $item !== null ? (int) $item['item_id'] : null,
            'status'             => $status,
            'scheduled_on'       => $data['scheduled_on'] ?? null,
            'administered_on'    => $status === 'administered' ? $data['administered_on'] : null,
            'dose'               => $data['dose'] ?? null,
            'dose_unit'          => $data['dose_unit'] ?? null,
            'route'              => $data['route'] ?? null,
            'batch_lot'          => $data['batch_lot'] ?? null,
            'expiry_date'        => $data['expiry_date'] ?? null,
            'administrator_name' => $data['administrator_name'] ?? null,
            'administered_by'    => $status === 'administered' ? currentUserId() : null,
            'next_due_on'        => $data['next_due_on'] ?? null,
            'notes'              => $data['notes'] ?? null,
            'recorded_by'        => currentUserId(),
        ]);

        foreach ($withdrawals as $withdrawal) {
            healthCreateWithdrawal($conn, (int) $animal['cow_id'], [
                'vaccination_id'   => $vaccinationId,
                'restriction_type' => $withdrawal['type'],
                'source_label'     => (string) $data['vaccine_name'],
                'starts_on'        => (string) $data['administered_on'],
                'days'             => $withdrawal['days'],
            ]);
        }

        auditCreate('vaccination', $vaccinationId, [
            'cow_id'          => (int) $animal['cow_id'],
            'vaccine_name'    => $data['vaccine_name'],
            'status'          => $status,
            'scheduled_on'    => $data['scheduled_on'] ?? null,
            'administered_on' => $status === 'administered' ? $data['administered_on'] : null,
            'batch_lot'       => $data['batch_lot'] ?? null,
        ]);
        return $vaccinationId;
    });
}

/**
 * Mark a scheduled vaccination as given — the explicit human action that the
 * passage of time never performs.
 *
 * @throws HealthRuleViolation
 */
function healthMarkVaccinationGiven(PDO $conn, int $vaccinationId, array $data): bool {
    $record = tenantFindOr404($conn, 'vaccination_records', 'vaccination_id', $vaccinationId);
    $animal = healthAnimalOr404($conn, (int) $record['cow_id']);

    if ($record['status'] !== 'scheduled') {
        throw new HealthRuleViolation('Only a scheduled vaccination can be marked as given.');
    }
    $administeredOn = (string) ($data['administered_on'] ?? farmToday());
    $withdrawals = healthNormaliseWithdrawalInput($data['withdrawals'] ?? [], $animal);

    tenantTransaction($conn, function (PDO $conn) use ($record, $vaccinationId, $administeredOn, $data, $withdrawals) {
        tenantExec(
            $conn,
            "UPDATE vaccination_records
                SET status = 'administered', administered_on = ?, administered_by = ?,
                    batch_lot = COALESCE(?, batch_lot), expiry_date = COALESCE(?, expiry_date),
                    administrator_name = COALESCE(?, administrator_name),
                    next_due_on = COALESCE(?, next_due_on)
              WHERE vaccination_id = ? AND {scope}",
            [
                $administeredOn, currentUserId(),
                $data['batch_lot'] ?? null, $data['expiry_date'] ?? null,
                $data['administrator_name'] ?? null, $data['next_due_on'] ?? null,
                $vaccinationId,
            ]
        );

        foreach ($withdrawals as $withdrawal) {
            healthCreateWithdrawal($conn, (int) $record['cow_id'], [
                'vaccination_id'   => $vaccinationId,
                'restriction_type' => $withdrawal['type'],
                'source_label'     => (string) $record['vaccine_name'],
                'starts_on'        => $administeredOn,
                'days'             => $withdrawal['days'],
            ]);
        }
    });

    auditUpdate('vaccination', $vaccinationId,
        ['status' => 'scheduled', 'administered_on' => null],
        ['status' => 'administered', 'administered_on' => $administeredOn]
    );
    return true;
}

/**
 * Correct a vaccination record WITHOUT editing it.
 *
 * The original is marked 'superseded' and retained; a new row carries the
 * corrected values and points back through supersedes_id. The mistake stays
 * visible in the history, which is the whole point of an immutable clinical
 * record.
 *
 * @throws HealthRuleViolation
 */
function healthSupersedeVaccination(PDO $conn, int $vaccinationId, array $data): int {
    $original = tenantFindOr404($conn, 'vaccination_records', 'vaccination_id', $vaccinationId);
    $animal = healthAnimalOr404($conn, (int) $original['cow_id']);

    if ($original['status'] === 'superseded') {
        throw new HealthRuleViolation('That record has already been superseded.');
    }

    return tenantTransaction($conn, function (PDO $conn) use ($original, $vaccinationId, $animal, $data): int {
        $replacementId = tenantInsert($conn, 'vaccination_records', [
            'cow_id'             => (int) $animal['cow_id'],
            'vaccine_name'       => $data['vaccine_name'] ?? $original['vaccine_name'],
            'disease_target'     => $data['disease_target'] ?? $original['disease_target'],
            'item_id'            => $original['item_id'],
            'status'             => $data['status'] ?? $original['status'],
            'scheduled_on'       => $data['scheduled_on'] ?? $original['scheduled_on'],
            'administered_on'    => $data['administered_on'] ?? $original['administered_on'],
            'dose'               => $data['dose'] ?? $original['dose'],
            'dose_unit'          => $data['dose_unit'] ?? $original['dose_unit'],
            'route'              => $data['route'] ?? $original['route'],
            'batch_lot'          => $data['batch_lot'] ?? $original['batch_lot'],
            'expiry_date'        => $data['expiry_date'] ?? $original['expiry_date'],
            'administrator_name' => $data['administrator_name'] ?? $original['administrator_name'],
            'administered_by'    => $original['administered_by'],
            'next_due_on'        => $data['next_due_on'] ?? $original['next_due_on'],
            'supersedes_id'      => $vaccinationId,
            'notes'              => $data['notes'] ?? null,
            'recorded_by'        => currentUserId(),
        ]);

        tenantExec(
            $conn,
            "UPDATE vaccination_records SET status = 'superseded' WHERE vaccination_id = ? AND {scope}",
            [$vaccinationId]
        );

        auditLog('vaccination.correct', 'vaccination', $vaccinationId,
            ['status' => $original['status'], 'vaccine_name' => $original['vaccine_name']],
            ['superseded_by' => $replacementId, 'reason' => $data['notes'] ?? null]
        );
        return $replacementId;
    });
}

function healthVaccinations(PDO $conn, int $animalId, int $limit = 50): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT v.*, i.item_name FROM vaccination_records v
           LEFT JOIN inventory_items i ON i.item_id = v.item_id AND i.farm_id = v.farm_id
          WHERE {scope} AND v.cow_id = ?
          ORDER BY COALESCE(v.administered_on, v.scheduled_on) DESC, v.vaccination_id DESC
          LIMIT " . (int) $limit,
        [$animalId],
        'v'
    );
}

// ===========================================================================
// QUARANTINE
// ===========================================================================

/**
 * Place an animal in isolation.
 *
 * Moves the health status to 'quarantined' in the SAME transaction, and
 * optionally moves the animal to an isolation location through the Phase 3
 * transfer helper, so the location history stays in one place.
 *
 * @throws HealthRuleViolation
 */
function healthStartQuarantine(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);

    $type = (string) ($data['quarantine_type'] ?? 'precautionary');
    if (!isset(DFMS_QUARANTINE_TYPES[$type])) {
        throw new HealthRuleViolation('That is not a recognised quarantine reason.');
    }

    // One active quarantine per animal. A second would make "is this animal
    // isolated" ambiguous and would double every alert.
    $existing = tenantQueryOne(
        $conn,
        "SELECT q.quarantine_id FROM health_quarantines q
          WHERE {scope} AND q.cow_id = ? AND q.status = 'active'",
        [(int) $animal['cow_id']],
        'q'
    );
    if ($existing !== null) {
        throw new HealthRuleViolation('This animal is already in quarantine. Release the current quarantine first.');
    }

    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);

    // A location, if given, must be one of this farm's — never a raw form id.
    $locationId = null;
    if (!empty($data['location_id'])) {
        $location = tenantFind($conn, 'locations', 'location_id', $data['location_id'], 'deleted_at IS NULL');
        if ($location === null) {
            throw new HealthRuleViolation('That isolation location is not on this farm.');
        }
        $locationId = (int) $location['location_id'];
    }

    $startedAt = (string) ($data['started_at'] ?? dfms_health_now());
    healthAssertNotFuture($startedAt, 'A quarantine start time');

    return tenantTransaction($conn, function (PDO $conn) use ($animal, $type, $caseId, $locationId, $startedAt, $data): int {
        $quarantineId = tenantInsert($conn, 'health_quarantines', [
            'cow_id'          => (int) $animal['cow_id'],
            'case_id'         => $caseId,
            'quarantine_type' => $type,
            'reason'          => $data['reason'],
            'location_id'     => $locationId,
            'started_at'      => $startedAt,
            'expected_end_on' => $data['expected_end_on'] ?? null,
            'status'          => 'active',
            'notes'           => $data['notes'] ?? null,
            'recorded_by'     => currentUserId(),
        ]);

        // Reuse the Phase 3 movement helper rather than writing location_id
        // directly, so the move appears in the animal's transfer history too.
        if ($locationId !== null && (int) ($animal['location_id'] ?? 0) !== $locationId) {
            animalMoveWithinFarm($conn, (int) $animal['cow_id'], $locationId, [
                'reason' => 'Quarantine: ' . $data['reason'],
            ]);
        }

        if (dfms_health_transition_allowed($animal['health_status'] ?? null, 'quarantined')) {
            healthSetStatus($conn, (int) $animal['cow_id'], 'quarantined', [
                'case_id' => $caseId,
                'reason'  => 'Quarantine started: ' . $data['reason'],
            ]);
        }

        auditCreate('health_quarantine', $quarantineId, [
            'cow_id'          => (int) $animal['cow_id'],
            'quarantine_type' => $type,
            'reason'          => $data['reason'],
            'location_id'     => $locationId,
            'started_at'      => $startedAt,
        ]);
        return $quarantineId;
    });
}

/**
 * End a quarantine and move the animal to a health status the operator chooses.
 *
 * The new status is NOT guessed. Releasing from isolation can mean recovered,
 * still under observation, or still sick but no longer contagious, and only the
 * person releasing knows which.
 *
 * @throws HealthRuleViolation
 */
function healthEndQuarantine(PDO $conn, int $quarantineId, array $data): bool {
    $quarantine = tenantFindOr404($conn, 'health_quarantines', 'quarantine_id', $quarantineId);
    $animal = healthAnimalOr404($conn, (int) $quarantine['cow_id']);

    if ($quarantine['status'] !== 'active') {
        throw new HealthRuleViolation('That quarantine is already ' . $quarantine['status'] . '.');
    }
    $status = (string) ($data['status'] ?? 'released');
    if (!in_array($status, ['released', 'cancelled'], true)) {
        throw new HealthRuleViolation('A quarantine is ended by releasing or cancelling it.');
    }

    $endedAt = (string) ($data['ended_at'] ?? dfms_health_now());
    if ($endedAt < $quarantine['started_at']) {
        throw new HealthRuleViolation('A quarantine cannot end before it started.');
    }

    $newHealthStatus = $data['health_status'] ?? null;
    if ($newHealthStatus !== null && !dfms_is_valid_health_status($newHealthStatus)) {
        throw new HealthRuleViolation('That is not a recognised health status.');
    }

    tenantTransaction($conn, function (PDO $conn) use ($quarantineId, $quarantine, $animal, $status, $endedAt, $newHealthStatus, $data) {
        tenantExec(
            $conn,
            "UPDATE health_quarantines
                SET status = ?, ended_at = ?, released_by = ?, release_notes = ?
              WHERE quarantine_id = ? AND {scope}",
            [$status, $endedAt, currentUserId(), $data['release_notes'] ?? null, $quarantineId]
        );

        if ($newHealthStatus !== null
            && dfms_health_transition_allowed($animal['health_status'] ?? null, $newHealthStatus)) {
            healthSetStatus($conn, (int) $animal['cow_id'], $newHealthStatus, [
                'case_id' => $quarantine['case_id'] !== null ? (int) $quarantine['case_id'] : null,
                'reason'  => 'Quarantine ' . $status,
            ]);
        }
    });

    auditUpdate('health_quarantine', $quarantineId,
        ['status' => 'active', 'ended_at' => null],
        ['status' => $status, 'ended_at' => $endedAt, 'health_status' => $newHealthStatus]
    );
    return true;
}

function healthQuarantines(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT q.*, l.name AS location_name FROM health_quarantines q
           LEFT JOIN locations l ON l.location_id = q.location_id AND l.farm_id = q.farm_id
          WHERE {scope} AND q.cow_id = ?
          ORDER BY q.started_at DESC",
        [$animalId],
        'q'
    );
}

function healthActiveQuarantine(PDO $conn, int $animalId): ?array {
    return tenantQueryOne(
        $conn,
        "SELECT q.*, l.name AS location_name FROM health_quarantines q
           LEFT JOIN locations l ON l.location_id = q.location_id AND l.farm_id = q.farm_id
          WHERE {scope} AND q.cow_id = ? AND q.status = 'active'
          ORDER BY q.started_at DESC",
        [$animalId],
        'q'
    );
}

// ===========================================================================
// WITHDRAWAL PERIODS
//
// SCOPE LIMIT, STATED PLAINLY AND REPEATEDLY
// milk_production stores a FARM-LEVEL daily total with no cow_id. This system
// therefore TRACKS and SURFACES an individual animal's restriction but cannot
// verify that its milk was excluded from the farm total, because the data model
// has no place to record whose milk went into the tank. The daily production
// form warns when any animal is restricted; it cannot do more than warn, and
// nothing in this codebase claims that it does.
// ===========================================================================

/**
 * Create one withdrawal restriction. Always called inside a transaction owned
 * by the administration or vaccination that caused it.
 *
 * ends_on is INCLUSIVE: a 4-day withdrawal starting on the 1st ends on the 4th
 * and the animal is clear on the 5th.
 */
function healthCreateWithdrawal(PDO $conn, int $animalId, array $data): int {
    $days = (int) $data['days'];
    $startsOn = (string) $data['starts_on'];
    $endsOn = (new DateTimeImmutable($startsOn))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');

    $withdrawalId = tenantInsert($conn, 'withdrawal_periods', [
        'cow_id'            => $animalId,
        'administration_id' => $data['administration_id'] ?? null,
        'vaccination_id'    => $data['vaccination_id'] ?? null,
        'treatment_id'      => $data['treatment_id'] ?? null,
        'case_id'           => $data['case_id'] ?? null,
        'restriction_type'  => $data['restriction_type'],
        'source_label'      => $data['source_label'],
        'starts_on'         => $startsOn,
        'ends_on'           => $endsOn,
        'status'            => 'active',
        'notes'             => $data['notes'] ?? null,
        'recorded_by'       => currentUserId(),
    ]);

    auditCreate('withdrawal_period', $withdrawalId, [
        'cow_id'           => $animalId,
        'restriction_type' => $data['restriction_type'],
        'source_label'     => $data['source_label'],
        'starts_on'        => $startsOn,
        'ends_on'          => $endsOn,
        'days'             => $days,
    ]);
    return $withdrawalId;
}

/**
 * Cancel a restriction — for a correction, never to make a product saleable.
 *
 * Requires a reason and is fully audited, because lifting a food-safety
 * restriction is exactly the action an investigation would want to trace.
 *
 * @throws HealthRuleViolation
 */
function healthCancelWithdrawal(PDO $conn, int $withdrawalId, string $reason): bool {
    $withdrawal = tenantFindOr404($conn, 'withdrawal_periods', 'withdrawal_id', $withdrawalId);

    if ($withdrawal['status'] !== 'active') {
        throw new HealthRuleViolation('That restriction is already ' . $withdrawal['status'] . '.');
    }
    if (trim($reason) === '') {
        throw new HealthRuleViolation('Give a reason for cancelling a withdrawal restriction.');
    }

    tenantExec(
        $conn,
        "UPDATE withdrawal_periods SET status = 'cancelled', cancelled_reason = ?
          WHERE withdrawal_id = ? AND {scope}",
        [$reason, $withdrawalId]
    );

    auditLog('withdrawal.cancel', 'withdrawal_period', $withdrawalId,
        ['status' => 'active', 'ends_on' => $withdrawal['ends_on'], 'restriction_type' => $withdrawal['restriction_type']],
        ['status' => 'cancelled', 'reason' => $reason]
    );
    return true;
}

/**
 * Restrictions in force for one animal today.
 *
 * "In force" is computed from the dates rather than stored, so a restriction
 * lapses on the right day without anything having to run. `status` only ever
 * removes a row from consideration (cancelled), never adds one.
 */
function healthActiveWithdrawals(PDO $conn, int $animalId): array {
    $today = farmToday();
    return tenantQuery(
        $conn,
        "SELECT w.* FROM withdrawal_periods w
          WHERE {scope} AND w.cow_id = ? AND w.status = 'active'
            AND w.starts_on <= ? AND w.ends_on >= ?
          ORDER BY w.ends_on ASC",
        [$animalId, $today, $today],
        'w'
    );
}

function healthWithdrawalHistory(PDO $conn, int $animalId, int $limit = 50): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT w.* FROM withdrawal_periods w
          WHERE {scope} AND w.cow_id = ?
          ORDER BY w.starts_on DESC, w.withdrawal_id DESC LIMIT " . (int) $limit,
        [$animalId],
        'w'
    );
}

/**
 * Every animal on this farm under an active restriction today.
 *
 * This is what the milk-production page reads to warn the operator. It is a
 * WARNING, not an enforcement gate — see the scope limit above.
 */
function healthFarmActiveWithdrawals(PDO $conn, ?string $restrictionType = null): array {
    $today = farmToday();
    $params = [$today, $today];
    $filter = '';
    if ($restrictionType !== null && dfms_is_valid_withdrawal_type($restrictionType)) {
        $filter = ' AND w.restriction_type = ?';
        $params[] = $restrictionType;
    }
    return tenantQuery(
        $conn,
        "SELECT w.*, c.unique_id, c.name, c.species
           FROM withdrawal_periods w
           JOIN cows c ON c.cow_id = w.cow_id AND c.farm_id = w.farm_id
          WHERE {scope} AND w.status = 'active' AND w.starts_on <= ? AND w.ends_on >= ?" . $filter . "
          ORDER BY w.ends_on ASC, c.unique_id",
        $params,
        'w'
    );
}

/** Days remaining on a restriction, counting the inclusive end date. */
function healthWithdrawalDaysRemaining(array $withdrawal): int {
    $today = new DateTimeImmutable(farmToday());
    $ends = new DateTimeImmutable((string) $withdrawal['ends_on']);
    $diff = (int) $today->diff($ends)->format('%r%a');
    return max(0, $diff + 1);
}

// ===========================================================================
// LABORATORY TESTS
// ===========================================================================

/** @throws HealthRuleViolation */
function healthRecordLabTest(PDO $conn, int $animalId, array $data): int {
    $animal = healthAnimalOr404($conn, $animalId);
    $caseId = healthResolveCaseId($conn, $data['case_id'] ?? null, (int) $animal['cow_id']);

    $type = (string) ($data['test_type'] ?? '');
    if (!isset(DFMS_LAB_TEST_TYPES[$type])) {
        throw new HealthRuleViolation('That is not a recognised test type.');
    }
    $resultStatus = (string) ($data['result_status'] ?? 'pending');
    if (!in_array($resultStatus, ['pending', 'received', 'inconclusive', 'cancelled'], true)) {
        throw new HealthRuleViolation('That is not a recognised result status.');
    }
    $resultCode = $data['result_code'] ?? null;
    if ($resultCode !== null && $resultCode !== '' && !isset(DFMS_LAB_RESULT_CODES[$resultCode])) {
        throw new HealthRuleViolation('That is not a recognised result.');
    }
    if ($resultStatus === 'pending' && ($resultCode || !empty($data['result_numeric']))) {
        throw new HealthRuleViolation('A test with a result is not pending. Set the status to received.');
    }
    if (!empty($data['result_received_on']) && $data['result_received_on'] < $data['sample_taken_on']) {
        throw new HealthRuleViolation('A result cannot be received before the sample was taken.');
    }

    $labTestId = tenantInsert($conn, 'laboratory_tests', [
        'cow_id'             => (int) $animal['cow_id'],
        'case_id'            => $caseId,
        'test_type'          => $type,
        'test_name'          => $data['test_name'],
        'laboratory_name'    => $data['laboratory_name'] ?? null,
        'sample_type'        => $data['sample_type'] ?? null,
        'sample_taken_on'    => $data['sample_taken_on'],
        'result_received_on' => $data['result_received_on'] ?? null,
        'result_status'      => $resultStatus,
        'result_numeric'     => $data['result_numeric'] ?? null,
        'result_unit'        => $data['result_unit'] ?? null,
        'result_code'        => $resultCode ?: null,
        'reference_range'    => $data['reference_range'] ?? null,
        'interpretation'     => $data['interpretation'] ?? null,
        'notes'              => $data['notes'] ?? null,
        'recorded_by'        => currentUserId(),
    ]);

    auditCreate('laboratory_test', $labTestId, [
        'cow_id'          => (int) $animal['cow_id'],
        'case_id'         => $caseId,
        'test_type'       => $type,
        'test_name'       => $data['test_name'],
        'result_status'   => $resultStatus,
        'result_code'     => $resultCode ?: null,
    ]);
    return $labTestId;
}

function healthLabTests(PDO $conn, int $animalId, int $limit = 50): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT l.* FROM laboratory_tests l
          WHERE {scope} AND l.cow_id = ?
          ORDER BY l.sample_taken_on DESC, l.lab_test_id DESC LIMIT " . (int) $limit,
        [$animalId],
        'l'
    );
}

/** Documents attached to a laboratory test, through the Phase 1 file system. */
function healthLabTestFiles(PDO $conn, int $labTestId): array {
    return tenantQuery(
        $conn,
        "SELECT f.* FROM stored_files f
          WHERE {scope} AND f.entity_type = 'lab_test' AND f.entity_id = ? AND f.deleted_at IS NULL
          ORDER BY f.created_at DESC",
        [$labTestId],
        'f'
    );
}

// ===========================================================================
// ANIMAL HEALTH SUMMARY — one call for the profile, the module and the API.
//
// Everything the three surfaces show comes from here, so they cannot disagree
// about whether an animal is under treatment.
// ===========================================================================

function healthAnimalSummary(PDO $conn, int $animalId): array {
    $animal = animalFindOr404($conn, $animalId, true);

    $activeCases = tenantQuery(
        $conn,
        "SELECT c.* FROM health_cases c
          WHERE {scope} AND c.cow_id = ? AND c.status IN ('open','monitoring','treating')
          ORDER BY FIELD(c.severity,'urgent','moderate','routine'), c.opened_on DESC",
        [$animalId],
        'c'
    );

    $upcomingVaccinations = tenantQuery(
        $conn,
        "SELECT v.* FROM vaccination_records v
          WHERE {scope} AND v.cow_id = ? AND v.status = 'scheduled'
          ORDER BY v.scheduled_on ASC LIMIT 5",
        [$animalId],
        'v'
    );

    // Whether this animal has ANY vaccination history at all. The UI uses it to
    // say "no records" rather than implying the animal is unvaccinated.
    $vaccinationCount = (int) tenantScalar(
        $conn,
        "SELECT COUNT(*) FROM vaccination_records v WHERE {scope} AND v.cow_id = ?
           AND v.status IN ('administered','superseded')",
        [$animalId],
        0,
        'v'
    );

    return [
        'animal'                => $animal,
        'health_status'         => $animal['health_status'] ?? 'healthy',
        'lifecycle_blocks'      => healthLifecycleBlocks($animal),
        'active_cases'          => $activeCases,
        'active_treatments'     => healthActiveTreatments($conn, $animalId),
        'active_withdrawals'    => healthActiveWithdrawals($conn, $animalId),
        'active_quarantine'     => healthActiveQuarantine($conn, $animalId),
        'upcoming_vaccinations' => $upcomingVaccinations,
        'vaccination_count'     => $vaccinationCount,
        'recent_observations'   => healthObservations($conn, $animalId, 8),
        'recent_lab_tests'      => healthLabTests($conn, $animalId, 5),
    ];
}

// ===========================================================================
// MODULE LISTINGS — search, filter and paginate, all tenant-scoped.
//
// Pagination follows the existing convention (page/perPage in, items + total +
// pages out) so the shared dfms_pagination() component renders it unchanged.
// ===========================================================================

/**
 * Search health cases on the ACTIVE farm.
 *
 * Filters are validated against the registries before they reach SQL, so an
 * unrecognised status becomes "no filter" rather than an injected fragment.
 * Free-text search is parameterised and covers the case reference, the title
 * and the animal's tag and name.
 *
 * @return array{items: array, total: int, page: int, pages: int}
 */
function healthSearchCases(PDO $conn, array $filters = [], int $page = 1, int $perPage = 25): array {
    $where = [];
    $params = [];

    $status = (string) ($filters['status'] ?? '');
    if ($status === 'active') {
        $where[] = "hc.status IN ('open','monitoring','treating')";
    } elseif ($status === 'closed') {
        $where[] = "hc.status IN ('resolved','closed')";
    } elseif (isset(DFMS_CASE_STATUSES[$status])) {
        $where[] = 'hc.status = ?';
        $params[] = $status;
    }

    if (!empty($filters['case_type']) && isset(DFMS_CASE_TYPES[$filters['case_type']])) {
        $where[] = 'hc.case_type = ?';
        $params[] = $filters['case_type'];
    }
    if (!empty($filters['severity']) && in_array($filters['severity'], DFMS_CASE_SEVERITIES, true)) {
        $where[] = 'hc.severity = ?';
        $params[] = $filters['severity'];
    }
    if (!empty($filters['cow_id'])) {
        $where[] = 'hc.cow_id = ?';
        $params[] = (int) $filters['cow_id'];
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(hc.case_reference LIKE ? OR hc.title LIKE ? OR c.unique_id LIKE ? OR c.name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $clause = $where ? ' AND ' . implode(' AND ', $where) : '';
    // The join is constrained on c.farm_id as well, so a case whose farm_id
    // ever drifted from its animal's cannot surface here — the same defence
    // the existing health list already applies.
    $from = "FROM health_cases hc JOIN cows c ON c.cow_id = hc.cow_id AND c.farm_id = hc.farm_id
             WHERE {scope}" . $clause;

    $total = (int) tenantScalar($conn, "SELECT COUNT(*) $from", $params, 0, 'hc');

    $perPage = max(1, min($perPage, 100));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    $items = tenantQuery(
        $conn,
        "SELECT hc.*, c.unique_id, c.name, c.species, c.health_status $from
          ORDER BY FIELD(hc.status,'open','treating','monitoring','resolved','closed'),
                   FIELD(hc.severity,'urgent','moderate','routine'),
                   hc.opened_on DESC, hc.case_id DESC
          LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params,
        'hc'
    );

    return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Treatments that are still planned or running, across the farm. */
function healthFarmActiveTreatments(PDO $conn, int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    return tenantQuery(
        $conn,
        "SELECT t.*, c.unique_id, c.name, hc.case_reference
           FROM health_treatments t
           JOIN cows c ON c.cow_id = t.cow_id AND c.farm_id = t.farm_id
           LEFT JOIN health_cases hc ON hc.case_id = t.case_id AND hc.farm_id = t.farm_id
          WHERE {scope} AND t.status IN ('planned','active')
          ORDER BY t.started_on DESC, t.treatment_id DESC LIMIT " . (int) $limit,
        [],
        't'
    );
}

/** Animals currently in isolation, across the farm. */
function healthFarmActiveQuarantines(PDO $conn, int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    return tenantQuery(
        $conn,
        "SELECT q.*, c.unique_id, c.name, l.name AS location_name
           FROM health_quarantines q
           JOIN cows c ON c.cow_id = q.cow_id AND c.farm_id = q.farm_id
           LEFT JOIN locations l ON l.location_id = q.location_id AND l.farm_id = q.farm_id
          WHERE {scope} AND q.status = 'active'
          ORDER BY q.started_at DESC LIMIT " . (int) $limit,
        [],
        'q'
    );
}

/**
 * Vaccinations scheduled or falling due within $days.
 *
 * Covers BOTH sources of a due date: an explicitly scheduled dose, and the
 * next_due_on recorded against a dose that was already given. A farm that only
 * ever records "next due" would otherwise see nothing here.
 */
function healthUpcomingVaccinations(PDO $conn, int $days = 30, int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    $days = max(0, min($days, 365));
    $today = farmToday();
    $horizon = farmDateOffset($days);

    return tenantQuery(
        $conn,
        "SELECT v.*, c.unique_id, c.name,
                COALESCE(v.scheduled_on, v.next_due_on) AS due_on
           FROM vaccination_records v
           JOIN cows c ON c.cow_id = v.cow_id AND c.farm_id = v.farm_id
          WHERE {scope} AND c.deleted_at IS NULL
            AND (
                  (v.status = 'scheduled' AND v.scheduled_on IS NOT NULL AND v.scheduled_on <= ?)
               OR (v.status = 'administered' AND v.next_due_on IS NOT NULL AND v.next_due_on <= ?)
            )
            AND COALESCE(v.scheduled_on, v.next_due_on) >= DATE_SUB(?, INTERVAL 365 DAY)
          ORDER BY due_on ASC LIMIT " . (int) $limit,
        [$horizon, $horizon, $today],
        'v'
    );
}

/** Cases whose follow-up date has arrived and that are still open. */
function healthDueFollowUps(PDO $conn, int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    return tenantQuery(
        $conn,
        "SELECT hc.*, c.unique_id, c.name FROM health_cases hc
           JOIN cows c ON c.cow_id = hc.cow_id AND c.farm_id = hc.farm_id
          WHERE {scope} AND hc.follow_up_on IS NOT NULL AND hc.follow_up_on <= ?
            AND hc.status IN ('open','monitoring','treating')
          ORDER BY hc.follow_up_on ASC LIMIT " . (int) $limit,
        [farmToday()],
        'hc'
    );
}

/** Headline counters for the health module and the dashboard. */
function healthFarmCounters(PDO $conn): array {
    $today = farmToday();
    $attention = dfms_health_attention_statuses();
    $placeholders = implode(',', array_fill(0, count($attention), '?'));

    return [
        'active_cases' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM health_cases hc WHERE {scope} AND hc.status IN ('open','monitoring','treating')",
            [], 0, 'hc'
        ),
        'urgent_cases' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM health_cases hc WHERE {scope} AND hc.severity = 'urgent'
               AND hc.status IN ('open','monitoring','treating')",
            [], 0, 'hc'
        ),
        'under_treatment' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(DISTINCT t.cow_id) FROM health_treatments t
              WHERE {scope} AND t.status IN ('planned','active')",
            [], 0, 't'
        ),
        'quarantined' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM health_quarantines q WHERE {scope} AND q.status = 'active'",
            [], 0, 'q'
        ),
        'withdrawals' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM withdrawal_periods w WHERE {scope} AND w.status = 'active'
               AND w.starts_on <= ? AND w.ends_on >= ?",
            [$today, $today], 0, 'w'
        ),
        'needs_attention' => (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM cows c WHERE {scope} AND c.deleted_at IS NULL
               AND c.health_status IN ($placeholders)",
            $attention, 0, 'c'
        ),
        'vaccinations_due' => count(healthUpcomingVaccinations($conn, 14, 500)),
    ];
}

// ===========================================================================
// HEALTH ALERTS
//
// REUSES the existing `alerts` table — no new alert store, no parallel queue.
//
// WHAT PHASE 1 ACTUALLY LEFT (audited, not assumed)
// The alerts table has generic (entity_type, entity_id) targeting and a
// read/unread flag, and modules/alerts/ has full CRUD. What it does NOT have is
// any generator: every alert in the system today was typed in by a person.
// There was therefore no existing deduplication or auto-resolution mechanism to
// follow, so this phase builds the smallest honest one and says so plainly
// rather than claiming to reuse something that was not there.
//
// THE CONTRACT
//   1. An alert is identified by (alert_type, entity_type, entity_id). Syncing
//      twice produces ONE row, so a page load can never spam the queue.
//   2. An alert whose condition no longer holds is AUTO-RESOLVED — marked read
//      by the system rather than deleted, so the history survives.
//   3. Only unread rows are touched. An alert a person has already
//      acknowledged is never resurrected by a later sync.
// ===========================================================================

const DFMS_HEALTH_ALERT_TYPES = [
    'Vaccination due',
    'Treatment follow-up due',
    'Withdrawal period ending',
    'Quarantine review due',
    'Unresolved urgent health case',
];

/**
 * Reconcile health alerts for the active farm with the current data.
 *
 * Idempotent by construction: it computes the set of conditions that hold right
 * now, inserts the ones missing, and resolves the unread ones that no longer
 * hold. Calling it twice in a row changes nothing the second time.
 *
 * @return array{created: int, resolved: int}
 */
function healthSyncAlerts(PDO $conn): array {
    $desired = [];
    $today = farmToday();

    // --- vaccinations due within 7 days -----------------------------------
    foreach (healthUpcomingVaccinations($conn, 7, 200) as $vaccination) {
        $desired[] = [
            'alert_type'  => 'Vaccination due',
            'entity_type' => 'cow',
            'entity_id'   => (int) $vaccination['cow_id'],
            'cow_id'      => (int) $vaccination['cow_id'],
            'severity'    => ((string) ($vaccination['due_on'] ?? $today)) <= $today ? 'warning' : 'info',
            'description' => ($vaccination['name'] ?: $vaccination['unique_id'])
                . ' — ' . $vaccination['vaccine_name'] . ' due ' . (string) $vaccination['due_on'],
        ];
    }

    // --- case follow-ups that have come due -------------------------------
    foreach (healthDueFollowUps($conn, 200) as $case) {
        $desired[] = [
            'alert_type'  => 'Treatment follow-up due',
            'entity_type' => 'cow',
            'entity_id'   => (int) $case['cow_id'],
            'cow_id'      => (int) $case['cow_id'],
            'severity'    => $case['severity'] === 'urgent' ? 'critical' : 'warning',
            'description' => ($case['name'] ?: $case['unique_id']) . ' — ' . $case['case_reference']
                . ' follow-up due ' . (string) $case['follow_up_on'],
        ];
    }

    // --- withdrawal periods ending within 3 days --------------------------
    // Worth an alert because the END of a restriction is the moment a decision
    // gets made about the animal's product.
    $horizon = farmDateOffset(3);
    foreach (tenantQuery(
        $conn,
        "SELECT w.*, c.unique_id, c.name FROM withdrawal_periods w
           JOIN cows c ON c.cow_id = w.cow_id AND c.farm_id = w.farm_id
          WHERE {scope} AND w.status = 'active' AND w.ends_on BETWEEN ? AND ?",
        [$today, $horizon],
        'w'
    ) as $withdrawal) {
        $label = DFMS_WITHDRAWAL_TYPES[$withdrawal['restriction_type']]['label'] ?? $withdrawal['restriction_type'];
        $desired[] = [
            'alert_type'  => 'Withdrawal period ending',
            'entity_type' => 'cow',
            'entity_id'   => (int) $withdrawal['cow_id'],
            'cow_id'      => (int) $withdrawal['cow_id'],
            'severity'    => 'info',
            'description' => ($withdrawal['name'] ?: $withdrawal['unique_id']) . ' — ' . $label
                . ' withdrawal ends ' . (string) $withdrawal['ends_on'] . ' (' . $withdrawal['source_label'] . ')',
        ];
    }

    // --- quarantines past their expected end ------------------------------
    foreach (tenantQuery(
        $conn,
        "SELECT q.*, c.unique_id, c.name FROM health_quarantines q
           JOIN cows c ON c.cow_id = q.cow_id AND c.farm_id = q.farm_id
          WHERE {scope} AND q.status = 'active' AND q.expected_end_on IS NOT NULL AND q.expected_end_on <= ?",
        [$today],
        'q'
    ) as $quarantine) {
        $desired[] = [
            'alert_type'  => 'Quarantine review due',
            'entity_type' => 'cow',
            'entity_id'   => (int) $quarantine['cow_id'],
            'cow_id'      => (int) $quarantine['cow_id'],
            'severity'    => 'warning',
            'description' => ($quarantine['name'] ?: $quarantine['unique_id'])
                . ' — quarantine was expected to end ' . (string) $quarantine['expected_end_on'],
        ];
    }

    // --- urgent cases still open after 3 days -----------------------------
    // The delay is what keeps this from firing on every case the moment it is
    // opened, which would make it noise instead of a signal.
    foreach (tenantQuery(
        $conn,
        "SELECT hc.*, c.unique_id, c.name FROM health_cases hc
           JOIN cows c ON c.cow_id = hc.cow_id AND c.farm_id = hc.farm_id
          WHERE {scope} AND hc.severity = 'urgent'
            AND hc.status IN ('open','monitoring','treating')
            AND hc.opened_on <= DATE_SUB(?, INTERVAL 3 DAY)",
        [$today],
        'hc'
    ) as $case) {
        $desired[] = [
            'alert_type'  => 'Unresolved urgent health case',
            'entity_type' => 'cow',
            'entity_id'   => (int) $case['cow_id'],
            'cow_id'      => (int) $case['cow_id'],
            'severity'    => 'critical',
            'description' => ($case['name'] ?: $case['unique_id']) . ' — ' . $case['case_reference']
                . ' (' . $case['title'] . ') open since ' . (string) $case['opened_on'],
        ];
    }

    // Collapse duplicates: two due vaccinations for one animal are one alert,
    // not two identical rows.
    $unique = [];
    foreach ($desired as $alert) {
        $unique[$alert['alert_type'] . '|' . $alert['entity_type'] . '|' . $alert['entity_id']] = $alert;
    }

    // Existing UNREAD health alerts. Read ones are left entirely alone.
    $existing = [];
    $typePlaceholders = implode(',', array_fill(0, count(DFMS_HEALTH_ALERT_TYPES), '?'));
    foreach (tenantQuery(
        $conn,
        "SELECT a.alert_id, a.alert_type, a.entity_type, a.entity_id FROM alerts a
          WHERE {scope} AND a.is_read = 0 AND a.alert_type IN ($typePlaceholders)",
        DFMS_HEALTH_ALERT_TYPES,
        'a'
    ) as $row) {
        $existing[$row['alert_type'] . '|' . $row['entity_type'] . '|' . (int) $row['entity_id']] = (int) $row['alert_id'];
    }

    $created = 0;
    $resolved = 0;

    foreach ($unique as $key => $alert) {
        if (isset($existing[$key])) {
            continue;   // already raised and not yet acknowledged
        }
        tenantInsert($conn, 'alerts', [
            'entity_type' => $alert['entity_type'],
            'entity_id'   => $alert['entity_id'],
            'cow_id'      => $alert['cow_id'],
            'alert_time'  => dfms_health_now(),
            'alert_type'  => $alert['alert_type'],
            'severity'    => $alert['severity'],
            'description' => $alert['description'],
            'is_read'     => 0,
        ]);
        $created++;
    }

    // Auto-resolve: the condition has passed, so clear it from the queue.
    // Marked read rather than deleted — the alert genuinely happened, and
    // deleting it would erase that.
    foreach ($existing as $key => $alertId) {
        if (isset($unique[$key])) {
            continue;
        }
        tenantExec(
            $conn,
            "UPDATE alerts SET is_read = 1, read_at = NOW() WHERE alert_id = ? AND is_read = 0 AND {scope}",
            [$alertId]
        );
        $resolved++;
    }

    return ['created' => $created, 'resolved' => $resolved];
}

// ===========================================================================
// TIMELINE CONTRIBUTION
//
// Returns health events in the SHAPE animalTimeline() already uses, so the
// Phase 3 timeline gains health without changing its design: it is still a
// union over real domain tables, with no duplicate event store.
// ===========================================================================

/**
 * Health events for one animal, formatted as timeline entries.
 *
 * Each entry links to the record that is authoritative for it, so the timeline
 * remains a view rather than becoming a second source of truth.
 *
 * @return list<array<string, mixed>>
 */
function healthTimelineEvents(PDO $conn, int $animalId): array {
    $events = [];

    foreach (healthStatusHistory($conn, $animalId) as $row) {
        $events[] = [
            'occurred_at' => (string) $row['effective_at'],
            'date'        => substr((string) $row['effective_at'], 0, 10),
            'type'        => 'health_status',
            'title'       => 'Health status: ' . dfms_health_status_label($row['new_status']),
            'detail'      => $row['reason'] ?: ($row['notes'] ?: null),
            'tone'        => dfms_health_status_tone($row['new_status']),
            'actor'       => $row['username'] ?? null,
            'link'        => null,
        ];
    }

    foreach (tenantQuery(
        $conn,
        "SELECT hc.* FROM health_cases hc WHERE {scope} AND hc.cow_id = ? ORDER BY hc.opened_on DESC",
        [$animalId],
        'hc'
    ) as $case) {
        $events[] = [
            'occurred_at' => $case['opened_on'] . ' 00:00:00',
            'date'        => $case['opened_on'],
            'type'        => 'health_case',
            'title'       => 'Case opened: ' . $case['title'],
            'detail'      => $case['case_reference'] . ' · ' . (DFMS_CASE_TYPES[$case['case_type']] ?? $case['case_type']),
            'tone'        => $case['severity'] === 'urgent' ? 'danger' : ($case['severity'] === 'moderate' ? 'warning' : 'info'),
            'link'        => 'modules/health/case.php?id=' . (int) $case['case_id'],
        ];
        if ($case['closed_on'] !== null) {
            $events[] = [
                'occurred_at' => $case['closed_on'] . ' 23:59:59',
                'date'        => $case['closed_on'],
                'type'        => 'health_case',
                'title'       => 'Case ' . $case['status'] . ': ' . $case['title'],
                'detail'      => $case['outcome'] !== null
                    ? 'Outcome: ' . (DFMS_CASE_OUTCOMES[$case['outcome']] ?? $case['outcome'])
                    : null,
                'tone'        => 'success',
                'link'        => 'modules/health/case.php?id=' . (int) $case['case_id'],
            ];
        }
    }

    foreach (healthObservations($conn, $animalId, 100) as $observation) {
        $meta = DFMS_OBSERVATION_TYPES[$observation['observation_type']] ?? null;
        $events[] = [
            'occurred_at' => (string) $observation['observed_at'],
            'date'        => substr((string) $observation['observed_at'], 0, 10),
            'type'        => 'observation',
            'title'       => ($meta['label'] ?? $observation['observation_type']) . ': ' . healthFormatObservation($observation),
            'detail'      => $observation['notes'] ?: null,
            'tone'        => 'neutral',
            'actor'       => $observation['username'] ?? null,
            'link'        => null,
        ];
    }

    foreach (tenantQuery(
        $conn,
        "SELECT d.* FROM health_diagnoses d WHERE {scope} AND d.cow_id = ? ORDER BY d.diagnosed_on DESC",
        [$animalId],
        'd'
    ) as $diagnosis) {
        $statusMeta = DFMS_DIAGNOSIS_STATUSES[$diagnosis['status']] ?? ['label' => $diagnosis['status'], 'tone' => 'neutral'];
        $events[] = [
            'occurred_at' => $diagnosis['diagnosed_on'] . ' 00:00:00',
            'date'        => $diagnosis['diagnosed_on'],
            'type'        => 'diagnosis',
            'title'       => $statusMeta['label'] . ' diagnosis: ' . dfms_condition_label($diagnosis),
            'detail'      => $diagnosis['confidence'] !== null
                ? 'Recorded confidence: ' . (DFMS_DIAGNOSIS_CONFIDENCE[$diagnosis['confidence']] ?? $diagnosis['confidence'])
                : null,
            'tone'        => $statusMeta['tone'],
            'link'        => $diagnosis['case_id'] !== null ? 'modules/health/case.php?id=' . (int) $diagnosis['case_id'] : null,
        ];
    }

    foreach (healthConsultations($conn, $animalId, 100) as $consultation) {
        $events[] = [
            'occurred_at' => (string) $consultation['consulted_at'],
            'date'        => substr((string) $consultation['consulted_at'], 0, 10),
            'type'        => 'consultation',
            'title'       => 'Veterinary consultation — ' . $consultation['vet_name'],
            'detail'      => $consultation['findings'] ?: ($consultation['reason'] ?: null),
            'tone'        => 'info',
            'link'        => $consultation['case_id'] !== null ? 'modules/health/case.php?id=' . (int) $consultation['case_id'] : null,
        ];
    }

    foreach (healthTreatments($conn, $animalId, 100) as $treatment) {
        $statusMeta = DFMS_TREATMENT_STATUSES[$treatment['status']] ?? ['label' => $treatment['status'], 'tone' => 'neutral'];
        $events[] = [
            'occurred_at' => $treatment['started_on'] . ' 00:00:00',
            'date'        => $treatment['started_on'],
            'type'        => 'treatment',
            'title'       => 'Treatment started: ' . $treatment['name'],
            'detail'      => (DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? $treatment['treatment_type'])
                . ' · ' . $statusMeta['label'],
            'tone'        => $statusMeta['tone'],
            'link'        => $treatment['case_id'] !== null ? 'modules/health/case.php?id=' . (int) $treatment['case_id'] : null,
        ];
    }

    foreach (healthMedicationHistory($conn, $animalId, 100) as $administration) {
        $events[] = [
            'occurred_at' => (string) $administration['administered_at'],
            'date'        => substr((string) $administration['administered_at'], 0, 10),
            'type'        => 'medication',
            'title'       => 'Medication: ' . $administration['medication_name'],
            'detail'      => fmt_number((float) $administration['dose'], 2) . ' '
                . (DFMS_DOSE_UNITS[$administration['dose_unit']] ?? $administration['dose_unit'])
                . ' · ' . (DFMS_ADMIN_ROUTES[$administration['route']] ?? $administration['route'])
                . ($administration['batch_lot'] ? ' · batch ' . $administration['batch_lot'] : ''),
            'tone'        => 'info',
            'link'        => null,
        ];
    }

    foreach (healthVaccinations($conn, $animalId, 100) as $vaccination) {
        // A SCHEDULED dose has not happened yet and is not a timeline event —
        // putting a future intention on a history would misrepresent it.
        if ($vaccination['status'] === 'scheduled' || $vaccination['administered_on'] === null) {
            continue;
        }
        $events[] = [
            'occurred_at' => $vaccination['administered_on'] . ' 00:00:00',
            'date'        => $vaccination['administered_on'],
            'type'        => 'vaccination',
            'title'       => 'Vaccination: ' . $vaccination['vaccine_name']
                . ($vaccination['status'] === 'superseded' ? ' (corrected)' : ''),
            'detail'      => trim(
                ($vaccination['disease_target'] ? (string) $vaccination['disease_target'] : '')
                . ($vaccination['batch_lot'] ? ' · batch ' . $vaccination['batch_lot'] : '')
            ) ?: null,
            'tone'        => $vaccination['status'] === 'superseded' ? 'neutral' : 'success',
            'link'        => null,
        ];
    }

    foreach (healthQuarantines($conn, $animalId) as $quarantine) {
        $events[] = [
            'occurred_at' => (string) $quarantine['started_at'],
            'date'        => substr((string) $quarantine['started_at'], 0, 10),
            'type'        => 'quarantine',
            'title'       => 'Quarantine started',
            'detail'      => $quarantine['reason']
                . ($quarantine['location_name'] ? ' · ' . $quarantine['location_name'] : ''),
            'tone'        => 'danger',
            'link'        => null,
        ];
        if ($quarantine['ended_at'] !== null) {
            $events[] = [
                'occurred_at' => (string) $quarantine['ended_at'],
                'date'        => substr((string) $quarantine['ended_at'], 0, 10),
                'type'        => 'quarantine',
                'title'       => 'Quarantine ' . $quarantine['status'],
                'detail'      => $quarantine['release_notes'] ?: null,
                'tone'        => 'success',
                'link'        => null,
            ];
        }
    }

    foreach (healthLabTests($conn, $animalId, 100) as $test) {
        $events[] = [
            'occurred_at' => $test['sample_taken_on'] . ' 00:00:00',
            'date'        => $test['sample_taken_on'],
            'type'        => 'lab_test',
            'title'       => 'Laboratory sample: ' . $test['test_name'],
            'detail'      => $test['result_status'] === 'pending'
                ? 'Result pending'
                : (trim((DFMS_LAB_RESULT_CODES[$test['result_code'] ?? ''] ?? '')
                    . ($test['laboratory_name'] ? ' · ' . $test['laboratory_name'] : '')) ?: null),
            'tone'        => in_array($test['result_code'], ['positive', 'abnormal'], true) ? 'danger'
                            : ($test['result_status'] === 'pending' ? 'neutral' : 'success'),
            'link'        => $test['case_id'] !== null ? 'modules/health/case.php?id=' . (int) $test['case_id'] : null,
        ];
    }

    foreach (healthWithdrawalHistory($conn, $animalId, 100) as $withdrawal) {
        $label = DFMS_WITHDRAWAL_TYPES[$withdrawal['restriction_type']]['label'] ?? $withdrawal['restriction_type'];
        $events[] = [
            'occurred_at' => $withdrawal['starts_on'] . ' 00:00:01',
            'date'        => $withdrawal['starts_on'],
            'type'        => 'withdrawal',
            'title'       => $label . ' withdrawal started',
            'detail'      => $withdrawal['source_label'] . ' · until ' . (string) $withdrawal['ends_on'] . ' inclusive'
                . ($withdrawal['status'] === 'cancelled' ? ' (cancelled)' : ''),
            'tone'        => 'warning',
            'link'        => null,
        ];
    }

    return $events;
}

/**
 * Everything the case detail page needs, in one call.
 */
function healthCaseDetail(PDO $conn, int $caseId): array {
    $case = healthFindCaseOr404($conn, $caseId);
    $animal = animalFindOr404($conn, (int) $case['cow_id'], true);

    return [
        'case'      => $case,
        'animal'    => $animal,
        'symptoms'  => healthCaseSymptoms($conn, $caseId),
        'diagnoses' => healthCaseDiagnoses($conn, $caseId),
        'treatments' => tenantQuery(
            $conn,
            "SELECT t.* FROM health_treatments t WHERE {scope} AND t.case_id = ?
              ORDER BY t.started_on DESC, t.treatment_id DESC",
            [$caseId],
            't'
        ),
        'medications' => tenantQuery(
            $conn,
            "SELECT m.* FROM medication_administrations m WHERE {scope} AND m.case_id = ?
              ORDER BY m.administered_at DESC",
            [$caseId],
            'm'
        ),
        'consultations' => tenantQuery(
            $conn,
            "SELECT c.* FROM veterinary_consultations c WHERE {scope} AND c.case_id = ?
              ORDER BY c.consulted_at DESC",
            [$caseId],
            'c'
        ),
        'lab_tests' => tenantQuery(
            $conn,
            "SELECT l.* FROM laboratory_tests l WHERE {scope} AND l.case_id = ?
              ORDER BY l.sample_taken_on DESC",
            [$caseId],
            'l'
        ),
        'observations' => tenantQuery(
            $conn,
            "SELECT o.*, u.username FROM health_observations o
               LEFT JOIN admin_users u ON u.admin_id = o.observed_by
              WHERE {scope} AND o.case_id = ? ORDER BY o.observed_at DESC",
            [$caseId],
            'o'
        ),
        'quarantines' => tenantQuery(
            $conn,
            "SELECT q.*, l.name AS location_name FROM health_quarantines q
               LEFT JOIN locations l ON l.location_id = q.location_id AND l.farm_id = q.farm_id
              WHERE {scope} AND q.case_id = ? ORDER BY q.started_at DESC",
            [$caseId],
            'q'
        ),
        'events' => tenantQuery(
            $conn,
            "SELECT e.* FROM health_events e WHERE {scope} AND e.case_id = ?
              ORDER BY e.event_date DESC",
            [$caseId],
            'e'
        ),
    ];
}

/**
 * Medication and vaccine products this farm stocks, for the dropdowns.
 *
 * Falls back to the legacy free-text item_type for items that predate the
 * `category` column, so an existing "Medicine" row is still offered rather than
 * requiring every farm to re-categorise its inventory before it can record a
 * treatment.
 */
function healthMedicationItems(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT i.item_id, i.item_name, i.item_type, i.category, i.unit,
                i.default_withdrawal_milk_days, i.default_withdrawal_meat_days,
                COALESCE(l.quantity, 0) AS quantity
           FROM inventory_items i
           LEFT JOIN inventory_levels l ON l.item_id = i.item_id AND l.farm_id = i.farm_id
          WHERE {scope}
            AND (i.category IN ('medication','vaccine')
                 OR (i.category IS NULL AND i.item_type IN ('Medicine','Vaccine','Medication')))
          ORDER BY i.item_name",
        [],
        'i'
    );
}
