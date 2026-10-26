# DFMIS Baseline Audit

**Date:** 27 August 2026
**Scope:** Full repository, read-only reconnaissance and baseline verification
**Verdict:** Sound security *design* on an unfinished *schema*. 34 findings. Baseline is green and reproducible.

This document records the state of DFMIS before any work toward the international
AI-powered platform begins. Nothing in the application was modified to produce it.

---

## 1. Current architecture

DFMIS is a **procedural, framework-free PHP application** served as classic
multi-page server-rendered HTML. There is no dependency manager, no build step,
no class hierarchy — 39 PHP files, all procedural, wired together with
`require_once`.

```
Request
  └── page (index.php / dashboard.php / modules/<domain>/<action>.php)
        ├── includes/auth.php          session + login/logout
        ├── includes/csrf.php          token issue + verify
        ├── includes/permissions.php   RBAC matrix (DFMS_PERMISSIONS)
        ├── includes/tenant.php        WHO is asking, WHICH farm  ← authority
        ├── includes/repository.php    tenant-scoped data access ({scope})
        ├── includes/functions.php     safeError(), flash messages
        ├── includes/insights.php      deterministic "Farm Advisor" analytics
        └── includes/header.php / footer.php / components.php / navigation.php
```

**The load-bearing idea** is that tenant scope is never a page's responsibility.
`tenant.php` resolves the active farm from live database membership on every
request, and `repository.php` makes the tenant-safe query the *shortest* query to
write — `{scope}` expands to the farm filter so a developer cannot forget it by
being lazy. This is a genuinely good decision and it should survive every future
phase intact.

**Layering is by convention, not enforcement.** Nothing prevents a page from
opening its own PDO handle and writing raw SQL, and several do (see D-3).

### Not present

| Expected in a platform | Status |
|---|---|
| Dependency manager (`composer.json`) | absent |
| Build system / asset pipeline | absent |
| Test framework (PHPUnit) | absent — one bash+curl script only |
| Static analysis (PHPStan/Psalm) | absent |
| Linter config (PHP_CodeSniffer, CS Fixer) | absent |
| CI pipeline (`.github/`, `.gitlab-ci.yml`) | absent |
| Containerization (`Dockerfile`, compose) | absent |
| HTTP API / JSON endpoints | absent |
| Autoloading (PSR-4) | absent — manual `require_once` |
| Internationalization | absent |

---

## 2. Current modules

Six modules exist under `modules/`. Navigation advertises four more as `soon`.

| Module | Files | Actions | State |
|---|---|---|---|
| **cows** (animals) | 5 | list, add, edit, view, soft-delete | Functional. Richest module. |
| **milk** (production) | 3 | list, add, edit | Functional. No delete. |
| **health** | 2 | list, add | **Read + create only.** No edit, no delete, no per-event detail. |
| **feeding** | 2 | list, add | **Skeleton.** Schedules only; `feed_consumption` table exists but no UI writes it. |
| **inventory** | 3 | list, add, update stock | Functional. Stock adjustment is transactional. |
| **alerts** | 4 | list, create, edit, delete | Functional but cannot be marked read (C-2). |
| *reproduction* | — | — | Nav placeholder, `status: soon`. No code, no table. |
| *reports* | — | — | Nav placeholder, `status: soon`. No code. |
| *AI Assistant* | — | — | Nav placeholder, `status: soon`. Backend exists but is unreachable (D-1). |
| *settings* | — | — | Nav placeholder, `status: soon`. No code. |
| **finance** | — | — | **Does not exist in any form** — not in the IA, not in the schema. |

`modules/cows/view.php` is the only cross-module composition in the app: it joins
an animal to its health history.

---

## 3. Database architecture

**MariaDB 10.4.32** (verified live). 17 tables. Schema is `database.sql` plus three
sequential migration files.

### Tenant hierarchy

```
Platform
└── organizations        customer / billing boundary (plan, status, settings JSON)
    └── farms            belongs to exactly ONE org; farm_type + farm_subtype
        └── locations    barn / house / pond / field / greenhouse / paddock
            └── cows     (the first vertical's entity)
                └── operational data
```

The decision to give business rows **`farm_id` only**, resolving organization by
joining up through `farms`, is correct and well argued in `ARCHITECTURE.md`. One
tenant key per row cannot drift out of sync with a second.

