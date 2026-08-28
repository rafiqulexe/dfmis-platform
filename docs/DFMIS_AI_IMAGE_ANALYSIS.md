# DFMIS AI Animal Image Analysis — Architecture (Phase 7)

> **This capability does not diagnose disease.**
>
> It sends one photograph of one animal to a language model and gets back a
> bounded description of what is **visible**, what that could be **consistent
> with**, and how quickly a person should involve a **veterinarian**. Only a
> qualified veterinarian examining the animal can diagnose anything. Any
> summary of this phase that says "AI disease detection" is wrong.

---

## 0. What this phase is, and what it is not

| Built | Deliberately NOT built |
| --- | --- |
| Analysis of an already-stored, authorized animal image | Any image upload path (the Phase 2B one is reused) |
| A bounded structured observation schema | Disease diagnosis or disease naming |
| Bounded certainty words (`speculative`/`possible`/`likely`) | Numeric probabilities or confidence scores |
| An urgency classification with a deterministic floor | Automatic disease alerts driven by model output |
| Next-step actions for a person | Any treatment, medication, dose or withdrawal advice |
| Analysis history per animal | Automatic health-record creation of any kind |
| A read-only API for that history | An API write surface for requesting analyses |
| One feature flag, `ai_image_analysis` | Predictive health, IoT, Smart Farm, Farm Copilot |

`future_disease_detection` remains registered, **unbuilt and default-off**. It
promises image-based *diagnostic* support, which is precisely what this phase
does not do; switching it on would tell an operator they had enabled diagnosis.

---

## 1. Why `stored_files` is reused, and why image binaries are not duplicated

The image already has an owner. Phase 2B's `stored_files`:

- writes bytes **outside the web root**, under `org_{id}/farm_{id}/{category}`
- gives every file a random 32-hex name, so no URL can be derived from an entity id
- records the owning farm, the uploader, the sniffed MIME type and the byte size
- is readable **only** through `modules/files/serve.php`, which re-checks tenancy
  on every byte and sends a `sandbox` CSP with `nosniff`

Copying an image into a second table would produce two sets of bytes with one
access-control path between them — and the copy is always the one that outlives
a deletion. So `ai_image_analyses` holds a `file_id` and **nothing else about
the image**. No base64, no path, no duplicate bytes.

The consequence is the correct one: `deleteStoredFile()` soft-deletes the row
and genuinely removes the bytes, while the analysis row survives as the record
that an analysis happened — which is what an audit question actually asks.

**No new storage system, no new upload path, no second MIME allow-list.** The
analysable-category list (`animal_photos`, `medical_images`) is a *subset* of
`DFMS_ALLOWED_CATEGORIES`, and the analysable MIME list *is*
`DFMS_ALLOWED_IMAGE_TYPES`. A test asserts both relationships.

---

## 2. Provider-neutral multimodal architecture

Phase 5F established an internal message vocabulary — `text`, `tool_use`,
`tool_result`. Phase 7 adds a fourth block, **additively**:

```php
['type' => 'image', 'media_type' => 'image/png', 'data' => '<base64>']
```

No new abstract method was added to `DfmsAiProvider`. That is the same seam
decision Phase 5F made and for the same reason: a fifth abstract method would
break every existing implementation, **including the provider stubs the 5D, 5E
and 5F suites depend on** — and a phase that starts by breaking the tests
proving the previous phase works has chosen the wrong seam.

Each vendor shape is absorbed inside its own class and nowhere else:

| Internal | Anthropic (`wireBlocks`) | Gemini (`wireParts`) |
| --- | --- | --- |
| `media_type` + `data` | `source: {type: base64, media_type, data}` | `inline_data: {mime_type, data}` |

A malformed image block (empty data or empty media type) is **dropped**, not
sent empty. An image part a vendor rejects fails the whole request with a 400,
which this codebase classifies as `invalid_request` and never retries — so a
model would silently stop answering the moment a photo was involved. Dropping
degrades to a text-only request instead.

A provider that ignores the block entirely is still a **correct** provider.

### What never happens to `data`

It is never logged, never audited, never written to the database and never
echoed in an error. The bytes live in one place — the file storage layer — and
the block is the only other copy that exists, for the duration of one request.
Integration tests grep both the page and the API payload for a base64 signature.

---

## 3. The structured observation schema

Reuses `includes/ai_schema.php` unchanged — extraction, strict validation,
bounded resource limits, and the standing rule that **unknown keys are
rejected**.

