# DFMIS Engineering Foundation

**Phase:** 2B — Platform Engineering Foundation
**Status:** Complete. 8 suites, 575 assertions, PHPStan level 5 with an empty baseline.

The tooling around DFMIS: dependencies, testing, static analysis, CI, and the
constraints all of it operates under.

---

## The governing principle

> **Incremental modernization without rewriting the existing procedural
> application.**

Every decision below follows from it. DFMIS is procedural PHP with no
framework, no MVC layer and no application-wide autoloader — and **Phase 2B did
not change that.** Composer was introduced for *dependency management and dev
tooling*, not as a step toward converting the application into classes.

What this rules out, deliberately:

- converting the application to Laravel, Symfony or any framework
- rewriting working files into classes for its own sake
- routing every legacy page through a new front controller
- a repository-wide reformat
- migrating ~400 working assertions into PHPUnit because PHPUnit now exists

---

## 1. Composer strategy

`composer.json` exists for three reasons: dependency management, dev tooling,
and a place for autoloading **if and when** it is genuinely needed.

### Autoloading is deliberately near-empty

```json
"autoload":     { "files": [] },
"autoload-dev": { "psr-4": { "DFMIS\\Tests\\": "tests/phpunit/" } }
```

The application still uses `require_once`. Only the PHPUnit test namespace is
PSR-4, because PHPUnit needs it. Converting `includes/` to namespaced classes
would touch every file in the project — a rewrite by another name.

**When a class-based module is genuinely warranted**, add a PSR-4 root for that
namespace and let it coexist. The two styles can live side by side indefinitely;
they already do.

### Dependency policy

| Rule | Rationale |
|---|---|
| **No runtime dependencies.** `require` contains `php` and nothing else. | The app must run from a file copy. Every runtime dependency is a supply-chain and deployment obligation. |
| **Dev dependencies must be justified in writing.** | Below. |
| **`composer.lock` is committed.** | This is an application, not a library: every environment must resolve identical versions. |
| **`vendor/` is not committed.** | Rebuilt from the lock file. |
| Adding a `require` entry **fails a test** until reviewed. | `EngineeringToolingTest::testProductionDependenciesRemainMinimal` |

### Dependencies introduced — and why

| Package | Version | Why |
|---|---|---|
| `phpunit/phpunit` | ^10.5 | A real assertion library, fixtures and lifecycle hooks for **new** tests. The bespoke harness has no setup/teardown and no isolation between cases. |
| `phpstan/phpstan` | ^2.2 | Catches, before runtime, the class of defect behind baseline finding **C-1** — a null reaching a non-nullable parameter and fataling in production. `php -l` cannot see that. |

Two direct dependencies, both dev-only. Nothing else was added.

### Why PHPStan moved from ^1.12 to ^2.2

1.12's last feature release predates PHP 8.5 by months, which is reason enough
not to analyse an 8.5 codebase with it. The upgrade also paid for itself
immediately: 2.3.2 found two things 1.12 did not — `AiToolGetRecentAlerts::run()`
and `AiToolGetFarmSummary::run()` both declaring the abstract's `?array` while
neither can return null — and those were fixed by narrowing the return types,
not baselined.

**A correction worth recording, because the first diagnosis was wrong.** Three
`Variable $argv might not be defined` findings (in `tools/lint.php`,
`tools/test-db.php` and `database/migrate.php`) were initially blamed on 1.12
being "wrong on a current runtime", and the PHPStan upgrade was credited with
fixing them. It did not. Re-tested with the result cache cleared between runs,
the finding depends **entirely on `register_argc_argv`** and not at all on the
analyser version:

| Runtime | `register_argc_argv` | PHPStan 2.3.2 |
|---|---|---|
| 8.5.12 | Off | 3 findings |
| 8.5.12 | On | clean |
| 8.2.12 | On | clean |

The earlier "clean" result was a stale cached one, read after the directive was
changed — an ini change does not invalidate PHPStan's result cache, so it
replayed an answer from before the change. Two lessons: clear the result cache
before trusting a comparison, and a result that confirms what you expect still
needs the same scrutiny as one that does not.