### Membership

| Table | Purpose |
|---|---|
| `admin_users` | Credentials + `is_platform_admin`. `role` column is legacy. |
| `organization_users` | User ↔ org, roles `owner` / `admin` / `member`. **No code reads this.** |
| `farm_users` | User ↔ farm, roles `farm_manager` / `veterinarian` / `staff` / `accountant` / `viewer`. **Authoritative.** |

### Migration history

| File | Adds |
|---|---|
| `database.sql` | Baseline single-tenant schema + demo rows |
| `002_tenancy_and_extensions.sql` | `farms`, `farm_id` on 10 tables, cow status dimensions, `audit_log` |
| `003_organizations_and_membership.sql` | `organizations`, membership tables, `ai_usage` |
| `004_multivertical_foundation.sql` | `farm_type`, `locations`, generic `(entity_type, entity_id)` on core tables |

There is **no migration runner and no schema version table.** Applying migrations is
a documented manual sequence of four `mysql <` commands. Nothing records what has
been applied.

### Verified schema state (live query, this session)

- **11 tables** carry `farm_id ... DEFAULT 1` — `admin_users`, `alerts`, `audit_log`,
  `cows`, `feeding_schedules`, `feed_consumption`, `health_events`,
  `inventory_items`, `inventory_levels`, `milk_production`, `temperature_readings`
- **9 business tables have no foreign key on `farm_id`** — only `cows` and
  `locations` are constrained
- `cows.unique_id` carries a **single-column global UNIQUE index** (confirmed via
  `SHOW INDEX FROM cows`)
- 12 foreign keys exist in total; two (`health_events_ibfk_1`, `inventory_levels_ibfk_1`)
  are unnamed implicit constraints from the baseline file

---

## 4. Security assessment

**No SQL injection was found.** Every query in the codebase uses prepared statements
with bound parameters. No string interpolation of user input into SQL exists
anywhere. `tenantQuery()`/`tenantExec()` interpolate only the literal `{scope}`
token and a hardcoded table alias.

**No XSS was found in the shell or components.** `e()` (`htmlspecialchars` with
`ENT_QUOTES`, UTF-8) is applied to every interpolated value in `header.php`,
`footer.php` and `components.php`, including inside attributes. Module pages use
`htmlspecialchars()` directly and consistently.

**CSRF coverage is complete.** All 11 POST handlers call `csrf_verify()` before
touching the database. Tokens use `random_bytes(32)` and `hash_equals()`.

**Session handling is mostly correct** — `session_regenerate_id(true)` on login
closes fixation, and stale `farm_id`/`role` are cleared at the same moment.

### What is missing rather than broken

| ID | Issue | Severity |
|---|---|---|
| S-1 | `BASE_URL` built from unvalidated `HTTP_HOST`; feeds 6 redirects | High |
| S-6 | `display_errors=1`; fatals leak absolute paths + stack traces to browser | High |
| S-2 | PDO uses emulated prepares, DSN has no `charset=utf8mb4` | Medium |
| S-3 | Session cookie has no `HttpOnly` / `SameSite` / `Secure` | Medium |
| S-4 | Logout clears cookie at hardcoded path `/` | Low |
| S-5 | Two third-party CDNs execute on every authed page, no SRI, no version pin | Low |

**Password storage** uses `password_hash(..., PASSWORD_BCRYPT)` — correct. There is
no password change flow, no reset flow, no rate limiting on login, no account
lockout, and no MFA. Demo credentials are documented in plain text in
`ARCHITECTURE.md` and `seed_admin.php`.

---

## 5. Tenant isolation assessment

**This is the strongest part of the codebase, and the automated suite passes 14/14.**

The design is right in the ways that matter:

- Allowed farms are resolved **from the database every request**, never trusted from
  the session, and cached only for that request — revoking access takes effect
  immediately rather than lingering until session expiry.
- `currentFarmId()` re-validates any session-stored farm against live membership.
- `tenantFind()` returns `null` for *both* "does not exist" and "belongs to another
  tenant", and callers 404. A 403 would confirm existence and permit enumeration;
  this correctly does not.
