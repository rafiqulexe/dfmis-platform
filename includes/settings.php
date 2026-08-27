<?php
// ============================================================================
// CONFIGURATION HIERARCHY
//
// The single place where "what timezone / currency / locale / unit system
// applies right now?" is answered. Nothing else in the application may
// implement fallback logic — scattering it is how two pages end up disagreeing
// about what a farm's timezone is.
//
// PRECEDENCE (highest wins)
//
//     Farm setting            farms.timezone, farms.currency, …
//         ↓  (NULL = inherit)
//     Organization setting    organizations.default_timezone, …
//         ↓  (NULL = inherit)
//     Platform default        env APP_DEFAULT_*, else the constants below
//
// NULL means INHERIT at every level. That is why none of the columns added in
// migration 006 carry a database DEFAULT: a column default would be a second
// copy of the platform default, and two copies drift.
//
// WHY NO USER-LEVEL PREFERENCES
// The hierarchy stops at farm deliberately. Timezone, currency and units are
// properties of the OPERATION, not of the person looking at it: two people
// reading the same milk record must see the same litres and the same
// production day, or they cannot discuss it. Locale is the one setting where a
// per-user preference would be defensible, and it is left out until there is a
// real second language to choose — adding an unused preference column now
// would be the speculative abstraction this phase is meant to avoid.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/units.php';

// ---------------------------------------------------------------------------
// Platform defaults — the bottom of the hierarchy.
//
// UTC / en / metric are deliberately NEUTRAL. The platform must not assume the
// country of its first customer; an organization states its own country and
// the rest follows from what they configure.
// ---------------------------------------------------------------------------
const DFMS_PLATFORM_DEFAULT_TIMEZONE = 'UTC';
const DFMS_PLATFORM_DEFAULT_LOCALE = 'en';
const DFMS_PLATFORM_DEFAULT_CURRENCY = 'USD';
const DFMS_PLATFORM_DEFAULT_MEASUREMENT = 'metric';

function dfms_platform_defaults(): array {
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    // Env may override, but an INVALID env value falls back to the constant
    // rather than propagating a broken setting through the whole application.
    $timezone = getenv('APP_DEFAULT_TIMEZONE') ?: DFMS_PLATFORM_DEFAULT_TIMEZONE;
    $locale = getenv('APP_DEFAULT_LOCALE') ?: DFMS_PLATFORM_DEFAULT_LOCALE;
    $currency = getenv('APP_DEFAULT_CURRENCY') ?: DFMS_PLATFORM_DEFAULT_CURRENCY;
    $measurement = getenv('APP_DEFAULT_MEASUREMENT') ?: DFMS_PLATFORM_DEFAULT_MEASUREMENT;

    return $defaults = [
        'timezone'           => dfms_is_valid_timezone($timezone) ? $timezone : DFMS_PLATFORM_DEFAULT_TIMEZONE,
        'locale'             => dfms_is_valid_locale($locale) ? $locale : DFMS_PLATFORM_DEFAULT_LOCALE,
        'currency'           => dfms_is_valid_currency($currency) ? strtoupper($currency) : DFMS_PLATFORM_DEFAULT_CURRENCY,
        'measurement_system' => dfms_is_valid_measurement_system($measurement) ? $measurement : DFMS_PLATFORM_DEFAULT_MEASUREMENT,
        'country_code'       => null,   // never guessed
    ];
}

// ---------------------------------------------------------------------------
// Raw row loaders, cached per request.
// ---------------------------------------------------------------------------

// One shared, clearable request cache. A function static inside each loader
// would be unreachable from outside, so a settings write could not invalidate
// it and the rest of the request would keep serving stale values.
function &dfms_settings_cache(): array {
    static $cache = ['org' => [], 'farm' => []];
    return $cache;
}

/**
 * Drop cached organization/farm rows so subsequent reads in this request see
 * freshly written values. Called by the writers below.
 */
function dfms_reset_settings_cache(): void {
    $cache = &dfms_settings_cache();
    $cache = ['org' => [], 'farm' => []];
}

