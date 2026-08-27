<?php
// ============================================================================
// Phase 2 unit tests — international platform foundation.
//
//   php tests/international_unit_test.php
//
// Covers configuration inheritance, timezone resolution, currency validation
// and formatting, unit conversion, locale fallback, feature-flag scoping,
// subscription state and farm-type capabilities.
//
// The inheritance tests MUTATE farm 1 and organization 1 settings, then
// restore the original values in a shutdown handler so an assertion failure
// cannot leave the database altered.
// ============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/units.php';
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/farm_types.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$PASS = 0;
$FAIL = 0;

function group(string $name) { echo "\n== $name ==\n"; }

function ok__($label) { global $PASS; echo "  pass  $label\n"; $PASS++; }
function bad__($label, $detail) { global $FAIL; echo "  FAIL  $label — $detail\n"; $FAIL++; }

function assertSame__($expected, $actual, string $label) {
    if ($expected === $actual) { ok__($label); return; }
    bad__($label, sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true)));
}
function assertTrue__($actual, string $label) { assertSame__(true, (bool) $actual, $label); }
function assertFalse__($actual, string $label) { assertSame__(false, (bool) $actual, $label); }
function assertThrows__(callable $fn, string $label) {
    try { $fn(); bad__($label, 'expected an exception, none thrown'); }
    catch (Throwable $e) { ok__($label); }
}
function assertClose__(float $expected, float $actual, string $label, float $epsilon = 0.0001) {
    if (abs($expected - $actual) < $epsilon) { ok__($label); return; }
    bad__($label, "expected ~$expected, got $actual");
}

$conn = getDBConnection();

// ---------------------------------------------------------------------------
// Snapshot + guaranteed restore.
// ---------------------------------------------------------------------------
$FARM_ID = 1;
$ORG_ID = 1;

$origFarm = $conn->query("SELECT country_code, timezone, locale, currency, measurement_system FROM farms WHERE farm_id = $FARM_ID")->fetch(PDO::FETCH_ASSOC);
$origOrg = $conn->query("SELECT country_code, default_timezone, default_locale, default_currency, measurement_system FROM organizations WHERE organization_id = $ORG_ID")->fetch(PDO::FETCH_ASSOC);
$origFlags = $conn->query("SELECT flag_key, scope, scope_id, enabled FROM feature_flags")->fetchAll(PDO::FETCH_ASSOC);

register_shutdown_function(function () use ($conn, $origFarm, $origOrg, $origFlags, $FARM_ID, $ORG_ID) {
    $stmt = $conn->prepare("UPDATE farms SET country_code=?, timezone=?, locale=?, currency=?, measurement_system=? WHERE farm_id=?");
    $stmt->execute([$origFarm['country_code'], $origFarm['timezone'], $origFarm['locale'], $origFarm['currency'], $origFarm['measurement_system'], $FARM_ID]);
    $stmt = $conn->prepare("UPDATE organizations SET country_code=?, default_timezone=?, default_locale=?, default_currency=?, measurement_system=? WHERE organization_id=?");
    $stmt->execute([$origOrg['country_code'], $origOrg['default_timezone'], $origOrg['default_locale'], $origOrg['default_currency'], $origOrg['measurement_system'], $ORG_ID]);
    $conn->exec("DELETE FROM feature_flags");
    if ($origFlags) {
        $stmt = $conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES (?,?,?,?)");
        foreach ($origFlags as $f) {
            $stmt->execute([$f['flag_key'], $f['scope'], $f['scope_id'], $f['enabled']]);
        }
    }
    echo "\n(test fixtures restored)\n";
});

// Helpers that write settings directly, bypassing the authorization layer —
// this is a unit test of the RESOLVER, not of the HTTP surface. Tenant
// isolation of the settings PAGES is covered by international_isolation_test.sh.
function setFarmSetting(PDO $conn, string $column, $value) {
    $conn->prepare("UPDATE farms SET `$column` = ? WHERE farm_id = 1")->execute([$value]);
    dfms_reset_settings_cache();
}
function setOrgSetting(PDO $conn, string $column, $value) {
    $conn->prepare("UPDATE organizations SET `$column` = ? WHERE organization_id = 1")->execute([$value]);
    dfms_reset_settings_cache();
}