- **IDOR through POST bodies is handled explicitly** — `health/add.php` re-validates
  a submitted `cow_id` against the active farm before writing. This is the case most
  applications miss, because the id came from a form the app itself rendered.
- `switchFarm()` and `assertFarmAccess()` exist as hard gates for farm ids arriving
  from anywhere else.

**The weakness is beneath the code, in the schema.** T-1, T-2 and T-3 mean the
database itself does not enforce what the PHP layer carefully does:

- a globally-unique cow tag lets one tenant's insert fail in a way that *confirms*
  another tenant's record exists — the exact enumeration channel `tenantFind()`
  was built to close, reintroduced at the storage layer;
- `DEFAULT 1` converts "forgot the tenant key" from a loud error into a silent
  write into farm 1;
- absent foreign keys allow rows referencing farms that do not exist.

**Assessment: application layer strong, storage layer unfinished.** Every finding is
in the second category, and all are fixable in one migration.

---

## 6. RBAC assessment

Roles live on **membership rows**, not on the user — `farm_users.role` is
authoritative. The same person can be a veterinarian at one farm and a viewer at
another. There is deliberately no global `currentRole()`, and `auth.php` carries a
comment saying so. This is the correct model.

The matrix is a single flat constant, `DFMS_PERMISSIONS` in `permissions.php`:
**18 permissions × 5 roles**, covering `cows`, `health`, `feeding`, `milk`,
`inventory`, `alerts`, `audit`.

Enforcement is two-tier and used correctly:
- `hasPermission()` — non-exiting, for conditionally rendering UI
- `requirePermission()` — hard gate, called at the top of every action

Navigation is filtered server-side by the same `hasPermission()` the endpoints
enforce, so a hidden nav item is never merely cosmetic. Verified by test: a
veterinarian gets 403 on `cows/add.php`, staff gets 403 on `health/add.php`.

### Gaps

- `organization_users` and its `owner`/`admin`/`member` roles are **never read by any
  code**. Org-level authorization does not exist.
- Platform admin is documented in three places as operating above the tenant layer,
  but `allowedFarmIds()` has no bypass — the privilege does not exist (T-6).
- `audit.view` is defined and granted to farm managers, but there is nothing to view
  (D-1).
- Permissions are compile-time constants. No per-organization customization, no
  custom roles, no delegation — all of which an international multi-customer
  platform will eventually need.

---

## 7. AI architecture assessment

**`includes/ai_service.php` is well designed and completely unreachable.** No file in
the repository includes it. It has zero call sites.

The design is sound. `aiComplete()` enforces an ordering that matters:

1. resolve organization / farm / user context
2. check the plan allowance **before spending anything**
3. record the call in `ai_usage` with tenant, feature, model and token counts

`DFMS_PLAN_AI_LIMITS` defines monthly allowances per plan (`starter` 100,
`professional` 1000, `enterprise` unlimited). Failures return
`['ok' => false, 'reason' => ...]` and the contract explicitly requires callers to
render a degraded state — *"an unavailable model must never look like a confident
answer."* That is the right instinct.

**Current AI reality:**

| Claim | Reality |
|---|---|
| "AI Farm Advisor" on the dashboard | **Deterministic PHP**, not AI. `insights.php` computes real trends over real data. Honest and working. |
| "AI Assistant" nav item | Placeholder, `status: soon`. No page. |
| `aiComplete()` model calls | Code complete, zero callers, no API key configured |
| `ai_usage` ledger | Table exists, indexed, FK'd — always empty |
| Vision / disease detection | Does not exist |

**Important nuance for the roadmap:** the dashboard's "Farm Advisor" is *not* a fake
AI placeholder. It is a real deterministic analytics layer that computes milk-trend
deltas, flags sick animals by name, and surfaces low stock — with severity,
reasoning and a recommended action for each insight. It is exactly the right
substrate for a genuine AI layer to narrate later, and it should be kept, not
replaced.

`DFMS_AI_MODEL` is pinned to `claude-sonnet-5`.

---

## 8. Current feature completeness

