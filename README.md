# DFMIS — Farm Management & Intelligence System

[![CI](https://github.com/rafiqulexe/dfmis-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/rafiqulexe/dfmis-platform/actions/workflows/ci.yml)

A multi-tenant farm management platform: animal records, veterinary health,
milk production, feeding, inventory and AI-assisted decision support. Built in
procedural PHP against MariaDB, with **no framework**.

It began as a university dairy-farm project and is being evolved — not
rewritten — into something a real farm could run. The original code was kept
and hardened in place; every phase below was an incremental change to a working
system, never a restart.

---

## What it is, and what it is not

**It is** a working application with a multi-tenant foundation: an
organization owns farms, a farm owns locations, a location holds animals, and
every row carries exactly one tenant key. Authentication, role-based
permissions, audit logging, input validation and tenant isolation are built and
tested.

**It is not** finished. Reproduction, finance, analytics, IoT and procurement
are designed but unbuilt, and the roadmap says so per phase. Nothing here
claims to be production-ready for a paying customer.

**It does not do AI diagnosis.** The AI features are deliberately decision
support only: they describe, summarise and cite, they escalate to a
veterinarian, and the schema has nowhere to record a diagnosis or a confidence
score. When no AI provider is configured, the assistant reports itself
unavailable and **refuses to answer** rather than guessing.

---

## Status

| Phase | Scope | State |
|---|---|---|
| 0 | Baseline audit | Complete |
| 1 | Foundation hardening — tenant isolation, RBAC, validation, audit, file storage | Complete |
| 2A | International foundation — locales, currencies, units, timezones | Complete |
| 2B | Engineering foundation — Composer, PHPUnit, PHPStan, CI, HTTP/API layer | Complete |
| 2C | Platform surfaces | Not started |
| 3 | Livestock core — animal digital twin, identity, lifecycle, parentage, transfers | Complete |
| 4 | Veterinary health — cases, treatments, withdrawals, quarantine, lab tests | Complete |
| 5D | AI veterinarian assistant + RAG over supplied text | Complete |
| 6A | AI-assisted image **observation** (not diagnosis) | Complete |
| 8A | Production & feeding operations | Complete |
| 5A/5B/5C | Disease detection, predictive ML | Not started |
| 9–14 | Finance, analytics, IoT, compliance, launch | Not started |

Full detail, including what each phase deliberately did **not** build, is in
[`docs/DFMIS_ROADMAP.md`](docs/DFMIS_ROADMAP.md).

---

## Design decisions worth knowing

These are the choices that shape the codebase. Each is argued in full in
`docs/`.

**One tenant key per row.** Every tenant-scoped table carries `farm_id` and
nothing else — no denormalised `organization_id` that can drift out of
agreement with it. Queries go through a repository layer where `{scope}`
expands to the tenant predicate, so *the safe query is the short query*.

**A cross-tenant miss returns 404, never 403.** A 403 confirms the record
exists. Resource lookups resolve within the caller's tenant or report nothing
at all.

**One authorization gate.** `requireAuth($permission)` runs the whole chain —
authenticated, tenant context, farm membership, organization membership,
permission — and resource ownership is settled separately by
`tenantFindOr404()`. There is no second path into a page.

**Metric storage, converted at display.** Weights are kilograms, volumes
litres, temperatures Celsius, everywhere in the database. Imperial is a
rendering concern. Timestamps are UTC instants; date-only values are never
timezone-shifted.

**Configuration inherits.** A setting resolves farm → organization → platform
default, where `NULL` means "inherit" rather than "unset". A farm overrides
only what it actually differs on.

**Capabilities, not farm-type conditionals.** Code asks
`farmHasCapability('milk_production')`, not `if ($farmType === 'dairy')`. The
species registry carries species-correct vocabulary too, so a goat's offspring
is a kid and its mother is a doe.

**The audit log and the business timeline are different things.** One is a
security record of who changed what; the other is the animal's story. Merging
them would have compromised both.

**Not a rewrite.** No framework was introduced, the database was not replaced,
and existing modules were extended rather than reimplemented. `cows` is still
called `cows` because renaming it would have broken twelve files and two
foreign keys to no functional gain — new code says *animal*.

---

## Stack

| | |
|---|---|
| PHP | 8.3 minimum, 8.5 recommended (developed on 8.5.12) |
| Database | **MariaDB** 10.11 LTS — not MySQL; migrations 002–004 use MariaDB-only syntax |
| Dependencies | **None at runtime.** Composer carries dev tooling only |
| Dev tooling | PHPUnit 10.5, PHPStan 2.3 (level 5) |
| Server | PHP's built-in server for development; Apache is not required |

Required extensions: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `json`,
`openssl`, plus `zip` for Composer.

---

## Getting started

```bash
git clone https://github.com/rafiqulexe/dfmis-platform.git
cd dfmis-platform

cp .env.example .env          # then set DB_PASS and any AI keys
composer install              # dev tooling only

mysql -u root < database.sql   # see the warning below — this DROPS dfms
php database/migrate.php
php database/seed_admin.php
php database/seed_demo_data.php

php -S localhost:8000 -t .
```

Then open <http://localhost:8000>.

> **`database.sql` begins with `DROP DATABASE IF EXISTS dfms`.** It creates and
> selects the schema itself, so it needs no database argument — and it will
> destroy an existing `dfms` without asking. Run it to initialise, never to
> "refresh". Once the schema exists, `php database/migrate.php` is the only
> thing you should need; it is idempotent and CI asserts that.

> **Change the demo passwords before exposing this anywhere.**
> `database/seed_admin.php` creates accounts with passwords like `admin123`
> (hashed on insert). They exist so the permission matrix is actually
> exercisable — five roles, including the two most restricted — and they are
> fine on a laptop and unacceptable anywhere else.

AI features are optional. With no provider key configured the assistant
degrades to a clearly-labelled unavailable state; it never invents an answer to
cover a missing key.

---

## Testing

```bash
bash tests/run_all.sh          # everything
composer check                 # lint + PHPStan + PHPUnit only
```

Thirteen suites run on every push, across PHP 8.2 and 8.5:

| | Suite | Covers |
|---|---|---|
| 1 | Syntax | `php -l` across 140 files |
| 2 | Validation unit | 104 assertions |
| 3 | PHPUnit | 504 tests, 2,321 assertions |
| 4 | International unit | 185 assertions |
| 5 | Tenant isolation + RBAC | 14 |
| 6 | Security regression | 62 |
| 7 | International settings isolation | 49 |
| 8 | API foundation | 63 |
| 9 | Animal digital twin | 56 *(isolated database)* |
| 10 | Veterinary health | 84 *(isolated database)* |
| 11 | AI veterinarian assistant | 90 *(isolated database)* |
| 12 | Production & feeding | 84 *(isolated database)* |
| 13 | AI image observation | 61 *(isolated database)* |

Plus PHPStan level 5 with an **empty baseline** — the findings present when
static analysis was introduced were fixed, not suppressed.

**The suites fail rather than skip.** If a required server or database is
missing, the run exits non-zero. PHPUnit's `markTestSkipped` idiom is avoided
on purpose: a silently-skipped security assertion still prints green, which is
worse than a failure because nobody investigates it.

**Destructive tests run against a separate database.** `dfms_test` is
identified by a naming convention *and* a marker table, and a guard refuses to
let a destructive test touch anything it cannot positively identify as a test
schema. CI asserts both directions — that the guard approves `dfms_test` and
that it **refuses** the development database.

CI builds from nothing on every push: empty Linux container, base schema, all
twelve migrations, an idempotency re-run, seeds, an isolated test database, two
servers, then all thirteen suites. That is a different question from "it works
on my machine", and on the first day it was allowed to run it found three
genuine defects that local conditions could not expose.

---

## Documentation

Thirteen architecture documents in [`docs/`](docs/), each covering one phase,
including the decisions that were rejected and why:

| Document | Subject |
|---|---|
| [`DFMIS_BASELINE_AUDIT.md`](docs/DFMIS_BASELINE_AUDIT.md) | The original audit — 34 findings, the IDs used throughout |
| [`DFMIS_ROADMAP.md`](docs/DFMIS_ROADMAP.md) | Phase status and deferred work |
| [`DFMIS_SECURITY_ARCHITECTURE.md`](docs/DFMIS_SECURITY_ARCHITECTURE.md) | Tenant isolation, RBAC, audit |
| [`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](docs/DFMIS_INTERNATIONAL_ARCHITECTURE.md) | Locales, currencies, units, timezones |
| [`DFMIS_ENGINEERING_FOUNDATION.md`](docs/DFMIS_ENGINEERING_FOUNDATION.md) | Tooling, test strategy, CI |
| [`DFMIS_DEVELOPMENT_GUIDE.md`](docs/DFMIS_DEVELOPMENT_GUIDE.md) | Local setup and environment traps |
| [`DFMIS_API_ARCHITECTURE.md`](docs/DFMIS_API_ARCHITECTURE.md) | Read-only JSON API |
| [`DFMIS_ANIMAL_DIGITAL_TWIN.md`](docs/DFMIS_ANIMAL_DIGITAL_TWIN.md) | Animal identity and lifecycle |
| [`DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md`](docs/DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md) | Clinical records |
| [`DFMIS_AI_ARCHITECTURE.md`](docs/DFMIS_AI_ARCHITECTURE.md) | Assistant, RAG, provider abstraction |
| [`DFMIS_AI_IMAGE_ANALYSIS.md`](docs/DFMIS_AI_IMAGE_ANALYSIS.md) | Image observation, and why not diagnosis |
| [`DFMIS_PRODUCTION_FEEDING_ARCHITECTURE.md`](docs/DFMIS_PRODUCTION_FEEDING_ARCHITECTURE.md) | Milk yields, rations, stock ledger |
| [`DFMIS_SAAS_FOUNDATION.md`](docs/DFMIS_SAAS_FOUNDATION.md) | Organizations, plans, feature flags |

---

## Known gaps

Stated plainly, because a roadmap that only lists wins is not useful:

- **Per-animal milk yield is incomplete.** `milk_production` is a farm-level
  daily total with no animal reference, which is why the animal timeline shows
  no production figures — attributing herd output to one animal would be
  fabrication. Phase 8A added per-animal `milk_yields` alongside it; the two
  are not yet reconciled.
- **Withdrawal periods are recorded but not enforced** against production.
  The page says so rather than implying protection that does not exist.
- **No reproduction module** — no heat detection, insemination, pregnancy
  check or calving workflow. `cows.reproductive_status` is worth singling out:
  it is *read* in two places (the dashboard's pregnant count, and the animal
  record the AI assistant is given) but nothing in the application ever
  **writes** it. Only the demo seed sets it. So the dashboard reports a
  breeding figure that no workflow maintains, which is the kind of number that
  looks authoritative and is not.
- **Finance, analytics, procurement and IoT are unbuilt.**
- **Subscription limits are displayed but not enforced.**
- **Four HTTP suites still run against the development database**, not an
  isolated one. The five newest suites are isolated.
- **`tools/test-db.php --create` is not idempotent** — run `--drop` first.

---

## License

**Proprietary. All rights reserved.**

This repository is public so the work can be read and reviewed. That is not a
grant of permission to use, copy, modify or redistribute it. No open-source
license is attached, and under default copyright that means no such rights are
given. If you want to use any of it, ask.