function dfms_organization_row(?int $organizationId = null): ?array {
    $organizationId = $organizationId ?? currentOrganizationId();
    if ($organizationId === null) {
        return null;
    }
    $cache = &dfms_settings_cache();
    if (array_key_exists($organizationId, $cache['org'])) {
        return $cache['org'][$organizationId];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT organization_id, name, legal_name, country_code, default_timezone,
                default_locale, default_currency, measurement_system,
                plan, status, trial_ends_at, current_period_ends_at
           FROM organizations WHERE organization_id = ?"
    );
    $stmt->execute([$organizationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cache['org'][$organizationId] = $row !== false ? $row : null;
}

function dfms_farm_row(?int $farmId = null): ?array {
    $farmId = $farmId ?? currentFarmId();
    if ($farmId === null) {
        return null;
    }
    $cache = &dfms_settings_cache();
    if (array_key_exists($farmId, $cache['farm'])) {
        return $cache['farm'][$farmId];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT farm_id, organization_id, name, farm_type, farm_subtype,
                country_code, timezone, locale, currency, measurement_system
           FROM farms WHERE farm_id = ?"
    );
    $stmt->execute([$farmId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cache['farm'][$farmId] = $row !== false ? $row : null;
}

// ---------------------------------------------------------------------------
// The resolver.
// ---------------------------------------------------------------------------

/**
 * Resolve one setting through the hierarchy.
 *
 * @param string $key one of: timezone, locale, currency, measurement_system, country_code
 * @return array{value: mixed, source: string} source is 'farm'|'organization'|'platform'
 *
 * The SOURCE is returned alongside the value because the settings UI has to
 * tell the user whether they are looking at an override or an inherited value.
 * Without it, "Asia/Dhaka" on a farm page is ambiguous between the two.
 */
function dfms_resolve_setting(string $key, ?int $farmId = null, ?int $organizationId = null): array {
    // Farm columns are named differently from organization columns, because
    // one is an override and the other is a default. Map both to one key.
    static $farmColumn = [
        'timezone'           => 'timezone',
        'locale'             => 'locale',
        'currency'           => 'currency',
        'measurement_system' => 'measurement_system',
        'country_code'       => 'country_code',
    ];
    static $orgColumn = [
        'timezone'           => 'default_timezone',
        'locale'             => 'default_locale',
        'currency'           => 'default_currency',
        'measurement_system' => 'measurement_system',
        'country_code'       => 'country_code',
    ];

    if (!isset($farmColumn[$key])) {
        throw new InvalidArgumentException("Unknown setting key: $key");
    }

    $farm = dfms_farm_row($farmId);
    if ($farm !== null) {
        $value = $farm[$farmColumn[$key]] ?? null;
        if ($value !== null && $value !== '') {
            return ['value' => $value, 'source' => 'farm'];
        }
        $organizationId = $organizationId ?? (int) $farm['organization_id'];
    }

    $org = dfms_organization_row($organizationId);
    if ($org !== null) {
        $value = $org[$orgColumn[$key]] ?? null;
        if ($value !== null && $value !== '') {
            return ['value' => $value, 'source' => 'organization'];
        }
    }

    return ['value' => dfms_platform_defaults()[$key], 'source' => 'platform'];
}

function dfms_setting(string $key, ?int $farmId = null, ?int $organizationId = null) {
    return dfms_resolve_setting($key, $farmId, $organizationId)['value'];
}

// ---------------------------------------------------------------------------
// Convenience accessors for the active tenant. These are what pages call.
// ---------------------------------------------------------------------------

function currentTimezone(): string {
    $tz = dfms_setting('timezone');
    return dfms_is_valid_timezone($tz) ? $tz : DFMS_PLATFORM_DEFAULT_TIMEZONE;
}

function currentLocale(): string {
    $locale = dfms_setting('locale');
    return dfms_is_valid_locale($locale) ? $locale : DFMS_PLATFORM_DEFAULT_LOCALE;
}

function currentCurrency(): string {
    $currency = dfms_setting('currency');
    return dfms_is_valid_currency($currency) ? strtoupper($currency) : DFMS_PLATFORM_DEFAULT_CURRENCY;
}

function currentMeasurementSystem(): string {
    $system = dfms_setting('measurement_system');
    return dfms_is_valid_measurement_system($system) ? $system : DFMS_PLATFORM_DEFAULT_MEASUREMENT;
}

function currentCountryCode(): ?string {
    $code = dfms_setting('country_code');
    return dfms_is_valid_country($code) ? strtoupper($code) : null;
}

// ---------------------------------------------------------------------------
// Resolved rendering helpers. Thin wrappers over the pure formatters in
// i18n.php / units.php, applying the active tenant's resolved settings.
// ---------------------------------------------------------------------------

/** Translate a key in the active locale. */
function t(string $key, array $params = []): string {
    return dfms_translate($key, currentLocale(), $params);
}

/** Render a stored UTC instant in the farm's timezone. */
function fmt_instant(?string $stored, string $format = 'Y-m-d H:i'): string {
    return dfms_format_instant($stored, currentTimezone(), currentLocale(), $format) ?? '—';
}

/** Render a date-only value. Never timezone-shifted — see i18n.php. */
function fmt_date(?string $date, string $format = 'Y-m-d'): string {
    return dfms_format_date($date, currentLocale(), $format) ?? '—';
}

function fmt_number($value, int $decimals = 2): string {
    return dfms_format_number($value, currentLocale(), $decimals);
}

function fmt_money($amount, ?string $currency = null): string {
    return dfms_format_money($amount, $currency ?? currentCurrency(), currentLocale());
}

/** Render a canonical (metric) measurement in the farm's measurement system. */
function fmt_measure($canonicalValue, string $kind): string {
    return dfms_format_measurement($canonicalValue, $kind, currentMeasurementSystem(), currentLocale());
}

/** "Today" on this farm — the calendar day at the farm, not on the server. */
function farmToday(): string {
    return dfms_today(currentTimezone());
}

/** A date offset from the farm's today, as Y-m-d. */
function farmDateOffset(int $days): string {
    return dfms_date_offset(currentTimezone(), $days);
}

// ---------------------------------------------------------------------------
// Writers.
//
// Both validate every field, write only known columns, and audit the change.
// Neither performs an authorization check — that is the caller's job via
// requireAuth() / requireOrganizationPermission(), so authorization stays in
// one place (Phase 1 architecture) rather than being duplicated here.
// ---------------------------------------------------------------------------

/**
 * Validate a settings payload.
 *
 * @param array $input      raw values keyed by setting name
 * @param bool  $allowEmpty when true, '' is accepted and means "inherit"
 * @return array{values: array, errors: array}
 */
function dfms_validate_settings(array $input, bool $allowEmpty): array {
    $values = [];
    $errors = [];

    $checks = [
        'country_code'       => ['dfms_is_valid_country', 'Select a valid country.'],
        'timezone'           => ['dfms_is_valid_timezone', 'Select a valid timezone.'],
        'locale'             => ['dfms_is_valid_locale', 'Select a supported language.'],
        'currency'           => ['dfms_is_valid_currency', 'Select a valid ISO 4217 currency.'],
        'measurement_system' => ['dfms_is_valid_measurement_system', 'Select a valid measurement system.'],
    ];

    foreach ($checks as $key => [$validator, $message]) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $raw = is_string($input[$key]) ? trim($input[$key]) : $input[$key];

        if ($raw === '' || $raw === null) {
            if ($allowEmpty) {
                $values[$key] = null;   // explicit "inherit"
                continue;
            }
            $errors[$key] = $message;
            continue;
        }
        if (!$validator($raw)) {
            $errors[$key] = $message;
            continue;
        }
        // Normalise case for the coded values so storage is consistent.
        $values[$key] = in_array($key, ['country_code', 'currency'], true) ? strtoupper($raw) : $raw;
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Update organization settings.
 *
 * $values keys: legal_name, country_code, timezone, locale, currency,
 * measurement_system. Absent keys are left unchanged; explicit nulls clear the
 * setting back to the platform default.
 */
function dfms_update_organization_settings(int $organizationId, array $values): bool {
    // Map hierarchy keys to the organization's column names.
    static $columns = [
        'legal_name'         => 'legal_name',
        'country_code'       => 'country_code',
        'timezone'           => 'default_timezone',
        'locale'             => 'default_locale',
        'currency'           => 'default_currency',
        'measurement_system' => 'measurement_system',
    ];

    $before = dfms_organization_row($organizationId);
    if ($before === null) {
        return false;
    }

    $sets = [];
    $params = [];
    $auditNew = [];
    foreach ($values as $key => $value) {
        if (!isset($columns[$key])) {
            continue;
        }
        $sets[] = "`{$columns[$key]}` = ?";
        $params[] = $value;
        $auditNew[$key] = $value;
    }
    if (!$sets) {
        return false;
    }

    $params[] = $organizationId;
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE organizations SET " . implode(', ', $sets) . " WHERE organization_id = ?");
    $stmt->execute($params);

    dfms_reset_settings_cache();

    // Audit the OLD values under the same keys so the diff is readable.
    $auditOld = [];
    foreach (array_keys($auditNew) as $key) {
        $auditOld[$key] = $before[$columns[$key]] ?? null;
    }
    if (function_exists('auditLog')) {
        auditLog('organization.settings_update', 'organization', $organizationId, $auditOld, $auditNew);
    }
    return true;
}

/**
 * Update farm settings. A null value means "inherit from the organization".
 */
function dfms_update_farm_settings(int $farmId, array $values): bool {
    static $columns = [
        'country_code'       => 'country_code',
        'timezone'           => 'timezone',
        'locale'             => 'locale',
        'currency'           => 'currency',
        'measurement_system' => 'measurement_system',
    ];

    $before = dfms_farm_row($farmId);
    if ($before === null) {
        return false;
    }

    $sets = [];
    $params = [];
    $auditNew = [];
    foreach ($values as $key => $value) {
        if (!isset($columns[$key])) {
            continue;
        }
        $sets[] = "`{$columns[$key]}` = ?";
        $params[] = $value;
        $auditNew[$key] = $value;
    }
    if (!$sets) {
        return false;
    }

    $params[] = $farmId;
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE farms SET " . implode(', ', $sets) . " WHERE farm_id = ?");
    $stmt->execute($params);

    dfms_reset_settings_cache();

    $auditOld = [];
    foreach (array_keys($auditNew) as $key) {
        $auditOld[$key] = $before[$columns[$key]] ?? null;
    }
    if (function_exists('auditLog')) {
        auditLog('farm.settings_update', 'farm', $farmId, $auditOld, $auditNew);
    }
    return true;
}
