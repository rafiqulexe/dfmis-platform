# DFMIS Roadmap

**From:** single-farm dairy management application
**To:** international-grade, AI-powered Farm Management & Farm Intelligence Platform

**Companion document:** [`DFMIS_BASELINE_AUDIT.md`](./DFMIS_BASELINE_AUDIT.md) — finding
IDs referenced below (T-1, C-2, D-1 …) are defined there.

---

## Governing constraints

These hold across every phase and are not revisited:

1. **Do not rewrite.** The tenancy model, the `{scope}` repository pattern, the
   membership-based RBAC and the deterministic insights engine are correct. They are
   extended, never replaced.
2. **One tenant key per row.** Business tables carry `farm_id` and nothing else.
   Organization is resolved by joining up through `farms`. This rule survives every
   phase.
3. **Cross-tenant misses return 404, never 403.**
4. **Core must not assume a vertical.** Core tables reference records through
   `(entity_type, entity_id)`. Dairy is the first vertical, not the only one.
5. **An unavailable model must never look like a confident answer.** Every AI surface
   renders an explicit degraded state.
6. **No phase begins until the previous phase's tests pass.**

---

## Phase 0 · Baseline — **COMPLETE**

Read-only reconnaissance and baseline verification. No application code modified.

**Delivered**
- Full repository inspection — 39 PHP files, 4 SQL files, JS, CSS, docs
- 34 findings classified Critical / High / Medium / Low
- Executable baseline established: 39/39 files lint clean, 14/14 tests passing,
  schema imports clean, authenticated smoke test green
- Two findings verified empirically rather than by inspection (C-1 reproduced,
  T-1/T-2/T-3 confirmed against the live schema)
- `docs/DFMIS_BASELINE_AUDIT.md`, `docs/DFMIS_ROADMAP.md`

**Exit criteria:** met.

---

## Phase 1 · Foundation Hardening — **COMPLETE**

Commit `ae249b9` — *security: harden DFMIS platform foundation*.

Every Critical and High finding from the baseline audit is closed. 180 assertions
passing at the time of completion. Delivered: schema integrity migration (005),
centralized authorization (`requireAuth`), centralized validation
(`DfmsValidator`), audit logging wired into every mutation, safe error handling,
hardened sessions, login throttling, tenant-aware repository helpers,
transactions, a cross-engine migration runner, and a security regression suite.

**Not delivered, deliberately:** Composer / PSR-4 / PHPUnit and CI (§1.6). Adding
them requires restructuring a procedural codebase, which conflicts with the
project's "do not rewrite" constraint. Carried forward to Phase 2's outstanding
work below.

See [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md).

<details>
<summary>Original Phase 1 plan</summary>

Fix what is broken and install what makes further work safe. **No new features.**
This phase pays down the audit and nothing else.

### 1.1 Schema integrity (migration 005)
- Per-farm unique animal tags — `UNIQUE (farm_id, unique_id)` (**T-1, Critical**)
- Drop `DEFAULT 1` from `farm_id` on all eleven tables (**T-2**)
- Add the nine missing `farm_id` foreign keys (**T-3**)
- `UNIQUE (farm_id, production_date)` on `milk_production` (**T-5**)
- Re-lead every tenant index with `farm_id`; add indexes to the five tables with
  none (**T-4**)
- Correct `seed_demo_data.php`, which matches on `unique_id` alone

### 1.2 Stop the crashes
- Null-farm dashboard guard + `Throwable` catch (**C-1**)
- `inTransaction()` guard before every `rollBack()` (**C-5**)

### 1.3 Security hardening
- Production `php.ini` fragment + global exception handler; no path or trace ever
  reaches the browser (**S-6**)
- Host allowlist / explicit `APP_URL` (**S-1**)
- `charset=utf8mb4` + `ATTR_EMULATE_PREPARES => false` (**S-2**) — also a
  prerequisite for Phase 12
- Session cookie `HttpOnly` / `SameSite` / `Secure` (**S-3**), correct logout cookie
  params (**S-4**)