| Capability | State | Notes |
|---|---|---|
| Authentication | **Working** | No reset / change-password / MFA / rate limiting |
| Authorization (farm-level) | **Working** | Org-level unimplemented |
| Tenant isolation | **Working** | Schema enforcement missing |
| Animal management | **Working** | Add/edit/view/archive. No photo upload, no location assignment, no breeding record |
| Health management | **Partial** | Create + list only. No edit, no delete, no treatment plan, no vaccination schedule |
| Milk production | **Partial** | Farm-level daily totals only — **no per-animal yield** |
| Feeding | **Skeleton** | Schedules only. `feed_consumption` table never written by the UI |
| Inventory | **Working** | Transactional stock adjustment with `FOR UPDATE` |
| Alerts | **Broken loop** | Can be created but never marked read (C-2) |
| Dashboard | **Working** | Real analytics, real chart, real attention list |
| Audit log | **Not built** | Table, index, permission and writer all exist; zero callers |
| File uploads | **Not built** | `storage.php` is complete and unreachable; no upload UI exists |
| AI features | **Not built** | Service complete and unreachable |
| Finance | **Not built** | No table, no module, no nav entry |
| Reporting | **Not built** | Nav placeholder only |
| Reproduction | **Not built** | Nav placeholder only. `reproductive_status` column exists on `cows` |
| IoT / sensors | **Stub data only** | `temperature_readings` seeded but never read or written by the app |
| HTTP API | **Not built** | No JSON endpoint anywhere |
| Internationalization | **Not built** | All strings hardcoded English; no locale, currency or timezone handling |
| Pagination | **Not built** | `dfms_pagination()` exists, zero callers |
| Multi-farm switching | **Not reachable** | `switchFarm()` exists, zero callers |

**Mock / demo data:** `database.sql` inserts 6 cows, 4 health events, 4 feeding
schedules, 5 inventory items and 4 alerts inline. `seed_demo_data.php` regenerates
30 days of milk and feed, 7 days of temperature readings, and a deliberate
cross-tenant fixture (farm 2, `RIVAL001`). The seeders are clearly labelled and
CLI-guarded (`PHP_SAPI !== 'cli'` → 403), which is correct.

---

## 9. Test status

**One test suite exists:** `tests/tenant_isolation_test.sh` — bash + curl, no
framework.

```
== Tenant isolation: farm 1 user vs farm 2 data (cow_id=7) ==
  pass  view.php?id=7 rejected (HTTP 404)
  pass  view.php body
  pass  edit.php?id=7 rejected (HTTP 404)
  pass  delete.php?id=7 rejected (HTTP 404)
  pass  cow list excludes other farm
== IDOR via POST body ==
  pass  rejected foreign cow_id in POST
== RBAC: veterinarian ==
  pass  vet blocked from cows/add.php (HTTP 403)
  pass  vet blocked from cows/delete.php (HTTP 403)
  pass  vet allowed health/add.php (HTTP 200)
== RBAC: staff ==
  pass  staff blocked from cows/delete.php (HTTP 403)
  pass  staff blocked from health/add.php (HTTP 403)
  pass  staff allowed cows/add.php (HTTP 200)
== Unauthenticated ==
  pass  anonymous dashboard redirected (HTTP 302)
  pass  anonymous cow list redirected (HTTP 302)

 passed: 14   failed: 0
```

**Test count: 14 assertions, 14 passing, 0 failing.**

### Coverage reality

| Area | Covered |
|---|---|
| Cross-tenant read via URL id | Yes |
| Cross-tenant write via POST FK | Yes (health only) |
| List-page leakage | Yes (cows only) |
| RBAC per role | Partial — 2 of 5 roles |
| Anonymous access | Yes (2 pages) |
| **Business logic** | **No** |
| **Validation** | **No** |
| **Transactions** | **No** |
| **Milk / inventory / alerts / feeding modules** | **No** |
| **Unit tests of any kind** | **No** |

There is no unit test, no integration test beyond HTTP, no fixture isolation
(the suite runs against the live seeded database), and no coverage measurement.
The suite also depends on the rival cow landing at autoincrement id 7 (F-4) —
verified true on a clean import this session, but silently fragile.

---

## 10. Build status

**There is no build.** No `composer.json`, no `package.json`, no bundler, no
compile step, no artifact. Deployment is a file copy plus four manual SQL imports.

Baseline verification performed instead:

