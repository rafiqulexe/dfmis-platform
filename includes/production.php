<?php
// ============================================================================
// MILK PRODUCTION — per-animal recording, analytics and operational signals.
//
// ---------------------------------------------------------------------------
// TWO KINDS OF RECORD, AND THEY ARE NOT INTERCHANGEABLE
//
//   milk_production   FARM-LEVEL DAILY AGGREGATE. Pre-existing, with historical
//                     rows. "The farm produced 1,250 L on this date."
//   milk_yields       RAW OPERATIONAL RECORD. New. "Animal A17 gave 12.5 L at
//                     the morning milking on this date."
//
// THE RULE THIS FILE ENFORCES EVERYWHERE:
//
//   A farm total is NEVER divided among animals, and per-animal figures are
//   NEVER presented as a farm total for a day they do not cover.
//
// A farm total does not decompose. If a farm recorded 1,250 L on a day with no
// per-animal entries, the honest per-animal answer is "not recorded" — not
// "1,250 / 40 animals". Every function below that returns a number also says
// WHICH source it came from, so a caller can never accidentally present one as
// the other. That is what productionDailyTotals() means by 'source'.
//
// ---------------------------------------------------------------------------
// WITHDRAWAL IS COMPUTED, NEVER COPIED
//
// Whether an animal's milk was under a withdrawal restriction on a given date
// is derived from withdrawal_periods at READ time, not stored on the yield row.
//
// Storing a flag would be a second answer to a question the Phase 4 health
// domain already owns, and it would be WRONG the moment a withdrawal was
// cancelled, corrected or back-dated — which is exactly when the answer
// matters. The cost is a join; the benefit is that there is only ever one
// truth about whether milk was saleable.
//
// The BEHAVIOUR follows the existing Phase 4 semantics, which are warn rather
// than block (see healthFarmActiveWithdrawals()'s own docblock). Recording is
// never refused: milk that was drawn still exists and pretending otherwise
// would make the record less true, not safer. It is flagged, and it is
// excluded from the saleable total.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/units.php';
require_once __DIR__ . '/livestock.php';
require_once __DIR__ . '/health.php';

/** A domain rule the user broke — safe to display verbatim. */
class ProductionRuleViolation extends RuntimeException {}

/**
 * Milking sessions.
 *
 * A CLOSED SET, kept small on purpose. The session is part of the uniqueness
 * key, so every value added here is a new way for the same milking to be
 * recorded twice. 'other' exists so a farm on an unusual routine is not forced
 * to misfile a record as 'midday'.
 */
const DFMS_MILKING_SESSIONS = [
    'morning' => 'Morning',
    'midday'  => 'Midday',
    'evening' => 'Evening',
    'other'   => 'Other',
];

/** Alert families this module owns. Nothing else may create or resolve them. */
const DFMS_PRODUCTION_ALERT_TYPES = [
    'Production drop',
    'Production not recorded',
];

/** How many days of recent history define an animal's baseline. */
const DFMS_PRODUCTION_BASELINE_DAYS = 14;

/** Below this fraction of baseline, a drop is worth surfacing. */
const DFMS_PRODUCTION_DROP_THRESHOLD = 0.25;

function dfms_milking_sessions(): array {
    return DFMS_MILKING_SESSIONS;
}

function dfms_is_valid_milking_session(?string $session): bool {
    return $session !== null && isset(DFMS_MILKING_SESSIONS[$session]);
}

function dfms_milking_session_label(?string $session): string {
    return DFMS_MILKING_SESSIONS[$session ?? ''] ?? (string) $session;
}

// ===========================================================================
// RECORDING
// ===========================================================================

/**
 * Record one animal's yield for one session.
 *
 * AUTHORIZATION: the animal is resolved through animalFind(), which returns
 * null for a missing animal AND for another farm's, indistinguishably — so a
 * posted cow_id from another tenant cannot reach an INSERT. The farm_id is
 * never read from input; tenantInsert() supplies it from the session.
 *
 * @param array{cow_id: mixed, production_date: string, session: string,
 *              quantity: float, discarded?: float, abnormal?: bool, notes?: ?string} $data
 * @throws ProductionRuleViolation
 */