- Pin and SRI-hash or vendor the two CDN dependencies (**S-5**)
- Login rate limiting and account lockout

### 1.4 Close the alerts loop
- Mark-as-read endpoint + per-row and bulk controls (**C-2**)
- Low-stock alert deduplication and auto-resolve (**C-3**)

### 1.5 Resolve the unreachable subsystems (**D-1**)
- Wire `auditLog()` into all mutating handlers; build the `audit.view` reader
- Decide and document the status of `ai_service.php` (activated in Phase 5) and
  `storage.php` (activated in Phase 3)

### 1.6 Engineering foundation — *the gate for everything after*
- **Composer + PSR-4 autoloading** (Blocker 1)
- **PHPUnit** with fixture isolation and a dedicated test database (Blocker 2)
- **Migration runner + `schema_migrations` table** (Blocker 3)
- Rewrite migrations with `information_schema` guards so they run on MySQL 8 and
  MariaDB alike (**D-7**, Blocker 4); fix the false "safe to re-run" claim (**D-6**)
- **PHPStan** (level 5 initially) and a linter, both wired into CI
- **CI pipeline** running lint + PHPStan + PHPUnit + the isolation suite on every
  push (Blocker 6)
- Fix the isolation suite's hardcoded fixture id (**F-4**)

### 1.7 Consistency cleanup
- Centralized validation helpers, then the per-page input bugs (**C-6, C-7, C-8**)
- Reconcile `ARCHITECTURE.md` with the code (**T-6, D-3**)
- Remove dead `url()` (**D-4**), bind the double-submit guard (**D-5**), extract the
  low-stock threshold (**C-9**), fix header variable clobbering and connection reuse
  (**C-10**)

**Exit criteria** — every Critical and High finding closed; PHPUnit + PHPStan + CI
green; migrations idempotent and cross-engine; audit log recording all mutations.

</details>

---

## Phase 2 · International Platform Architecture — **PARTIALLY COMPLETE**

This phase was scoped in two halves. The **international configuration
foundation** is delivered; the **platform/API structural work** is not, and is
listed as outstanding below. Phase 2 is therefore *not* wholesale complete.

### 2A · International foundation — **COMPLETE**

Commit `feat: establish international platform foundation`. Migration 006.
412 assertions passing across 6 suites.

- Configuration hierarchy — farm → organization → platform default, resolved once
  in `includes/settings.php`; NULL means inherit at every level
- Organization international settings — legal name, ISO 3166-1 country, IANA
  timezone, BCP 47 locale, ISO 4217 currency, measurement system
- Farm-level overrides for all of the above, independently overridable
- International location model on `locations` — deliberately generic
  `admin_area` / `locality`, no country-specific address assumptions
- Timezone strategy — UTC storage, farm-timezone display; date-only values never
  shifted; PHP and MySQL both pinned to UTC, fixing a real two-clock defect
- Currency foundation — ISO 4217 validation, per-currency decimal precision
  (JPY 0, BHD 3), centralized formatting. No monetary columns exist yet, so no
  historical amount can change meaning
- Canonical measurement strategy — metric storage (kg / L / °C), conversion at
  display only; reinterprets zero existing values because the data was already
  metric
- Locale foundation — translation mechanism with fallback chain, `en` complete
  and `bn` deliberately partial to exercise it. **Not** full multilingual support
- Farm-type capability model, replacing scattered `if ($farmType === 'dairy')`
- Subscription foundation extending the existing `plan`/`status` columns —
  advisory limits, **no enforcement**, nobody restricted
- Feature flags — three scopes, registry defaults, tenant-isolated
- Notification preferences — model only, no delivery; `alerts` untouched
- Organization-level authorization activated (`organization_users` is now read)
- Settings UI for organization, farm, features and notifications