```
observations[]          observation, location, visibility, severity   (>= 1 required)
possible_concerns[]     concern, rationale, certainty                 (optional)
urgency                 routine | monitor | veterinary_review | urgent
recommended_actions[]   observe | photograph_again |
                        isolate_if_advised | veterinarian_review      (>= 1 required)
limitations[]           free text                                     (>= 1 required)
```

**What the schema cannot express, deliberately:**

- **A diagnosis.** There is no field called `diagnosis` and no field a UI could
  render as one. A model that returns one hits the unknown-key rule and the
  **whole analysis is refused** — not partially accepted.
- **A probability.** There is no numeric field anywhere in this schema. A test
  walks the schema tree and asserts no `integer` or `decimal` node exists, so a
  future edit reintroducing fake medical certainty fails before any behaviour does.
- **A treatment or dose.** `recommended_actions` is a closed enum of four next
  steps for a person. There is nowhere to put a medicine.

`limitations` is **required with at least one entry**. Every photograph has
limitations, and a result claiming none has not understood the task — making it
required means such a result is refused rather than displayed as unusually
confident.

Results are **re-validated on the way out** as well as in
(`aiImageDecodeResult()`). A row edited by hand, or left by an older schema,
renders nothing rather than something unchecked.

---

## 4. Safety boundaries — four independent layers

Each holds if the others fail.

1. **The prompt** — `animal_image_observation` (v1, `safety_critical`) forbids
   naming a disease, giving a numeric probability, prescribing anything, and
   describing what is not visible. It is drawn from the registry and is never
   caller-controlled.
2. **The schema** — there is nowhere to put a diagnosis or a percentage (section 3).
3. **The urgency floor** — `aiImageResolveUrgency()` takes the **higher** of
   what the model said and what the deterministic scan of the person own words
   found.
4. **The write boundary** — nothing writes to the health domain (section 6).

### The urgency floor in detail

Phase 5D `aiVetDetectUrgency()` patterns are **reused, not restated**: an
emergency is an emergency whether it was typed into the assistant or into an
image request. The scan runs on the person words, **before the model and
independently of it**.

> If someone writes *"she cannot stand and the wound is bleeding heavily"* and
> attaches a photograph of a hoof, a model looking only at the hoof may quite
> reasonably answer `monitor`. That answer must not be what gets shown.

The floor only ever **raises**. A model that considers something urgent when the
words contained no trigger phrase is believed — raising urgency is always the
safe direction. An absent or unrecognised model urgency falls to
`veterinary_review`, never `routine`: a malformed answer must not read as
reassurance.

The **resolved** urgency is what gets stored, including inside `result_json`, so
no reader — page, history row, or API — has to remember to apply the floor.

---

## 5. Authorization flow and tenant isolation

Requesting an analysis passes every gate below before a provider is reached:

```
requireAuth('ai.consult')          the caller gate (module / API router)
  -> CSRF + POST only              spending an allowance is never a GET
  -> feature flag                  rollout, checked AFTER authorization
  -> animalFind()                  the animal resolves in the ACTIVE FARM
  -> aiImageResolveFile()          the file resolves in the active farm
                                   AND is linked to THIS animal
                                   AND is an analysable category
                                   AND is an allowed image MIME type
                                   AND its bytes exist inside the tenant dir
  -> aiImageReadBlock()            size-bounded and re-sniffed on disk
  -> row INSERTed as pending, moved to processing
  -> aiStructured()                plan gate, rate limit, retry policy,
                                   prompt version, usage row, output validation
  -> urgency floor
  -> terminal state
```

**Every refusal is indistinguishable.** A file that does not exist, one
belonging to another farm, one attached to a different animal, a PDF, and one
whose bytes are corrupt all return the same `image_unreadable` token. A test
drives seven different bad inputs and asserts a single distinct reason.

**No client-supplied tenant key is ever trusted.** `organization_id` and
`farm_id` are never read from the request; they come from the session through
`includes/repository.php`, as everywhere else in this codebase. `tenantInsert()`
strips a caller-supplied `farm_id` by construction.

### Permissions — nothing new was added

`ai.consult` (farm_manager, veterinarian, staff) governs **both** requesting and
viewing, which is exactly what `/v1/ai-conversations` already does for assistant
history. A new permission would have had an identical role list and given the
matrix a second place to be wrong.

`cows.view` is deliberately **not** enough to see analyses: seeing an animal is
not the same decision as seeing what an AI said about it, or spending the
organization metered allowance.

