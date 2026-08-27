# DFMIS Veterinary & Animal Health Architecture

**Phase 4.** Branch `feature/veterinary-health`. Migration `008_veterinary_health.php`.

**Companion documents:** [`DFMIS_ANIMAL_DIGITAL_TWIN.md`](./DFMIS_ANIMAL_DIGITAL_TWIN.md) (Phase 3,
the animal record this phase hangs off), [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md)
(Phase 1, the tenancy and authorization model reused throughout),
[`DFMIS_API_ARCHITECTURE.md`](./DFMIS_API_ARCHITECTURE.md) (Phase 2B, the API contract extended here).

---

## 0. What this phase is, and what it is not

Phase 4 builds the **structured clinical record** for an animal: cases, observations, symptoms,
diagnoses, consultations, treatments, medication administrations, vaccinations, quarantine,
withdrawal periods and laboratory results.

**It contains no artificial intelligence of any kind.** No model is called, no prediction is made,
no image is analysed, no risk is scored. Every clinical judgement stored by this system was entered
by a person and is attributed to that person. The purpose of the phase is to make the data
*trustworthy enough* that a later phase could reason over it — which is a data-quality problem, not
a modelling one.

The system is a **management and decision-support platform**. Veterinary judgement remains with the
veterinarian. This is stated in the code, on the pages where diagnoses are recorded, and in the API
payloads, not only here.

---

## 1. Architecture decisions

| Decision | Alternative rejected | Why |
|---|---|---|
| `health_cases` is a NEW table; `health_events` gains one nullable `case_id` | Convert `health_events` into the case table | An episode and a single dated note are different things. Converting would force every one of the four existing rows to be assigned a case status nobody knows. |
| Twelve normalised satellite tables | One `animal_health` table, or JSON columns | "Every animal treated with this product", "every unresolved suspected diagnosis" and "who is inside a withdrawal period today" must be SQL queries. A blob makes exactly the queries that matter impossible. |
| `cows.health_status` ENUM **widened**, never rewritten | New status column, or a rewritten ENUM | MySQL stores ENUM members by ordinal. Appending is safe; reordering silently remaps every existing row. The four original values keep their meaning. |
| `deceased` is NOT a health status | Add it alongside `sick`, `recovered` | Death is a lifecycle fact and `cows.lifecycle_status` already owns it. Two columns that can disagree about whether an animal is alive is the exact drift Phase 3 avoided. |
| Withdrawal is its own table, not columns on the administration | `withdrawal_milk_end`, `withdrawal_meat_end` columns | One medication commonly creates several restrictions with different end dates. Columns cannot express that, and getting it wrong puts unsafe product into the food chain. |
| External veterinarians are descriptive text | Provision every vet as a DFMIS user | A farm calls whichever practice is available. Requiring an account first would make the record unusable and drag an external-identity system into a health phase. |
| The timeline is a UNION over source tables | A duplicated `animal_events` store | Phase 3's principle, unchanged: two copies of the same fact drift, and the copy is always the wrong one. |

---

## 2. Existing components reused (audited, not assumed)

Nothing in this list was rebuilt.

| Component | How Phase 4 uses it |
|---|---|
| `cows` (Phase 3 animal master) | Every clinical record's `cow_id`. Still named `cows`; still not renamed. |
| `health_events` + `modules/health/add.php` | Preserved verbatim as the free-form **Event log** tab. Four real rows keep their page. |
| `cows.health_status` | Widened by two values; the four originals untouched. |
| `locations` (migration 004, Phase 3) | Quarantine isolation locations. No second location model. |
| `animalMoveWithinFarm()` (Phase 3) | Moving a quarantined animal, so the move appears in its transfer history too. |
| `stored_files` + `modules/files/serve.php` | Laboratory reports and veterinary documents. No new file table, no new storage path. |
| `alerts` | All five health alert types. No parallel alert queue. |
| `inventory_items` / `inventory_levels` | Medication stock consumption, using the same `FOR UPDATE` pattern `modules/inventory/update.php` already uses. |
| `DfmsValidator` | Every field on every form. No page-specific validation was written. |
| `tenantQuery/Find/Insert/Exec/Transaction` | Every read and write. No health function accepts a `farm_id`. |
| `requireAuth()` / `DFMS_PERMISSIONS` | Every page and both API routes. No local permission logic exists. |
| `auditLog()` and the Phase 1 credential deny-list | Every clinical mutation. |
| Phase 2 units architecture (`includes/units.php`) | Temperature and weight observations. |
| Phase 2 settings hierarchy (`farmToday()`, `fmt_*`) | Every date, number and measurement rendered. |
| Design system (`assets/css/style.css`, `includes/components.php`) | Every page. **Untouched** — Phase 4 adds only scoped rules under `.dfms-health-scope`. |
| API front controller + route allow-list | Two new explicit routes. No pattern router, no filesystem dispatch. |