See [`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](./DFMIS_INTERNATIONAL_ARCHITECTURE.md)
and [`DFMIS_SAAS_FOUNDATION.md`](./DFMIS_SAAS_FOUNDATION.md).

### 2B · Platform Engineering Foundation — **COMPLETE**

Commit `chore: establish platform engineering foundation`.
8 suites, 575 assertions, PHPStan level 5 with an empty baseline.

**Delivered**

- **Composer** — 2 dev dependencies only (PHPUnit, PHPStan), zero runtime
  dependencies, `composer.lock` committed. Autoloading deliberately near-empty:
  the application still uses `require_once` and was not converted to PSR-4.
- **PHPUnit** — 37 tests / 102 assertions covering the HTTP foundation, API
  route-table invariants, the test-database guard and the tooling config. The
  four legacy suites are untouched and still run.
- **PHPStan level 5, empty baseline** — the level chosen from a measured sweep
  (level 6's extra 274 findings are pure typehint reporting, not a bug class).
  All 14 findings present at introduction were **fixed, not suppressed**.
- **Test environment isolation** — `dfms_test` built from the real baseline and
  migrations; a positive-evidence guard that treats missing configuration as
  refusal. This uncovered a genuine hazard: migrations 002–004 open with
  `USE dfms;` and would have modified the development database when run against
  the test one. The runner now strips embedded `USE` statements.
- **CI** — `.github/workflows/ci.yml` running lint, PHPStan, migrations plus an
  **idempotency check**, PHPUnit and the full suite, with a web server started
  explicitly so the HTTP suites cannot silently skip.
- **HTTP foundation** — `includes/http.php`: request inspection, JSON body
  parsing, `data`/`error` envelopes, method enforcement, correlation ids.
- **API foundation** — `/api/v1/` behind an API-only front controller with an
  explicit route allow-list. Three endpoints (`health`, `me`, `settings`).
  Reuses `requireAuth()` verbatim; no parallel authorization exists.
- **Quality commands** — `composer lint | analyse | test:unit | check`.
- Documentation: `DFMIS_DEVELOPMENT_GUIDE.md`, `DFMIS_API_ARCHITECTURE.md`,
  `DFMIS_ENGINEERING_FOUNDATION.md`.

**Two latent defects fixed along the way:** `getDBConnection()` failed via
`exit("message")`, which exits with status **0** — a database failure would have
looked like success to CI; and `footer.php` used ten variables it never defined,
the same class of fault as baseline finding C-1.

**Deliberately NOT delivered** (deferred with reasons in
[`DFMIS_ENGINEERING_FOUNDATION.md`](./DFMIS_ENGINEERING_FOUNDATION.md) §8):

- **PSR-4 conversion of the application.** Out of scope by instruction — it is a
  rewrite. Composer autoloading is present but near-empty by design.
- **A general front controller.** Confined to `/api/`; all 25 HTML pages remain
  directly reachable and unmodified.
- **Token/API-key authentication, rate limiting, pagination conventions, OpenAPI,
  CORS, write endpoints.** The API is a foundation, not a public API.
- **Full test isolation for suites 4–8** — they still use the development
  database; only PHPUnit is isolated.
- **Module pages in static analysis** — shared layers only.

### 2C · Platform surfaces — **NOT STARTED**

Remaining from the original Phase 2 scope, none of which Phase 2B claimed:

- Platform admin console, and the T-6 privilege gap it depends on.
- Tenant onboarding: organization signup, farm provisioning, user invitation.
  **Organization member management** is the concrete near-term gap — org roles
  can currently only be set by seeding.
- Structured logging and error tracking (health checks now exist via
  `/api/v1/health`).
- Finish the design-system migration and drop the Tailwind Play CDN (**F-1, F-2**).

**Exit criteria for 2C** — a second organization can be onboarded end-to-end
without manual SQL.

---

## Phase 3 · Livestock Core — **COMPLETE**

Commit `feat: establish livestock core and animal digital twin`. Migration 007.
9 suites, 730 assertions, PHPStan level 5 with an empty baseline.

**Delivered** — see [`DFMIS_ANIMAL_DIGITAL_TWIN.md`](./DFMIS_ANIMAL_DIGITAL_TWIN.md)

- `cows` **evolved** into the animal master record — not replaced. Renaming it
  would have broken 12 files and 2 foreign keys for no gain.
- Classification: species registry (cattle, buffalo, goat, sheep) with
  species-correct vocabulary; breed stays free text with suggestions.
- `animal_identifiers` — typed identifiers (RFID, registry, microchip, national
  ID), unique **per farm**, deliberately not globally, to avoid reintroducing the
  T-1 cross-tenant enumeration leak.
- `animal_lifecycle_events` — a real state machine with append-only history;
  terminal states return to `active` only, so corrections are visible rather than
  erasing the past. Kept in step with the existing `deleted_at` flag, so no
  pre-existing list page changed.
- `animal_transfers` — internal movement and inter-farm transfer, the latter
  guarded by live membership **and** same-organization checks, in one transaction.
- `animal_ownership` — owned / leased / managed / boarding, with periods.
- Parentage — internal or external parents, with self-parent, sex-mismatch and
  pedigree-cycle prevention. An external sire is never created as a fake animal.
- Documents and photos **reusing the Phase 1 storage layer**; no second file
  system.
- Animal timeline — a union over existing tables, not a duplicate event store.
- Search, filters, pagination; read-only `/api/v1/animals` reusing the same
  domain functions as the UI.
- Tabbed animal profile (server-rendered, no frontend framework), reviewed
  against the Web Interface Guidelines for accessibility.
- **Test isolation improved** (the Phase 2B deferred item): `tools/test-db.php`
  now seeds `dfms_test`, and the Phase 3 HTTP suite runs against its own server
  on that database. It fails rather than silently falling back.

**Two omissions caught by tests during development:** the new tables were missing
from `DFMS_TENANT_TABLES` (the repository correctly refused them), and migrations
`002`–`004` embed `USE dfms;` which would have redirected a test-database run at
the development schema.

**Deliberately NOT delivered** (deferred with reasons in the document §22):

- **Per-animal milk yield** — production is still a farm-level daily total. This
  is the largest remaining data-model gap and belongs to Phase 8.
- **Reproduction / breeding module** — Phase 8.
- **Genetics beyond a registry id and free-text notes** — no genomic analysis.
- **CSV bulk import** — export groundwork exists; destructive import was out of
  scope.
- **Weight and body-condition history.**
- **Test isolation for the four pre-Phase-3 HTTP suites** — still on the
  development database.
- **No AI, vision, ML or IoT of any kind.**

<details>
<summary>Original Phase 3 plan</summary>

## Phase 3 · Livestock Core

Deepen the first vertical to production standard.

- Full animal profile: photo upload (activates `storage.php`), breed registry,
  acquisition and disposal records, weight history, identification (ear tag, RFID,
  passport number)
- Location assignment — `cows.location_id` exists and is unused; barn/paddock
  occupancy and capacity
- Herd hierarchy: groups, batches, lifecycle stages
- Animal transfer between farms within an organization, fully audited
- Bulk import/export (CSV, XLSX) with validation and dry-run preview
- Animal timeline aggregating every module's events into one chronology

**Exit criteria** — an animal's complete life history is reconstructable from the UI.

</details>

---

## Phase 4 · Health & Veterinary — **COMPLETE**

Commit `feat: implement veterinary health foundation`. Migration 008.
10 suites, 1159 assertions, PHPStan level 5 with an empty baseline.

**Delivered** — see [`DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md`](./DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md)

- Twelve normalised clinical tables: health cases, status history, observations,
  symptoms, diagnoses, veterinary consultations, treatments, medication
  administrations, vaccinations, quarantine, withdrawal periods and laboratory
  tests. All tenant-owned, all registered in `DFMS_TENANT_TABLES`.
- `health_events` **preserved** as the free-form event log, gaining one nullable
  `case_id`. Its four existing rows keep `case_id = NULL` — no case existed when
  they were written, and inventing one would be fiction.
- `cows.health_status` **widened** by `quarantined` and `recovered`. The four
  original values keep their stored form and meaning; only labels were clarified.
  `deceased` was deliberately NOT added — lifecycle owns death, and two columns
  that can disagree about whether an animal is alive is the drift Phase 3 avoided.
- **Lifecycle is authoritative over health.** A terminal lifecycle state refuses
  every clinical write; the correction path is the Phase 3 lifecycle page.
- Structured observations that integrate with the Phase 2 units architecture:
  entered in the farm's system, **stored canonically**, range-checked on the
  canonical value. An unsupported unit **fails** rather than converting wrongly.
- Coded symptom and condition registries with a single `other` escape hatch, so
  patterns are analysable without rejecting an unrecognised sign.
- Diagnoses carry explicit epistemic status (`suspected` / `confirmed` /
  `ruled_out` / `resolved`) and the **clinician's own stated confidence**, never
  a computed one. A diagnosis is never rewritten, only re-statused.
- External veterinarians need **no DFMIS account** — the practitioner is
  descriptive text, so authentication assumptions are untouched.
- Medication administration consumes inventory through the existing `FOR UPDATE`
  pattern inside one transaction; stock never goes negative and a refusal leaves
  no medication row, no withdrawal and no stock change.
- Vaccination schedule and record in one table. **A scheduled dose is never
  promoted to administered by the passage of time** — only an explicit human
  action does that. Corrections supersede rather than edit.
- Withdrawal periods per animal, per restriction type, with an **inclusive** end
  date, species-filtered types, and **no zero-day default** — a blank withdrawal
  creates no restriction at all.
- Quarantine reusing the Phase 3 `locations` model and `animalMoveWithinFarm()`.
- Laboratory tests with PDF report attachment through the Phase 1 file system.
  PDF is the one non-image type added, magic-byte checked and always served as an
  attachment, never inline.
- Health events extend the Phase 3 timeline as a **union over source tables** —
  no duplicate event store.
- Five deduplicating, auto-resolving health alert types on the existing `alerts`
  table. Phase 1 left no generator to reuse, so the smallest honest one was built.
- Two new permissions only: `health.observe` (wider — routine stockmanship) and
  `health.medicate` (narrowest — consumes stock, creates a food-safety restriction).
- Read-only API: `/v1/animal-health/{id}` and `/v1/health-cases[/{id}]`.
  `/v1/health` remains the public liveness probe, untouched.

**Not delivered, deliberately — withdrawal is ADVISORY, not enforced.**
`milk_production` remains a farm-level daily total with no `cow_id`, so DFMIS
tracks and surfaces an individual animal's restriction but **cannot verify** that
its milk was excluded from the tank. This is stated on the withdrawals page, in
the API payload (`withdrawal_enforcement: "advisory_only"`) and in the
architecture document. Real enforcement needs per-animal production traceability
and is carried to Phase 8. Building a fake gate now would create confidence the
data cannot support.

**Also deferred:** batch-level inventory stock (inventory holds one quantity per
item, so batch/lot is captured on the administration instead), scheduled alert
generation, and international disease-code alignment.

**Exit criteria** — a veterinarian can run a full treatment cycle in the system:
open a case, record symptoms and a diagnosis, start a treatment, administer
medication with batch and withdrawal, isolate the animal, record laboratory work,
and resolve the case with an outcome. Withdrawal periods are tracked and
surfaced per animal; enforcement against production records is **explicitly
carried to Phase 8** rather than claimed here.

<details>
<summary>Original Phase 4 plan</summary>

Health is currently create-and-list only.

- Full CRUD on health events with edit history and audit trail
- Vaccination schedules, due-date tracking, compliance reporting
- Treatment plans with dosage, withdrawal periods and follow-up scheduling
- **Medicine withdrawal enforcement** — block milk from animals inside a withdrawal
  window. A food-safety control, not a convenience feature.
- Veterinary visit records, prescriptions, practitioner registry
- Disease and condition taxonomy aligned to international coding standards
- Mortality and post-mortem records
- Health cost tracking per animal, feeding Phase 9

*Delivered as above, with three deviations recorded honestly: withdrawal
enforcement against production is not possible without per-animal milk data;
the condition taxonomy is a stable pragmatic registry rather than an
international standard; and a full practitioner registry was not built because
requiring an account per external vet would make the record unusable.*

</details>

---
## Phase 5 · AI Intelligence Engine

Activate `ai_service.php`, which is complete and currently unreachable.

- Wire `aiComplete()` into the application with plan gating and `ai_usage`
  attribution already implemented
- **Preserve `insights.php`.** The deterministic engine is the substrate; AI narrates
  the same signals in natural language. It does not replace the computation and must
  never invent a signal the data does not support.
- Natural-language farm summaries grounded in real computed metrics
- Anomaly detection: production drops, feed-conversion drift, health clusters
- Predictive alerts with explicit confidence levels
- Prompt versioning, response caching, cost telemetry per organization
- Degraded-state rendering on every AI surface (already the documented contract)
- Human-in-the-loop review for any AI output touching animal welfare

**Exit criteria** — AI features degrade cleanly with no API key; every call is
attributed and plan-gated; no AI output is presented without its grounding data.

---

## Phase 6 · AI Disease & Vision

- Image ingestion for visual diagnosis — lameness, mastitis, body condition scoring,
  skin conditions
- Vision model integration through the same `aiComplete()` seam
- Confidence thresholds with **mandatory veterinary referral** below threshold
- Training-data governance: consent, retention, tenant-scoped isolation of images
- Regulatory positioning — decision *support*, explicitly not diagnosis

**Exit criteria** — vision features carry clear confidence and referral guidance;
image storage is tenant-isolated and consent-tracked.

---

## Phase 7 · Farm AI Copilot

- Conversational interface over the farm's own data
- Tool-calling into existing repositories with **tenant scope enforced at the tool
  boundary, not the prompt** — a prompt is not an access control
- Action proposals requiring explicit human confirmation before any write
- Full conversation audit trail
- Multi-turn context with per-organization memory
- Voice input for hands-free field use

**Exit criteria** — the copilot cannot read or write outside the active farm under
adversarial prompting; every proposed action is confirmable and audited.

---

## Phase 8 · Production / Feed / Reproduction Intelligence

- **Per-animal milk yield** — currently only farm-level daily totals exist. This is
  the single largest data-model gap in the product.
- Milking session records (AM/PM), parlour and robot integration
- Quality metrics: fat, protein, somatic cell count, bacterial count
- Feed formulation, ration planning, feed-conversion ratio
- Activate `feed_consumption`, which has a table but no UI writer
- Reproduction module: heat detection, insemination records, pregnancy checks,
  calving, genealogy. `cows.reproductive_status` exists and is unused.
- Lactation curve modelling and yield forecasting
- Breeding value and genetic merit tracking

**Exit criteria** — yield is attributable to the individual animal; a full
reproduction cycle is recordable.

---

## Phase 9 · Inventory / Procurement / Finance

Finance does not exist in any form today — no table, no module, no navigation entry.

- Extend inventory: multi-location stock, batch and lot tracking, expiry management,
  per-item reorder levels (replacing the hardcoded threshold from **C-9**)
- Procurement: suppliers, purchase orders, goods receipt, invoice matching
- **Finance from scratch:** chart of accounts, cost centres per farm, revenue and
  expense tracking, payroll, asset register and depreciation
- Profitability analysis per animal, per group, per farm
- Budgeting and variance reporting
- Multi-currency with historical exchange rates (depends on Phase 12)
- Accounting-system integration and export

**Exit criteria** — cost per litre and margin per animal are computable from system
data alone.

---

## Phase 10 · Analytics & Command Center

- Report builder with scheduled delivery and PDF/XLSX export — activates the
  `reports` navigation placeholder
- Benchmarking against anonymized, aggregated peer data with explicit opt-in
- Executive dashboards spanning multiple farms in an organization
- KPI framework with configurable targets and alerting
- Data warehouse separation so analytics queries stop competing with operational
  ones
- Custom dashboard composition per role

**Exit criteria** — a multi-farm organization can answer operational questions
without exporting to a spreadsheet.

---

## Phase 11 · IoT & Sensor Intelligence

`temperature_readings` is seeded but never read or written by the application — the
first real sensor integration replaces that stub.

- Device registry, provisioning and per-device credentials
- Telemetry ingestion endpoint (depends on the Phase 2 API) with backpressure and
  buffering
- Time-series storage sized for high-frequency data
- Wearables: activity collars, rumination sensors, boluses
- Environmental monitoring: barn temperature, humidity, ammonia
- Milking-equipment and weighbridge integration
- Edge-tolerant sync for intermittent rural connectivity
- Sensor-triggered alerts feeding the Phase 5 anomaly engine

**Exit criteria** — a sensor stream survives a 24-hour connectivity outage without
data loss.

---

## Phase 12 · Internationalization

Nothing in the codebase is currently internationalized (**I-1**). Every string is
hardcoded English, dates use hardcoded formats, and there is no currency or timezone
handling.

- Translation infrastructure and locale resolution per user and per organization
- Full string externalization across all modules
- **Per-farm timezone** — currently every date derives from the server clock, which
  is wrong the moment two farms sit in different zones
- Locale-aware date, number and unit formatting
- **Unit systems** — metric and imperial (litres/gallons, kg/lb, °C/°F)
- Multi-currency with historical rates
- RTL layout support
- Regional compliance variants: EU, US, UK, South Asia
- Country-specific animal identification schemes and regulatory reporting formats
- `utf8mb4` throughout (delivered in Phase 1 via **S-2**)

**Exit criteria** — the platform is usable end-to-end in at least two languages, two
unit systems and two timezones simultaneously within one organization.

---

## Phase 13 · Security / Compliance / Performance

- Independent penetration test and remediation
- SOC 2 / ISO 27001 readiness
- **GDPR**: data subject rights, retention policy, right to erasure, processing
  register
- Animal-welfare regulatory compliance per region
- Encryption at rest, key management, secret rotation
- MFA, SSO/SAML, session policy
- Full audit-log coverage with tamper evidence — builds on Phase 1's `auditLog()`
- Performance: query optimization, caching layer, CDN, connection pooling (replacing
  the connection-per-call behaviour noted in **C-10**)
- Load testing against realistic multi-tenant volume
- Automated tenant-isolation fuzzing in CI, extending the Phase 0 suite
- Backup, restore and disaster-recovery drills with measured RTO/RPO

**Exit criteria** — a clean external penetration test; documented and *rehearsed*
recovery procedures.

---

## Phase 14 · Production Launch

- Production infrastructure: containerization, orchestration, blue/green deploys
- Database replication, automated failover, point-in-time recovery
- Monitoring, alerting, on-call rotation, runbooks
- Status page and incident communication process
- Customer onboarding, documentation, in-app help, support tooling
- Billing activation — `organizations.plan` and `status` exist and are unused
- Usage metering and plan enforcement, building on `ai_usage`
- SLA definition and measurement
- Staged rollout: pilot customers, then regional, then general availability

**Exit criteria** — paying customers in production with measured SLAs and a
rehearsed incident process.

---

## Dependency map

```
Phase 0  ✔ complete
   │
Phase 1  Foundation Hardening        ← gates everything; 1.6 gates 2+
   │
Phase 2  Platform Architecture       ← API gates 7 and 11; org-auth gates 9
   ├──── Phase 3  Livestock Core     ← gates 4 and 8
   │        └──── Phase 4  Health    ← withdrawal control gates 8
   │        └──── Phase 8  Production/Feed/Reproduction
   ├──── Phase 5  AI Engine          ← gates 6 and 7
   │        ├──── Phase 6  Vision
   │        └──── Phase 7  Copilot
   ├──── Phase 9  Inventory/Finance  ← gates 10
   │        └──── Phase 10  Analytics
   └──── Phase 11 IoT                ← feeds 5's anomaly engine
             │
Phase 12 Internationalization        ← touches every prior phase; do not defer further
             │
Phase 13 Security/Compliance/Performance
             │
Phase 14 Production Launch
```

**Two sequencing warnings.**

*Phase 12 is placed late but touches every phase before it.* Deferring
internationalization until after nine phases of feature work means retrofitting
strings, dates, units and currency across the entire surface. The `utf8mb4` and
timezone groundwork should land in Phase 1, and every phase from 2 onward should
externalize its strings as it writes them rather than leaving that to Phase 12.

*Phase 8's per-animal yield is a data-model change, not a feature.* The longer
farm-level-only production data accumulates, the more expensive that migration
becomes. Consider pulling the schema change forward into Phase 3.

---

## Current position

**Phase 0 complete. Phase 1 complete. Phase 2A complete; Phase 2B not started.**

| Phase | Status | Commit |
|---|---|---|
| 0 · Baseline | complete | `50277c8` |
| 1 · Foundation Hardening | complete | `ae249b9` |
| 2A · International foundation | complete | `78b25ac` |
| 2B · Platform Engineering Foundation | complete | `cfac2ce` |
| 2C · Platform surfaces | **not started** | — |
| 3 · Livestock Core & Animal Digital Twin | complete | `feat: establish livestock core and animal digital twin` |
| 4 · Health & Veterinary | complete | `feat: implement veterinary health foundation` |
| 5+ | not started | — |

Test suite: **1159 assertions across 10 suites, all passing** (`bash tests/run_all.sh`).
Static analysis: **PHPStan level 5, zero findings, empty baseline**.
Schema at migration **008**.

**Phase 5 (AI Intelligence Engine) is unblocked.** The structured, coded and
provenance-complete clinical record it needs now exists, and it represents
uncertainty explicitly — `suspected` / `ruled_out`, `outcome: unknown`, absent
confidence stored as NULL — so a model reads what was actually known rather than
a flattened certainty.

Three items are worth weighing before Phase 5:

1. **Per-animal production data still does not exist**, and Phase 4 has now hit
   it directly. `milk_production` remains a farm-level daily total with no animal
   reference, so medicine withdrawal is tracked and surfaced but **cannot be
   enforced** against production. This is the single most valuable thing to pull
   forward from Phase 8: it converts a food-safety advisory into a food-safety
   control.
2. **Test isolation for the four pre-Phase-3 HTTP suites.** Phases 3 and 4 both
   run isolated on `dfms_test`; the four older suites still use the development
   database. Each phase adds more mutating tests, so this keeps getting more
   expensive to defer.
3. **Organization member management** (Phase 2C). Organization roles can still
   only be set by seeding, which makes onboarding a second real customer manual.

None blocks Phase 5.

### Sequencing note carried forward

Phase 12 (Internationalization) is **substantially de-risked** by Phase 2A: the
configuration hierarchy, timezone strategy, currency handling, unit conversion,
`utf8mb4` and the translation mechanism all now exist. What remains for Phase 12
is breadth — externalizing the strings still inline in module templates, RTL
layout, and locale-aware date patterns — rather than architecture. Each phase
from here should externalize its own strings as it writes them, so Phase 12 does
not become a single enormous sweep.

### Sequencing note carried forward

Phase 12 (Internationalization) is **substantially de-risked** by Phase 2A: the
configuration hierarchy, timezone strategy, currency handling, unit conversion,
`utf8mb4` and the translation mechanism all now exist. What remains for Phase 12
is breadth — externalizing the strings still inline in module templates, RTL
layout, and locale-aware date patterns — rather than architecture. Each phase
from here should externalize its own strings as it writes them, so Phase 12 does
not become a single enormous sweep.
