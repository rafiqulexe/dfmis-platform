# DFMIS Security Architecture

**Phase:** 1 — Foundation Hardening
**Date:** 27 August 2026
**Status:** Implemented and verified. 180 automated assertions passing.

This document describes how DFMIS enforces tenancy, authorization, validation,
integrity, auditing and error containment after Phase 1. It is the reference
every future module must build against.

**Companion documents:** [`DFMIS_BASELINE_AUDIT.md`](./DFMIS_BASELINE_AUDIT.md)
(finding ids — T-1, C-2, S-6 … — are defined there) and
[`DFMIS_ROADMAP.md`](./DFMIS_ROADMAP.md).

---

## 1. The security model in one page

Seven layers. A request must clear all of them; no layer is optional and none
substitutes for another.

```
Request
  │
  ├─ 1. Session        HttpOnly + SameSite + Secure, idle/absolute expiry
  │                    includes/auth.php
  ├─ 2. Authentication requireLogin() — throttled, timing-safe, audited
  │                    includes/auth.php
  ├─ 3. Tenant context requireTenantContext() — live DB membership, never session
  │                    includes/tenant.php
  ├─ 4. Authorization  requireAuth('perm') — org membership + farm role + permission
  │                    includes/permissions.php
  ├─ 5. CSRF           csrf_verify() on every POST
  │                    includes/csrf.php
  ├─ 6. Validation     DfmsValidator — typed, bounded, allow-listed
  │                    includes/validation.php
  └─ 7. Resource scope tenantFindOr404() — the row itself must belong to the farm
                       includes/repository.php
                            │
                            ├─ audit  auditLog() records the mutation
                            └─ errors errors.php contains any failure
```

**The standard page preamble.** Every protected page starts identically:

```php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('cows.edit');              // layers 2, 3, 4

$id  = dfms_route_id('id');            // layer 6 (route input)
$conn = getDBConnection();
$cow = tenantFindOr404($conn, 'cows', 'cow_id', $id);   // layer 7

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();                     // layer 5
    $v = new DfmsValidator($_POST);    // layer 6 (body input)
    // …
    auditUpdate('cow', $cow['cow_id'], $before, $after);
}
```

---

## 2. Tenant isolation

### The rule

**One tenant key per row.** Business tables carry `farm_id` and nothing else.
Organization is resolved by joining up through `farms`. Two independent tenant
keys on one row can drift apart, and a stale one is a security hole.

### Enforcement, application layer

`includes/tenant.php` is the only place that reads `$_SESSION` to make an access
decision.

| Function | Guarantee |
|---|---|
| `allowedFarmIds()` | Read live from `farm_users` every request, cached only for that request. Revoking access takes effect immediately, not at session expiry. |
| `allowedOrganizationIds()` | Union of explicit org membership and org-via-farm membership. |
| `currentFarmId()` | Re-validates any session-stored farm against live membership. Returns `null` when the user belongs to no farm. |
| `requireTenantContext()` | Hard gate for the null case. Renders "no farm assigned", never crashes. |
| `switchFarm($id)` | Rejects any farm the user is not a member of. |
| `assertFarmAccess()` / `assertOrganizationAccess()` | Hard gates for a tenant id arriving from anywhere else. 404, not 403. |

`includes/repository.php` makes the safe query the *short* query:

| Function | Use |
|---|---|
| `tenantQuery()` / `tenantQueryOne()` / `tenantScalar()` | Reads. `{scope}` expands to the farm filter. Throws if the token is missing. |
| `tenantFind()` / `tenantFindOr404()` | Load one row by id. Rejects non-integer ids before the query. |
| `tenantInsert()` | Writes. **Strips any caller-supplied `farm_id`** and applies the tenant context's. |
| `tenantExec()` | Updates/deletes. `{scope}` required. |
| `tenantTransaction()` | Multi-step operations. Commits on success, rolls back on any throwable, joins an outer transaction rather than nesting. |