| Check | Result |
|---|---|
| `php -l` across all 39 PHP files | **39 clean, 0 syntax errors** |
| PHP 8.2 deprecation scan (`${}` interpolation, dynamic properties) | **None applicable** — procedural, no classes |
| Database connectivity | **OK** — MariaDB 10.4.32, 17 tables |
| Schema import (baseline + 3 migrations) | **Clean, no errors** |
| Seeders (`seed_admin`, `seed_demo_data`) | **Clean** — 5 accounts, 6 cows, 30 days of series data |
| Dev server boot (`php -S`) | **OK** on :8000 |
| Authenticated smoke test | **OK** — login 302 → dashboard 200, 45,845 bytes |
| Static analysis | **Not available** — no tooling installed or configured |
| Linting | **Not available** — no linter configured |

### Known environment warnings

- Migrations use `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` and
  `CREATE INDEX IF NOT EXISTS`, which are **MariaDB extensions**. They applied
  cleanly here only because XAMPP ships MariaDB; **MySQL 8 rejects both as syntax
  errors** (D-7).
- `display_errors=1` in the stock XAMPP `php.ini`. No production INI is provided
  (S-6).
- The PHP built-in server is single-threaded and unsuitable for anything but
  development.

---

## 11. Critical issues

### T-1 · Cow tag numbers are unique across every farm, not per farm

`database.sql:26` — `unique_id VARCHAR(50) NOT NULL UNIQUE`. Confirmed live: a
single-column unique index on `cows.unique_id`. No migration corrects it.

Once Farm A registers tag `BD001`, Farm B can never register that tag — and the
failure surfaces as a duplicate-key error, which **confirms the existence of
another customer's record**. That is precisely the enumeration channel
`tenantFind()` was designed to close, reintroduced beneath it. It also makes the
multi-tenant premise unworkable in practice, because ear-tag numbering restarts at
every real farm.

**Fix:** drop the global unique index, add `UNIQUE KEY uq_cow_tag (farm_id, unique_id)`.
Then correct `seed_demo_data.php`, which matches cows on `WHERE unique_id = ?`
alone and only works today because of this bug.

---

## 12. High-priority issues

### T-2 · `farm_id` defaults to 1 on eleven tables
`002:30–39`. Any insert that omits the tenant key silently lands in farm 1 instead
of failing. This inverts the entire premise of `repository.php`. **Fix:** drop the
default on all eleven.

### T-3 · Nine business tables have no foreign key on `farm_id`
Only `cows` and `locations` are constrained. `alerts`, `audit_log`,
`feeding_schedules`, `feed_consumption`, `health_events`, `inventory_items`,
`inventory_levels`, `milk_production` and `temperature_readings` can all reference
a nonexistent farm. `ARCHITECTURE.md` makes this step 1 of adding a business table
— the rule is right, the existing tables never got it.

### S-1 · `BASE_URL` is built from an unvalidated `Host` header
`config/database.php:42`. The attacker controls that header; `BASE_URL` feeds
`header('Location: ...')` in six places plus every asset URL. On a server with a
permissive default vhost this yields off-site redirects and poisonable absolute
URLs. **Fix:** allowlist the host, or set `APP_URL` in `.env` and prefer it.

### S-6 · Fatal errors leak absolute filesystem paths to the browser
`display_errors=1` in the stock INI, and the app ships no production INI or
`ini_set` guard. **Reproduced this session** — see C-1, whose output exposed the
full server path `D:\Projects\...\includes\insights.php:27` to an unauthenticated-
adjacent user. **Fix:** ship a production `php.ini` fragment with
`display_errors=Off`, `log_errors=On`, and register a global exception handler that
renders a safe error page.

### C-1 · A user with no farm membership crashes the dashboard — **REPRODUCED**
`dashboard.php:53` → `insights.php:27`. `getFarmAdvisorInsights(PDO $conn, int $farmId)`
declares a non-nullable `int`; `currentFarmId()` returns `null` whenever
`farm_users` has no row — the state of every account between creation and farm
assignment. Only `PDOException` is caught, so `TypeError` escapes.

Verified by creating a membership-less account and requesting the dashboard:

```
TypeError: getFarmAdvisorInsights(): Argument #2 ($farmId) must be of type int,
null given, called in ...\dashboard.php on line 53 and defined in
...\includes\insights.php:27
```

HTTP 200, 644 bytes, no page. Probe account removed after the test.
**Fix:** guard for `null` at the top of `dashboard.php` and render a "no farm
assigned" state; widen the catch to `Throwable`.