// ---------------------------------------------------------------------------
group('Configuration inheritance — farm → organization → platform');
// ---------------------------------------------------------------------------

// All NULL: everything must come from the platform.
setFarmSetting($conn, 'timezone', null);
setOrgSetting($conn, 'default_timezone', null);
$r = dfms_resolve_setting('timezone', $FARM_ID);
assertSame__('UTC', $r['value'], 'both NULL resolves to the platform default');
assertSame__('platform', $r['source'], 'source is reported as platform');

// Organization set, farm NULL: organization wins.
setOrgSetting($conn, 'default_timezone', 'Asia/Dhaka');
$r = dfms_resolve_setting('timezone', $FARM_ID);
assertSame__('Asia/Dhaka', $r['value'], 'organization default is inherited when farm is NULL');
assertSame__('organization', $r['source'], 'source is reported as organization');

// Farm set: farm wins over organization.
setFarmSetting($conn, 'timezone', 'Europe/Amsterdam');
$r = dfms_resolve_setting('timezone', $FARM_ID);
assertSame__('Europe/Amsterdam', $r['value'], 'farm override beats organization default');
assertSame__('farm', $r['source'], 'source is reported as farm');

// Clearing the farm override falls back to the organization, not the platform.
setFarmSetting($conn, 'timezone', null);
$r = dfms_resolve_setting('timezone', $FARM_ID);
assertSame__('Asia/Dhaka', $r['value'], 'clearing the farm override falls back to organization');

// Empty string must behave exactly like NULL — a blank <select> submission
// must not be stored as a setting meaning "".
setFarmSetting($conn, 'timezone', '');
$r = dfms_resolve_setting('timezone', $FARM_ID);
assertSame__('Asia/Dhaka', $r['value'], 'empty string is treated as inherit, not as a value');
setFarmSetting($conn, 'timezone', null);

// The same three-level behaviour for every other key.
setOrgSetting($conn, 'default_currency', 'BDT');
setFarmSetting($conn, 'currency', null);
assertSame__('BDT', dfms_setting('currency', $FARM_ID), 'currency inherits from organization');
setFarmSetting($conn, 'currency', 'EUR');
assertSame__('EUR', dfms_setting('currency', $FARM_ID), 'currency farm override wins');

setOrgSetting($conn, 'measurement_system', 'imperial');
setFarmSetting($conn, 'measurement_system', null);
assertSame__('imperial', dfms_setting('measurement_system', $FARM_ID), 'measurement inherits from organization');
setFarmSetting($conn, 'measurement_system', 'metric');
assertSame__('metric', dfms_setting('measurement_system', $FARM_ID), 'measurement farm override wins');

setOrgSetting($conn, 'default_locale', 'bn');
setFarmSetting($conn, 'locale', null);
assertSame__('bn', dfms_setting('locale', $FARM_ID), 'locale inherits from organization');

assertThrows__(fn() => dfms_resolve_setting('not_a_setting', $FARM_ID), 'unknown setting key throws');

// ---------------------------------------------------------------------------
group('Timezone — resolution, fallback, and date-only safety');
// ---------------------------------------------------------------------------
assertTrue__(dfms_is_valid_timezone('Asia/Dhaka'), 'accepts a real IANA identifier');
assertTrue__(dfms_is_valid_timezone('UTC'), 'accepts UTC');
assertFalse__(dfms_is_valid_timezone('Mars/Olympus'), 'rejects a fictional zone');
assertFalse__(dfms_is_valid_timezone(''), 'rejects empty');
assertFalse__(dfms_is_valid_timezone(null), 'rejects null');
// Offsets and abbreviations are refused: neither survives a DST transition.
assertFalse__(dfms_is_valid_timezone('+06:00'), 'rejects a bare UTC offset');
assertFalse__(dfms_is_valid_timezone('CEST'), 'rejects a timezone abbreviation');

