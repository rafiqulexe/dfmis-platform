# DFMIS International Architecture

**Phase:** 2 — International Platform Architecture
**Date:** 27 August 2026
**Status:** Implemented and verified. 412 automated assertions passing across 6 suites.

How DFMIS represents countries, timezones, currencies, units, locales and farm
types so one codebase can serve organizations in different countries without
special-casing any of them.

**Companion documents:** [`DFMIS_BASELINE_AUDIT.md`](./DFMIS_BASELINE_AUDIT.md),
[`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md),
[`DFMIS_SAAS_FOUNDATION.md`](./DFMIS_SAAS_FOUNDATION.md),
[`DFMIS_ROADMAP.md`](./DFMIS_ROADMAP.md).

---

## 1. Configuration hierarchy

### Precedence — exact rule

```
Farm setting                farms.timezone, farms.currency, farms.locale,
    ↓  NULL = inherit       farms.measurement_system, farms.country_code
Organization setting        organizations.default_timezone, .default_currency,
    ↓  NULL = inherit       .default_locale, .measurement_system, .country_code
Platform default            env APP_DEFAULT_*, else constants in settings.php
```

Resolution is implemented **once**, in `includes/settings.php`. No page, module
or query implements its own fallback.

### NULL means inherit — everywhere

None of the columns added in migration 006 carry a database `DEFAULT`. That is
deliberate: a column default would be a second copy of the platform default, and
two copies drift. An empty string is treated identically to `NULL`, so a blank
`<select>` submission clears an override rather than storing `""` as a value.

### Platform defaults

| Setting | Default | Env override |
|---|---|---|
| Timezone | `UTC` | `APP_DEFAULT_TIMEZONE` |
| Locale | `en` | `APP_DEFAULT_LOCALE` |
| Currency | `USD` | `APP_DEFAULT_CURRENCY` |
| Measurement system | `metric` | `APP_DEFAULT_MEASUREMENT` |
| Country | *none* — never guessed | — |

Defaults are neutral by design. The platform does not assume the country of its
first customer, and country is never inferred from an organization's name or its
existing data. An **invalid** env value falls back to the constant rather than
propagating a broken setting through the whole application.

### Why the hierarchy stops at farm

There are no user-level preferences, deliberately. Timezone, currency and units
are properties of the **operation**, not of the person looking at it: two people
reading the same milk record must see the same litres and the same production
day, or they cannot discuss it.

Locale is the one setting where a per-user preference would be defensible. It is
left out until there is a real second language to choose — adding an unused
preference column now would be the speculative abstraction this phase is meant
to avoid.

### The API

```php
// Resolve with provenance — returns ['value' => …, 'source' => 'farm'|'organization'|'platform']
dfms_resolve_setting('timezone');
dfms_setting('currency');                 // value only

// Active-tenant accessors — what pages actually call
currentTimezone(); currentLocale(); currentCurrency();
currentMeasurementSystem(); currentCountryCode();

// Rendering with the resolved configuration applied
t('nav.dashboard');                       // translate
fmt_instant($storedUtc);                  // UTC → farm timezone
fmt_date($dateOnly);                      // never timezone-shifted
fmt_number($v); fmt_money($v); fmt_measure($v, 'volume');
farmToday(); farmDateOffset(-30);         // the FARM's calendar day
```

`dfms_resolve_setting()` returns the **source** alongside the value because the
settings UI must distinguish an override from an inherited value — `Asia/Dhaka`
alone does not tell a farm manager which page they need to change it on.

---

## 2. Organization settings

| Column | Type | Purpose |
|---|---|---|
| `name` | VARCHAR(150) | Display name. Pre-existing; unchanged. |
| `legal_name` | VARCHAR(200) NULL | Registered entity name, which differs from the trading name in most jurisdictions. Used by contracts and invoices in a later phase. |
| `country_code` | CHAR(2) NULL | ISO 3166-1 alpha-2 |
| `default_timezone` | VARCHAR(64) NULL | IANA identifier |
| `default_locale` | VARCHAR(10) NULL | BCP 47 language tag |
| `default_currency` | CHAR(3) NULL | ISO 4217 |
| `measurement_system` | ENUM NULL | `metric` \| `imperial` |

Managed at `modules/settings/organization.php`, gated on
`requireOrganizationPermission('org.manage')` (owner or admin). The organization
id comes from `currentOrganizationId()` — never from the request.

---

## 3. Farm inheritance

| Column | Meaning when NULL |
|---|---|
| `farms.country_code` | Inherit the organization's country |
| `farms.timezone` | Inherit the organization's default timezone |
| `farms.locale` | Inherit the organization's default locale |
| `farms.currency` | Inherit the organization's default currency |
| `farms.measurement_system` | Inherit the organization's measurement system |

Every farm field is independently overridable. A farm may override timezone and
units while still inheriting locale and currency — verified by test.

**Why a farm needs its own values at all:** an organization headquartered in one
country may operate farms in another. Those farms keep local time (which decides
which calendar day a milking is recorded against), local units (local staff want
local units regardless of head office) and sometimes local currency (a
cross-border operation).

Managed at `modules/settings/farm.php`, gated on `requireAuth('settings.farm')`
(farm managers). The farm id comes from `currentFarmId()` — never from the
request. Each field's "inherit" option is labelled with the value that would
apply if the override were removed, so the consequence of clearing is visible.

---

## 4. Country strategy

**ISO 3166-1 alpha-2** codes, stored as `CHAR(2)`. Names are display-only and are
never persisted — a country's name changes, its code does not.

The full 249-entry list lives in `includes/i18n.php` as reference data.
Validation is a membership test against that list: `dfms_is_valid_country()`
accepts `BD`, `US`, `GB`, `NL`, `JP` and rejects `XX`, `USA`, `B`, `12`, `''`
and `null`.

**No country is privileged.** There is no Bangladesh-specific code path,
column, or default anywhere in the schema or application. Existing Bangladeshi
data remains valid because nothing ever depended on country, and country is left
`NULL` on existing rows rather than inferred.

---

## 5. Location architecture

`locations` gained a generic international address model in migration 006:

| Column | Covers |
|---|---|
| `country_code` | ISO 3166-1 alpha-2 |
| `admin_area` | state, province, region, prefecture, county, division |
| `locality` | city, town, village |
| `postal_code` | postal / ZIP / PIN code |
| `address_line1`, `address_line2` | street address |
| `latitude`, `longitude` | DECIMAL(10,7) — WGS84, ~1cm precision |

**Field names are deliberately generic.** `admin_area` and `locality` are used
rather than `state`, `province`, `district`, `division`, `thana` or `union`.
Naming a column after one country's administrative subdivisions is exactly the
assumption that makes a schema untranslatable later — not every country has a
state, and not every country has a district.

**Legacy data:** an `information_schema` sweep before writing the migration
confirmed **no** Bangladesh-specific or address columns existed anywhere in the
schema. There was no historical location data to preserve, migrate or corrupt.
All new columns are `NULL` on existing rows.

Postal-code *format* is deliberately not validated per country: formats change,
lists go stale, and a false rejection blocks a real address. Length is bounded;
shape is not.

---

## 6. Timezone strategy

### Storage and display

| Kind | Storage | Display |
|---|---|---|
| **Instant** (a moment in time) | UTC | Converted to the resolved timezone |
| **Date-only** (a calendar fact) | `DATE`, as written | **Never** timezone-converted |
| **Time-of-day** | `TIME` | Rendered as stored (wall-clock) |

### Which existing columns are which

**Date-only — remain `DATE`, never shifted:**
`cows.date_of_birth`, `milk_production.production_date`,
`health_events.event_date`, `feed_consumption.consumption_date`

A birth date or a production day is a calendar fact, not an instant. Shifting it
by a timezone offset can move it to the previous or next day and silently change
what the record means. `dfms_format_date()` applies no conversion at all, and a
test asserts the rendering is identical across `Pacific/Kiritimati` (UTC+14),
`Pacific/Niue` (UTC−11), `Asia/Dhaka` and `UTC`.

**Instants — stored UTC, rendered in farm time:**
`audit_log.created_at`, `login_attempts.attempted_at`, `alerts.read_at`,
`stored_files.created_at`, `cows.deleted_at`, all other `TIMESTAMP` columns,
plus the `DATETIME` columns `alerts.alert_time` and
`temperature_readings.reading_time`.

**Time-of-day — `TIME`:** `feeding_schedules.feed_time` is a wall-clock time
("milk at 08:00"), correctly typed and not converted.

### Clock discipline (new in Phase 2)

Both ends are pinned to UTC:

- `date_default_timezone_set('UTC')` in `config/database.php`
- `SET time_zone = '+00:00'` on every PDO connection

**The problem this fixed.** Before Phase 2 the PHP process inherited the host
timezone (`Europe/Berlin`, +02:00) while MySQL's session zone was `SYSTEM`
(+06:00). `alerts.alert_time` is a `DATETIME` — a bare wall-clock value with no
zone of its own — and it was written by PHP's `date()` on one clock **and** by
the seeder's `NOW()` on another. Two clocks, one column, six hours apart.

Pinning both ends makes every **new** write unambiguous.

### Historical data — the honest limitation

Existing `TIMESTAMP` values are unaffected: the engine stores them as UTC
internally regardless of session zone, so the stored instant never changed. What
changed is that they are now *read back* as UTC, which is what the display layer
expects.

Existing `DATETIME` values in `alerts.alert_time` and
`temperature_readings.reading_time` are a **known, unresolved inconsistency**.
Rows written by PHP carry Berlin wall-time; rows written by the seeder carry
Dhaka wall-time. Which is which cannot be determined from the data, so **no
backfill was attempted** — inventing an offset would be exactly the silent
reinterpretation this phase forbids. The rows are demo data; the ambiguity is
recorded here rather than papered over. Converting these two columns to
`TIMESTAMP` with an explicit, documented backfill is deferred work.

### Date-boundary queries

`CURDATE()` and `DATE_SUB(CURDATE(), …)` were removed from `dashboard.php` and
`includes/insights.php`. They answered *"what day is it where the database
server is"*, which is a different day from the farm's for several hours of every
day. Boundaries are now computed with `farmToday()` / `farmDateOffset()` in the
farm's timezone and bound as parameters.

---

## 7. Currency strategy

### What exists

- Organization default currency, optional farm override
- ISO 4217 validation against a 106-entry table
- Centralized formatting with **correct per-currency decimal places**

### Decimal precision is not universally 2

The ISO 4217 minor-unit exponent is carried as reference data, not assumed:

| Currency | Exponent | Formatted |
|---|---|---|
| JPY, KRW, ISK, VND, CLP, RWF, UGX, XAF, XOF, PYG | 0 | `JPY 1,235` |
| USD, EUR, GBP, BDT, and most others | 2 | `USD 1,234.50` |
| BHD, KWD, OMR, JOD, TND, IQD, LYD | 3 | `BHD 1,234.500` |

Formatting money with a hardcoded `2` is simply wrong in those currencies.

### The ISO code is always rendered

`dfms_format_money()` emits `USD 1,234.50`, never `$1,234.50`. A bare `$` is
ambiguous across at least a dozen currencies. Until DFMIS has real multi-currency
accounting, unambiguous beats pretty.

### Historical amounts cannot change meaning

**There are no monetary columns in DFMIS.** An `information_schema` sweep for
`%price%`, `%cost%`, `%amount%`, `%currency%` and `%revenue%` returned exactly
one match — `feeding_schedules.feed_amount`, which is a **mass in kilograms**,
not money.

So the risk the brief describes — an organization changing its display currency
and silently reinterpreting historical figures — **cannot arise today**, and no
migration was needed to guard against it.

**The rule for when finance arrives (Phase 9):** every monetary column must be
stored as an amount **plus its own `currency_code`** on the same row. The
organization's *display* currency must never be used to interpret a stored
amount, because the display setting can change and a stored transaction cannot.
No speculative money tables were created in this phase.

### Not implemented

Live exchange rates, currency conversion, multi-currency accounting. Deferred to
Phase 9 with Phase 12 for historical rates.

---

## 8. Canonical measurement strategy

### The rule

Every physical quantity is **stored in exactly one canonical (metric) unit** and
**converted only at display or input**.

| Quantity | Canonical stored unit | Columns |
|---|---|---|
| Mass | kilogram (kg) | `feeding_schedules.feed_amount`, `feed_consumption.total_feed_consumed` |
| Volume | litre (L) | `milk_production.total_milk_yield` |
| Temperature | degrees Celsius (°C) | `temperature_readings.temperature` |

Display units follow the resolved measurement system: metric renders kg / L / °C,
imperial renders lb / gal (US liquid) / °F.

### Why this reinterprets nothing

Every one of those columns **already held metric values** — verified against the
live database before the strategy was chosen (milk ≈ 108 L/day, feed ≈ 5 kg per
schedule, temperature ≈ 36.5 °C). Declaring metric canonical therefore changes
the meaning of **zero** historical values, and no backfill was required.

Had the existing data been imperial, the honest move would have been a migration
that converted it explicitly — not a redefinition of what the numbers mean.

### Why not `weight_kg` / `weight_lb` column pairs

Two columns for one fact desynchronise the moment anything writes one and not
the other, and the pair still cannot express a third unit. One canonical column
plus a conversion at the edge is smaller, cannot drift, and extends to any unit.

### Round-trips are lossless

Conversion factors are exact (`1 kg = 2.20462262185 lb`,
`1 L = 0.264172052358 US gal`), and `dfms_to_canonical(dfms_from_canonical(x))`
returns `x` to within 1e-6 — tested for mass, volume and temperature. Without
that, editing a record in imperial would drift its stored value on every save.

### Unknown units throw

`dfms_from_canonical(1, 'mass', 'stone')` raises `InvalidArgumentException`. An
unknown unit must never be silently treated as canonical, because that returns a
plausible-looking number that is wrong.

### Deliberately excluded

`inventory_levels.quantity` is **not** a physical measurement. Its unit is a
free-text label on `inventory_items.unit` chosen by the user (`Bottle`, `Pack`,
`Bundle`, `Kg`) — it is a count of user-defined packaging, and converting it
would be meaningless. `cows` has no weight column; when one is added it must be
canonical kilograms.

---

## 9. Locale strategy

### What exists

- `lang/en.php` — the fallback catalogue and the only complete one
- `lang/bn.php` — **deliberately incomplete**, proving the fallback path works
  with a non-Latin script
- `dfms_translate($key, $locale, $params)` and the `t()` wrapper
- Locale-aware number formatting, using `ext-intl` when present and degrading to
  a correct non-intl implementation when it is not (stock XAMPP ships it off)

### Resolution

```
requested locale → fallback locale (en) → the key itself
```

An unknown key returns **the key**, not an empty string, so a missing
translation is visible in the interface instead of rendering a blank label.
An unknown *locale* falls back to English rather than fataling.

### What this is NOT

**This is not full multilingual support and must not be described as such.**
Roughly 45 shared navigation, action and settings strings are externalized. The
thousands of strings inline in module templates are untouched — that is
incremental work each later phase does for its own surfaces. Phase 12 completes
the sweep.

RTL layout, locale-aware date *patterns*, and pluralisation rules are not
implemented.

---

## 10. Farm type extensibility

### The problem avoided

The natural way to add a second vertical is `if ($farmType === 'dairy')`
wherever behaviour differs. Those checks multiply through unrelated code until
adding a third vertical means finding all of them.

### The model

A farm type declares **capabilities**. Code asks *"can this farm do X?"*, never
*"is this farm a dairy?"*.

```php
farmHasCapability('milk_production');     // correct
currentFarmType() === 'livestock';        // wrong outside includes/farm_types.php
```

Capabilities are **derived** from `farms.farm_type` / `farm_subtype` (which
already existed, from migration 004), not stored — storing them would let a
farm's declared type and its declared capabilities disagree. **No new column was
added.**

| Type / subtype | Capabilities |
|---|---|
| livestock/dairy | animal registry, milk, health, feeding, inventory, reproduction |
| livestock/beef | animal registry, health, feeding, inventory, reproduction — **no milk** |
| poultry | animal registry, **egg production**, health, feeding, inventory |
| aquaculture | animal registry, health, feeding, inventory, **water quality** |
| crop / greenhouse | crop cycles, inventory (+ water quality for greenhouse) |

### Modelled vs built

`DFMS_IMPLEMENTED_CAPABILITIES` records which capabilities have a real module
behind them today. `farmCapabilityAvailable()` requires **both** capability and
implementation, so a farm type never advertises a module that does not exist.
`reproduction` is modelled and not built; navigation shows it as "Soon".

### Capability is not authorization

```php
requireAuth('milk.create');                 // may this user?
if (farmHasCapability('milk_production'))   // does this farm do it?
```

Both are required. An unknown farm type degrades to the livestock capability set
rather than an empty one — an empty set would silently hide every module from a
farm whose type was mistyped, which looks like data loss to the user.

**Current dairy behaviour is unchanged.** Farm 1 is `livestock`/`dairy` and every
capability it previously relied on resolves true.

---

## 11. Authorization

No authorization logic was added in Phase 2. Every new surface reuses the Phase 1
architecture:

| Surface | Gate |
|---|---|
| Settings hub | `requireAuth('settings.view')` |
| Organization settings | `requireAuth('settings.view')` + `requireOrganizationPermission('org.manage')` |
| Farm settings | `requireAuth('settings.farm')` |
| Feature configuration | `requireAuth('settings.view')`, then **per-scope**: farm → `settings.features`, organization → `org.manage` |
| Notification preferences | `requireAuth('notifications.manage')` |

`requireAuth()` covers authentication, tenant context, farm membership,
organization membership and permission in one call.

**Tenant scope:** every identifier used for a write comes from the tenant
context (`currentFarmId()`, `currentOrganizationId()`, `currentUserId()`), never
from the request. Tests confirm that posting a crafted `organization_id=1` or
`farm_id=1` as another tenant writes to the attacker's **own** tenant, leaving
the target untouched.

**Audit:** every settings mutation writes through the Phase 1 audit layer —
`organization.settings_update`, `farm.settings_update`, `organization.rename`,
`farm.rename`, `feature.configure`, `subscription.update`,
`notification.preference_update` — with the credential deny-list applied.

---

## 12. Verification

| Suite | Assertions |
|---|---|
| `php -l` | 65 files clean |
| `validation_unit_test.php` | 104 |
| `international_unit_test.php` | 185 |
| `tenant_isolation_test.sh` | 14 |
| `security_regression_test.sh` | 62 |
| `international_isolation_test.sh` | 47 |
| **Total** | **412 passing, 0 failing** |

```bash
bash tests/run_all.sh
```

The international unit suite mutates farm and organization settings to prove
inheritance, and restores every original value in a shutdown handler so an
assertion failure cannot leave the database altered.