---

## 6. Why AI output is not automatically a health record

Phase 4 `health_observations` records a fact a **person** established — a
temperature that was taken, a behaviour that was witnessed. It is clinical
truth, and the health domain treats it as such.

An AI image analysis is not that. It is a description of a photograph produced
by an **untrusted external system**. Giving it a row in a clinical table would
make it indistinguishable from something a stockperson saw with their own eyes,
and every downstream reader — the health timeline, the case summary, the
withdrawal logic — would then reason over model output as if it were a
measurement.

So `ai_image_analyses` is a separate, clearly-named table, and **nothing in this
phase writes from it into the health domain**: no case, no diagnosis, no
treatment, no medication, no quarantine, no status change, no inventory
movement, no alert. A test snapshots the row counts of nine health tables around
an `urgent` analysis and asserts they are unchanged.

Promoting an analysis into a health record is an explicit human action for a
later phase to design.

---

## 7. Database changes

**One new table** (migration `013_ai_image_analysis.php`). No existing table
changed.

`ai_image_analyses` — `farm_id`, `cow_id`, `file_id`, `requested_by`, `status`,
`failure_reason`, `provider`, `model`, `prompt_key`, `prompt_version`,
`reported_signs`, `urgency`, `urgency_flagged`, `result_json`, token counts,
`created_at`, `completed_at`.

Registered in `DFMS_TENANT_TABLES`, so an unregistered-table write fails loudly
rather than producing an unscoped row. The organization is derived from the farm,
as it is for every other tenant table in this codebase — a farm belongs to
exactly one organization, and storing the organization again would create a
second source of truth that can disagree.

**Why the result is one JSON column.** Compare `ai_message_sources`, which is a
real table because *"which of our documents has this passage been cited in"*
must be a query. Nothing asks that of an individual observation — the result is
read back whole, for one analysis, on one page. The facts that **are** queried
(`status`, `urgency`) are indexed columns. The column only ever holds structure
that has already passed schema validation.

**Never stored:** API keys, provider credentials, raw request or response
envelopes, request headers, image bytes, any base64 payload.

**Foreign keys.** `farm_id` RESTRICT (a farm with history is not something a FK
should quietly erase). `cow_id` and `file_id` CASCADE — unlike `ai_conversations`,
which holds clinical *reasoning* worth keeping after an animal leaves the herd,
an analysis describes one photograph of one animal and means nothing without it.

---

## 8. Lifecycle states — all five are real

| State | Meaning |
| --- | --- |
| `pending` | the row exists, nothing has been sent |
| `processing` | handed to the provider; a row **left** here means the request died mid-flight |
| `completed` | a schema-validated result is stored |
| `failed` | the provider answered and the answer was unusable, or it errored |
| `unavailable` | never attempted — AI unconfigured, allowance spent, or rate limited |

The states are not decorative. The row is inserted as `pending` and moved to
`processing` **before** the provider call, so a crashed or timed-out request
cannot leave behind something that looks like a success.

`failed` and `unavailable` are kept distinct because they mean different things
to the person reading the message: one says the model would not follow the
contract, the other says nothing was spent and trying later is sensible.

---

## 9. Failure behaviour

On any provider failure the system:

1. marks the analysis `failed` or `unavailable` — **never** `completed`
2. writes **no** observations and **no** urgency (a failed analysis carrying an
   urgency would be a fabricated assessment)
3. writes a **machine token** to `failure_reason`, never an upstream body
4. renders mapped, human-safe text via `aiImageFailureMessage()`, which falls
   through the image, provider and schema token tables and ends at a safe generic
   string — an unrecognised token is never shown verbatim
5. writes a **new row** per request, so a later failure can never inherit an
   earlier success and show stale advice as current

Never exposed: credentials, upstream bodies, request headers, endpoints, model
internals, stored paths, or the tenant key.

---

## 10. Rate limiting, plan gating and usage

All reused unchanged. One analysis is **one logical provider call** routed
through `aiStructured()` then `aiChat()`, which applies the plan gate before
spending anything, then the rate limiter, then the bounded retry policy — and
records exactly one `ai_usage` row with the provider, model, prompt key and
prompt version. Nothing here bypasses or redesigns the Phase 5E limiter.