### Audit findings that changed the implementation

1. **`/api/v1/health` was already taken** by the public, unauthenticated liveness probe. Overloading
   it would have exposed clinical data on a public endpoint or broken every load balancer pointing
   at it. The new routes are `/v1/animal-health/{id}` and `/v1/health-cases`.
2. **`cows.health_status` already existed** as `ENUM('healthy','at_risk','sick','treatment')` and is
   already read by `insights.php` and the dashboard. The status model extends it rather than
   introducing a competing column.
3. **There was no alert generator, and therefore no deduplication or auto-resolution mechanism** to
   follow. Every alert in the system had been typed in by a person. Phase 4 builds the smallest
   honest one (§16) and says so rather than claiming to reuse something that did not exist.
4. **Inventory had no stock ledger, no batch table and no expiry.** `inventory_levels` holds exactly
   one quantity row per item. This constrained the medication traceability design (§8, §9).
5. **`storage.php` accepted images only.** Laboratory reports are PDFs, so the file system required
   a contained extension (§14).
6. **`milk_production` is farm-level with no `cow_id`** — confirmed against the live schema, not
   taken from the Phase 3 summary. This is the limitation in §12 and §22.
7. **No AI code reads health data.** `includes/ai_service.php` exists but `aiComplete()` has zero
   call sites; `insights.php` is deterministic. Nothing was disturbed.

---

## 3. Database architecture

Twelve new tables, all tenant-owned, all registered in `DFMS_TENANT_TABLES`.

```
cows (Phase 3 animal master — unchanged name, widened health_status)
 │
 ├── health_status_events        history behind cows.health_status
 ├── health_cases ───────────┐   the clinical episode
 │    ├── health_symptoms    │   many per case
 │    ├── health_diagnoses   │   many per case, incl. ruled-out
 │    ├── health_treatments  │   many per case
 │    ├── laboratory_tests   │
 │    └── health_quarantines │
 ├── health_observations ────┤   case_id NULLABLE — routine monitoring needs no case
 ├── veterinary_consultations┤
 ├── medication_administrations  ──► withdrawal_periods
 ├── vaccination_records         ──► withdrawal_periods
 └── health_events (PRE-EXISTING, + nullable case_id)
```

**Nullable `case_id` is deliberate.** A routine weight, a scheduled vaccination and a
precautionary quarantine on a new arrival legitimately happen without an illness episode. Forcing a
case would pollute disease surveillance with cases that were never cases.

Every table:

- carries `farm_id` as its single tenant key (verified by a test),
- has `FOREIGN KEY (farm_id) REFERENCES farms ON DELETE RESTRICT`,
- has `FOREIGN KEY (cow_id) REFERENCES cows ON DELETE CASCADE`,
- leads its indexes with `farm_id` (the Phase 1 T-4 lesson),
- is registered in `DFMS_TENANT_TABLES` — an unregistered table is refused by `tenantFind()`
  and `tenantInsert()` by design, so an omission fails loudly at the first write.

`ON DELETE SET NULL` is used where a parent's removal must not erase a clinical fact: deleting an
inventory product must never delete the record that an animal received a medicine.

### Migration safety

`008_veterinary_health.php` is idempotent (verified: a second run reports 0 changes, 67 skips),
non-destructive, and invents nothing:

- no table dropped or renamed; `cows` is still `cows`
- no column removed; no historical value reinterpreted
- existing `health_events` rows keep `case_id = NULL` — factually true, no case existed
- existing animals gain **no** cases, diagnoses, treatments, vaccinations or restrictions
- `inventory_items.default_withdrawal_*_days` backfills to `NULL`, never `0` — a wrong default
  withdrawal period is the most dangerous value this migration could have invented

A new helper, `schemaEnsureEnumValues()`, widens an ENUM by **appending** to its existing member
list, preserving order and default. It can only ever widen.

