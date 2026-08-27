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

### 2B · Platform & API structure — **NOT STARTED**

Still outstanding from the original Phase 2 scope:

- Engineering foundation carried over from Phase 1.6: **Composer + PSR-4
  autoloading, PHPUnit, PHPStan, CI**. Still the largest structural gap.
- Application skeleton: front controller, routing, service layer. Existing
  procedural pages migrate incrementally behind the router, never big-bang.
- **HTTP API layer** — versioned JSON endpoints (`/api/v1/`), token
  authentication, consistent error envelope, rate limiting, OpenAPI spec.
  Prerequisite for Phases 7 and 11.
- Platform admin console, and the T-6 privilege gap it depends on.
- Tenant onboarding: organization signup, farm provisioning, user invitation.
  **Organization member management** is a concrete near-term gap — org roles can
  currently only be set by seeding.
- Structured logging, health checks, error tracking.
- Finish the design-system migration and drop the Tailwind Play CDN (**F-1, F-2**).

**Exit criteria for 2B** — a second organization can be onboarded end-to-end
without manual SQL; API v1 documented and authenticated; isolation suite extended
to cover the API.

---

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

---

## Phase 4 · Health & Veterinary

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

**Exit criteria** — a veterinarian can run a full treatment cycle in the system;
withdrawal periods are enforced against production records.

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
| 2A · International foundation | complete | `feat: establish international platform foundation` |
| 2B · Platform & API structure | **not started** | — |
| 3+ | not started | — |

Test suite: **412 assertions across 6 suites, all passing** (`bash tests/run_all.sh`).
Schema at migration **006**.

The immediate next action is **Phase 2B**, and within it the engineering
foundation — Composer, PSR-4 autoloading, PHPUnit, PHPStan and CI — which has now
been deferred twice. Every subsequent phase compounds without it: there is still
no automated gate preventing a tenancy regression from reaching a branch, and
`php -l` cannot catch the class of type error that caused baseline finding C-1.

Phase 3 should not begin until 2B's testing and autoloading work lands.

### Sequencing note carried forward

Phase 12 (Internationalization) is **substantially de-risked** by Phase 2A: the
configuration hierarchy, timezone strategy, currency handling, unit conversion,
`utf8mb4` and the translation mechanism all now exist. What remains for Phase 12
is breadth — externalizing the strings still inline in module templates, RTL
layout, and locale-aware date patterns — rather than architecture. Each phase
from here should externalize its own strings as it writes them, so Phase 12 does
not become a single enormous sweep.