PHPStan was also **right**. `$argv` is only guaranteed when that directive is
on, and it has to stay off here because PHP 8.5 deprecates deriving argv from
the query string on every web request. The CLI SAPI populates `$argv` anyway,
so the scripts were never actually broken — but they were relying on something
the configuration did not promise. The three now read
`$_SERVER['argv'] ?? []`, which is correct under either setting and in either
SAPI, and the findings are gone on their merits rather than silenced.

---

## 2. Testing strategy

### Legacy tests are preserved, not migrated

The four pre-existing suites (~412 assertions) are **untouched and still run**.

Rewriting them into PHPUnit would be churn with no behavioural benefit and real
risk of losing coverage in translation. The brief warned against it; so does
judgment.

### PHPUnit's actual role

PHPUnit covers **new, pure unit tests** — 37 tests, 102 assertions:

| File | Covers |
|---|---|
| `HttpFoundationTest` | Request inspection, error-code mapping, correlation-id stability, credential redaction, response-mode safety |
| `ApiRouteTableTest` | Route-table invariants: nothing public unless approved, handlers exist and guard direct execution, declared permissions are real, everything is versioned |
| `TestEnvironmentSafetyTest` | The test-database guard, migration completeness, and that the runner strips `USE` statements |
| `EngineeringToolingTest` | composer/phpstan/CI/gitignore/`.env.example` configuration cannot silently regress |

This is **not fake adoption**: these tests assert things nothing else does, and
two of them are security controls in their own right.

### Deprecations are fatal

`phpunit.xml` sets `failOnWarning`, `failOnRisky`, `failOnNotice` **and
`failOnDeprecation`**, all true.

The last of those exists because of a concrete miss. A PHP 8.4 deprecation —
an implicitly nullable `string $name = null` in `ProductionDomainTest` — rode
along silently for the whole life of the 8.2 runtime. `php -l` does not see
deprecations, PHPStan is not pointed at `tests/`, and PHPUnit reported it as a
note while still printing `OK`. It only became visible when the runtime moved
to 8.5.12, and only because the upgrade run was read line by line.

A deprecation is the one warning a language gives you *before* it breaks your
code. Letting it print next to the word `OK` wastes that notice. Now the suite
fails on it, which means the next PHP release announces itself here rather than
in production. Verified clean on both 8.2.12 and 8.5.12 with the flag on.

### Why HTTP tests stayed in shell

This project **fails rather than skips** when no server is running. PHPUnit's
idiom is `markTestSkipped`, which produces exactly the silent-green failure the
project avoids. Rather than fight that, HTTP integration stays in shell scripts
where a missing server is a hard failure — and `tests/run_all.sh` exits non-zero.

### The unified command

```bash
bash tests/run_all.sh        # or: composer test:legacy
```

| # | Suite | Needs | Assertions |
|---|---|---|---|
| 1 | Lint (`php -l`) | — | 78 files |
| 2 | Validation unit | — | 104 |
| 3 | **PHPUnit** | `dfms_test` | 37 tests / 102 |
| 4 | International unit | database | 185 |
| 5 | Tenant isolation | server | 14 |
| 6 | Security regression | server | 62 |
| 7 | International isolation | server | 47 |
| 8 | **API foundation** | server | 61 |

**575 assertions.** Suites 5–8 fail, never skip, without a server.

---

## 3. Test environment safety

### The hazard

Before Phase 2B every suite ran against the development database `dfms`, and
`international_unit_test.php` issues `DELETE FROM feature_flags`. Survivable on a
laptop; unacceptable anywhere else.

### The rule

> A destructive test may run **only** against a database that has positively
> identified itself as a test database. Absence of configuration is **refusal**,
> never permission.

`tests/bootstrap.php` approves a database on two positive signals — a name ending
in `_test`, or a `dfms_test_marker` table. Neither can be satisfied by accident,
and a missing environment variable satisfies neither.

`tools/test-db.php` builds `dfms_test` by running the **real** baseline and
migrations, so it cannot drift from a hand-maintained dump. `--drop` refuses any
name not ending in `_test`, and there is no `--force`.