function productionRecordYield(PDO $conn, array $data): int {
    $animal = animalFind($conn, $data['cow_id'] ?? null);
    if ($animal === null) {
        // Deliberately the same message for foreign and missing, matching the
        // convention every other module here follows.
        throw new ProductionRuleViolation('That animal is not on this farm.');
    }

    // An archived or sold animal is not producing today. Lifecycle is the
    // Phase 3 domain's call, so it is read rather than re-decided here.
    if (($animal['lifecycle_status'] ?? 'active') !== 'active') {
        throw new ProductionRuleViolation(
            'That animal is ' . (DFMS_LIFECYCLE_STATUSES[$animal['lifecycle_status']]['label'] ?? 'not active')
            . ' and cannot have production recorded against it.'
        );
    }

    $session = (string) ($data['session'] ?? '');
    if (!dfms_is_valid_milking_session($session)) {
        throw new ProductionRuleViolation('Choose which milking this was.');
    }

    $date = (string) ($data['production_date'] ?? '');
    if ($date === '' || $date > farmToday()) {
        // A future-dated yield is always a typo. Phase 4 makes the same check
        // for observations (healthAssertNotFuture) for the same reason.
        throw new ProductionRuleViolation('Production cannot be recorded for a future date.');
    }

    $quantity = (float) ($data['quantity'] ?? 0);
    if ($quantity <= 0) {
        // Zero is rejected rather than stored. "Nothing was recorded" and "this
        // animal produced nothing" are different claims, and a zero row would
        // make the second one silently, dragging every average down with it.
        throw new ProductionRuleViolation('Enter a quantity greater than zero.');
    }

    $discarded = (float) ($data['discarded'] ?? 0);
    if ($discarded < 0 || $discarded > $quantity) {
        throw new ProductionRuleViolation('Discarded milk cannot exceed the quantity recorded.');
    }

    try {
        $yieldId = tenantInsert($conn, 'milk_yields', [
            'cow_id'          => (int) $animal['cow_id'],
            'production_date' => $date,
            'session'         => $session,
            'quantity'        => $quantity,
            'discarded'       => $discarded,
            'abnormal'        => !empty($data['abnormal']) ? 1 : 0,
            'notes'           => $data['notes'] ?? null,
            'recorded_by'     => currentUserId(),
        ]);
    } catch (PDOException $e) {
        // The UNIQUE key is the authority on double entry, not a prior SELECT:
        // check-then-insert is a race, and Phase 1's baseline audit (T-5) hit
        // exactly that on milk_production.
        if ($e->getCode() === '23000') {
            throw new ProductionRuleViolation(
                'A ' . mb_strtolower(dfms_milking_session_label($session))
                . ' record already exists for this animal on ' . $date . '. Edit it instead.'
            );
        }
        throw $e;
    }

    auditCreate('milk_yield', $yieldId, [
        'cow_id'          => (int) $animal['cow_id'],
        'production_date' => $date,
        'session'         => $session,
        'quantity'        => $quantity,
        'discarded'       => $discarded,
    ]);

    return $yieldId;
}

/**
 * Correct an existing yield.
 *
 * Only the measured facts are editable — never the animal, date or session,
 * because changing those turns a correction into a different record and would
 * let a row slip past the uniqueness key it was checked against. To move a
 * reading to another animal or session, delete it and record it properly.
 *
 * @throws ProductionRuleViolation
 */