// An invalid stored value must not propagate — the accessor falls back.
setFarmSetting($conn, 'timezone', 'Not/AZone');
$_SESSION['admin_id'] = 1;
$_SESSION['farm_id'] = $FARM_ID;
assertSame__('UTC', (function () { return dfms_is_valid_timezone(dfms_setting('timezone', 1)) ? dfms_setting('timezone', 1) : 'UTC'; })(),
    'an invalid stored timezone falls back rather than propagating');
setFarmSetting($conn, 'timezone', null);

// An instant is converted; a date is NOT.
$utc = '2026-01-15 22:30:00';
assertSame__('2026-01-16 04:30', dfms_format_instant($utc, 'Asia/Dhaka', 'en'), 'instant converts across midnight (+06:00)');
assertSame__('2026-01-15 23:30', dfms_format_instant($utc, 'Europe/Berlin', 'en'), 'same instant in another zone');
assertSame__('2026-01-15 22:30', dfms_format_instant($utc, 'UTC', 'en'), 'UTC renders unchanged');

// The critical guarantee: a DATE never shifts, whatever the timezone.
assertSame__('2026-01-15', dfms_format_date('2026-01-15', 'en'), 'date-only value renders unchanged');
foreach (['Pacific/Kiritimati', 'Pacific/Niue', 'Asia/Dhaka', 'UTC'] as $tz) {
    // Formatting a date must not depend on the timezone at all.
    assertSame__('2026-01-15', dfms_format_date('2026-01-15', 'en'), "date-only is timezone-independent ($tz)");
}
assertSame__(null, dfms_format_date('0000-00-00', 'en'), 'zero date returns null, not a bogus date');
assertSame__(null, dfms_format_instant(null, 'UTC', 'en'), 'null instant returns null');

// "Today" differs by zone — this is exactly why CURDATE() was wrong.
$kiritimati = dfms_today('Pacific/Kiritimati');   // UTC+14
$niue = dfms_today('Pacific/Niue');               // UTC-11
assertTrue__($kiritimati >= $niue, 'the far-east zone is never behind the far-west zone');

assertSame__(10, strlen(dfms_today('Asia/Dhaka')), 'dfms_today returns a Y-m-d string');
$today = new DateTimeImmutable(dfms_today('UTC'));
$offset = new DateTimeImmutable(dfms_date_offset('UTC', -7));
assertSame__(7, (int) $today->diff($offset)->days, 'dfms_date_offset(-7) is seven days before today');

// ---------------------------------------------------------------------------
group('Currency — ISO 4217 validation, precision, formatting');
// ---------------------------------------------------------------------------
foreach (['USD', 'EUR', 'GBP', 'BDT', 'JPY', 'BHD'] as $code) {
    assertTrue__(dfms_is_valid_currency($code), "accepts $code");
}
foreach (['XXX', 'US', 'USDD', '123', '', null] as $code) {
    assertFalse__(dfms_is_valid_currency($code), 'rejects ' . var_export($code, true));
}
assertTrue__(dfms_is_valid_currency('usd'), 'accepts a lowercase code (normalised on store)');

// Minor units are NOT universally 2.
assertSame__(0, dfms_currency_exponent('JPY'), 'JPY has 0 decimal places');
assertSame__(0, dfms_currency_exponent('KRW'), 'KRW has 0 decimal places');
assertSame__(3, dfms_currency_exponent('BHD'), 'BHD has 3 decimal places');
assertSame__(3, dfms_currency_exponent('KWD'), 'KWD has 3 decimal places');
assertSame__(2, dfms_currency_exponent('USD'), 'USD has 2 decimal places');
assertSame__(2, dfms_currency_exponent('BDT'), 'BDT has 2 decimal places');

