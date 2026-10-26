# DFMIS AI Architecture — Veterinarian Assistant & RAG

**Phase 5D.** Branch `feature/ai-intelligence-engine`. Migration `009_ai_veterinary_assistant.php`.

**Companion documents:**
[`DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md`](./DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md) (Phase 4 — the
clinical record this reads),
[`DFMIS_ANIMAL_DIGITAL_TWIN.md`](./DFMIS_ANIMAL_DIGITAL_TWIN.md) (Phase 3 — the animal record),
[`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md) (Phase 1 — tenancy and authorization),
[`DFMIS_API_ARCHITECTURE.md`](./DFMIS_API_ARCHITECTURE.md) (Phase 2B — the API contract).

---

## 0. What this phase is, and what it is not

Phase 5D adds an **AI Veterinarian Assistant**: a decision-support surface that answers a question
using the farm's own health records and its own written knowledge, and says plainly when it does not
know.

**It is not a diagnostic system.** It does not confirm a diagnosis, does not prescribe, does not
produce a medication dose, and does not replace a licensed veterinarian. Those are not disclaimers
bolted onto a page — §6 describes how each is enforced in code.

**Explicitly NOT in this phase** (and no placeholder, stub or "coming soon" surface was created for
any of them): image disease detection (5A), animal image analysis (5B), predictive ML models (5C),
IoT or sensor ingestion, camera monitoring, autonomous treatment decisions.

---

## 1. What already existed before Phase 5

Audited from source. The roadmap was not trusted.

| Component | State found | Verdict |
|---|---|---|
| `includes/ai_service.php` | Existed. **Zero call sites** — verified by grep across the whole codebase. Hardcoded to one vendor via a direct `curl`. Single-turn only. Returned plain text. | Reusable as a *seam*; incomplete as a provider layer. Refactored, not replaced. |
| `ai_usage` table | Existed (migrations 003/004). Organization-scoped, nullable `farm_id`, 0 rows. Carries feature, model, entity, tokens, status. | **Reused unchanged.** |
| Plan gating | `DFMS_PLAN_AI_LIMITS` — starter 100, professional 1000, enterprise unlimited. Enforced before spending. | **Reused unchanged.** |
| `includes/insights.php` | The deterministic Farm Advisor. Real computations, **no model call anywhere**. | Untouched. Asserted still working, and asserted to contain no AI call. |
| RAG | **Nothing.** No knowledge, document, chunk, embedding or retrieval table. | Built from scratch. |
| `stored_files` | Tenant-scoped, access-controlled, PDF-capable since Phase 4. | Reused; no second file store. |
| `DFMS_AUDIT_DENY_KEYS` | **Already contained `api_key` and `anthropic_api_key`.** | Credential redaction was already solved. Reused. |
| `feature_flags` | `future_ai_copilot` (default off, four tests assert this). | Left alone; a new flag was added instead. Phase 7 did the same again, adding `ai_image_analysis` rather than reusing `future_disease_detection`. |
| Navigation | An `'ai'` item with `url => null, status => 'soon'`. | Became a real link. |

### Audit findings that changed the design

1. **`/api/v1/health` was already the public liveness probe.** Overloading it would have exposed
   clinical data publicly or broken load balancers. New routes are `/v1/ai-status` and
   `/v1/ai-conversations`.
2. **PDF text is not extractable** — `stored_files` stores PDFs but no parser exists, and adding one
   is a dependency this phase does not justify. This determined that the knowledge pipeline ingests
   **text a person actually supplied** (§5).
3. **MariaDB supports InnoDB `FULLTEXT`** (probed directly on 10.4.32 when this was designed, and
   re-probed on 10.11.19 after the engine upgrade — the index survives and `MATCH … AGAINST` runs).
   That makes genuine relevance-ranked retrieval possible with **no vector database**, which the
   brief asks us not to introduce unless the scope requires it.
4. **`innodb_ft_min_token_size = 3`** — so short queries can miss through the index. A scored LIKE
   fallback was warranted, not defensive bloat. Still 3 on 10.11.19, so the fallback is still
   earning its place.
5. **`EngineeringToolingTest` only scanned `APP_|DB_|ANTHROPIC_` prefixes** in `.env.example`. A new
   `AI_` variable would have escaped the secret scan; the prefix list was widened.

---

## 2. Architecture

```
question (HTML form POST — the API is read-only)
  ↓
requireAuth('ai.consult')  +  feature flag  +  currentFarmId()
  ↓
optional animal context   → Phase 3/4 domain, already tenant-scoped
  ↓
knowledge retrieval       → aiKnowledgeRetrieve()   [the only function that knows HOW]
  ↓
farm health data          → healthAnimalSummary(), alerts
  ↓
safe context builder      → FOUR LABELLED BLOCKS, never merged
  ↓
aiChat()                  → tenant accounting + plan gate
  ↓
DfmsAiProvider            → the only code that names a vendor
  ↓
structured response       → answer + urgency + citations + strategy
  ↓
conversation + citations + audit   (one transaction)
```

### Files

| File | Role |
|---|---|
| `includes/ai_provider.php` | `DfmsAiProvider` interface, `DfmsAnthropicProvider`, failure-token registry, injection point. |
| `includes/ai_service.php` | Tenant accounting, plan gate, `aiChat()` (multi-turn) and `aiComplete()` (retained single-turn wrapper). |
| `includes/ai_knowledge.php` | Sources, documents, chunking, retrieval. |
| `includes/ai_vet.php` | System prompt, urgency layer, context builder, conversations, `aiVetAsk()`. |
| `modules/ai/` | Consultation and knowledge-curation UI. |
| `api/v1/ai-status.php`, `api/v1/ai-conversations.php` | Read-only API. |

---

## 3. Provider layer

`DfmsAiProvider` is a deliberately narrow interface: `name()`, `isConfigured()`, `defaultModel()`,
`complete($systemPrompt, $messages, $options)`. No streaming, no tool use, no embeddings — empty
methods for unbuilt features would be abstraction for its own sake.

**Only Anthropic is implemented**, because that is what this deployment is configured for. Shipping
an unconfigured OpenAI provider would be dead code no test could exercise end to end. The interface
is the deliverable: a second provider is a new file plus one line in `dfms_ai_provider()`, with **no
change to the veterinary domain, the UI, or the API**.

### Credential rules, enforced in code

1. The key is read from the environment only, through `ANTHROPIC_API_KEY`, in exactly **one
   expression** in the codebase (`DfmsAnthropicProvider::apiKey()`).
2. No method ever returns the key or any structure containing it.
3. **The upstream response body is never logged** — only a machine token and an HTTP status. A
   provider that echoed a request header into an error message would otherwise write our key into
   our log file. `curl_error()` is not logged either, for the same reason.
4. Every failure becomes a **token from a closed registry** (`DFMS_AI_FAILURE_REASONS`). An
   unrecognised token maps to safe generic text rather than being echoed.
5. `isConfigured()` returns a boolean. That is the entire extent of what any caller learns.

If AI is not configured, the assistant reports itself unavailable and **refuses to answer**. It
never fabricates a reply, and the ask form is not even rendered — a form that posts into a void is
worse than an honest empty state.

### `dfms_ai_set_provider()`

The injection point, and the concrete reason the interface earns its place. It exists so tests can
exercise provider **success and failure** without network access or a live key — an untested failure
path is one that will be wrong exactly when it matters — and so a deployment selects a provider
without touching the domain.

---

## 4. RAG architecture

Four concerns, kept separate because they genuinely are:

| Concern | Table | Why it is its own thing |
|---|---|---|
| **Source** | `ai_knowledge_sources` | A curated collection with provenance and attribution. A farm's standing order and a transcribed national guideline carry different authority, and a citation must say which. |
| **Document** | `ai_knowledge_documents` | One titled body of text. The authoritative copy. |
| **Chunk** | `ai_knowledge_chunks` | The retrievable unit. A document is far too large for a prompt, and retrieving whole documents is what makes naive RAG useless. |
| **Retrieval** | `aiKnowledgeRetrieve()` | The **only** function that knows how a chunk is found. |

### Chunking

Splits on **paragraph boundaries** first, falling back to a character cut with overlap only for an
oversized paragraph. Splitting mid-sentence by default would routinely separate a dose from the
animal it applies to. Target ~900 characters; deterministic and directly unit-tested.

Chunks are **derived**: saving a document rebuilds every chunk inside the same transaction, so a
chunk can never claim to quote text the document no longer contains.

### Retrieval

**Pass 1 — InnoDB `FULLTEXT`**, boolean mode, relevance-ranked. A real index, not a pretence.

**Pass 2 — scored `LIKE`**, only when pass 1 returns nothing. `innodb_ft_min_token_size` is 3, so a
legitimate short query can match zero rows through the index while the words are plainly present.
Passages are scored by how many distinct query terms they contain, so the fallback still ranks.

**Neither found anything → the caller is told so**, and the prompt states it explicitly (§6).

Both passes are tenant-scoped through `tenantQuery()`, and both exclude inactive sources and
documents — a disabled source stops being retrieved, not merely hidden in the UI.

### Why no vector database

Lexical retrieval over a farm's own small, hand-written corpus is the right tool at this scale, and
a vector store would be a new service and a new dependency for no benefit today.

**The path to embeddings is one function.** Add an `embedding` column and an ANN index to
`ai_knowledge_chunks`, and change the *body* of `aiKnowledgeRetrieve()`. Nothing above it depends on
how a chunk was found — only on the documented return shape. That shape is the contract.

### Nothing is seeded

DFMIS ships **no** veterinary content and the migration creates none. Inventing clinical guidance is
not something this software is qualified to do. A farm's knowledge base contains its own protocols
and material it chose to transcribe.

### The PDF limitation, stated honestly

`stored_files` accepts PDFs (Phase 4), but there is no text extractor here, so **a PDF cannot be
searched or quoted by the assistant**. This is stated on the knowledge page itself — not only in
this document — so nobody uploads a PDF and assumes the assistant has read it.

---

## 5. Animal-aware consultation

Selecting an animal is optional. When one is selected, context is built **only** from data the user
is already authorized to read: `animalFind()` returns null for another tenant's animal exactly as it
does for a missing one, and every health read uses the same tenant-scoped helpers the health module
uses. There is no query in `ai_vet.php` that could reach another farm.

Context includes species, breed, sex, age, lifecycle and health status; open cases with their
**diagnoses and epistemic status**; active treatments; recent medication with dose, unit and route;
vaccination history and schedule; quarantine; **active withdrawal restrictions**; recent
observations; laboratory results; and open alerts.

**Nothing is fabricated.** An absent value is reported as absent (`Breed: not recorded`). The Phase 4
distinction survives into the prompt: *no vaccination record means the history is UNKNOWN, not that
the animal is unvaccinated* — asserted by test.

### The four blocks, never merged

```
=== ANIMAL RECORD DATA (recorded facts from the farm's system) ===
=== HEALTH HISTORY (recorded facts from the farm's system) ===
=== RETRIEVED KNOWLEDGE (from this farm's own knowledge base — cite by title) ===
=== USER-REPORTED (an observation by the person asking; not a measurement) ===
```

The labels are load-bearing. The system prompt tells the model these blocks carry **different
certainty** and instructs it not to treat a user report as an established fact nor a recorded fact
as something the user merely believes. A recorded temperature and a stockperson's impression are not
the same kind of claim, and flattening them is how an assistant starts treating a guess as a
measurement.

A suspected diagnosis reaches the model **labelled `[Suspected]`**, with the clinician's own stated
confidence or an explicit "no confidence stated" — never as a bare condition name.

---

## 6. Medical safety layer

Enforced in three places in code, not as a footer.

### 1. A fixed system prompt

`aiVetSystemPrompt()` is a constant contract. **No caller may supply or extend it** — there is no
user-supplied system prompt anywhere in this phase, asserted by a test that sends a prompt-injection
attempt and checks the system prompt is unchanged.

It states absolute rules the model can actually act on: never claim a diagnosis is confirmed, never
present a treatment as a prescription, **never invent a medication dose, route or withdrawal period**
(defer to the product label or the attending vet), never invent facts about the animal, never hide
uncertainty.

It also prescribes the answer shape: what the records show / possible explanations / **what is
missing** / warning signs / **when to call a veterinarian**.

### 2. Server-side urgency detection

`aiVetDetectUrgency()` scans the **user's own words** before the model is called and independently of
it. Ten recognition patterns — down and unable to rise, severe bleeding, breathing difficulty,
seizure or collapse, bloat, calving trouble, suspected poisoning, not eating or drinking, high
fever, possible notifiable disease.

When it fires, `aiVetUrgencyBanner()` is **prepended to the answer regardless of what the model
said**. A model that fails to notice an emergency cannot suppress the warning. Both stored messages
are flagged `urgency_flagged = 1`.

These are **escalation patterns, not diagnostic criteria**. They are deliberately broad: a false
positive costs one extra sentence of advice; a false negative costs an animal. The asymmetry decides
the design.

### 3. Honest degradation

A provider failure produces an explicit degraded response. It never produces text that could be
mistaken for advice, it records **no citations** (attaching sources to a failure would claim they
informed something), and the failure is visible in the conversation history as a failure rather than
as silence.

---

## 7. Conversations

`ai_conversations` + `ai_messages` + `ai_message_sources`.

**Double scoping, and both halves matter:**
- `farm_id` — the tenant boundary, enforced by the repository layer.
- `admin_id` — per-user ownership *within* the farm. A colleague's clinical conversation is not
  readable just because you share a farm.

`aiVetFindConversation()` applies both and returns null if either fails, so a foreign conversation is
indistinguishable from one that does not exist.

**What is deliberately NOT stored:** no API key, no provider request or response envelope, no request
headers, no IP address, no user agent. The audit log already records who acted and from where;
duplicating it here would spread request metadata across two tables for no benefit. A test asserts
`ai_messages` has no such columns.

`failure_reason` stores a **machine token from the closed registry only** — never upstream prose that
might contain a request header.

**Citations are a table, not a JSON column**, because traceability is a requirement: "which of our
documents did the assistant rely on, and where else has that passage been used" must be a query. The
`relevance` recorded is the score *at the time of the answer* — a historical fact about that answer.

Everything writes in **one transaction**: user turn, assistant turn, citations and conversation
counters. A half-written exchange would show a question with no answer, or an answer with no
provenance.

---

## 8. Database changes

Six new tenant-owned tables, all registered in `DFMS_TENANT_TABLES`, all carrying `farm_id`, all with
`FOREIGN KEY (farm_id) REFERENCES farms ON DELETE RESTRICT` and indexes led by `farm_id`.

`ai_knowledge_sources`, `ai_knowledge_documents`, `ai_knowledge_chunks`, `ai_conversations`,
`ai_messages`, `ai_message_sources`.

**`ai_usage` is deliberately NOT tenant-registered** — it is organization-scoped with a nullable
`farm_id` and is written through `aiChat()`'s own accounting path. Listing it would invite a
farm-scoped read of a table whose rows legitimately have no farm. A test asserts it stays out.

`ON DELETE CASCADE` from `ai_knowledge_chunks` to `ai_message_sources` is deliberate and is the one
place a citation is destroyed: if the underlying passage is deleted, a citation pointing at it is no
longer resolvable, and a dangling citation is worse than none. **The answer text itself is kept.**

Migration 009 is **idempotent** (second run: 0 changes, 18 skips), non-destructive, alters no
existing table, and **seeds nothing**.

---

## 9. Permissions

Two new, and only because no existing permission expresses either decision.

| Permission | Roles | Reasoning |
|---|---|---|
| `ai.consult` | farm_manager, veterinarian, staff | Spends the organization's metered AI allowance and returns clinical decision support. Not viewer or accountant: no clinical decision to support, and a shared budget to consume. |
| `ai.knowledge` | farm_manager | Whatever is written here is quoted back to **everyone** who asks. Editing it shapes the advice the whole farm receives — narrower than consulting. |

`health.view` was considered and rejected as too wide (it includes viewer and grants read of the
whole clinical record). Nothing existing covered knowledge curation at all.

### Feature flag

New `ai_vet_assistant`, **default ON** (like `multi_farm_switching`, which is also built). The
`future_*` flags default off because they are unbuilt.

`future_ai_copilot` was deliberately **not** reused: it describes a Phase 7 conversational assistant
over *all* farm data, four existing tests assert it is unbuilt and default-off, and quietly
redefining it would make both this feature and that roadmap entry harder to reason about.

The flag gates **every** AI surface uniformly — both API routes and the HTML module. `/v1/ai-status`
is the one place that *reports* the disabled state rather than 404ing, because answering "is this
available" is its entire purpose.

A feature flag is **not** authorization: every endpoint still enforces its own permission.

---

## 10. API

Two routes, both **read-only** and both authenticated, extending the existing allow-list front
controller. The API has been read-only since Phase 2B and this phase does not change that — asking a
question is a POST to the HTML module, so no speculative write surface was created.

| Route | Permission |
|---|---|
| `GET /v1/ai-status` | `ai.consult` |
| `GET /v1/ai-conversations[/{id}]` | `ai.consult` |

`/v1/health` remains the **public** liveness probe, untouched — asserted by test that it still works
and exposes no AI detail.

**What the API never returns:** any credential or fragment of one (`configured` is a boolean);
`farm_id` or `admin_id`; the provider name or model (deployment information no consumer needs);
knowledge chunk *bodies* (a client wanting the passage reads the document through the knowledge base,
which enforces `ai.knowledge`). A degraded turn is reported **as degraded**, so a client cannot render
a failure message as if it were advice.

Both responses carry `assistant_role: decision_support_only` and a note that the assistant does not
diagnose, prescribe or replace a veterinarian — restated in the payload because a client acting on it
is about to put an answer in front of someone making a clinical decision.

---

## 11. Security and tenant isolation

- Every AI table carries `farm_id`, is tenant-registered, and is reached only through the repository
  helpers. No function in `ai_vet.php` or `ai_knowledge.php` accepts a `farm_id`.
- Cross-farm knowledge is unreachable: a farm-2 source, document and chunk were created in a test and
  proved invisible to farm 1 through retrieval and through direct id lookup.
- A document cannot be attached to a foreign source — the source id from the form is resolved through
  `tenantFind()` and refused if foreign, with nothing written.
- A foreign animal in a consultation is **refused**, not silently dropped.
- Conversations are farm- **and** user-scoped.
- Malformed and foreign ids both yield **404**, indistinguishably — including the optional `?c=`,
  `?edit=` and `?source=` query ids (see §13, defect 2).
- CSRF is enforced on every mutating endpoint.
- Model output is escaped and rendered as pre-wrapped text; it is **never** treated as markup. It is
  untrusted text from outside the application, and interpreting it as HTML would be an injection
  route straight into the page.

---

## 12. Tests

**11 suites, 1445 assertions, 0 failing, 0 skipped** (up from 1159 across 10).
PHPUnit: **211 tests, 773 assertions** (up from 160 / 546). Counts verified deterministic across
repeated runs.

New: `tests/phpunit/AiAssistantTest.php` (51 tests) and `tests/ai_isolation_test.sh` (59 assertions),
both against the isolated `dfms_test` database, wired into `tests/run_all.sh` as suite 11.

**No test makes a real AI call.** Provider behaviour is exercised through `dfms_ai_set_provider()`
with in-test stubs; the HTTP suite runs with no key at all, which is exactly the "fail safely when
unconfigured" path it asserts.

Covering every item the brief asked for:

| # | Requirement | Where |
|---|---|---|
| 1 | AI unavailable without a key | `testProviderIsUnconfiguredWithoutAKey`, `testUnconfiguredProviderMakesNoCallAndRecordsNoUsage`, `testNoFakeAnswerIsEverProduced` |
| 2 | Credentials never in responses | `testProviderResultNeverContainsTheCredential`, HTTP scan of all four AI surfaces for `sk-`, `ANTHROPIC_API_KEY`, `x-api-key`, `api.anthropic.com` |
| 3 | Credentials never in logs | `testDegradedMessageStoresOnlyAMachineToken`, `testAuditPayloadCarriesNoCredential`, `testFailureMessagesAreMappedNotEchoed`, `testAuditDenyListStillRedactsProviderKeys` |
| 4 | Cross-org conversation blocked | `testAnotherFarmsConversationIsUnreachable`, `testAnotherUsersConversationOnTheSameFarmIsUnreachable` |
| 5 | Cross-farm animal context blocked | `testCrossFarmAnimalContextIsRefused`, `testConsultationRefusesAForeignAnimal` |
| 6 | Unauthorized users blocked | HTTP permission matrix — viewer/accountant 403 on module and API; vet/staff 403 on knowledge curation |
| 7 | Context only authorized records | `testAnimalContextContainsOnlyRecordedFacts`, `testAbsentVaccinationIsReportedAsUnknownNotAsUnvaccinated`, `testDiagnosisStatusTravelsIntoTheContext` |
| 8 | Empty retrieval safe | `testEmptyRetrievalReturnsNothingRatherThanGuessing`, `testRetrievalWithNoUsableTermsReturnsNothing`, `testEmptyRetrievalIsStatedExplicitlyInThePrompt` |
| 9 | Sources traceable | `testAnswerCitationsAreStoredAndResolvable`, `testDegradedAnswerRecordsNoCitations` |
| 10 | Provider failures fail safely | `testProviderFailureIsRecordedAsDegradedNotAsAnAnswer`, `testProviderFailureIsRecordedInUsage`, `testDegradedTurnsAreNotReplayedAsHistory` |
| 11 | Deterministic advisor unaffected | `testDeterministicAdvisorStillWorksWithoutAI`, `testDeterministicAdvisorMakesNoAiCall`, HTTP dashboard check |

Plus the safety layer: `testUrgencyIsDetectedServerSide`,
`testUrgencyBannerIsPrependedRegardlessOfTheModelOutput`, `testSystemPromptCarriesTheSafetyContract`,
`testSystemPromptIsFixedAndNotCallerSupplied`, `testContextBlocksAreLabelledAndSeparated`.

**No existing test was weakened.** One was **strengthened**: `EngineeringToolingTest`'s
`.env.example` secret scan now also covers `AI_` and `DFMS_` prefixes.

---

## 13. Real defects found during implementation

**1. `{scope}` must be the first placeholder — FULLTEXT was silently dead.**
`tenantQuery()` binds the farm id **positionally as parameter #1**, so any `?` earlier in the SQL text
than `{scope}` receives it instead. The FULLTEXT relevance column
(`MATCH(...) AGAINST (?) AS relevance`) sits in the SELECT clause, which necessarily precedes the
WHERE — so the farm id went to the search and the search string went to the tenant filter.

It **failed closed** (a string coerces to `farm_id = 0` and matches nothing, so no cross-tenant row
was ever returned) but retrieval silently never used the index and always fell through to LIKE. Found
only because the returned strategy was `like` where it should have been `fulltext`.

Fixed by inlining the *provably constrained* boolean query (letters, digits, spaces and the trailing
`*` only, asserted at runtime, the same pattern `dfms_assert_identifier()` uses). **The constraint is
now documented on `tenantQuery()` itself**, and `testFulltextRetrievalIsActuallyReached` exists
specifically so it cannot regress silently again.

**2. A malformed optional resource id rendered 200 instead of 404.**
`DfmsValidator::id()` with `['nullable' => true]` returns null for *both* "absent" and "present but
malformed", so `?c=abc` silently fell through to the default view — a 200 where every other DFMIS page
returns 404, breaking the Phase 1 convention that malformed and foreign ids are indistinguishable.
Fixed with `aiOptionalResourceId()`, which distinguishes the two. A convenience *preselect*
(`?cow_id=`) is correctly still ignored, matching the Phase 4 health forms.

**3. `dfms_feature_enabled()` was undefined in the API context.**
The API front controller loads the Phase 1 gates, not every domain file. Caught by a 500 in the smoke
test; each handler now requires what it actually uses, and `navigation.php` gained a hard require on
`features.php` for the same reason.

**4. Two brittle assertions in my own new tests.** One asserted a substring that legitimately appears
in the *correct* wording; two assumed the test owned the entire knowledge corpus, which is false once
the HTTP suite has run. Both rewritten to be specific about their own fixtures.

---

## 14. Known limitations

1. **PDF text is not extractable** (§4). PDFs can be stored and cited by name, not searched.
2. **Lexical retrieval, not semantic.** A question phrased with entirely different vocabulary from
   the document will not match. The path to embeddings is one function body (§4).
3. **No cross-farm or platform-level knowledge.** Every source is farm-owned by design; there is no
   shared corpus, and DFMIS supplies no content.
4. **Urgency detection is English keyword matching.** It will miss a phrasing not in the pattern list
   and cannot understand a non-English description. It is a safety net, not a triage system, and the
   system prompt independently instructs the model to escalate.
5. **One provider implemented.** The interface supports more; none is configured.
6. **Conversation history is capped at 10 replayed turns** to bound prompt size.
7. **`ai_usage` counts calls, not cost.** Token counts are recorded; no pricing model exists yet.
8. **No streaming.** An answer arrives whole, after a wait of several seconds.
9. **The feature flag hides history too.** Switching the assistant off makes past conversations
   unreachable until it is switched back on. Simple and predictable, but it is a trade-off.

---

## 15. What remains for Phase 5A, 5B and 5C

Deliberately untouched. **No placeholder, stub, disabled button or "coming soon" surface was created
for any of them** — the brief forbids it and a fake affordance is worse than an absent one.

| Phase | Scope | What Phase 5D leaves ready |
|---|---|---|
| **5A — AI disease image detection** | Image-based **diagnostic** support | STILL DEFERRED. Phase 7 built AI-assisted image **observation**, which is deliberately not this: it describes what is visible and escalates to a veterinarian, and its schema has nowhere to put a diagnosis or a probability. `future_disease_detection` remains registered, unbuilt and default-off. See `docs/DFMIS_AI_IMAGE_ANALYSIS.md`. |
| **5B — Animal image analysis** | Body condition, lameness scoring from images | PARTLY SUPERSEDED by Phase 7, which delivered general image observation over the same file architecture and added a provider-neutral `image` block to the message vocabulary. What remains unbuilt is **scoring**: mapping a vision result onto the coded `health_observations` scales for body condition and mobility. Phase 7 deliberately writes nothing into the health domain. |
| **5C — Predictive disease ML models** | Risk prediction over history | Phase 4 made the clinical record row-shaped, coded and provenance-complete, with uncertainty explicit (`suspected`/`ruled_out`, `outcome: unknown`, NULL confidence). That is the feature-extraction substrate. No model, no training pipeline, no scoring exists. |

Also future: IoT/sensor ingestion (`future_iot`, Phase 11), autonomous decisions (explicitly out of
scope — the safety layer exists precisely to prevent this).

---

## Verification

| Gate | Result |
|---|---|
| PHP lint | 112 files, 0 failing |
| PHPStan level 5 | 0 errors, baseline still empty |
| PHPUnit | 211 tests, 773 assertions |
| Full unified runner | **11/11 suites, 1445 assertions, 0 failing, 0 skipped** |
| Migration 009 | applied; second run 0 changes, 18 skips (idempotent) |
| Test database | isolated `dfms_test`; no test makes a real AI call |

Phase 5D added **286 assertions** to the Phase 4 baseline of 1159.