function productionUpdateYield(PDO $conn, $yieldId, array $data): bool {
    $existing = tenantFind($conn, 'milk_yields', 'yield_id', $yieldId);
    if ($existing === null) {
        throw new ProductionRuleViolation('That production record is not available.');
    }

    $quantity = (float) ($data['quantity'] ?? 0);
    if ($quantity <= 0) {
        throw new ProductionRuleViolation('Enter a quantity greater than zero.');
    }
    $discarded = (float) ($data['discarded'] ?? 0);
    if ($discarded < 0 || $discarded > $quantity) {
        throw new ProductionRuleViolation('Discarded milk cannot exceed the quantity recorded.');
    }

    $after = [
        'quantity'  => $quantity,
        'discarded' => $discarded,
        'abnormal'  => !empty($data['abnormal']) ? 1 : 0,
        'notes'     => $data['notes'] ?? null,
    ];

    tenantExec(
        $conn,
        "UPDATE milk_yields SET quantity = ?, discarded = ?, abnormal = ?, notes = ?, updated_at = NOW()
          WHERE yield_id = ? AND {scope}",
        [$quantity, $discarded, $after['abnormal'], $after['notes'], (int) $existing['yield_id']]
    );

    // CHANGED FIELDS ONLY. Logging the whole row on every edit would bury the
    // one value that moved and copy unchanged clinical notes into the audit
    // store on every save.
    $before = [];
    $changed = [];
    foreach ($after as $field => $value) {
        $was = $existing[$field] ?? null;
        $comparableWas = is_numeric($was) ? (float) $was : $was;
        $comparableNow = is_numeric($value) ? (float) $value : $value;
        if ($comparableWas !== $comparableNow) {
            $before[$field] = $was;
            $changed[$field] = $value;
        }
    }
    if ($changed !== []) {
        auditLog('milk_yield.update', 'milk_yield', (int) $existing['yield_id'], $before, $changed);
    }

    return true;
}

/**
 * Remove a yield.
 *
 * A hard delete, matching how this codebase treats a mis-keyed operational
 * reading elsewhere. The audit entry carries the full removed row, so the
 * record of what was deleted survives the deletion.
 *
 * @throws ProductionRuleViolation
 */
function productionDeleteYield(PDO $conn, $yieldId): bool {
    $existing = tenantFind($conn, 'milk_yields', 'yield_id', $yieldId);
    if ($existing === null) {
        throw new ProductionRuleViolation('That production record is not available.');
    }

    tenantExec($conn, "DELETE FROM milk_yields WHERE yield_id = ? AND {scope}", [(int) $existing['yield_id']]);

    auditLog('milk_yield.delete', 'milk_yield', (int) $existing['yield_id'], [
        'cow_id'          => (int) $existing['cow_id'],
        'production_date' => $existing['production_date'],
        'session'         => $existing['session'],
        'quantity'        => (float) $existing['quantity'],
    ], null);

    return true;
}

// ===========================================================================
// READING
// ===========================================================================

/**
 * Yields for one animal, most recent first, with restriction computed.
 *
 * The LEFT JOIN onto withdrawal_periods is the whole withdrawal integration:
 * a row is restricted when an active milk restriction covered its production
 * date. Nothing is stored, so a corrected withdrawal immediately corrects
 * every historical row it touches.
 *
 * @return list<array<string, mixed>>
 */
function productionAnimalYields(PDO $conn, int $animalId, int $limit = 30): array {
    $limit = max(1, min($limit, 200));
    return tenantQuery(
        $conn,
        "SELECT y.*,
                EXISTS (
                    SELECT 1 FROM withdrawal_periods w
                     WHERE w.farm_id = y.farm_id AND w.cow_id = y.cow_id
                       AND w.status = 'active' AND w.restriction_type = 'milk'
                       AND w.starts_on <= y.production_date AND w.ends_on >= y.production_date
                ) AS restricted
           FROM milk_yields y
          WHERE {scope} AND y.cow_id = ?
          ORDER BY y.production_date DESC, FIELD(y.session,'morning','midday','evening','other')
          LIMIT " . (int) $limit,
        [$animalId],
        'y'
    );
}

/**
 * Every yield on one date, for the daily recording screen.
 *
 * @return list<array<string, mixed>>
 */
function productionYieldsOn(PDO $conn, string $date): array {
    return tenantQuery(
        $conn,
        "SELECT y.*, c.unique_id, c.name,
                EXISTS (
                    SELECT 1 FROM withdrawal_periods w
                     WHERE w.farm_id = y.farm_id AND w.cow_id = y.cow_id
                       AND w.status = 'active' AND w.restriction_type = 'milk'
                       AND w.starts_on <= y.production_date AND w.ends_on >= y.production_date
                ) AS restricted
           FROM milk_yields y
           JOIN cows c ON c.cow_id = y.cow_id AND c.farm_id = y.farm_id
          WHERE {scope} AND y.production_date = ?
          ORDER BY FIELD(y.session,'morning','midday','evening','other'), c.unique_id",
        [$date],
        'y'
    );
}

