# DFMIS — Production & Feeding Architecture

**Scope:** per-animal milk production, feed consumption, rations, deterministic
analytics and operational alerts.
**Schema:** migration `012_production_and_feeding.php`.
**Companion documents:** [`DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md`](./DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md)
(withdrawal semantics), [`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](./DFMIS_INTERNATIONAL_ARCHITECTURE.md)
(canonical units), [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md)
(tenancy).

---

## 1. The one idea this phase is built on

**A farm daily total and a sum of per-animal readings are different
measurements, and they are never reconciled.**

| | measures | table | example |
|---|---|---|---|
| Farm daily aggregate | what the bulk tank held | `milk_production` | "the farm produced 1,250 L on 12 March" |
| Raw operational record | what one animal gave at one milking | `milk_yields` | "A17 gave 12.5 L at the morning milking on 12 March" |

A farm total **does not decompose**. If a farm recorded 1,250 L on a day with no
per-animal entries, the honest per-animal answer is *not recorded* — never
`1250 / 40 animals`. Every analytics function returns which source a number came
from, and the UI labels each metric, so a reader never has to guess.

The two figures can legitimately disagree: a farm may record a bulk total and
only some of its animals individually. `productionSummary()` returns both in
separate blocks and does no arithmetic between them.

---

## 2. Historical compatibility

Nothing existing was altered, renamed or reinterpreted.

- `milk_production` — 30 historical rows, `UNIQUE(farm_id, production_date)`,
  still written by `modules/milk/add.php` and edited by `edit.php`. It is now
  the "Farm totals" tab.
- `feed_consumption` — 30 historical rows of farm-level daily totals with **no
  `item_id`**. Left entirely alone. Item-level events live in a new table and
  are never summed into it.
- `feeding_schedules` — the daily routine, unchanged, now the "Schedule" tab.

**Why satellites rather than `ALTER`.** Adding a nullable `cow_id` to
`milk_production` would give one table two meanings — NULL for a farm total,
set for one animal — and every future `SUM()` would double-count from the day it
was first used both ways. The existing rows cannot be migrated into the new
meaning either, because a farm total genuinely does not decompose. That is the
fabrication this phase is forbidden to commit.

---

## 3. Production model

```
milk_yields
  farm_id, cow_id, production_date, session
  quantity          canonical LITRES
  discarded         canonical litres withheld at the parlour
  abnormal          stockperson's observation, not a diagnosis
  notes, recorded_by, recorded_at, updated_at
  UNIQUE (farm_id, cow_id, production_date, session)
```

**Sessions** are a closed set — `morning`, `midday`, `evening`, `other`. Each
value added is a new way for one milking to be recorded twice, so the set is
deliberately small.

**Uniqueness** is `(farm, animal, date, session)`. Safe to enforce because the
table is new — no historical workflow can be invalidated by it — and it is the
correct domain rule: an animal is milked once per session, and a second row is a
double entry, the commonest recording error this workflow will see. A correction
is an **edit**, which keeps the audit trail showing what changed rather than
leaving two contradictory rows.

Feed consumption deliberately has **no** uniqueness constraint: feeding the same
item to the same target twice in a day is ordinary practice.

**Units** follow the Phase 2 rule without exception. Milk is stored in canonical
litres, feed in canonical kilograms; conversion to the operator's display unit
happens only at the form and the renderer.

**Zero is refused, not stored.** "Nothing was recorded" and "this animal
produced nothing" are different claims, and a zero row would silently make the
second one, dragging every average down.

---

## 4. Withdrawal interaction

**Behaviour: record, flag, exclude from saleable. Never block.**

This follows the existing Phase 4 semantics rather than inventing a medical
rule. `healthFarmActiveWithdrawals()`'s own docblock has always said the
restriction is *"a WARNING, not an enforcement gate"* — Phase 6 is the first
code to actually honour it in the production workflow. Milk that was drawn
exists; refusing to record it would make the record less true, not safer.

**Restriction is computed, never copied.** There is no `restricted` column. Every
read joins `withdrawal_periods` on the production date:

```sql
EXISTS (SELECT 1 FROM withdrawal_periods w
         WHERE w.farm_id = y.farm_id AND w.cow_id = y.cow_id
           AND w.status = 'active' AND w.restriction_type = 'milk'
           AND w.starts_on <= y.production_date AND w.ends_on >= y.production_date)
```

A stored flag would be a second answer to a question the health domain already
owns, and it would be **wrong** the moment a withdrawal was cancelled,
corrected or back-dated — which is exactly when the answer matters. Cancelling a
withdrawal immediately corrects every historical record it covered; this is
asserted by test.

**Saleable volume** distinguishes two different rules:

- a **withdrawal** zeroes the whole session — the restriction is on the animal's
  milk, so none of what it gave is saleable;
- a **discard** subtracts only what was discarded — withholding half a litre of
  foremilk does not make the other nine unsaleable.

---

## 5. Quality

No laboratory system was created. `laboratory_tests` (migration 008) already
owns lab chemistry, and duplicating it would create a second place to look for a
result. Only the two lightweight fields a milking actually produces were added
inline: `discarded` and `abnormal`. Fat, protein, SCC and bacterial counts
belong to `laboratory_tests` and are deferred.

---

## 6. Feed and the single stock ledger

**Feed is inventory.** `inventory_items.category IN ('feed','supplement')`
(Phase 4). There is no feed-items table.

**There is no inventory transaction table, and that is deliberate.** The
established movement pattern, set by Phase 4's `healthAdministerMedication()`,
is:

```
SELECT … FOR UPDATE      lock the level row, scoped by farm_id
check                    refuse a move that would go below zero
UPDATE inventory_levels  through tenantExec, so {scope} applies
auditLog('stock.adjust') old quantity, new quantity, and the reason
```

`audit_log` **is** the movement history: tenant-scoped, already read by
`modules/audit/`, already recording who and when. A parallel
`inventory_transactions` table would hold the same facts, could disagree with
`inventory_levels` the first time one write succeeded and the other did not, and
the one consulted would be the one nobody reconciles.

That sequence existed once, inline, in the middle of a 200-line medication
function. Phase 6 needed it a second time, so it was **extracted** into
`inventoryConsume()` and `health.php` now calls it too. This is a
de-duplication, not a new subsystem — the SQL, the lock, the zero floor and the
audit action are the behaviour Phase 4 shipped. A test asserts `health.php` no
longer contains its own stock update.

`inventoryConsume()` must be called **inside** an existing transaction: a stock
deduction is never the whole of what a caller is doing, and those writes must
commit or roll back together.

---

## 7. Ration vs consumption

| | is | changes stock? |
|---|---|---|
| `feed_rations` + `feed_ration_items` | a **plan** — "a lactating cow should get 8 kg/day" | never |
| `feed_consumption_events` | a **fact about the past** — "we fed 240 kg to the milking barn on 12 March" | yes, transactionally |

A ration never consumes inventory by existing and never on a schedule. If it
did, the stock level would become a projection of what *should* have happened
rather than a count of what is in the store, and nobody reading the number could
tell which. `feedRationProjection()` is explicitly named a projection; it writes
nothing and can prefill a consumption form, which a person still confirms.

Rations are **retired** (`is_active = 0`), not deleted, because past events
reference them.

**Scope** is `farm | location | animal`. `locations` (migration 004) is the
existing grouping in this codebase and there is no animal-group concept; Phase 6
did not invent one. Location *is* the group. Exactly one target is permitted and
it is enforced in the domain layer rather than by a CHECK constraint, because
this schema targets MySQL and MariaDB versions whose CHECK support differs and a
constraint that silently does nothing on one engine is worse than one stated in
code.

---

## 8. Deterministic analytics

No AI. Every figure is arithmetic over recorded rows, and the functions are
careful about what they do **not** know:

- **A day with no records is absent from the result, never returned as zero.**
  "Nobody recorded anything" and "the herd produced nothing" are different facts.
- **Averages divide by days *with data*, not by the window length.** An average
  over days nobody recorded is an average of invented zeroes. `days_with_data`
  and `days_recorded` are returned alongside so a recording gap is visible as a
  gap rather than read as a low-yielding animal.
- **An animal's baseline is its own history**, excluding the day being judged —
  on a mixed farm a high-yielding cow and a late-lactation cow say nothing about
  each other, and a drop measured against itself is not a drop.

---

## 9. Alerts

`productionSyncAlerts()` is the **same desired-state reconciler**
`healthSyncAlerts()` uses: build the desired set, dedupe on
`type|entity_type|entity_id`, create what is missing, auto-resolve what is no
longer true by marking it read rather than deleting it.

It is not a second alert system — same table, same dedupe key, same resolve
rule — over a **disjoint** alert-type family (`DFMS_PRODUCTION_ALERT_TYPES`,
asserted by test to not overlap `DFMS_HEALTH_ALERT_TYPES`). Two reconcilers that
never touch each other's types are how one alert queue serves two domains.

A drop requires at least three prior days of data, so one quiet day against a
single earlier reading cannot fire it.

**Wording is operational, never medical.** "Production on 12 March was 2.0 L
against a 14-day average of 20.0 L (90% lower)" — never "the animal is sick". A
test asserts the description contains no diagnostic vocabulary.

Low-stock alerts reuse the Phase 1 dedupe-and-auto-resolve rule, extracted into
`inventorySyncLowStockAlert()` so a deduction made from the feeding module keeps
the alert queue as accurate as one made from the inventory module.

---

## 10. Tenancy, authorization and audit

All four new tables are registered in `DFMS_TENANT_TABLES`; `tenantInsert()` and
`tenantFind()` refuse an unregistered table by design.

- `farm_id` is **never** read from input. `tenantInsert()` supplies it from the
  session; a POSTed `farm_id` is inert, asserted over HTTP.
- Every animal, item, location and ration id arriving from a form is resolved
  through the tenant repository before use. A well-formed id belonging to
  another farm is refused with the **same message** as a missing one.
- Malformed route ids 404, including on the ration page, where an optional id
  that is present but malformed is a 404 rather than a silent fallback to the
  list.
- Deletion re-checks `milk.delete` inside the handler, not only at page level.

**Permissions — exactly one was added.** Everything else reuses what existed:
recording and correcting are `milk.create` / `milk.edit`, viewing is
`milk.view`, rations and consumption are `feeding.create` / `feeding.view`.
Feed consumption moves stock and so looks like it should demand
`inventory.update` — but that permission's role list is *identical* to
`feeding.create`'s, so requiring both would grant nothing while implying a
distinction the matrix does not make.

`milk.delete` (farm manager only) exists because deleting is a genuinely
different decision from correcting, and this codebase already says so
everywhere: `cows.delete`, `alerts.delete` and `files.delete` are each
farm-manager-only and separately named.

**Audit** uses the existing infrastructure. Updates log **changed fields only** —
logging the whole row on every edit would bury the one value that moved and copy
unchanged notes into the audit store on every save. Deletion logs what was
removed, so the record survives the deletion.

---

## 11. API surface

One route, read-only:

```
GET /api/v1/production            herd summary for a window
GET /api/v1/production/{animal}   one animal's recorded yields
```

Requires `milk.view`. Returns `recorded` and `farm_aggregate` as **separate
blocks** with a note that they measure different things.

**Feeding gets no endpoint.** Recording consumption moves stock, and this API has
been read-only since Phase 2B; a write surface would be a new security decision,
not a convenience. Tests assert `/v1/feed` and `/v1/feed-consumption` do not
exist.

---

## 12. What Phase 6 did NOT implement

Not built, and not partially built — no tables, prompts, flags or stub screens:

- AI disease detection
- Animal image analysis / computer vision
- Predictive health alerts (the drop rule is arithmetic, not prediction)
- AI Health Risk Engine
- Smart Farm Intelligence
- Farm AI Copilot
- IoT / sensor ingestion
- Milk quality laboratory chemistry (fat, protein, SCC) — belongs to
  `laboratory_tests`
- Parlour / robot integration
- Reproduction: heat detection, insemination, pregnancy checks, calving
- Lactation curve modelling and yield forecasting
- Feed-conversion ratio and feed formulation
- Read-only AI tools over production and feeding — the domain helpers were
  written to be safely exposable later, but registering them was out of scope

Everything in this document works with **no AI provider configured**.