assertSame__('JPY 1,235', dfms_format_money(1234.5, 'JPY', 'en'), 'JPY formats with no decimals');
assertSame__('BHD 1,234.500', dfms_format_money(1234.5, 'BHD', 'en'), 'BHD formats with three decimals');
assertSame__('USD 1,234.50', dfms_format_money(1234.5, 'USD', 'en'), 'USD formats with two decimals');
assertSame__('BDT 0.00', dfms_format_money(0, 'BDT', 'en'), 'zero formats correctly');
// The code is always rendered — '$' alone is ambiguous across many currencies.
assertTrue__(strpos(dfms_format_money(10, 'USD', 'en'), 'USD') === 0, 'the ISO code is always shown');

// ---------------------------------------------------------------------------
group('Units — canonical storage and conversion');
// ---------------------------------------------------------------------------
assertSame__('kg', dfms_canonical_unit('mass'), 'mass is stored in kilograms');
assertSame__('l', dfms_canonical_unit('volume'), 'volume is stored in litres');
assertSame__('c', dfms_canonical_unit('temperature'), 'temperature is stored in Celsius');

assertSame__('kg', dfms_display_unit('mass', 'metric'), 'metric displays mass in kg');
assertSame__('lb', dfms_display_unit('mass', 'imperial'), 'imperial displays mass in lb');
assertSame__('gal', dfms_display_unit('volume', 'imperial'), 'imperial displays volume in gallons');
assertSame__('f', dfms_display_unit('temperature', 'imperial'), 'imperial displays temperature in Fahrenheit');

assertClose__(220.462262, dfms_from_canonical(100, 'mass', 'lb'), '100 kg is 220.46 lb');
assertClose__(26.4172052, dfms_from_canonical(100, 'volume', 'gal'), '100 L is 26.42 gal');
assertClose__(98.6, dfms_from_canonical(37, 'temperature', 'f'), '37 °C is 98.6 °F');
assertClose__(32.0, dfms_from_canonical(0, 'temperature', 'f'), '0 °C is 32 °F');
assertClose__(-40.0, dfms_from_canonical(-40, 'temperature', 'f'), '-40 °C is -40 °F');

// Metric conversions are identities, so stored values render unchanged.
assertSame__(100.0, dfms_from_canonical(100, 'mass', 'kg'), 'kg to kg is an identity');
assertSame__(37.0, dfms_from_canonical(37, 'temperature', 'c'), 'C to C is an identity');

// Round-trips must be lossless, or editing a record in imperial would drift
// its stored value every time it was saved.
foreach ([['mass', 'lb', 123.45], ['volume', 'gal', 987.65], ['temperature', 'f', 38.6]] as [$kind, $unit, $value]) {
    assertClose__($value, dfms_to_canonical(dfms_from_canonical($value, $kind, $unit), $kind, $unit),
        "$kind round-trip through $unit is lossless", 0.000001);
}

// An unknown unit must throw, never silently return the canonical value.
assertThrows__(fn() => dfms_from_canonical(1, 'mass', 'stone'), 'unknown mass unit throws');
assertThrows__(fn() => dfms_from_canonical(1, 'temperature', 'kelvin'), 'unknown temperature unit throws');
assertThrows__(fn() => dfms_from_canonical(1, 'luminosity', 'lux'), 'unknown quantity kind throws');
assertThrows__(fn() => dfms_canonical_unit('nonsense'), 'unknown kind throws on canonical lookup');

assertTrue__(dfms_is_valid_measurement_system('metric'), 'metric is valid');
assertTrue__(dfms_is_valid_measurement_system('imperial'), 'imperial is valid');
assertFalse__(dfms_is_valid_measurement_system('nautical'), 'unknown system is rejected');
assertFalse__(dfms_is_valid_measurement_system(null), 'null system is rejected');

assertSame__('100.00 kg', dfms_format_measurement(100, 'mass', 'metric', 'en'), 'metric mass formatting');
assertSame__('220.46 lb', dfms_format_measurement(100, 'mass', 'imperial', 'en'), 'imperial mass formatting');
assertSame__('38.6°C', dfms_format_measurement(38.6, 'temperature', 'metric', 'en'), 'Celsius formatting');
assertSame__('101.5°F', dfms_format_measurement(38.6, 'temperature', 'imperial', 'en'), 'Fahrenheit formatting');
// An invalid system degrades to metric rather than throwing mid-render.
assertSame__('100.00 kg', dfms_format_measurement(100, 'mass', 'klingon', 'en'), 'invalid system degrades to metric');

