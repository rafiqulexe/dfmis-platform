# DFMIS Animal Digital Twin

**Phase:** 3 — Livestock Core
**Status:** Foundation implemented and verified. 9 suites, 730 assertions, PHPStan level 5 with an empty baseline.

**Companion documents:** [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md),
[`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](./DFMIS_INTERNATIONAL_ARCHITECTURE.md),
[`DFMIS_API_ARCHITECTURE.md`](./DFMIS_API_ARCHITECTURE.md),
[`DFMIS_ENGINEERING_FOUNDATION.md`](./DFMIS_ENGINEERING_FOUNDATION.md),
[`DFMIS_ROADMAP.md`](./DFMIS_ROADMAP.md).

---

## 1. Purpose

An animal is not one database row. It is an identity, a classification, a
lifecycle, a location, an owner, a pedigree, a set of documents, and everything
that has ever happened to it.

Phase 3 turns the flat `cows` record into that structure — the **foundation**
future veterinary, breeding, production and AI work will read from.

### Implemented now vs future foundation

| Capability | Status |
|---|---|
| Animal master record with classification and lifecycle | **Implemented** |
| Multiple typed identifiers (RFID, registry, microchip…) | **Implemented** |
| Lifecycle state machine with full history | **Implemented** |
| Internal movement and inter-farm transfer | **Implemented** |
| Ownership history | **Implemented** |
| Parentage — internal and external parents | **Implemented** |
| Documents and photos, tenant-scoped | **Implemented** |
| Animal timeline | **Implemented** |
| Search, filtering, pagination | **Implemented** |
| Read-only animal API | **Implemented** |
| Multi-species classification | **Foundation** — model exists, only cattle is exercised |
| Genetics | **Foundation** — a registry id and free-text notes. No analysis |
| Per-animal production | **Not built** — production is farm-level; Phase 8 |
| Breeding / reproduction module | **Not built** — Phase 8 |
| AI disease detection, vision, ML | **Not built, not started** — see §20 |

---

## 2. Animal identity architecture

### `cows` is the animal master record

The existing table was **evolved, not replaced**. It already carried `farm_id`,
`location_id`, `sex`, `breed`, `date_of_birth`, `deleted_at` and foreign keys to
`farms` and `locations` — it *was* the animal master record under a legacy name.

Renaming it to `animals` would have broken 12 source files and two foreign keys
(`alerts.cow_id`, `health_events.cow_id`) for no functional gain. The brief was
explicit: prefer evolution over replacement.

**The table name is legacy. The structure is sound.** New code refers to
*animals* and calls `animalFind()`, `animalProfile()`, `animalSearch()`; the
table name is confined to `includes/livestock.php` and the pre-existing modules.

### Structure

```
cows (animal master record)
 ├── animal_identifiers        RFID, registry, microchip, national ID
 ├── animal_lifecycle_events   state transition history
 ├── animal_transfers          movement, internal and inter-farm
 ├── animal_ownership          ownership periods
 ├── stored_files              documents and photos (Phase 1 storage, reused)
 ├── health_events             existing module, referenced not duplicated
 └── locations                 existing tier (migration 004), reused
```

Three things were deliberately **not** created: a documents table, a photos
table, and a timeline table. `stored_files` already provides tenant-scoped,
access-controlled file storage, and the timeline is a query — duplicating events
would create two sources of truth that drift, and the copy is always the one
that is wrong.

---

## 3. Identifier strategy

| Type | Scope |
|---|---|
| `ear_tag` | farm |
| `rfid` | national |
| `microchip` | national |
| `national_id` | national |
| `breed_registry` | registry |
| `management` | farm |
| `other` | farm |

An animal's **primary** identifier remains `cows.unique_id`, unique per farm
(`uq_cow_tag_per_farm`, from Phase 1). Additional identifiers live in
`animal_identifiers`, each with a type, value, optional issuing authority,
optional issue date, and a status (`active` / `inactive` / `lost` / `replaced`).

### Uniqueness is PER FARM — and this is a security decision

```sql
UNIQUE KEY uq_identifier_per_farm (farm_id, identifier_type, identifier_value)
```

A global unique index would let one tenant discover another tenant's animal by
watching an insert fail. That is exactly the enumeration leak the baseline audit
recorded as **T-1 (Critical)** and Phase 1 fixed on `cows.unique_id`; it must not
be reintroduced through a new table.

**The trade-off is real and accepted:** a genuinely national identifier could be
duplicated across two farms in this database. Detecting that requires a
cross-tenant authority the platform does not have, and inventing one would
breach tenant isolation. A test asserts both halves — a duplicate within a farm
is rejected, the same value on another farm is permitted.

Search matches identifier values, but its subquery is scoped to the same farm,
so searching an RFID can never confirm that the tag exists elsewhere.

---

## 4. Multi-species strategy

`DFMS_SPECIES` in `includes/livestock.php` is a closed registry — cattle,
buffalo, goat, sheep — rather than an ENUM, so adding a species is a code change
rather than a migration. Each entry declares its own vocabulary:

```php
'goat' => ['label' => 'Goat', 'young_label' => 'Kid',
           'female_label' => 'Doe', 'male_label' => 'Buck',
           'dairy_capable' => true, 'gestation_days' => 150],
```

A goat has does and bucks, not cows and bulls. `dfms_animal_sex_label()` is the
one place that knows this, so labels stop being hardcoded per page.

**Breed stays free text.** Local and regional breeds are endless; a closed list
would either reject real breeds or force users to mislabel their animals.
`DFMS_BREED_SUGGESTIONS` powers a `<datalist>` — suggestions, not a constraint.

**Existing data:** migration 007 backfilled `species = 'cattle'`. That is a
statement of fact — the table is `cows`, the application is a dairy system, and
every seeded breed is a cattle breed — not a guess. Only cattle is exercised
today; the other species are declared so the model's shape is visible and
testable.

---

## 5. Animal lifecycle

```
              ┌──────────► sold
              │
   active ────┼──────────► transferred
              │
              ├──────────► deceased
              │
              ├──────────► retired
              │
              └──────────► archived

   any terminal state ──► active   (correcting a mistake only)
```

`active` is the only state an animal can leave freely. Every other state is
terminal for day-to-day management.

**Terminal does not mean immutable.** A mistake is corrected by transitioning
back to `active`, which leaves the error visible in the history rather than
erasing it. Terminal states cannot move sideways to each other: `sold` →
`deceased` would describe a sequence that did not happen.

`animal_lifecycle_events` is append-only by convention and records previous
state, new state, date, reason, notes and actor.

### Integration with the existing soft delete

A terminal status also sets `deleted_at`, and returning to `active` clears it.
`deleted_at` is the flag every pre-Phase-3 query already filters on, so keeping
the two in step is what let the lifecycle be added **without changing a single
existing list page**. Both writes happen in one transaction — a status column
that disagrees with its own history is worse than either being missing.

---

## 6. Location architecture

```
Organization → Farm → Location → Animal
```

The `locations` table from migration 004 already provided this tier, with
`location_type` covering barn, house, pond, field, greenhouse, paddock and
other. **No new location table was created.**

`cows.location_id` (also pre-existing) links an animal to its location.
Assignment goes through `animalMoveWithinFarm()`, which resolves the destination
via `tenantFind()` — a crafted `location_id` cannot place an animal in another
farm's barn, and a test asserts it.

Phase 3 deliberately did not build a farm GIS: no coordinates per pen, no
capacity enforcement, no occupancy map. Migration 006 added lat/long to
`locations` for future use.

---

## 7. Transfer architecture

Two operations, deliberately distinguished:

| | Internal movement | Inter-farm transfer |
|---|---|---|
| Changes | `location_id` | **`farm_id` — the tenant key** |
| Permission | `animal.transfer` | `animal.transfer` |
| Guards | destination resolved via `tenantFind()` | membership **and** same-organization |

### Inter-farm transfer guards, all required

1. The animal resolves inside the **current** tenant (`animalFindOr404`)
2. The destination farm is in `allowedFarmIds()` — live membership, the same
   check `requireAuth()` uses
3. The destination farm is in the **same organization**, so an animal cannot move
   between customers even by someone who belongs to both
4. Everything happens in **one transaction** — a half-transferred animal would
   be visible to neither farm, or to both

Denials are audited via `auditSecurityEvent()`. `location_id` is cleared on
transfer, because a location belongs to a farm and keeping it would point at a
barn on the farm the animal just left.

**Tenant key of the transfer row** is the *destination* farm, so history follows
the animal. A single row cannot be scoped to two tenants; scoping it to the
destination keeps it consistent with where the animal now is.

---

## 8. Ownership foundation

`animal_ownership` records `owned`, `leased_in`, `leased_out`, `managed`,
`partnership` or `boarding`, with an owner name and effective dates.

An animal on a farm is not necessarily *owned* by that farm — leasing, contract
rearing and boarding are ordinary in livestock operations.

Recording a change closes the open period and opens a new one **in one
transaction**, so an animal never has two concurrent owners. Exactly one open
period is an application invariant, not a database constraint: MySQL cannot
express "at most one NULL per group" without a trigger, and a trigger would hide
the rule from anyone reading the code.

**Deliberately minimal.** No legal-entity model, no valuation, no share
percentages — those are a finance concern (Phase 9) and would be speculative.

---

## 9. Parentage

A parent may be **inside** DFMIS (`dam_id` / `sire_id`) or **outside** it
(`dam_external` / `sire_external`).

> An external sire is **never** forced to exist as a fake internal animal. Doing
> so would pollute the herd count, the dashboard and every list page with animals
> the farm does not own. A test asserts that recording an external sire creates
> exactly one row — the calf.

`animalValidateParent()` enforces, in order:

1. the parent resolves **inside this tenant** (via `tenantFind()`)
2. it is not the animal itself
3. its **sex matches the role** — a bull as dam is a data error that would corrupt
   every breeding calculation built on it
4. it is not a **descendant** of the animal, which would make the pedigree a cycle

The descendant walk is depth-limited to 20 generations: far beyond any real
pedigree, and it guarantees termination even if existing data already contains a
cycle.

`ON DELETE SET NULL` on both parent FKs — removing a parent must not cascade
away its offspring. Losing a pedigree link is recoverable; losing the calf is not.

Cross-farm parentage is **not** constrained at the database level, because a
purchased animal legitimately has parents on another farm. Safety is enforced in
the application, where a parent id from a form can only resolve to an animal the
user can already see.

---

## 10. Genetics foundation

Two fields only: `cows.breed_registry_id` (herd book / breed society number) and
`cows.genetic_notes` (free text).

**No genomic analysis, no DNA interpretation, no breeding prediction, no
inbreeding coefficients.** Full genetics belongs to a later phase and needs
reliable pedigree data first — which is what §9 establishes.

---

## 11. Documents

Animal documents reuse the **Phase 1 storage architecture** unchanged. No second
file system was created; that would have been a security regression.

`stored_files` gained `document_type`, `caption` and `captured_on`. Document
kinds: registration, vaccination, purchase, health, pedigree, insurance, other.

Every byte a user receives still passes the Phase 1 gate in
`modules/files/serve.php`: authenticated → tenant context → `files.view` →
`tenantFindOr404()` on the record → path containment check → served with
`nosniff` and a restrictive CSP. Files live outside the web root and
`storage/.htaccess` denies direct access.

Upload goes through `storeUploadedImage()`, which performs content-sniffed MIME
validation cross-checked with `finfo`, a real on-disk size check against the 8 MB
cap, a random filename, and ownership registration. `modules/cows/files.php` is a
thin wrapper that adds the animal link and document type — it re-implements
none of that.

---

## 12. Photos

Same storage path, `animal_photos` category, with a purpose: `profile`,
`identification`, or `observation`.

> **This is not AI disease detection.** No image analysis of any kind is
> performed. Photos are structured, tenant-scoped, dated data so that a future
> vision capability has something reliable to work from — see §20.

The profile page uses the `profile` photo as the animal's avatar, with `alt`
text naming the animal.

---

## 13. Animal timeline

A **union over existing tables**, not a duplicate event store:

| Source | Event |
|---|---|
| `cows.created_at` | Animal registered |
| `animal_lifecycle_events` | Status changed |
| `animal_transfers` | Moved / transferred |
| `animal_ownership` | Ownership changed |
| `health_events` | Health event |
| `stored_files` | Document or photo added |

Tenant-scoped through `tenantQuery()`, newest-first, paginated.

### Timeline vs audit log — different purposes

| | Audit log | Timeline |
|---|---|---|
| Answers | *who touched what* | *what happened to this animal* |
| Audience | compliance, investigation | the person looking after the animal |
| Contains | security events, denied access, field diffs | business events |
| Scope | the whole farm | one animal |

Both exist; neither replaces the other.

**Milk production is deliberately absent.** `milk_production` records a
farm-level daily total and carries no `cow_id`, so there is no per-animal figure
to show — attributing the whole herd's output to every animal would be a
fabrication. The UI says so explicitly. Per-animal yield is Phase 8.

---

## 14. Integration with health

`health_events.cow_id` already existed. The profile's Health tab shows the five
most recent events and links to the health module for the rest — a summary and a
reference, not a copy.

The join is constrained on both `cow_id` **and** `farm_id`, so a health row whose
tenant key ever drifted from its animal's cannot surface.

---

## 15. Integration with production

**Honest position: there is no per-animal production data to integrate.**

`milk_production` has `farm_id`, `production_date` and `total_milk_yield` — no
animal reference. Phase 3 did not add one, because doing so means designing
milking sessions, parlour integration and yield attribution, which is Phase 8's
scope.

The timeline and profile therefore show no production, and say why rather than
showing a misleading zero.

---

## 16. Search and filtering

`animalSearch()` supports free-text search across tag, name, breed and
**identifier values**, plus filters for species, sex, lifecycle status, breed and
location, with pagination and an explicit "include archived" toggle.

Archived animals are hidden by default.

**Every query is tenant-scoped** through `tenantQuery()`; there is no code path
that reads animals across farms. Tests assert that searching for the other
farm's animal by tag returns nothing.

### Indexes — added from actual query patterns, not speculatively

| Index | Serves |
|---|---|
| `idx_cows_farm_lifecycle (farm_id, lifecycle_status, deleted_at)` | the default list and status filter |
| `idx_cows_farm_species (farm_id, species, deleted_at)` | species filter |
| `idx_cows_farm_name (farm_id, name)` | ordering and name search |
| `idx_cows_dam`, `idx_cows_sire` | offspring lookups |
| `idx_identifier_search (farm_id, identifier_value)` | identifier search |

Every one leads with `farm_id`, because every tenant read filters on it first —
the T-4 lesson from the baseline audit.

---

## 17. Tenant isolation

Unchanged from Phase 1 and reused, not reimplemented:

- reads via `tenantQuery()` / `tenantFind()`, writes via `tenantInsert()` /
  `tenantExec()` / `tenantTransaction()`
- **cross-tenant misses return 404, never 403**
- no function accepts a farm id from the caller
- new tables registered in `DFMS_TENANT_TABLES` — the repository refuses
  unregistered tables, which caught this exact omission during development

Verified: profiles, lifecycle, transfer, identifiers, files, search and the API
all return 404 for another tenant's animal, in both directions.

---

## 18. Permissions

Only **two** new permissions, because the rest already existed:

| Permission | Roles | Why it is distinct |
|---|---|---|
| `animal.lifecycle` | farm_manager, veterinarian | Declaring an animal sold, dead or retired is a herd management call, and a vet is the right person to record a death. Also covers ownership. |
| `animal.transfer` | farm_manager | Moves an animal's `farm_id` — its tenant key. |

Viewing, creating, editing and archiving reuse `cows.view/create/edit/delete`;
files reuse `files.view/upload/delete`. Creating `animal.*` duplicates would be
permission bloat with two places to get the matrix wrong.

Every page enters through `requireAuth()`. Viewers remain read-only — asserted by
test for lifecycle, transfer, identifiers and uploads.

---

## 19. Audit logging

Every significant mutation writes through the Phase 1 audit layer:

`animal.create` · `animal.lifecycle_change` · `animal.move` · `animal.transfer` ·
`animal.ownership_change` · `animal.identifier_add` · `animal.identifier_remove` ·
`animal.file_upload` · `animal.transfer_denied` (security event)

Metadata only — never file bytes, credentials or secrets. The Phase 1 recursive
credential deny-list applies unchanged.

---

## 20. API exposure

```
GET /api/v1/animals        list, with search, filters and pagination
GET /api/v1/animals/{id}   one animal with identifiers, parentage, location
```

Read-only. Requires `cows.view`. Reuses `animalSearch()` and `animalProfile()` —
the same functions the HTML pages use — so the API cannot drift from the UI or
acquire its own interpretation of tenant scope.

Fields are an **explicit allow-list**. `farm_id` is deliberately absent: the
caller's farm is implied by their session, and echoing a tenant key invites
clients to send one back. Stored paths, `deleted_at` and internal columns are
never exposed; tests assert their absence.

The route table entry opts into a single trailing id segment. A deeper path
(`/v1/animals/1/private`) does not match and returns 404.

---

## 21. AI data readiness

> **No AI, machine learning, computer vision or disease detection exists in
> DFMIS. None was built in this phase. This section describes only the data
> foundation those capabilities would need.**

Phase 3 exists partly because AI features are only as good as the records
underneath them. What is now in place:

| Foundation | Why a future AI capability needs it |
|---|---|
| Stable animal identity with typed identifiers | An observation must attach to one unambiguous animal |
| Lifecycle history, not just current state | "When did this change?" is the basis of anomaly detection |
| Parentage with sex and cycle validation | Genetic and breeding inference needs a pedigree that is not self-contradictory |
| Dated, categorised photos | Vision work needs labelled images with provenance |
| Health events linked per animal | Diagnostic support needs the animal's actual history |
| Timeline correlating events | Most useful signals are sequences, not points |
| Canonical metric units (Phase 2A) | A model cannot learn from mixed kg and lb |
| Farm timezone (Phase 2A) | "Yesterday" must mean the farm's yesterday |
| Tenant isolation everywhere | Training or inference must never cross a customer boundary |
| Audit logging on every mutation | An AI-influenced decision must be traceable |

### Non-negotiable constraints for any future AI work

1. **Tenant-safe access.** Data must be read through the same tenant helpers.
   A model must never see another customer's animals.
2. **Human review.** AI must not silently make irreversible animal health
   decisions. A suggestion is a suggestion; a treatment is a person's call.
3. **Auditability.** Any AI-derived recommendation must record its inputs and be
   attributable.
4. **Honest degradation.** Phase 1's rule stands: an unavailable model must never
   look like a confident answer.
5. **Confidence and referral.** Diagnostic support below a confidence threshold
   must refer to a veterinarian, not guess.

### Known gaps before AI is viable

- **Per-animal production data does not exist** (§15). Yield prediction is not
  possible from farm-level totals.
- **No structured weight or body-condition history.**
- **No reproduction records** — no heat, insemination, pregnancy or calving data.
- **Photo volume is zero.** Vision needs a labelled corpus that does not yet exist.

---

## 22. Remaining risks and deferred work

### Accepted

| Risk | Why acceptable | Revisit |
|---|---|---|
| Table is still named `cows` | Renaming breaks 12 files and 2 FKs for no functional gain. New code says *animal*. | Only with a compelling reason |
| National identifiers unique per farm, not globally | Global uniqueness is a cross-tenant enumeration leak (§3) | Needs a real registry integration |
| Only cattle exercised | Other species are modelled, not used. Building their modules would be speculative | When a second species is onboarded |
| Ownership single-open-period is an app invariant | MySQL cannot express it without a trigger, which would hide the rule | If ownership grows |
| `dairy_capable` shown for all four species | Cattle, buffalo, goat and sheep are all milked somewhere | — |
| Timeline paginates in PHP | Five sources with different shapes; row counts are small | If an animal accumulates thousands of events |

### Deferred

- **Per-animal milk yield** (Phase 8) — the largest data-model gap in the product.
- **Reproduction and breeding module** (Phase 8).
- **Weight and body-condition history.**
- **CSV import/export.** Identifiers and canonical units are now well defined, so
  an export is straightforward; a destructive bulk import was explicitly out of
  scope for this phase.
- **Location capacity and occupancy.**
- **Test isolation for the four pre-Phase-3 HTTP suites** — Phase 3's own suite is
  isolated; the older ones still use the development database.
- **Genetics beyond a registry id and notes.**

### Not started

Animal Digital Twin *analytics* · AI gateway · LLM integration · disease
detection · computer vision · IoT · payment processing · mobile applications.
