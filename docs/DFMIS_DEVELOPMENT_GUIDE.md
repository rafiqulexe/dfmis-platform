# DFMIS Development Guide

Everything needed to run, test and contribute to DFMIS locally.

**No secrets appear in this document.** Where a credential is required, the
variable name is given and the value is left to your own `.env`.

---

## Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| **PHP** | 8.2 minimum, **8.5 recommended** (developed on 8.5.12) | Extensions: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `json`, `openssl`. `zip` is needed by Composer. Do **not** enable `intl` — see [The intl extension](#the-intl-extension). |
| **MariaDB** | **10.11 LTS** (developed on 10.11.19) | **MariaDB, not MySQL** — migrations 002–004 are raw SQL written against it. Matches the `mariadb:10.11` image CI uses. See [Database engine](#database-engine). |
| **Composer** | 2.x | Dev tooling only; the application has no runtime dependencies. |
| **bash + curl** | any | The integration suites are shell scripts. Git Bash is fine on Windows. |

XAMPP supplies MariaDB and is the reference local setup for the database.

**PHP is installed separately from XAMPP and deliberately so.** XAMPP bundles
whatever PHP it shipped with — 8.2.12, built October 2023 — and that version
leaves security support on 2026-12-31. The reference runtime is a standalone
**PHP 8.5.12** at `C:\php85`, used for the CLI, the built-in dev server and the
whole test harness.

The application never needs Apache: it is served by PHP's built-in server
(`php -S`). Apache is only there for phpMyAdmin and the XAMPP dashboard.

### Local stack, as it actually stands

| Component | Version | Where |
|---|---|---|
| PHP | 8.5.12 | `C:\php85` — one install, used by CLI, dev server, tests **and** Apache |
| MariaDB | 10.11.19 LTS | `C:\xampp\mysql` — supported to 2028-02-16 |
| Apache | 2.4.58 (Apache Lounge VS17) | `C:\xampp\apache` — repointed at `C:\php85` |
| phpMyAdmin | 5.2.3 | `C:\xampp\phpMyAdmin` |

There is deliberately **one** PHP install, not two. XAMPP's Apache was
repointed at `C:\php85` in `conf\extra\httpd-xampp.conf` (`LoadModule
php_module`, `PHPINIDir`, and the `/php-cgi/` `ScriptAlias`, which otherwise
kept the retired 8.2.12 binary reachable over HTTP). One PHP means one thing
to patch next time.

Retired, kept for rollback and safe to delete once you are confident:
`C:\xampp\mysql-10.4.32-retired` (including its original data directory),
`C:\xampp\phpMyAdmin-5.2.1-retired`, and XAMPP's now-unused `C:\xampp\php`.

> **`extension_dir` must be absolute.** It is `C:/php85/ext`, not `ext`. A
> relative value resolves against the *process* working directory, so it works
> for the CLI and silently fails under Apache — the symptom is phpMyAdmin
> reporting "the mysqli extension is missing".

> **curl does not load under Apache's `mod_php`.** `php_curl.dll` links
> against PHP's OpenSSL 3.5.9 while Apache 2.4.58 ships its own copy of
> `libssl-3-x64.dll` for `mod_ssl`, and Windows resolves DLLs by name for the
> whole process. Preloading PHP's copies with `LoadFile` stops Apache booting
> altogether. This costs nothing today — phpMyAdmin does not need curl, and the
> application runs on `php -S` where curl works normally. If DFMIS is ever
> served through Apache, use FastCGI (`php-cgi.exe` is its own process with its
> own OpenSSL), not `LoadFile`.

> **`register_argc_argv` must stay `Off`.** PHP 8.5 deprecates deriving
> `$_SERVER['argv']` from the query string, so turning it on prints a
> deprecation on *every* web request. The CLI populates `$argv` regardless of
> this setting, so the CLI tooling is unaffected.

> **Windows note:** PHP 8.5 builds are linked against the Visual C++ 2015–2022
> runtime 14.44 or newer. An older runtime fails hard — PHP refuses to start
> with `VCRUNTIME140.dll ... is not compatible with this PHP build`. Install
> the current [VC++ x64 redistributable](https://aka.ms/vs/17/release/vc_redist.x64.exe)
> first.

> **Composer note:** `zip` must be enabled or Composer is slow and warns on
> every command. It is already enabled in `C:\php85\php.ini`.

### The intl extension

`includes/i18n.php` branches on `extension_loaded('intl')`: with it, numbers
are formatted through `NumberFormatter`; without it, a built-in fallback runs.

The local reference environment does **not** load `intl`, so day-to-day
development exercises the fallback. CI's PHP build **does** include it, which
means both branches are now exercised on every push — and that is how a real
divergence was found.

> **The two branches used to round differently.** ICU defaults to half-even
> ("banker's rounding"), so `dfms_format_money(1234.5, 'JPY', 'en')` rendered
> `JPY 1,234` with `intl` loaded and `JPY 1,235` without it. A monetary figure
> depended on which extensions the server happened to have. `dfms_format_number()`
> now sets `NumberFormatter::ROUND_HALFUP` explicitly, matching what
> `number_format()` always did, and the two branches agree. Verified both ways:
> `php tests/international_unit_test.php` and
> `php -d extension=intl tests/international_unit_test.php` both report
> 185 passed.

Enabling `intl` locally is therefore no longer a formatting risk. It is still
not the reference configuration, so if you turn it on, run the international
suite both ways before trusting a result.

### Database engine

Migrations `002`–`004` use MariaDB-only syntax (`ADD COLUMN IF NOT EXISTS`,
`CREATE INDEX IF NOT EXISTS`), which MySQL 8 rejects. Migrations from `005`
onward are PHP and engine-agnostic via `database/schema_helpers.php`.

New migrations should be **PHP**, not `.sql`, so this constraint does not grow.
Converting the three legacy files is deferred work.

---

## First-time setup

```bash
# 1. Dependencies (dev tooling only)
composer install

# 2. Configuration — copy the template and fill in your own values
cp .env.example .env

# 3. Database
mysql -u root -e "CREATE DATABASE dfms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root dfms < database.sql
php database/migrate.php

# 4. Demo data (development only)
php database/seed_admin.php
php database/seed_demo_data.php

# 5. Isolated test database — required before PHPUnit will run
php tools/test-db.php --create

# 6. Run it
php -S localhost:8000 -t .
```

Then open <http://localhost:8000>.

Demo accounts are created by `database/seed_admin.php`; the usernames and their
passwords are printed by that script when it runs. **They are development
credentials and must never exist in a deployed environment.**

---

## Environment configuration

`.env` is gitignored and must never be committed. `.env.example` documents every
variable by name with placeholder values only.

| Variable | Required | Purpose |
|---|---|---|
| `APP_ENV` | recommended | `local`/`development`/`dev` shows errors in the browser. **Anything else — including unset — hides them.** Fails safe. |
| `APP_URL` | production | Explicit base URL. Removes host detection entirely. |
| `APP_ALLOWED_HOSTS` | production | Allow-list for reflecting the `Host` header when `APP_URL` is unset. An unlisted host is never reflected. |
| `APP_TRUST_PROXY` | if behind a proxy | `X-Forwarded-For` is honoured **only** when `1`, so a client cannot forge its own IP. |
| `DB_HOST` `DB_USER` `DB_PASS` `DB_NAME` | yes | Connection. `DB_CHARSET` defaults to `utf8mb4`. |
| `APP_DEFAULT_*` | no | Platform-level timezone / locale / currency / measurement defaults. |
| `DFMS_TEST_DB_NAME` | for testing | Defaults to `dfms_test`. Must end in `_test`. |
| `DFMS_BASE_URL` | for testing | Base URL the HTTP suites target. |
| `ANTHROPIC_API_KEY` | no | Phase 5. Blank runs AI features in their degraded state. |

### Production requirements

- `APP_ENV` **must not** be a development value — errors must never render.
- `APP_URL` set explicitly.
- A dedicated database user with only the privileges the app needs; **not root**.
- HTTPS, so the session cookie gets its `Secure` flag.
- `APP_TRUST_PROXY=1` **only** behind a proxy you control.

---

## Daily commands

| Task | Command |
|---|---|
| Start the app | `php -S localhost:8000 -t .` |
| Lint | `composer lint` — or `php tools/lint.php` |
| Static analysis | `composer analyse` |
| PHPUnit | `composer test:unit` |
| **Everything** | `composer test:legacy` — or `bash tests/run_all.sh` |
| No-server checks | `composer check` (lint + analyse + PHPUnit) |
| Migration status | `php database/migrate.php --status` |
| Apply migrations | `php database/migrate.php` |
| Test DB status | `php tools/test-db.php --status` |

On Windows, if PHP is not on `PATH`:

```bash
DFMS_PHP=C:/php85/php.exe bash tests/run_all.sh
```

---

## Testing

Eight suites, run by `bash tests/run_all.sh`:

| # | Suite | Needs | Assertions |
|---|---|---|---|
| 1 | `php -l` across every file | — | 78 files |
| 2 | `tests/validation_unit_test.php` | — | 104 |
| 3 | **PHPUnit** (`tests/phpunit/`) | `dfms_test` | 37 tests / 102 |
| 4 | `tests/international_unit_test.php` | database | 185 |
| 5 | `tests/tenant_isolation_test.sh` | server | 14 |
| 6 | `tests/security_regression_test.sh` | server | 62 |
| 7 | `tests/international_isolation_test.sh` | server | 47 |
| 8 | `tests/api_test.sh` | server | 61 |

**The integration suites fail rather than skip when no server is running.** A
green run can never mean "half of this did not execute". Start the server first.

### Test database safety

PHPUnit runs against **`dfms_test`**, never your development database.

`tests/bootstrap.php` approves a database for destructive use only on positive
evidence — a name ending in `_test`, or a `dfms_test_marker` table. **A missing
environment variable is treated as refusal, never as permission.**

```bash
php tools/test-db.php --create   # build it (schema + all migrations)
php tools/test-db.php --status   # is it there and marked?
php tools/test-db.php --drop     # refuses any non-test database
```

> **Known limitation.** Suites 4–8 still run against the **development**
> database, because they exercise HTTP against the running server, which reads
> `.env`. Suite 4 mutates settings and feature flags and restores them in a
> shutdown handler, but a hard crash could leave residue. Only PHPUnit is fully
> isolated today. Pointing the whole harness at `dfms_test` requires the server
> to run with a separate environment and is deferred work.

---

## Code quality

```bash
composer lint      # php -l, no dependencies required
composer analyse   # PHPStan level 5, zero findings, empty baseline
```

**PHPStan runs at level 5 with an empty baseline.** All 14 findings present when
analysis was introduced were fixed, not suppressed. Keep it that way: a new
entry in `phpstan-baseline.neon` should be a deliberate, reviewed deferral.

Analysis covers `includes/`, `config/`, `database/`, `tools/` and `api/` — the
shared layers every module depends on. Module pages mix HTML with logic and are
not analysed; see [`DFMIS_ENGINEERING_FOUNDATION.md`](./DFMIS_ENGINEERING_FOUNDATION.md).

There is **no auto-formatter**, deliberately. A repository-wide reformat would
bury real changes in noise.

---

## Continuous integration

`.github/workflows/ci.yml` runs on push and pull request: PHP setup → Composer
install → lint → PHPStan → MariaDB service → schema + migrations →
**idempotency check** → seed → create test DB → PHPUnit → **start a server** →
full suite.

CI **starts a web server explicitly** so the HTTP suites genuinely run.

> Nothing runs today — DFMIS is deliberately local-only and has no remote. The
> workflow is written to be correct when one is eventually added.

---

## Architecture orientation

DFMIS is **procedural PHP**. There is no framework, no MVC layer and no
application-wide autoloader, and Phase 2B did not introduce one.

```
index.php  dashboard.php  logout.php     entry points
modules/<domain>/<action>.php            one file per action
api/index.php + api/v1/*.php             the ONLY front-controlled area
includes/                                shared layers
config/database.php                      env, connection, constants
database/migrate.php + migrations/       schema
tests/                                   8 suites
tools/                                   lint, test-db, phpstan bootstrap
lang/                                    translation catalogues
```

### Rules for any new page

1. `requireAuth('permission')` — authentication, tenant context, farm and org membership, permission
2. `dfms_route_id()` for ids from the URL
3. `tenantFindOr404()` before touching a row
4. `csrf_verify()` on every POST
5. `DfmsValidator` for all input
6. `tenantTransaction()` for multi-step writes
7. `auditCreate` / `auditUpdate` / `auditDelete` for every mutation

Full detail in [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md).

### Adding an API endpoint

See [`DFMIS_API_ARCHITECTURE.md`](./DFMIS_API_ARCHITECTURE.md). In short: write
the handler in `api/v1/`, guard it with `DFMS_API_DISPATCHED`, and register it in
the route table in `api/index.php` — routes are an explicit allow-list, so an
unregistered file is not reachable.

---

## Further reading

| Document | Covers |
|---|---|
| [`DFMIS_BASELINE_AUDIT.md`](./DFMIS_BASELINE_AUDIT.md) | The original 34 findings; finding ids used throughout |
| [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md) | Tenancy, RBAC, validation, audit, errors |
| [`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](./DFMIS_INTERNATIONAL_ARCHITECTURE.md) | Config hierarchy, timezone, currency, units, locale |
| [`DFMIS_SAAS_FOUNDATION.md`](./DFMIS_SAAS_FOUNDATION.md) | Subscription, feature flags, notifications |
| [`DFMIS_API_ARCHITECTURE.md`](./DFMIS_API_ARCHITECTURE.md) | API contract and evolution |
| [`DFMIS_ENGINEERING_FOUNDATION.md`](./DFMIS_ENGINEERING_FOUNDATION.md) | Tooling strategy and constraints |
| [`DFMIS_ROADMAP.md`](./DFMIS_ROADMAP.md) | Phase status |