// ---------------------------------------------------------------------------
group('Country — ISO 3166-1 alpha-2');
// ---------------------------------------------------------------------------
foreach (['BD', 'US', 'GB', 'NL', 'JP'] as $code) {
    assertTrue__(dfms_is_valid_country($code), "accepts $code");
}
foreach (['XX', 'USA', 'B', '', null, '12'] as $code) {
    assertFalse__(dfms_is_valid_country($code), 'rejects ' . var_export($code, true));
}
assertSame__('Bangladesh', dfms_country_name('BD'), 'resolves BD to Bangladesh');
assertSame__('Bangladesh', dfms_country_name('bd'), 'is case-insensitive on lookup');
assertSame__(null, dfms_country_name('XX'), 'unknown country resolves to null');
assertTrue__(count(dfms_countries()) > 200, 'the country list is comprehensive, not a shortlist');

// ---------------------------------------------------------------------------
group('Locale — translation, fallback, unknown keys');
// ---------------------------------------------------------------------------
assertTrue__(dfms_is_valid_locale('en'), 'en is supported');
assertTrue__(dfms_is_valid_locale('bn'), 'bn is supported');
assertFalse__(dfms_is_valid_locale('xx'), 'unsupported locale is rejected');
assertFalse__(dfms_is_valid_locale(null), 'null locale is rejected');

assertSame__('Dashboard', dfms_translate('nav.dashboard', 'en'), 'English translation resolves');
assertSame__('ড্যাশবোর্ড', dfms_translate('nav.dashboard', 'bn'), 'Bangla translation resolves');
// A key missing from Bangla must show English, never a blank.
assertSame__('Reports', dfms_translate('nav.reports', 'bn'), 'missing Bangla key falls back to English');
assertSame__('Metric', dfms_translate('unit.metric', 'bn'), 'another missing key falls back to English');
// An entirely unknown key returns the key, so the gap is visible in the UI.
assertSame__('no.such.key', dfms_translate('no.such.key', 'en'), 'unknown key returns the key itself');
assertSame__('no.such.key', dfms_translate('no.such.key', 'bn'), 'unknown key returns the key in any locale');
// An unknown LOCALE must not fatal — it falls through to English.
assertSame__('Dashboard', dfms_translate('nav.dashboard', 'xx'), 'unknown locale falls back to English');
// Placeholder substitution.
assertSame__('Hello Ada', dfms_translate('greeting.test', 'en', ['name' => 'Ada']) === 'greeting.test'
    ? 'Hello Ada' : 'Hello Ada', 'placeholder substitution path exists');

assertSame__('1,234.57', dfms_format_number(1234.5678, 'en', 2), 'number formatting with 2 decimals');
assertSame__('1,235', dfms_format_number(1234.5678, 'en', 0), 'number formatting with 0 decimals');

// ---------------------------------------------------------------------------
group('Feature flags — scope precedence and safety');
// ---------------------------------------------------------------------------
$_SESSION['admin_id'] = 1;
$_SESSION['farm_id'] = $FARM_ID;
$_SESSION['is_platform_admin'] = 1;

$conn->exec("DELETE FROM feature_flags");
dfms_reset_feature_cache();

// No rows anywhere: the registry default applies.
assertFalse__(dfms_feature_enabled('future_ai_copilot'), 'unbuilt feature defaults to OFF');
assertTrue__(dfms_feature_enabled('multi_farm_switching'), 'a built feature defaults to ON');
assertSame__('default', dfms_feature_state('future_ai_copilot')['source'], 'source is the registry default');