### C-2 · Alerts can never be marked read
`alerts.is_read` is queried in `header.php:37` and `insights.php:158` and **written
by no code path**. There is no mark-read control anywhere. The bell badge only ever
climbs, and the dashboard attention list permanently repeats handled items —
training users to ignore both. **Fix:** a POST endpoint setting `is_read = 1` via
`tenantExec()`, plus per-row and "mark all" controls.

### D-1 · Three documented subsystems have zero call sites
`includes/audit.php`, `includes/ai_service.php`, `includes/storage.php` are included
by nothing. `ARCHITECTURE.md` gives each a section describing behaviour that never
runs. The audit log is sharpest: table, index, `DFMS_TENANT_TABLES` entry and an
`audit.view` permission restricted to farm managers — and **not one row is ever
written**. Nothing records who archived an animal or edited a production figure.
**Fix:** wire `auditLog()` into the mutating handlers and build the reader, or move
all three to `planned/` and mark them unbuilt. A documented feature that silently
does nothing is the worst of the three options.

---

## 13. Medium-priority issues

| ID | Issue | Location |
|---|---|---|
| **T-4** | Indexes don't lead with `farm_id` though every read filters on it; five tables have no `farm_id` index at all | `002:86–91` |
| **T-5** | One-milk-record-per-day enforced in PHP only — TOCTOU race, no unique key; `dashboard.php:37` then reads one arbitrary row as the daily total | `milk/add.php:26`, `milk/edit.php:37` |
| **T-6** | Platform admin documented in 3 places as bypassing tenancy; `allowedFarmIds()` has no bypass, so the privilege doesn't exist | `tenant.php:31` |
| **S-2** | PDO uses emulated prepares; DSN omits `charset=utf8mb4` (will also mangle non-Latin names — relevant to internationalization) | `config/database.php:64` |
| **S-3** | Session cookie has no `HttpOnly` / `SameSite` / `Secure` | `auth.php:2` |
| **C-3** | Every stock save ≤ threshold inserts another duplicate low-stock alert, no dedupe | `inventory/update.php:81` |
| **C-4** | "30-Day Production Trend" query has no date bound — plots all history, serialized into the page | `dashboard.php:49` |
| **C-5** | `rollBack()` called without `inTransaction()` guard; throws inside the handler → uncaught fatal | `inventory/add.php:42`, `update.php:96,100` |
| **C-6** | Validation rules differ per page for the same field: blank `date_of_birth` written as `''`; `milk/add` accepts any string where `milk/edit` checks `is_numeric`; `severity` never checked against its ENUM | multiple |
| **D-2** | `switchFarm()` has no caller — a user in two farms is permanently pinned to the lowest farm id | `tenant.php:102` |
| **D-3** | `ARCHITECTURE.md` rule 2 ("never type `WHERE farm_id =` by hand") violated **26 times** — 9 in `dashboard.php`, 6 in `insights.php`. All correctly scoped, so nothing leaks; the risk is teaching the next contributor the rule is optional | repo-wide |
| **F-1** | Two complete UI systems ship side by side. The DFM design system is called by `dashboard.php` **only**; all 19 module pages still write raw Tailwind inline | `components.php` vs `modules/` |
| **F-2** | Tailwind **Play CDN** serving production pages — compiles CSS in-browser on every load | `header.php:92` |
| **I-1** | **No internationalization of any kind** — all strings hardcoded English, `date()` with hardcoded formats, no locale, no currency, no timezone handling, no `utf8mb4`. Blocking for the stated international goal | repo-wide |

---

## 14. Low-priority issues