---

## 4. Health status model

Registry: `DFMS_HEALTH_STATUSES` in `includes/health.php`. Column: `cows.health_status`.

| Value | Label | Origin | Needs attention |
|---|---|---|---|
| `healthy` | Healthy | pre-existing | no |
| `at_risk` | Under observation | pre-existing | yes |
| `sick` | Sick | pre-existing | yes |
| `treatment` | Under treatment | pre-existing | yes |
| `quarantined` | Quarantined | **new** | yes |
| `recovered` | Recovered | **new** | no |

The stored values of the four original states are unchanged; only their **labels** were clarified.
Renaming `at_risk` to `under_observation` would have reinterpreted every existing row.

**Lifecycle is authoritative over health.** `healthAnimalOr404()` refuses every clinical write
against an animal whose `lifecycle_status` is terminal (`sold`, `transferred`, `deceased`,
`retired`, `archived`). A deceased animal cannot become "recovered". The correction path is the
Phase 3 lifecycle page, which returns the animal to `active` and leaves the mistake visible in the
lifecycle history rather than erasing it.

**No silent changes.** Every status change writes a `health_status_events` row and updates the
column **in one transaction**, and is audited as `health.status_change`. Opening or closing a case
changes an animal's status only when explicitly asked to.

---

## 5. Health cases

`health_cases` — the clinical episode.

- `case_reference` (`HC-2026-0001`) is unique **per farm**, so one tenant's case count is not
  inferable from another's — the same reasoning that made animal identifiers per-farm unique.
- `status`: `open → monitoring → treating → resolved → closed` (and back).
- `outcome`: required when resolving or closing, and **`unknown` is a first-class choice**. Many
  real episodes end without a definitive answer; a system that refuses to record that will be given
  a fabricated outcome instead.
- `is_contagious` is the operator's judgement, recorded as theirs. DFMIS does not decide whether a
  condition is transmissible and does not isolate anything on its own.
- A case cannot be closed before the date it was opened.

## 6. Clinical observations

`health_observations`. The observation **type** is data (`DFMS_OBSERVATION_TYPES`), so a new
measurement is a registry entry rather than a migration. The **value** is split into
`value_numeric` + `unit` and `value_code` — never a free-text blob — so "was this animal's
temperature trending up" and "every animal with poor appetite" are both SQL.

Each type declares either:

- a **`kind`** — a DFMS measurement kind (`temperature`, `mass`). The value is entered in the farm's
  measurement system, converted through `dfms_to_canonical()`, and **stored canonically** (°C, kg).
- a **`scale`** — a coded vocabulary (`appetite`: `normal|reduced|poor|none`).

Types with a numeric value but no DFMS kind (heart rate in bpm, body condition score) store the
number with a plain unit label and are honestly not convertible — bpm has no other unit.

**An unsupported unit fails rather than converting.** `healthRecordObservation()` raises
`HealthRuleViolation` on an unknown unit instead of treating it as canonical. A silently
mis-converted body temperature is a plausible-looking number that is clinically wrong.

**Range checks run on the canonical value**, so 105 °F converts to 40.6 °C and passes, while 500 of
anything is rejected.

No historical data is reinterpreted: this is a new table, and Phase 2 already established that every
existing measurement column holds metric values.

## 7. Symptoms and diagnoses

`health_symptoms` and `health_diagnoses` — many per case.

Both use a **stable code registry with a single `other` escape hatch** that requires a free-text
description. That is controlled extensibility: an unrecognised sign is still recorded truthfully and
is visibly distinguishable from a coded one, rather than silently degrading the vocabulary.

The registries are deliberately **not presented as an international standard**. Aligning to one is
real work with real licensing questions and belongs in a later phase; the codes are stable, so a
future mapping is a translation table rather than a data migration.

Diagnosis status is `suspected | confirmed | ruled_out | resolved`. `ruled_out` is first-class — a
differential that was considered and excluded is clinically valuable, and discarding it makes the
record less honest, not tidier.

**`confidence` is the recording clinician's own stated confidence.** It is never computed, never
scored, and this software never generates it. The API field is named `stated_confidence` so a client
cannot present it as a system score. Absent confidence stays `NULL`, never inferred.

**A diagnosis is never rewritten into a different condition** — only its status may change. Editing
the condition would erase what was actually believed at the time.