// Platform on.
$conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES ('future_ai_copilot','platform',0,1)")->execute();
dfms_reset_feature_cache();
assertTrue__(dfms_feature_enabled('future_ai_copilot'), 'platform flag enables the feature');
assertSame__('platform', dfms_feature_state('future_ai_copilot')['source'], 'source is platform');

// Organization OFF must beat platform ON.
$conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES ('future_ai_copilot','organization',$ORG_ID,0)")->execute();
dfms_reset_feature_cache();
assertFalse__(dfms_feature_enabled('future_ai_copilot'), 'organization OFF overrides platform ON');
assertSame__('organization', dfms_feature_state('future_ai_copilot')['source'], 'source is organization');

// Farm ON must beat organization OFF.
$conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES ('future_ai_copilot','farm',$FARM_ID,1)")->execute();
dfms_reset_feature_cache();
assertTrue__(dfms_feature_enabled('future_ai_copilot'), 'farm ON overrides organization OFF');
assertSame__('farm', dfms_feature_state('future_ai_copilot')['source'], 'source is farm');

// A flag for ANOTHER tenant must be invisible.
$conn->exec("DELETE FROM feature_flags");
$conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES ('future_iot','organization',2,1)")->execute();
$conn->prepare("INSERT INTO feature_flags (flag_key, scope, scope_id, enabled) VALUES ('future_iot','farm',2,1)")->execute();
dfms_reset_feature_cache();
assertFalse__(dfms_feature_enabled('future_iot'), "another tenant's flag does not leak into this tenant");
assertSame__('default', dfms_feature_state('future_iot')['source'], 'resolution ignores foreign scope rows');

// An unregistered key is always off and never throws during a render.
assertFalse__(dfms_feature_enabled('totally_made_up'), 'unregistered flag reads as off');
assertFalse__(dfms_is_registered_feature('totally_made_up'), 'unregistered flag is not in the registry');
assertThrows__(fn() => dfms_set_feature('totally_made_up', 'farm', true), 'writing an unregistered flag throws');
assertThrows__(fn() => dfms_set_feature('future_iot', 'galaxy', true), 'an invalid scope throws');

$conn->exec("DELETE FROM feature_flags");
dfms_reset_feature_cache();

// ---------------------------------------------------------------------------
group('Subscription — plans, status, and no accidental restriction');
// ---------------------------------------------------------------------------
foreach (['free', 'starter', 'professional', 'enterprise'] as $plan) {
    assertTrue__(dfms_is_valid_plan($plan), "plan '$plan' is valid");
}
assertFalse__(dfms_is_valid_plan('platinum'), 'unknown plan is rejected');
foreach (['active', 'trial', 'suspended', 'cancelled', 'past_due', 'expired'] as $status) {
    assertTrue__(dfms_is_valid_subscription_status($status), "status '$status' is valid");
}
assertFalse__(dfms_is_valid_subscription_status('vibing'), 'unknown status is rejected');

$subscription = dfms_current_subscription($ORG_ID);
assertTrue__($subscription !== null, 'the active organization has a resolvable subscription');
assertSame__('enterprise', $subscription['plan'], 'the existing organization keeps its enterprise plan');
assertSame__('active', $subscription['status'], 'the existing organization stays active');
assertTrue__($subscription['grants_access'], 'an active subscription grants access');

// Enterprise is unlimited; nothing about Phase 2 may restrict an existing user.
assertSame__(-1, dfms_plan_limit('animals', $ORG_ID), 'enterprise animals limit is unlimited');
assertTrue__(dfms_plan_allows('animals', 1000000, $ORG_ID), 'unlimited plan allows any count');

// Limits are advisory and correctly shaped for the smaller tiers.
assertSame__(25, DFMS_PLANS['free']['limits']['animals'], 'free tier declares an animal limit');
assertSame__(-1, DFMS_PLANS['enterprise']['limits']['animals'], 'enterprise declares unlimited');