### A real hazard this uncovered

Migrations `002`–`004` open with `USE dfms;`. Running them against `dfms_test`
would have **silently switched the connection and modified the development
database** — the exact failure the guard is meant to prevent, hiding one layer
down.

`database/migrate.php` now strips embedded `USE` statements before executing raw
SQL: a migration applies to the connected database, full stop. Asserted by
`TestEnvironmentSafetyTest::testMigrationRunnerStripsEmbeddedUseStatements`.

### Honest limitation

**Only PHPUnit is fully isolated.** Suites 4–8 still use the development
database, because they drive HTTP against a server that reads `.env`. Suite 4
restores what it mutates in a shutdown handler, but a hard crash could leave
residue. Full isolation needs the server started with a separate environment —
deferred, and recorded in the roadmap rather than glossed over.

---

## 4. Static analysis strategy

### Level 5, chosen from measurement

| Level | Findings |
|---|---|
| 3 | 10 |
| **5** | **14** |
| 6 | 288 |
| 7 | 298 |

The jump at level 6 is **entirely missing-typehint reporting** (`no value type
specified in iterable type array`), not a new class of bug. Level 6 *is* level 5
plus typehints — so raising the level and then disabling its only added check
would be theatre.

Level 5 already covers everything that matters here: undefined variables,
invalid arguments, wrong return assumptions, impossible types, dead code and
null errors — including the class behind **C-1**.

### The baseline is empty, on purpose

All 14 findings were **fixed, not suppressed**:

| Finding | Fix |
|---|---|
| 10 × undefined variable in `footer.php` | Defaults added so the file is self-sufficient — the C-1 class of fault |
| Unreachable `match` arm in `seed_admin.php` | Replaced with a lookup that fails loudly on an unmapped role |
| Dead `=== ''` comparison in `auth.php` | Removed; `?: '/'` already guaranteed non-empty |
| Dead `??` on `samesite` in `auth.php` | Removed; always present on PHP 7.3+ |
| Dead `??` on `mime` in `storage.php` | Removed; always present when `getimagesize()` returns an array |

`phpstan-baseline.neon` therefore starts at zero. Any entry added later is a
**deliberate, reviewable deferral**, not an inherited backlog nobody reads.
`EngineeringToolingTest` fails if the level is lowered below 5 or the baseline
gains content.

### Scope

`includes/`, `config/`, `database/`, `tools/`, `api/` — the shared layers every
module depends on and where a type error does the most damage.

Module pages are **not** analysed. They interleave HTML with logic and would
produce a large baseline nobody reads — the failure mode this configuration
exists to avoid. Bringing them in incrementally, a module at a time, is
reasonable future work.

`tools/phpstan-bootstrap.php` declares the runtime constants (`BASE_URL`,
`APP_ENV`, `DB_*`) so their use is not reported as unknown, drowning real
findings. It is never loaded by the application.