## 8. Treatments and medication administration

`health_treatments` is a **plan with a lifecycle**; `medication_administrations` is a **single act**.
That separation is what lets "three injections over five days" be one treatment with three dose
records, rather than three treatments or one treatment with no dose history.

**Treatment history is never overwritten.** `completed` and `cancelled` are terminal and remain
visible; nothing deletes a treatment row. Completing requires an outcome (`unknown` accepted). A
completed or cancelled treatment cannot be re-closed.

`medication_administrations` records product, dose, dose unit, route, frequency, timestamp, actor,
batch/lot, expiry, and links to a treatment and a case.

- **`medication_name` is `NOT NULL` even when `item_id` is set.** The name at the time of
  administration is part of the clinical record, not a lookup — it must stay readable after the
  inventory item is renamed or removed.
- **Dose units are a medication vocabulary, not a DFMS measurement kind.** No conversion is
  attempted between ml and mg, because that depends on the product's concentration, which this
  system does not hold.
- **Nothing may be recorded meaningfully in the future.** `healthAssertNotFuture()` rejects a
  future administration timestamp, because it would create a withdrawal that has not started and
  leave the animal unflagged today. A generous timezone tolerance is allowed (the browser sends
  local wall-clock time; storage is UTC).

### Inventory integration

Implemented, and safe, within what the existing model supports.

Stock is consumed only when the caller asks **and** the product is a real inventory item on this
farm. Inside one `tenantTransaction()`:

1. `SELECT … FOR UPDATE` on the `inventory_levels` row, scoped by `item_id` **and** `farm_id` — the
   same pattern `modules/inventory/update.php` already uses, so two concurrent administrations
   cannot both read the same starting quantity.
2. Stock is never driven below zero; the whole administration is **refused** rather than recording
   negative stock.
3. The administration row, the withdrawal rows and the stock change all commit together, or none do.
   A test asserts that a refused administration leaves no medication row, no withdrawal and no stock
   change.
4. The stock movement is audited as `stock.adjust` as well, so the inventory trail shows why the
   quantity fell without anyone opening the health module.

## 9. Medication batch, expiry and traceability

**Audited first.** `inventory_items` had `item_name`, a free-text `item_type`, a free-text `unit`
and a reorder level. `inventory_levels` holds **one** quantity row per item. There was no stock
ledger, no batch table and no expiry anywhere.

**Added — the minimum, and why each is necessary:**

| Column | Reason |
|---|---|
| `inventory_items.category` | A normalised key beside the free-text `item_type`, so "is this a medicine" stops depending on spelling. The old column is untouched and still displayed. |
| `inventory_items.default_withdrawal_milk_days` | A withdrawal period is a property *of the product*. Without it, every administration needs it retyped, and a mistyped withdrawal is a food-safety failure. |
| `inventory_items.default_withdrawal_meat_days` | As above. |

**Deliberately NOT added: batch-level stock.** `inventory_levels` can hold exactly one quantity per
item, so a batch table would need a parallel stock model — a pharmacy ERP, explicitly out of scope.

**The resulting traceability chain, stated honestly:**

```
product (inventory_items)
  → batch/lot + expiry  (recorded ON THE ADMINISTRATION, as read off the container)
    → animal
      → treatment
        → withdrawal period
```

This is **complete for the animal record** — given a batch number you can find every animal that
received it. It is **incomplete for the stock ledger**: DFMIS cannot reconcile how much of a
specific batch remains, because inventory does not track stock by batch. Full batch-level stock
traceability is deferred (§23).

## 10. Vaccinations

One table, `vaccination_records`, for both the schedule and the record — a scheduled dose and the
dose that was given are the same clinical intention at two points in time, and splitting them
guarantees they drift.

**A scheduled vaccination is never promoted to administered by the passage of time.** No background
process, no cron, no page load does it. `administered_on` stays `NULL` until
`healthMarkVaccinationGiven()` is called by an explicit human action. An assumed vaccination is
worse than a missing one, because it stops anyone looking.

`missed` exists so a dose that did not happen is recorded as such rather than deleted.

**Corrections do not edit history.** `healthSupersedeVaccination()` marks the original `superseded`
and retains it, then writes a new row pointing back through `supersedes_id`. The mistake stays
visible, and the audit log records `vaccination.correct`.