The image is bounded at **4 MB** (`DFMS_AI_IMAGE_MAX_BYTES`), which is
**stricter than and subordinate to** the unchanged 8 MB upload limit
(`DFMS_MAX_UPLOAD_BYTES`). These govern different boundaries: a larger photo
stores perfectly well and simply cannot be analysed. A test asserts the analysis
ceiling never exceeds the upload ceiling, so the two cannot drift into conflict.

Refusing an oversized image is deliberately preferred over silently downscaling
it — a resized photograph is not the photograph the stockperson looked at, and
quietly changing the evidence before an assessment is exactly the kind of
invisible transformation this codebase avoids elsewhere.

---

## 11. Audit logging

Three events through the existing `auditLog()`: `ai_image_analysis.requested`,
`.completed`, `.failed`.

Recorded: animal id, file id, urgency, the deterministic urgency flag, provider,
model, prompt key, prompt version, and a machine reason token.

**Not recorded:** image bytes or base64, the observations themselves (already on
the row; the audit log answers *who did what*, not *what did the model say*), the
person free-text reported signs, credentials, or any upstream payload. The
existing `DFMS_AUDIT_DENY_KEYS` list applies unchanged.

---

## 12. UI and API

**UI** — one new tab on the existing animal profile (`modules/cows/view.php`),
consistent with the seven that were already there. No duplicate animal profile
was created. The tab renders the framing on the page itself, offers no form when
the feature cannot answer, and renders **no raw provider JSON** and **no
provider errors** — every value shown has passed schema re-validation, and every
failure is a mapped message.

**API** — one new route, **read-only**: `GET /api/v1/animal-image-analysis/{animal id}`,
gated on `ai.consult`. Requesting an analysis stays a POST to the HTML module,
because it spends a metered allowance and an API write surface for that is a
separate security decision, not a convenience. The payload declares itself
(`interpretation: ai_assisted_observation`, `is_diagnosis: false`,
`clinical_record: none`) so a client cannot present it as a diagnosis without
ignoring the machine-readable statement that it is not one. `farm_id` is never
echoed.

---

## 13. Tests

- **`tests/phpunit/AiImageAnalysisTest.php`** — 48 tests. No network call, no API
  key: every provider is a stub, and the two real providers are exercised only
  through their pure static mapping methods.
- **`tests/vision_isolation_test.sh`** — 52 HTTP assertions against the isolated
  `dfms_test` server, wired in as suite 13 of `tests/run_all.sh`.

Coverage: authorization and indistinguishable refusals; organization, farm, file
and animal isolation; the provider-neutral block reaching the abstraction; both
vendor mappings; unsupported MIME, oversized input, corrupt bytes and category
rules; unknown keys, missing fields, bad enums and prose-instead-of-JSON all
refused; the urgency floor at every level; no fabricated result on failure; no
health-record mutation; correct tenant ownership and provenance; feature-flag
gating; and no credential, vendor key or base64 payload in any response.

---

## 14. Known limitations

1. **Synchronous.** The analysis runs inside the request. There is no job queue
   in this codebase, and adding one for a single feature would be infrastructure
   built ahead of need. The lifecycle states are genuine, so a request that dies
   leaves a visible `processing` row rather than a false success.
2. **One image per analysis.** Comparing two photographs over time is a real
   clinical technique and is not built.
3. **No downscaling.** Images over 4 MB are refused rather than resized
   (section 10).
4. **The model is not verified against the image.** Nothing here can detect a
   model describing something that is not in the photograph. That is what the
   `limitations` field, the certainty vocabulary and veterinary escalation exist
   to compensate for.
5. **`tests/health_isolation_test.sh` accumulates state.** Its alert-deduplication
   assertion counts rows and creates one withdrawal per run, so it fails after
   repeated runs against the same database and passes on a fresh one. This is
   pre-existing Phase 4 behaviour, unrelated to this phase, and was diagnosed
   rather than papered over. Fixing it means giving that suite its own cleanup —
   a change to Phase 4 test code that this phase deliberately did not make.

---

## 15. Explicitly deferred

Disease detection and diagnosis; disease probability scoring; automatic
treatment, medication or inventory changes; automatic health-record mutation;
automatic disease alerts from AI output; predictive health; IoT; Smart Farm
Intelligence; Farm Copilot; autonomous workflows.

No placeholder, flag, table, column or route was created for any of them.

---

## Verification

```
php tools/lint.php
php vendor/bin/phpstan analyse --memory-limit=512M
php vendor/bin/phpunit
php tools/test-db.php --create     # a fresh isolated database
bash tests/run_all.sh
```