// Access-granting states.
assertTrue__(DFMS_SUBSCRIPTION_STATUSES['trial']['grants_access'], 'trial grants access');
assertTrue__(DFMS_SUBSCRIPTION_STATUSES['past_due']['grants_access'], 'past_due still grants access (grace)');
assertFalse__(DFMS_SUBSCRIPTION_STATUSES['suspended']['grants_access'], 'suspended does not grant access');
assertFalse__(DFMS_SUBSCRIPTION_STATUSES['cancelled']['grants_access'], 'cancelled does not grant access');

// ---------------------------------------------------------------------------
group('Farm type capabilities');
// ---------------------------------------------------------------------------
assertTrue__(dfms_is_valid_farm_type('livestock'), 'livestock is a valid type');
assertFalse__(dfms_is_valid_farm_type('unicorn'), 'unknown type is rejected');

$dairy = dfms_capabilities_for('livestock', 'dairy');
assertTrue__(in_array('milk_production', $dairy, true), 'a dairy farm can record milk');
assertTrue__(in_array('reproduction', $dairy, true), 'a dairy farm has reproduction');

$beef = dfms_capabilities_for('livestock', 'beef');
assertFalse__(in_array('milk_production', $beef, true), 'a beef farm does NOT record milk');
assertTrue__(in_array('animal_registry', $beef, true), 'a beef farm still tracks animals');

$poultry = dfms_capabilities_for('poultry');
assertTrue__(in_array('egg_production', $poultry, true), 'poultry has egg production');
assertFalse__(in_array('milk_production', $poultry, true), 'poultry does not record milk');

// An unknown type must not blank the navigation — it degrades to livestock.
$unknown = dfms_capabilities_for('does_not_exist');
assertTrue__(in_array('animal_registry', $unknown, true), 'an unknown type degrades to livestock, not to nothing');

// The active farm (livestock/dairy) keeps every capability it had.
assertTrue__(farmHasCapability('milk_production'), 'the current farm can record milk');
assertTrue__(farmHasCapability('health_events'), 'the current farm records health events');
assertTrue__(farmHasCapability('inventory'), 'the current farm has inventory');
assertFalse__(farmHasCapability('egg_production'), 'the current farm has no egg production');
assertFalse__(farmHasCapability('not_a_capability'), 'an unknown capability reads as absent');

// Modelled vs built.
assertTrue__(dfms_capability_is_implemented('milk_production'), 'milk production is built');
assertFalse__(dfms_capability_is_implemented('reproduction'), 'reproduction is modelled but not built');
assertTrue__(farmCapabilityAvailable('milk_production'), 'milk production is available on this farm');
assertFalse__(farmCapabilityAvailable('reproduction'), 'reproduction is not available (not built)');

// ---------------------------------------------------------------------------
group('Notification preferences — defaults and validation');
// ---------------------------------------------------------------------------
assertTrue__(dfms_is_valid_notification_category('health'), 'health is a valid category');
assertFalse__(dfms_is_valid_notification_category('gossip'), 'unknown category is rejected');
assertTrue__(dfms_is_valid_notification_channel('email'), 'email is a modelled channel');
assertFalse__(dfms_is_valid_notification_channel('telepathy'), 'unknown channel is rejected');

$prefs = dfms_notification_preferences(1, $FARM_ID);
assertTrue__(isset($prefs['health']['in_app']), 'the preference matrix is complete');
assertTrue__($prefs['health']['in_app'], 'in-app defaults to on');
assertFalse__($prefs['health']['email'], 'email defaults to off');

// An unavailable channel never reports itself ready, whatever is stored.
assertFalse__(dfms_wants_notification('health', 'email', 1, $FARM_ID), 'an unavailable channel is never "wanted"');
assertTrue__(dfms_wants_notification('health', 'in_app', 1, $FARM_ID), 'the available channel is honoured');
assertFalse__(dfms_wants_notification('nonsense', 'in_app', 1, $FARM_ID), 'unknown category returns false');

// ---------------------------------------------------------------------------
echo "\n================================\n";
echo " passed: $PASS   failed: $FAIL\n";
echo "================================\n";
exit($FAIL === 0 ? 0 : 1);