**"No vaccination recorded" is not "unvaccinated".** The animal profile says so in those words: an
animal with no records has an *unknown* vaccination history in DFMIS. The API field is named
`recorded_vaccination_count` for the same reason.

## 11. Quarantine and isolation

`health_quarantines`. `location_id` references the **Phase 3 `locations` table** — there is no
separate quarantine map, because a second location model would immediately disagree with the first
about where an animal is. Choosing a location also calls `animalMoveWithinFarm()`, so the move
appears in the animal's transfer history.

Starting a quarantine sets `health_status = 'quarantined'` in the same transaction. Exactly one
active quarantine per animal is enforced; a second would make "is this animal isolated" ambiguous
and would double every alert.

**Releasing does not guess the animal's health status.** Coming out of isolation can mean recovered,
still under observation, or still sick but no longer contagious, and only the person releasing knows
which. If no status is supplied, the animal stays as it was (a test asserts this).

## 12. Withdrawal periods

`withdrawal_periods` — the food-safety record.

- One row per restriction type per source, because one medication commonly creates several
  restrictions with **different end dates** (milk 4 days, meat 28 days).
- `starts_on` / `ends_on` are **stored, not computed on read**: the withdrawal that applied is a
  fact about the day it was set, and must not change if a product's default is later corrected.
- **`ends_on` is INCLUSIVE.** A 4-day withdrawal starting on the 1st runs through the 4th and lifts
  on the 5th. This is asserted on exact dates in the test suite and stated in the API payload
  (`ends_inclusive: true`), because an off-by-one here puts unsafe product into the food chain.
- **A blank withdrawal creates NO restriction — never a zero-day one.** "Not specified" and "no
  withdrawal period" are different claims, and only a product label can make the second. The
  medication form states this prominently, and a test asserts it.
- Restriction types are filtered by species (`dfms_withdrawal_types_for_species()`), so cattle are
  not offered egg withdrawal. An **unknown** species gets only the universally applicable types.
- Cancelling a restriction requires a reason and is fully audited — lifting a food-safety
  restriction is exactly the action an investigation would want to trace.

### The production traceability limitation — stated plainly

> **`milk_production` stores a FARM-LEVEL daily total with no `cow_id`.**
>
> DFMIS therefore **tracks and surfaces** an individual animal's withdrawal restriction, but
> **cannot verify** that its milk was excluded from the farm total. There is no place in the data
> model to record whose milk went into the tank.

What Phase 4 does, honestly:

- ✅ per-animal withdrawal periods, correctly calculated and dated
- ✅ a farm-wide "Active restrictions" view listing exactly which animals are withheld
- ✅ the restriction shown on the animal's own profile as a badge with days remaining
- ✅ an alert when a restriction is about to end
- ✅ the limitation stated **on the withdrawals page itself**, not only in this document, because
  the person filling the bulk tank is the one who needs to know
- ✅ the API returns `withdrawal_enforcement: "advisory_only"` with an explanatory note

What Phase 4 does **not** do, and does not claim to:

- ❌ block a milk production entry for a restricted animal — there is no per-animal entry to block
- ❌ compute "safe" vs "unsafe" farm output
- ❌ assert anywhere that withdrawal is *enforced*

Real enforcement requires per-animal milk attribution. That is a production-traceability phase
(roadmap Phase 8), and building a fake gate now would be worse than having none: it would create
confidence that the data cannot support.

## 13. Laboratory results

`laboratory_tests` is an **integration point, not a laboratory information system**. It records what
was sampled, where it went, and what came back. It does not model specimens, analysers, panels or
accreditation.

Results split the same way observations do — `result_numeric` + `result_unit` for quantitative,
`result_code` for qualitative. Laboratory units are free text and are **not** converted: they are
not a DFMIS measurement kind.

Consistency rules: a `pending` test cannot carry a result; a result cannot be received before the
sample was taken.

## 14. File and document security

Reports go through the **Phase 1 file system** — same tenant scope, same `stored_files` table, same
access-controlled `modules/files/serve.php`, same soft-delete. No new table, no new storage path, no
direct file path anywhere in the health schema.

Three new `document_type` keys (`lab_report`, `prescription`, `vet_report`) on the existing column.
The `medical_images` storage category already existed and was unused; Phase 4 is its first consumer.