`dfms_assert_identifier()` guards every table, column and alias interpolated into
SQL — there is no placeholder for an identifier, so anything not matching
`^[A-Za-z_][A-Za-z0-9_]*$` throws rather than being escaped.

### Enforcement, storage layer (new in Phase 1)

The application layer was already correct; the database did not back it up.
Migration `005_integrity_and_constraints.php` closed that gap:

| Change | Closes |
|---|---|
| `UNIQUE (farm_id, unique_id)` on `cows`, replacing a **global** unique index | **T-1 (Critical)** |
| `DEFAULT 1` dropped from `farm_id` on 11 tables | T-2 |
| `farm_id` foreign keys added to 9 business tables, `ON DELETE RESTRICT` | T-3 |
| `UNIQUE (farm_id, production_date)` on `milk_production` | T-5 |
| Every tenant index re-led with `farm_id`; 5 tables that had none now have one | T-4 |

`ON DELETE RESTRICT` is deliberate, not an oversight: deleting a farm must be
*blocked* while it still owns data, never silently cascade away a customer's
records. Farm removal is an explicit, audited archival operation.

**Verified behaviour** (live database, this phase):

```
two farms may now share tag 'BD001'          -> INSERT SUCCEEDED
same tag twice on one farm                   -> ERROR 1062 uq_cow_tag_per_farm
INSERT omitting farm_id                      -> ERROR 1452 fk_cows_farm
INSERT with farm_id = 999                    -> ERROR 1452 fk_milk_production_farm
```

### Cross-tenant misses return 404, never 403

A 403 confirms the record exists, which lets an attacker enumerate another
customer's data. `tenantFind()` returns `null` for *both* "does not exist" and
"belongs to another tenant", and every caller 404s. Malformed ids take the same
path, so `?id=abc` and `?id=<another farm's cow>` are indistinguishable.

---

## 3. Authorization (RBAC)

Roles live on **membership rows**, not on the user. `farm_users.role` is
authoritative. The same person can be a veterinarian at one farm and a viewer at
another. There is deliberately no global `currentRole()`.

`requireAuth($permission)` performs every required check, cheapest first:

1. authenticated user
2. tenant context present
3. farm membership (implied by a non-null `currentFarmId()`)
4. **organization membership** — the active farm's org must be in `allowedOrganizationIds()`
5. role + permission via the `DFMS_PERMISSIONS` matrix
6. resource scope — the caller passes the row through `tenantFindOr404()`

Step 6 stays with the caller deliberately: only the caller knows which table and
which id, and the repository already makes that the shortest thing to write.

**An unknown permission string is denied, never granted.** A typo in a
permission name fails closed and is logged.

### Platform admin — a resolved contradiction

The baseline audit found the documentation claiming platform admins "operate
above the tenant layer" while the code implemented no such thing (T-6). The rule
is now explicit and matches the code:

> A platform admin bypasses **role** checks on farms they belong to.
> A platform admin does **not** bypass **tenant** boundaries.

Cross-tenant access is deliberately unimplemented. When a platform admin console
is built (Phase 2) it must use a separate, audited code path — not a widening of
the request-scoped helpers every module depends on.

### Organization-level roles

`organization_users` (`owner` / `admin` / `member`) was dead in the baseline.
`hasOrganizationPermission()` / `requireOrganizationPermission()` now read it via
`DFMS_ORG_PERMISSIONS`. Phase 2 attaches the org-management surfaces.

---

## 4. Input validation

`includes/validation.php` defines one rule per field type. Handlers no longer
invent their own.

| Rule | Rejects |
|---|---|
| `id()` | `0`, negatives, decimals, `1 OR 1=1`, `1; DROP TABLE`, non-ASCII digits, overflow |
| `integer()` | non-integers, out-of-range |
| `decimal()` | `NaN`, `INF`, exponent notation (`1e10`), thousands separators, excess precision, out-of-bounds |
| `date()` | `''` (previously written as `0000-00-00`), `2024-02-31`, unpadded parts, future dates unless opted in, implausibly old |
| `datetime()` | anything not normalising to SQL format — accepts HTML `datetime-local` |
| `time()` | `25:00`, `10:99` — **PHP's own `H:i` parser rolls these over silently**, so the result is round-tripped and compared |
| `enum()` | anything outside the allow-list; a `default` applies only to an *absent* value, never a wrong one |
| `string()` / `text()` | NUL bytes, control characters, length violations (counted in **characters**, not bytes) |