`tests/` is **not** analysed either, and that gap has already cost something:
a PHP 8.4 deprecation (an implicitly nullable `string $name = null` in
`ProductionDomainTest`) sat unreported until the runtime moved to 8.5, because
neither `php -l` nor PHPStan's configured scope could see it. The guard added
in response is `failOnDeprecation="true"` in `phpunit.xml` — see
[Deprecations are fatal](#deprecations-are-fatal) — rather than widening the
analysed scope, which remains future work.

### The target PHP version is pinned

`phpVersion: 80512` in `phpstan.neon`. Analysis is pinned to the runtime the
project actually runs on, so PHPStan reasons about the right standard-library
signatures and the right set of deprecations. It is deliberately a separate
setting from the floor `composer.json` declares: analysis targets the newest
runtime, while the floor states the oldest one the suites still prove. Raise
both together.

---

## 5. Code quality workflow

```bash
composer lint      # php -l across every file — works with no vendor/ present
composer analyse   # PHPStan level 5
composer test:unit # PHPUnit
composer check     # all three; nothing requiring a server or database
composer test:legacy  # the full 8-suite run
```

`tools/lint.php` is PHP rather than a shell one-liner so it behaves identically
on Windows, macOS, Linux and CI, and uses `PHP_BINARY` so it lints with the same
interpreter the app runs on.

**No auto-formatter, deliberately.** A repository-wide reformat would bury real
changes in noise and produce a diff nobody can review.

---

## 6. CI pipeline

`.github/workflows/ci.yml` — push, PR and manual dispatch.

```
checkout → PHP 8.2 AND 8.5 (matrix) → cache vendor → composer install
        → lint → PHPStan
        → MariaDB service → schema → migrations → idempotency check
        → seed → create dfms_test
        → PHPUnit
        → START A SERVER (explicitly, with a readiness loop)
        → full 8-suite run
        → server log on failure → always stop the server
```

Three properties worth stating:

1. **The server is started explicitly.** The HTTP suites genuinely run; they do
   not quietly skip and leave the build green.
2. **Migration idempotency is verified.** A second `migrate.php` run must report
   "Nothing to apply". A non-idempotent migration breaks redeploys, and this
   catches it in CI rather than in production.
3. **MariaDB, not MySQL** — migrations 002–004 require it.

> **Nothing runs today.** DFMIS is deliberately local-only with no remote. The
> workflow is written to be correct when one is added, and its structure and
> completeness are asserted by `EngineeringToolingTest` so it cannot rot
> unnoticed in the meantime.

A defect found while writing this: `getDBConnection()` failed via
`exit("message")`, which exits with status **0** — a database failure would have
looked like success to CI. It now exits `1` on the command line.

---

## 7. Architectural constraints

Binding on all future work:

1. **No framework migration.** Not Laravel, not Symfony, not any other.
2. **No runtime dependencies** without explicit review; a test enforces it.
3. **Legacy pages stay directly reachable.** Only `/api/` is front-controlled.
4. **No parallel security logic.** Everything authenticates and authorizes
   through `requireAuth()`. The API proves this is workable.
5. **Fail, never silently skip.** A suite that cannot run is a failure.
6. **Fix findings; do not baseline them.** The baseline is a deliberate
   deferral mechanism, not a dumping ground.
7. **No repository-wide reformat.**
8. **New migrations are PHP**, using `schema_helpers.php`, so the MariaDB-only
   constraint does not grow.

---

## 8. Remaining risks and deferred work

### Accepted

| Risk | Why acceptable | Revisit |
|---|---|---|
| Suites 4–8 use the development database | Isolating them needs the server run with a separate environment. PHPUnit — the destructive suite — is isolated. | Phase 3 |
| Module pages excluded from static analysis | Including them means a large baseline nobody reads. Shared layers, where damage is worst, are covered. | Incrementally |
| Level 5, not 6+ | Measured: level 6 adds only typehint reporting. Raising it would require declared types throughout — a rewrite. | With gradual typing |
| PHPUnit covers only new code | Migrating 412 working assertions is churn with real risk. | Not planned |
| CI has never executed | No remote exists yet, by instruction. Structure is asserted by tests. | First push |
| API is session-authenticated only | Token auth is real work (issuance, rotation, scoping, revocation) belonging to the phase that needs machine access. | Phase 7/11 |
| No rate limiting | `/v1/health` is public and unmetered — cheap, and discloses nothing. | Before external exposure |
| Migrations 002–004 are MariaDB-only | Rewriting historical migrations is riskier than the constraint. New ones are PHP. | If MySQL is required |
| `/api/index.php/v1/...` needs a rewrite rule for clean URLs | Deployment configuration, not application code. | Deployment |

### Deferred, carried forward

- **Full test-environment isolation** for the HTTP suites.
- **Gradual typing** of `includes/`, enabling a level rise later.
- **Module pages into static analysis**, one at a time.
- **Token authentication** for non-browser clients.
- **Converting migrations 002–004** to PHP.
- **`.htaccess` / nginx rewrite** for clean API URLs.

### Not started — and not part of Phase 2B

Animal Digital Twin · veterinary intelligence · AI gateway or any LLM
integration · disease detection · computer vision · IoT · payment processing ·
mobile applications · a complete public API · microservices.