**PDF support** was the one genuine extension, since laboratory reports arrive as PDFs. It is
deliberately contained:

- `application/pdf` is the **only** non-image type, and only in the `documents` category — a PDF
  cannot be uploaded as an animal photo.
- Two independent content checks: `finfo` MIME sniffing **and** a `%PDF-` magic-byte check. The
  client's declared type and filename are still never trusted.
- The stored filename is still 32 random hex characters, so no URL can be derived from an entity id.
- The forbidden-extension list is unchanged and still applies.
- **A PDF is always served as `Content-Disposition: attachment`**, never inline. PDF supports
  embedded JavaScript and a browser's built-in viewer will run it; serving one inline would put
  attacker-influenced script in this origin. `X-Content-Type-Options: nosniff` and a
  `default-src 'none'; object-src 'none'; sandbox` CSP apply on top.
- `storeUploadedDocument()` is a **separate function** from `storeUploadedImage()`, not a flag on
  it. The two have genuinely different validation, and one function with two mutually exclusive
  halves and a boolean choosing which security checks run is how an upload validator eventually gets
  called with the wrong flag.

A failed report upload never loses the test record — the clinical fact is the sample, not the PDF.

## 15. Health timeline

The Phase 3 animal timeline is **extended, not replaced**. It remains a UNION over real domain
tables; no duplicate event store was created.

`healthTimelineEvents()` contributes: health status changes, case opened/closed, observations,
diagnoses, consultations, treatments, medication administrations, vaccinations, quarantine
start/end, laboratory samples and withdrawal starts. Each entry links to the record authoritative
for it.

**Scheduled vaccinations are excluded** — a future intention is not history, and putting one on a
timeline would misrepresent what happened. A test asserts this, and asserts that the dose *does*
appear once given.

`animalTimeline()` loads `includes/health.php` lazily inside the function, because `health.php`
requires `livestock.php` in turn; this keeps the load-time dependency one-directional while
guaranteeing the timeline is complete wherever it is rendered.

## 16. Alerts

Reuses the existing `alerts` table. No new alert store, no parallel queue.

**What Phase 1 actually left:** generic `(entity_type, entity_id)` targeting, a read/unread flag and
full CRUD in `modules/alerts/` — but **no generator**, and therefore no deduplication or
auto-resolution mechanism to follow. Every alert in the system had been typed in by a person. Phase
4 builds the smallest honest one and states that rather than claiming to reuse something absent.

Five alert types: vaccination due, treatment follow-up due, withdrawal period ending, quarantine
review due, unresolved urgent health case.

`healthSyncAlerts()` contract:

1. An alert is identified by `(alert_type, entity_type, entity_id)`. Syncing twice produces **one**
   row — a page load can never spam the queue. Verified by test and by an HTTP assertion.
2. An alert whose condition no longer holds is **auto-resolved**: marked read by the system, never
   deleted, so the history survives.
3. Only **unread** rows are touched. An alert a person has already acknowledged is never
   resurrected.
4. Anti-spam by design: the urgent-case alert fires only after 3 days open; the withdrawal alert
   only within 3 days of ending.

The sync runs on the health module page load, gated on `alerts.create`, so a viewer loading the page
does not perform a write on the farm's behalf.

## 17. Permissions

Only **two** new permissions. Most health actions are the same decision as an existing one.

| Permission | Roles | Status |
|---|---|---|
| `health.view` | farm_manager, veterinarian, staff, viewer | pre-existing, unchanged |
| `health.create` | farm_manager, veterinarian | pre-existing, unchanged — covers cases, diagnoses, treatments, consultations, lab tests, quarantine |
| `health.observe` | farm_manager, veterinarian, **staff** | **new** |
| `health.medicate` | farm_manager, veterinarian | **new** |

`health.observe` is deliberately **wider** than `health.create`: taking a temperature or noting that
an animal is off its feed is routine stockmanship. Locking staff out means it goes unrecorded, which
is worse than recording it.

`health.medicate` is the **narrowest** action in the module: it consumes controlled stock and
creates a food-safety restriction. Not granted to staff, accountants or viewers.

A `health.manage` permission was considered and rejected — its role list would have been identical
to `health.create`'s, which is two names for one decision and two places to get the matrix wrong.

Every page calls `requireAuth('<permission>')`. There is no local permission logic anywhere in
`modules/health/`.

## 18. Audit logging