/** One yield with its animal, or null for missing AND foreign alike. */
function productionFindYield(PDO $conn, $yieldId): ?array {
    return tenantFind($conn, 'milk_yields', 'yield_id', $yieldId);
}

// ===========================================================================
// ANALYTICS — deterministic, and explicit about what is recorded
// ===========================================================================

/**
 * Recorded totals per day over a window, from the PER-ANIMAL records.
 *
 * `source` is always 'recorded' here, and it is returned rather than assumed
 * because productionDailyTotals() is combined with the farm-level aggregate by
 * callers that must keep the two apart. A day with no per-animal rows is
 * ABSENT from the result — it is not returned as zero, because "nobody
 * recorded anything" and "the herd produced nothing" are different facts.
 *
 * @return array<string, array{date: string, total: float, discarded: float,
 *                             saleable: float, animals: int, entries: int, source: string}>
 */
function productionDailyTotals(PDO $conn, string $fromDate, string $toDate): array {
    $rows = tenantQuery(
        $conn,
        "SELECT y.production_date,
                SUM(y.quantity)  AS total,
                SUM(y.discarded) AS discarded,
                -- SALEABLE = what could actually go to the tank.
                --
                -- A WITHDRAWAL zeroes the whole session: the restriction is on
                -- the animal's milk, so none of what it gave is saleable.
                --
                -- A DISCARD subtracts only what was discarded. Withholding
                -- half a litre of foremilk does not make the other nine
                -- unsaleable, and treating it that way understated the farm's
                -- most commercially important figure by an entire session.
                SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM withdrawal_periods w
                         WHERE w.farm_id = y.farm_id AND w.cow_id = y.cow_id
                           AND w.status = 'active' AND w.restriction_type = 'milk'
                           AND w.starts_on <= y.production_date AND w.ends_on >= y.production_date
                    ) THEN 0 ELSE GREATEST(y.quantity - y.discarded, 0) END) AS saleable,
                COUNT(DISTINCT y.cow_id) AS animals,
                COUNT(*) AS entries
           FROM milk_yields y
          WHERE {scope} AND y.production_date BETWEEN ? AND ?
          GROUP BY y.production_date
          ORDER BY y.production_date",
        [$fromDate, $toDate],
        'y'
    );

    $totals = [];
    foreach ($rows as $row) {
        $date = (string) $row['production_date'];
        $totals[$date] = [
            'date'      => $date,
            'total'     => (float) $row['total'],
            'discarded' => (float) $row['discarded'],
            // Saleable excludes discarded milk, and excludes ENTIRELY any
            // session from an animal under a milk restriction. See the SQL
            // above for why those two are treated differently.
            'saleable'  => (float) $row['saleable'],
            'animals'   => (int) $row['animals'],
            'entries'   => (int) $row['entries'],
            'source'    => 'recorded',
        ];
    }
    return $totals;
}

/**
 * Per-animal totals over a window, ranked.
 *
 * `average_per_day` divides by DAYS THE ANIMAL WAS ACTUALLY RECORDED, not by
 * the length of the window. Dividing by the window would silently punish an
 * animal that was only recorded on three of seven days, reporting a low
 * average that is really a recording gap. The gap is reported separately, as
 * `days_recorded`, so the reader can see the difference.
 *
 * @return list<array<string, mixed>>
 */