| ID | Issue |
|---|---|
| **S-4** | Logout clears session cookie at hardcoded path `/`, ignoring actual cookie params |
| **S-5** | Tailwind + Chart.js loaded from CDNs with no SRI and no version pin (`chart.js` resolves to latest) |
| **C-7** | Any `adjustment_type` other than `set`/`add` silently **subtracts** stock |
| **C-8** | `cows/edit.php` doesn't re-validate `unique_id`; a crafted POST can blank an animal's only identifier |
| **C-9** | Low-stock threshold `10` hardcoded in three places; also unit-blind (10 bottles ≠ 10 kg) |
| **C-10** | `header.php` reassigns `$conn`/`$stmt`/`$row` into the including page's scope; `getDBConnection()` opens a new connection per call (~6 per dashboard request) |
| **D-4** | `url()` helper has no callers and emits a double slash (`BASE_URL` already ends in `/`) |
| **D-5** | `main.js` double-submit guard binds to `form[data-loading]`; no form has that attribute, so it never runs |
| **D-6** | Migration 002 header says "safe to re-run"; its last two statements are unguarded `ADD CONSTRAINT` and fail on a second run |
| **D-7** | Migrations are MariaDB-only (`IF NOT EXISTS` extensions); MySQL 8 rejects them. Setup docs say only `mysql -u root` |
| **F-3** | No list page paginates. `dfms_pagination()` exists with correct window arithmetic and zero callers |
| **F-4** | Test suite hardcodes the cross-tenant fixture as `id=7` — an autoincrement value |

---

## 15. Recommended implementation order

Sequenced by dependency, not severity alone — schema changes should land before the
code that relies on them.

1. **Schema integrity migration (005).** Per-farm unique cow tags (T-1), drop
   `DEFAULT 1` (T-2), add nine missing FKs (T-3), add
   `UNIQUE (farm_id, production_date)` on milk (T-5), re-lead indexes on `farm_id`
   (T-4). One migration, one review. Everything after is safer.
2. **Stop the crashes.** Null-farm dashboard fatal (C-1) and unguarded `rollBack()`
   (C-5) — both turn handled conditions into fatal error pages.
3. **Error and connection hardening.** Production INI with `display_errors=Off` plus
   a global exception handler (S-6); host allowlist (S-1); `utf8mb4` + real prepares
   (S-2); session cookie flags (S-3). Small, contained, and S-1/S-6 are the only
   findings with an outside-attacker path.
4. **Close the alerts loop.** Mark-as-read (C-2) and low-stock dedupe (C-3).
5. **Resolve the three unreachable subsystems** (D-1). Wiring `auditLog()` into the
   mutating handlers should come first — audit trail is a prerequisite for the
   compliance work in a later phase, and it is cheap now.
6. **Reconcile documentation with code** — platform-admin claim (T-6), repository
   rule (D-3), migration re-run claim (D-6), MariaDB dependency (D-7).
7. **Centralize validation** (C-6), then the individual input bugs (C-7, C-8).
8. **Introduce tooling before feature work.** Composer + PSR-4 autoloading, PHPUnit,
   PHPStan, a linter, and a migration runner with a schema-version table. Every
   subsequent phase compounds without this.
9. **Finish the design-system migration** (F-1, F-2) and add pagination as each list
   page converts (F-3).
10. **Then, and only then, begin Phase 2** of the roadmap.

Items 1–4 are the minimum before any new feature work. Item 8 is the minimum before
*sustained* feature work.

---

## Appendix A · Files inspected

**Entry points (3):** `index.php`, `dashboard.php`, `logout.php`

**Includes (12):** `auth.php`, `csrf.php`, `functions.php`, `permissions.php`,
`tenant.php`, `repository.php`, `audit.php`, `ai_service.php`, `storage.php`,
`insights.php`, `header.php`, `footer.php`, `components.php`, `navigation.php`

**Config (1):** `config/database.php`

**Modules (19):** `cows/{index,add,edit,view,delete}.php`,
`milk/{index,add,edit}.php`, `health/{index,add}.php`,
`feeding/{index,add}.php`, `inventory/{index,add,update}.php`,
`alerts/{index,create,edit,delete}.php`

**Database (5):** `database.sql`,
`migrations/002_tenancy_and_extensions.sql`,
`migrations/003_organizations_and_membership.sql`,
`migrations/004_multivertical_foundation.sql`,
`seed_admin.php`, `seed_demo_data.php`

**Frontend (2):** `assets/js/main.js` (314 lines), `assets/css/style.css` (1,988 lines)

**Tests (1):** `tests/tenant_isolation_test.sh`

**Docs / config (6):** `ARCHITECTURE.md`, `project_structure.md`,
`project_proposal.txt`, `.env.example`, `.gitignore`, `storage/.htaccess`,
`storage/.gitignore`