`dfms_route_id()` validates ids from the URL and 404s on anything malformed —
identical to the response for a foreign id.

**Fixed by this layer:** C-6 (rules differed per page), C-7 (an unrecognised
`adjustment_type` silently *subtracted* stock), C-8 (a crafted POST could blank
an animal's only identifier).

---

## 5. Audit logging

`includes/audit.php` existed in the baseline with **zero call sites** (D-1).
It is now wired into every mutating handler.

Every event records **actor, organization, farm, action, entity type, entity id,
timestamp, IP, user agent** and a metadata diff.

| Helper | Records |
|---|---|
| `auditCreate($type, $id, $new)` | `<type>.create` |
| `auditUpdate($type, $id, $old, $new)` | `<type>.update` — **only the fields that actually changed**; writes nothing if nothing changed |
| `auditDelete($type, $id, $old)` | `<type>.delete` — called *before* the delete, while the detail still exists |
| `auditLog(...)` | Anything else, including security events |
| `auditSecurityEvent()` | Denied authorization and cross-tenant attempts, with `force` so they record even without tenant context |

### Secrets never enter the log

`dfms_audit_sanitize()` recursively strips a deny-list of keys
(`password`, `password_hash`, `csrf_token`, `api_key`, `token`, `secret`,
`authorization`, `session_id`, `private_key`, …) at any nesting depth, and
pattern-matches bcrypt hashes and `sk-` API keys in free-text values. A handler
that carelessly passes `$_POST` cannot leak a credential.

Verified: after a full test run, `SELECT COUNT(*) … WHERE new_value LIKE '%password%' OR … '%$2y$%'`
returns **0**.

The reader lives at `modules/audit/index.php`, gated on `audit.view`
(farm managers only), farm-scoped and paginated.

---

## 6. Configuration and secrets

`.env` is gitignored; `.env.example` is a template of **names only** and contains
no real values.

| Variable | Purpose |
|---|---|
| `APP_ENV` | `local`/`development`/`dev` shows errors; **anything else, including unset, hides them.** A typo fails safe. |
| `APP_URL` | Explicit base URL. Set in production; removes host detection entirely. |
| `APP_ALLOWED_HOSTS` | Allow-list for reflecting the `Host` header when `APP_URL` is unset. |
| `APP_TRUST_PROXY` | `X-Forwarded-For` is honoured **only** when `1`, so a client cannot forge its own IP to defeat throttling or poison the audit log. |
| `DB_*` | Connection, `utf8mb4` by default. |
| `ANTHROPIC_API_KEY` | Phase 5. Blank runs AI features in their degraded state. |

**Host header injection (S-1) is closed.** `BASE_URL` feeds six redirects and
every asset URL; it now comes from `APP_URL`, or from a Host that appears in
`APP_ALLOWED_HOSTS`, or from the first allow-listed entry. An unlisted Host is
never reflected.

**Database connection (S-2/C-10):** `charset=utf8mb4`, `ATTR_EMULATE_PREPARES => false`
(real server-side prepares — the multibyte escaping bypass class is gone), and a
single handle reused per request instead of ~6 new connections per page.

---

## 7. Error handling

`includes/errors.php` installs an exception handler, an error handler and a
shutdown handler. Together they guarantee a user **never** sees a SQL fragment,
a stack trace, a filesystem path, a class name or a credential.

- `display_errors` is forced off unless `APP_ENV` is explicitly a dev value.
- Every failure gets a **correlation id** — shown to the user, logged beside the
  real detail, so support can find the log line without exposing anything.
- `dfms_redact()` strips api-key/password/token patterns before anything is
  written to the log.
- `dfms_abort($status)` discards partially rendered output first, so a user
  never sees half a layout with an error stapled underneath.

**This closed a reproduced defect.** In the baseline, a user with no farm
membership hit `TypeError: getFarmAdvisorInsights(): Argument #2 ($farmId) must
be of type int, null given … D:\Projects\…\includes\insights.php:27` — a fatal
that printed the absolute server path to the browser. Now that state renders a
handled "No farm assigned yet" page (HTTP 403), verified by test.

---

## 8. File storage

`includes/storage.php` handled bytes safely in the baseline but recorded **no
ownership**, so retrieval could not be access-controlled.

**Write path** — `storeUploadedImage()`:
- PHP upload-error mapping to user-safe messages
- `is_uploaded_file()` check
- real on-disk size, not the client-reported one, against an 8 MB cap
- MIME from **content sniffing** (`getimagesize`), cross-checked against `finfo`;
  a file crafted to satisfy one and not the other is refused
- extension derived from the sniffed MIME, checked against a forbidden list
  (`php`, `phtml`, `phar`, `htaccess`, `svg`, …)
- random 32-hex filename — a URL cannot be derived from an entity id
- stored **outside the web root**, `storage/.htaccess` denies direct access
- registered in `stored_files` with farm, uploader, entity link and size
- if registration fails, the bytes are removed rather than orphaned

**Read path** — `modules/files/serve.php` is the *only* route to a stored file:
authenticated → tenant context → `files.view` → `tenantFindOr404()` on the record
→ `tenantFilePath()` containment check → served with `X-Content-Type-Options: nosniff`,
a restrictive CSP, and `Cache-Control: private`.

**Delete path** — `deleteStoredFile()` soft-deletes the record (keeping the audit
trail) while genuinely unlinking the bytes, because retaining a deleted medical
image is a liability, not an asset.

> **Scope note.** No upload *UI* exists yet — animal photos are Phase 3. This
> phase made the storage layer reachable and access-controlled so Phase 3 does
> not have to invent security while also inventing a feature.

---

## 9. Test coverage

```
bash tests/run_all.sh
```

| Suite | Assertions | Status |
|---|---|---|
| `php -l` syntax check | 49 files | 49 clean, 0 failing |
| `tests/validation_unit_test.php` | 104 | 104 passing |
| `tests/tenant_isolation_test.sh` | 14 | 14 passing |
| `tests/security_regression_test.sh` | 62 | 62 passing |
| **Total** | **180** | **180 passing, 0 failing** |

The security regression suite covers one case per fix:

- **Missing tenant context** — membership-less user gets a handled 403, no
  `TypeError`, no path, no stack trace
- **Cross-organization** — an org-2 *owner* (highest role available) is refused
  every org-1 animal and sees none in any list
- **Cross-farm** — view/edit/delete by foreign id all 404
- **Manipulated ids** — 10 probes including `1 OR 1=1`, `1; DROP TABLE cows`,
  `-1`, `1.5`, `%27`, overflow — all 404, and the table still exists afterwards
- **IDOR via POST body** — foreign `cow_id` on a health event; foreign `alert_id`
  on an acknowledgement
- **CSRF** — missing token and forged token on multiple endpoints
- **Method enforcement** — mutating endpoints reject GET with 405
- **Farm switching** — cannot cross a tenant boundary, and scope is unchanged
  after a failed attempt
- **RBAC** — all five roles, positive and negative, server-side
- **Validation** — blank tag, Feb 31, future birth date, negative yield,
  non-numeric yield, invalid enum, unknown adjustment type
- **Error containment** — no path, SQL or stack trace in any error response
- **Session** — `HttpOnly` and `SameSite` present on the cookie
- **Host header** — `Location` never contains an unlisted host
- **Unauthenticated** — every entry point redirects

`tests/run_all.sh` **fails** rather than skips when no dev server is running, so
CI cannot go green because nobody started one.

---

## 10. Remaining risks

Honest inventory of what Phase 1 did **not** close.

### Accepted for now

| Risk | Why it is acceptable today | Closes in |
|---|---|---|
| **No Composer / PSR-4 / PHPUnit** | Introducing them means restructuring a procedural codebase, which conflicts with the "do not rewrite" constraint. The bespoke harness gives 180 real assertions now. | Phase 2 |
| **No CI** | Nothing runs the suites automatically; a tenancy regression could reach the branch unnoticed. The suites exist and are one command. | Phase 2 |
| **No static analysis** | PHPStan needs Composer. `php -l` catches syntax only — it cannot catch a type error like the one behind C-1. | Phase 2 |
| **No HTTP API** | Nothing to secure yet. When it lands it must reuse `requireAuth()`, not reimplement it. | Phase 2 |
| **Tailwind Play CDN + unpinned Chart.js** | Third-party script executes on authenticated pages. Vendoring is bundled with the design-system migration. | Phase 2 |
| **No password change / reset / MFA** | Demo credentials are documented and weak. Acceptable for a pre-launch single-customer install; not acceptable at launch. | Phase 13 |
| **No i18n, timezone or currency handling** | Every date derives from the server clock. `utf8mb4` groundwork is done. | Phase 12 |

### Known limitations of what was built

- **Login throttling fails open.** If the `login_attempts` table is unavailable,
  throttling is skipped rather than locking everyone out. This is a deliberate
  availability trade-off and it is logged loudly. A distributed rate limiter
  belongs in Phase 13.
- **Throttling is per username *or* IP.** A large botnet with distinct IPs
  targeting distinct accounts is not stopped by this alone.
- **DDL is not transactional in MySQL/MariaDB.** A migration that fails partway
  leaves the schema half-changed. The runner refuses to record it as applied and
  says so, but recovery is manual.
- **Audit log is append-only by convention, not by constraint.** Anyone with
  database credentials can edit it. Tamper evidence is Phase 13.
- **`tests/run_all.sh` runs against the live seeded database**, not an isolated
  fixture. Suites are read-mostly and clean up after themselves, but a failing
  run can leave residue.
- **The `id=7` fixture assumption is reduced, not eliminated.** The seeder now
  prints `FIXTURE_RIVAL_COW_ID` and the suite honours `DFMS_RIVAL_COW_ID`, but
  the default is still `7`.
- **Organization-level permissions are defined but have no UI**, so they are
  effectively untested against real usage.

### Explicitly out of scope for Phase 1

Per the roadmap, none of the following were touched: AI features, disease/vision
detection, IoT ingestion, new verticals, finance, reporting, per-animal yield.
`includes/ai_service.php` remains complete and unreachable by design — Phase 5
activates it.

---

## 11. Rules for every future module

1. **Never read a farm or organization id from the client.** Tenant context comes
   only from `includes/tenant.php`.
2. **Never write a raw tenant query.** Use `includes/repository.php`. If you type
   `WHERE farm_id =` by hand you are working around the layer that stops leaks.
3. **Every id from a URL or form is hostile** until `tenantFind*()` confirms it —
   including ids in POST bodies used as foreign keys.
4. **Cross-tenant misses return 404, never 403.**
5. **Start every protected page with `requireAuth('permission')`.**
6. **Validate every input through `DfmsValidator`.** No ad-hoc `empty()` checks.
7. **Wrap multi-step writes in `tenantTransaction()`.** Never call `rollBack()`
   by hand.
8. **Audit every mutation** with `auditCreate` / `auditUpdate` / `auditDelete`.
9. **Never echo an exception.** Use `safeError()`, or let `errors.php` handle it.
10. **Add a regression test for every security-relevant change**, and add the
    module to `tests/security_regression_test.sh`.
11. **Add new tenant tables to `DFMS_TENANT_TABLES`** and give them
    `farm_id INT NOT NULL` with a foreign key and a leading `farm_id` index.