function productionAnimalTotals(PDO $conn, string $fromDate, string $toDate, int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    $rows = tenantQuery(
        $conn,
        "SELECT y.cow_id, c.unique_id, c.name, c.lactation_status,
                SUM(y.quantity) AS total,
                SUM(y.discarded) AS discarded,
                COUNT(DISTINCT y.production_date) AS days_recorded,
                COUNT(*) AS entries
           FROM milk_yields y
           JOIN cows c ON c.cow_id = y.cow_id AND c.farm_id = y.farm_id
          WHERE {scope} AND y.production_date BETWEEN ? AND ?
          GROUP BY y.cow_id, c.unique_id, c.name, c.lactation_status
          ORDER BY total DESC
          LIMIT " . (int) $limit,
        [$fromDate, $toDate],
        'y'
    );

    $totals = [];
    foreach ($rows as $row) {
        $days = max(1, (int) $row['days_recorded']);
        $totals[] = [
            'cow_id'          => (int) $row['cow_id'],
            'unique_id'       => (string) $row['unique_id'],
            'name'            => $row['name'],
            'lactation_status' => $row['lactation_status'],
            'total'           => (float) $row['total'],
            'discarded'       => (float) $row['discarded'],
            'days_recorded'   => (int) $row['days_recorded'],
            'entries'         => (int) $row['entries'],
            'average_per_day' => (float) $row['total'] / $days,
        ];
    }
    return $totals;
}

/**
 * Herd summary for a window, plus the same window's farm-level aggregate.
 *
 * Returns BOTH numbers and never reconciles them. They measure different
 * things and can legitimately disagree — a farm may record a daily total and
 * only some of its animals individually. Presenting one as a correction of the
 * other, or quietly preferring whichever is larger, would be inventing a fact.
 *
 * @return array{from: string, to: string, days: int,
 *               recorded: array{total: float, discarded: float, saleable: float,
 *                               days_with_data: int, animals: int, average_per_day: ?float,
 *                               average_per_animal_day: ?float},
 *               farm_aggregate: array{total: ?float, days_with_data: int}}
 */
function productionSummary(PDO $conn, string $fromDate, string $toDate): array {
    $daily = productionDailyTotals($conn, $fromDate, $toDate);

    $total = 0.0;
    $discarded = 0.0;
    $saleable = 0.0;
    $entries = 0;
    foreach ($daily as $day) {
        $total += $day['total'];
        $discarded += $day['discarded'];
        $saleable += $day['saleable'];
        $entries += $day['entries'];
    }
    $daysWithData = count($daily);

    $animals = (int) tenantScalar(
        $conn,
        "SELECT COUNT(DISTINCT y.cow_id) FROM milk_yields y
          WHERE {scope} AND y.production_date BETWEEN ? AND ?",
        [$fromDate, $toDate],
        0,
        'y'
    );

    // The legacy farm-level aggregate over the same window, reported beside
    // the recorded figures and never merged into them.
    $aggregateRows = tenantQuery(
        $conn,
        "SELECT SUM(m.total_milk_yield) AS total, COUNT(*) AS days
           FROM milk_production m
          WHERE {scope} AND m.production_date BETWEEN ? AND ?",
        [$fromDate, $toDate],
        'm'
    );
    $aggregateTotal = $aggregateRows !== [] && $aggregateRows[0]['total'] !== null
        ? (float) $aggregateRows[0]['total'] : null;
    $aggregateDays = $aggregateRows !== [] ? (int) $aggregateRows[0]['days'] : 0;

    $start = new DateTimeImmutable($fromDate);
    $end = new DateTimeImmutable($toDate);
    $windowDays = (int) $start->diff($end)->format('%a') + 1;

    return [
        'from' => $fromDate,
        'to'   => $toDate,
        'days' => $windowDays,
        'recorded' => [
            'total'     => $total,
            'discarded' => $discarded,
            'saleable'  => $saleable,
            'entries'   => $entries,
            // Days that HAVE data, not the window length. An average over days
            // nobody recorded is an average of invented zeroes.
            'days_with_data' => $daysWithData,
            'animals'   => $animals,
            'average_per_day' => $daysWithData > 0 ? $total / $daysWithData : null,
            'average_per_animal_day' => ($daysWithData > 0 && $animals > 0)
                ? $total / $daysWithData / $animals : null,
        ],
        'farm_aggregate' => [
            'total' => $aggregateTotal,
            'days_with_data' => $aggregateDays,
        ],
    ];
}