Every clinical mutation is audited through the centralized `auditLog()`. No parallel audit table.

`health_case.create`, `health_case.update`, `health.status_change`, `health_symptom.create`,
`health_diagnosis.create`, `health_diagnosis.update`, `veterinary_consultation.create`,
`health_treatment.create`, `health_treatment.update`, `medication_administration.create`,
`vaccination.create`, `vaccination.update`, `vaccination.correct`, `health_quarantine.create`,
`health_quarantine.update`, `withdrawal_period.create`, `withdrawal.cancel`,
`laboratory_test.create`, `stock.adjust`, `file.upload`.

`includes/health.php` requires `includes/audit.php` as a **hard dependency**, deliberately not a
`function_exists()` guard: a conditional call would mean loading the file without audit.php silently
produces *unaudited medication records*, which is precisely the failure nobody would notice until an
investigation needed the trail.

The Phase 1 recursive credential deny-list still applies to every health payload (asserted by test).
Veterinarian contact details are recorded in the clinical record but are **not** written into audit
payloads.

## 19. Tenant isolation

Non-negotiable, and unchanged from Phase 1.

- Every health table carries `farm_id` and is registered in `DFMS_TENANT_TABLES` (both asserted by
  tests).
- Every read uses `tenantQuery`/`tenantFind`; every write uses `tenantInsert`/`tenantExec`. **No
  function in `includes/health.php` accepts a `farm_id`**, and `tenantInsert()` ignores a
  caller-supplied one (asserted by test).
- A foreign or missing resource is **404, never 403** — deliberately indistinguishable.
- Every join to `cows` is additionally constrained on `c.farm_id = x.farm_id`, so a row whose
  `farm_id` ever drifted from its animal's cannot surface.
- **Cross-resource ownership is checked separately from tenancy.** A case id can name a real case on
  the *same farm* but a *different animal*; `healthResolveCaseId()`, `healthResolveTreatmentId()`,
  `healthResolveDiagnosisId()` and `healthResolveConsultationId()` all verify both. `tenantFind()`
  alone does not make this check.
- An inventory item, location or consultation arriving from a form is resolved through
  `tenantFind()` before use — never trusted.
- `vet_user_id` is set only after confirming the id is a member of the active farm, verified against
  `farm_users`.

Tested explicitly: cross-farm ids in POST bodies and URLs, malformed id probes
(`1 OR 1=1`, `-1`, `0`, `abc`, `1.5`, `'`), a tampered session `farm_id`, a foreign inventory item,
and cross-tenant API access in both directions.

## 20. API

Extends the existing `/api/v1/` front controller. Same explicit route allow-list, same
`requireAuth()`, same JSON envelope, same direct-execution guard. No new framework.

| Route | Methods | Auth | Permission |
|---|---|---|---|
| `/v1/animal-health/{id}` | GET | yes | `health.view` |
| `/v1/health-cases` | GET | yes | `health.view` |
| `/v1/health-cases/{id}` | GET | yes | `health.view` |

**Read-only.** Phase 4 adds no write endpoints, exactly as Phase 2B added none for animals.

`/v1/health` remains the **public liveness probe** and is untouched — a test asserts it still works
and exposes no clinical data.

Both handlers call the same functions the HTML pages use, so the API cannot drift from the UI or
acquire its own interpretation of tenant scope. Responses use an explicit field allow-list;
`farm_id` is never echoed. Laboratory report **files** are never exposed — bytes are reachable only
through `serve.php`, which re-checks tenancy and `files.view`. Veterinarian contact details and
registration numbers are deliberately omitted from the API: no consumer has a demonstrated need for
a third party's personal contact details.

## 21. AI readiness — data only

No AI is implemented. No provider, no model, no LLM, no vision, no prediction, no external call.

What a future phase can consume, because it is now row-shaped, coded and queryable:

| Signal | Source |
|---|---|
| species, breed, sex, age, lifecycle | `cows` (Phase 3) |
| current + historical health status | `cows.health_status`, `health_status_events` |
| symptoms with severity and onset | `health_symptoms` (coded) |
| observations incl. temperature, in canonical units | `health_observations` |
| diagnosis history with epistemic status and stated confidence | `health_diagnoses` |
| treatment and outcome history | `health_treatments` |
| medication exposure with dose, route and batch | `medication_administrations` |
| vaccination history and schedule | `vaccination_records` |
| laboratory results, numeric and coded | `laboratory_tests` |
| quarantine history | `health_quarantines` |
| active product restrictions | `withdrawal_periods` |