**Total: 7,572 lines of application source across 24 PHP, 4 SQL, 1 JS, 1 CSS file.**

---

## Appendix B · Commands executed

```bash
# Discovery
find . -type f -not -path "./vendor/*"
wc -l <all source files>
grep -rn "auditLog|ai_service|storage.php|switchFarm|farm_id = ?" --include=*.php .

# Environment provisioning (no PHP/MySQL existed on this machine)
winget search --id ApacheFriends.Xampp
winget install --id ApacheFriends.Xampp.8.2 --exact --silent

# Database baseline
mysqld --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
mysql -u root -e "SHOW DATABASES;"                      # confirmed no dfms before destructive import
mysql -u root      -e "source database.sql"
mysql -u root dfms -e "source database/migrations/002_tenancy_and_extensions.sql"
mysql -u root dfms -e "source database/migrations/003_organizations_and_membership.sql"
mysql -u root dfms -e "source database/migrations/004_multivertical_foundation.sql"
php database/seed_admin.php
php database/seed_demo_data.php

# Schema verification (read-only)
mysql -u root dfms -e "SHOW TABLES;"
mysql -u root dfms -e "SELECT ... FROM information_schema.KEY_COLUMN_USAGE ..."
mysql -u root dfms -e "SHOW INDEX FROM cows WHERE Non_unique=0;"

# Static verification
php -l <each of 39 PHP files>
php -r 'echo ini_get("display_errors");'

# Runtime baseline
php -S localhost:8000 -t .
curl -c jar http://localhost:8000/index.php          # CSRF token extraction
curl -b jar -X POST -d "..." http://localhost:8000/index.php
curl -b jar http://localhost:8000/dashboard.php

# Test suite
bash tests/tenant_isolation_test.sh
```

**Destructive command encountered:** `database.sql` opens with
`DROP DATABASE IF EXISTS dfms`. Before running it, `SHOW DATABASES` was executed and
confirmed no `dfms` database existed, so the statement was a verified no-op on a
fresh XAMPP install. No existing data was at risk.

---

## Appendix C · Tests executed

| Suite | Assertions | Passed | Failed |
|---|---|---|---|
| `tests/tenant_isolation_test.sh` | 14 | 14 | 0 |
| `php -l` syntax check | 39 files | 39 | 0 |
| Manual: login → dashboard smoke | 3 | 3 | 0 |
| Manual: C-1 null-farm reproduction | 1 | 1 (defect confirmed) | — |

---

## Appendix D · Current status

- **Application:** running at `http://localhost:8000`, fully functional for seeded
  accounts
- **Database:** MariaDB 10.4.32, `dfms`, 17 tables, seeded with demo + cross-tenant
  fixture
- **Tests:** 14/14 passing
- **Syntax:** 39/39 files clean
- **Build:** N/A — no build system exists
- **Application code modified this phase:** **none**

---

## Appendix E · Blockers

| # | Blocker | Impact |
|---|---|---|
| 1 | **No dependency manager or autoloader.** Composer must be introduced before any library (i18n, PDF, queue, HTTP client, test framework) can be added. | Blocks Phases 2+ |
| 2 | **No test framework.** 14 HTTP assertions cannot support safe refactoring at platform scale. PHPUnit + fixture isolation is a prerequisite for the schema migration in item 1 of the implementation order. | Blocks safe refactoring |
| 3 | **No migration runner or schema-version table.** Four manual `mysql <` commands with no record of what has been applied. Cannot safely evolve a schema across environments. | Blocks Phase 1 |
| 4 | **Migrations are MariaDB-only.** Rules out MySQL 8 and most managed hosting until rewritten with `information_schema` guards. | Blocks deployment choice |
| 5 | **No HTTP API layer.** Mobile clients, IoT ingestion and third-party integration all require one; none exists. | Blocks Phases 7, 11 |
| 6 | **No CI.** Nothing runs the isolation suite automatically; a tenancy regression would reach `master` unnoticed. | Blocks Phase 13 |
| 7 | **Environment is developer-local and ephemeral.** MariaDB runs as a detached process, not a service; the PHP built-in server is single-threaded. Both stop when the session or machine ends. | Blocks Phase 14 |

**No blocker prevents Phase 1 from starting.** Items 1–3 are themselves Phase 1 work.