/**
 * One animal's recent daily totals and its baseline.
 *
 * The baseline is the mean of the animal's own recorded daily totals over the
 * PRIOR window, excluding the day being judged. Comparing an animal to itself
 * rather than to the herd is what makes this usable on a mixed farm, where a
 * high-yielding cow and a late-lactation cow have nothing to say about each
 * other.
 *
 * @return array{days: array<string, float>, baseline: ?float, latest: ?array{date: string, total: float}}
 */
function productionAnimalTrend(PDO $conn, int $animalId, int $days = DFMS_PRODUCTION_BASELINE_DAYS): array {
    $days = max(2, min($days, 120));
    $from = farmDateOffset(-$days);

    $rows = tenantQuery(
        $conn,
        "SELECT y.production_date, SUM(y.quantity) AS total
           FROM milk_yields y
          WHERE {scope} AND y.cow_id = ? AND y.production_date >= ?
          GROUP BY y.production_date
          ORDER BY y.production_date",
        [$animalId, $from],
        'y'
    );

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(string) $row['production_date']] = (float) $row['total'];
    }
    if ($byDay === []) {
        return ['days' => [], 'baseline' => null, 'latest' => null];
    }

    $dates = array_keys($byDay);
    $latestDate = end($dates);
    $latest = ['date' => (string) $latestDate, 'total' => $byDay[$latestDate]];

    // The baseline deliberately EXCLUDES the latest day, so a drop is measured
    // against history rather than against itself.
    $prior = $byDay;
    unset($prior[$latestDate]);
    $baseline = $prior === [] ? null : array_sum($prior) / count($prior);

    return ['days' => $byDay, 'baseline' => $baseline, 'latest' => $latest];
}

// ===========================================================================
// OPERATIONAL SIGNALS — deterministic rules, never a diagnosis
// ===========================================================================

/**
 * Animals whose most recent recorded day is far below their own baseline.
 *
 * THIS IS NOT A DIAGNOSIS AND THE WORDING MUST NOT BECOME ONE. A drop in yield
 * has many ordinary causes — a missed session, a hot day, a cow in late
 * lactation, someone writing the figure in the wrong row. The rule states the
 * arithmetic and stops there; deciding what it means is the stockperson's job,
 * and the veterinary assistant's if they ask for one.
 *
 * Requires at least three prior days of data, so a single quiet day against
 * one earlier reading cannot trigger it.
 *
 * @return list<array{cow_id: int, unique_id: string, name: ?string,
 *                    baseline: float, latest: float, drop_pct: float, date: string}>
 */
function productionDropSignals(PDO $conn): array {
    $signals = [];
    $from = farmDateOffset(-DFMS_PRODUCTION_BASELINE_DAYS);

    $animals = tenantQuery(
        $conn,
        "SELECT DISTINCT y.cow_id, c.unique_id, c.name
           FROM milk_yields y
           JOIN cows c ON c.cow_id = y.cow_id AND c.farm_id = y.farm_id
          WHERE {scope} AND y.production_date >= ? AND c.deleted_at IS NULL
            AND c.lifecycle_status = 'active'",
        [$from],
        'y'
    );

    foreach ($animals as $animal) {
        $trend = productionAnimalTrend($conn, (int) $animal['cow_id']);
        if ($trend['baseline'] === null || $trend['latest'] === null) {
            continue;
        }
        // At least three prior days, so one unusual pair cannot fire this.
        if (count($trend['days']) < 4 || $trend['baseline'] <= 0) {
            continue;
        }
        $drop = ($trend['baseline'] - $trend['latest']['total']) / $trend['baseline'];
        if ($drop < DFMS_PRODUCTION_DROP_THRESHOLD) {
            continue;
        }
        $signals[] = [
            'cow_id'    => (int) $animal['cow_id'],
            'unique_id' => (string) $animal['unique_id'],
            'name'      => $animal['name'],
            'baseline'  => $trend['baseline'],
            'latest'    => $trend['latest']['total'],
            'drop_pct'  => $drop * 100,
            'date'      => $trend['latest']['date'],
        ];
    }
    return $signals;
}