Design properties that make this safe to reason over later:

- **Uncertainty is explicit.** `suspected` / `ruled_out`, `outcome: unknown`, `confidence: NULL`.
  A future model reads what was actually known, not a flattened certainty.
- **Absence is distinguishable from negation.** No vaccination record means *unknown*, not
  *unvaccinated*. No withdrawal means *not specified*, not *zero days*.
- **Units are canonical and unambiguous**, so a temperature series is comparable across farms.
- **Provenance is complete** — every record carries its actor and timestamp.

When AI is built, the contract is already documented: it must provide risk indicators, possible
conditions, an explanation, a confidence and a recommended next action — **for veterinary review**.
It must not become an autonomous diagnosis engine. Nothing in this phase pre-commits the schema to
any particular model.

## 22. Disease detection readiness

The structures a future detection phase would need exist and are general:

- coded symptoms and conditions with group and contagiousness metadata, enabling clustering
- `health_diagnoses` indexed on `(farm_id, condition_code, status, diagnosed_on)` for surveillance
- `laboratory_tests` indexed on `(farm_id, test_type, result_code, sample_taken_on)`
- `health_cases.is_contagious` and the `notifiable` condition group
- `stored_files` already accepts images against any entity, with `medical_images` as a category and
  `observation` as a photo category — so image ingestion needs no new file architecture

**Not implemented, and deliberately so:** IoT ingestion, sensor pipelines, camera integration, image
analysis, outbreak models. The schema is **not** designed around one future model — the domain
architecture is general, which is what keeps a later modelling decision cheap.

## 23. Known limitations

1. **Withdrawal is advisory, not enforced.** (§12) `milk_production` is farm-level; per-animal
   attribution does not exist. Stated on the page, in the API and here.
2. **Inventory has no batch-level stock.** (§9) Batch and expiry are captured on the administration,
   which completes the animal-side chain but not the stock ledger.
3. **No stock ledger at all.** `inventory_levels` holds a current quantity; the only record of *why*
   it changed is the audit log and the `medication_administrations` row.
4. **Symptom and condition registries are pragmatic, not an international standard.** Codes are
   stable so a future mapping is a translation table.
5. **`health_events` and `health_cases` coexist.** The legacy free-form log is preserved and still
   writable. This is intentional, but it does mean two places a health note can live.
6. **Cost fields are recorded but not aggregated.** `veterinary_consultations.cost_amount` exists;
   nothing sums it. Finance is a later phase.
7. **Health alerts are generated on page load**, not by a scheduler. A farm nobody visits generates
   no alerts. A background runner is a scheduling concern, not a health one.
8. **No per-animal milk, feed or weight trend correlation.** Phase 8.
9. **Observation ranges are single global bounds**, not species- or age-specific. A calf and an adult
   cow share a temperature range. Refining this needs veterinary reference data.
10. **No mortality or post-mortem workflow beyond the lifecycle `deceased` state and a `post_mortem`
    laboratory test type.**

## 24. Deferred work

| Item | Phase |
|---|---|
| Per-animal milk production, enabling real withdrawal enforcement | 8 |
| Batch-level inventory stock and a stock movement ledger | 9 |
| AI health insights, risk indicators, decision support | 5 |
| Image-based disease detection | 6 |
| IoT / sensor observation ingestion | 11 |
| Scheduled alert generation (background runner) | 10 |
| International disease coding standard alignment | 12 |
| Health cost tracking and reporting | 9 |
| Reproduction and breeding health | 8 |
| Species- and age-specific observation reference ranges | later |
| Practitioner registry and prescription management | later |

---

## Verification

| Gate | Result |
|---|---|
| PHP lint | 102 files, 0 failing |
| PHPStan level 5 | 0 errors, baseline still empty |
| PHPUnit | 160 tests, 546 assertions |
| Full unified runner | **10/10 suites, 1159 assertions, 0 failing, 0 skipped** |
| Migration 008 | applied; second run reports 0 changes, 67 skips (idempotent) |
| Test database | isolated `dfms_test`; every Phase 4 mutation test runs against it |

Phase 4 added **429 assertions** to the Phase 3 baseline of 730.