/**
 * Reconcile production alerts with reality.
 *
 * The SAME desired-state algorithm healthSyncAlerts() uses, over a DISJOINT
 * family of alert types (DFMS_PRODUCTION_ALERT_TYPES). It is not a second
 * alert system: same table, same dedupe key, same auto-resolve rule, same
 * "mark read rather than delete" decision. Two reconcilers that never touch
 * each other's types are how one alert queue serves two domains.
 *
 * @return array{created: int, resolved: int}
 */
function productionSyncAlerts(PDO $conn): array {
    $desired = [];

    foreach (productionDropSignals($conn) as $signal) {
        $desired[] = [
            'alert_type'  => 'Production drop',
            'entity_type' => 'cow',
            'entity_id'   => $signal['cow_id'],
            'cow_id'      => $signal['cow_id'],
            'severity'    => $signal['drop_pct'] >= 50 ? 'warning' : 'info',
            // States the arithmetic. Never "the animal is sick".
            'description' => ($signal['name'] ?: $signal['unique_id'])
                . sprintf(
                    ' — production on %s was %.1f L against a %d-day average of %.1f L (%.0f%% lower).',
                    $signal['date'], $signal['latest'],
                    DFMS_PRODUCTION_BASELINE_DAYS, $signal['baseline'], $signal['drop_pct']
                ),
        ];
    }

    // Collapse duplicates the same way the health reconciler does.
    $unique = [];
    foreach ($desired as $alert) {
        $unique[$alert['alert_type'] . '|' . $alert['entity_type'] . '|' . $alert['entity_id']] = $alert;
    }

    $placeholders = implode(',', array_fill(0, count(DFMS_PRODUCTION_ALERT_TYPES), '?'));
    $existing = [];
    foreach (tenantQuery(
        $conn,
        "SELECT a.alert_id, a.alert_type, a.entity_type, a.entity_id FROM alerts a
          WHERE {scope} AND a.is_read = 0 AND a.alert_type IN ($placeholders)",
        DFMS_PRODUCTION_ALERT_TYPES,
        'a'
    ) as $row) {
        $existing[$row['alert_type'] . '|' . $row['entity_type'] . '|' . (int) $row['entity_id']] = (int) $row['alert_id'];
    }

    $created = 0;
    $resolved = 0;

    foreach ($unique as $key => $alert) {
        if (isset($existing[$key])) {
            continue;
        }
        tenantInsert($conn, 'alerts', [
            'entity_type' => $alert['entity_type'],
            'entity_id'   => $alert['entity_id'],
            'cow_id'      => $alert['cow_id'],
            'alert_time'  => date('Y-m-d H:i:s'),
            'alert_type'  => $alert['alert_type'],
            'severity'    => $alert['severity'],
            'description' => $alert['description'],
            'is_read'     => 0,
        ]);
        $created++;
    }

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
// ===========================================================================

/**
 * Production entries for one animal, in the shape animalTimeline() uses.
 *
 * Follows the Phase 4 precedent exactly: the timeline stays a VIEW over real
 * domain tables rather than becoming a second event store. Only the most
 * recent entries are contributed — a year of twice-daily milkings would bury
 * every other kind of event on the page.
 *
 * @return list<array<string, mixed>>
 */
function productionTimelineEvents(PDO $conn, int $animalId, int $limit = 20): array {
    $events = [];
    foreach (productionAnimalYields($conn, $animalId, $limit) as $row) {
        $events[] = [
            'occurred_at' => $row['production_date'] . ' 00:00:00',
            'date'        => (string) $row['production_date'],
            'type'        => 'production',
            'title'       => dfms_milking_session_label($row['session']) . ' milking — '
                . fmt_measure((float) $row['quantity'], 'volume'),
            'detail'      => !empty($row['restricted'])
                ? 'Under an active milk withdrawal restriction on this date.'
                : ((float) $row['discarded'] > 0
                    ? fmt_measure((float) $row['discarded'], 'volume') . ' discarded at the parlour.'
                    : null),
            'tone'        => !empty($row['restricted']) || !empty($row['abnormal']) ? 'warning' : 'neutral',
            'link'        => null,
        ];
    }
    return $events;
}
