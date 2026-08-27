# DFMIS API Architecture

**Phase:** 2B — Platform Engineering Foundation
**Status:** Foundation. Three endpoints exist. This is **not** a public API.

**Companion documents:** [`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md),
[`DFMIS_ENGINEERING_FOUNDATION.md`](./DFMIS_ENGINEERING_FOUNDATION.md),
[`DFMIS_DEVELOPMENT_GUIDE.md`](./DFMIS_DEVELOPMENT_GUIDE.md).

---

## 1. Purpose and scope

The API exists so later phases — an AI copilot, IoT ingestion, a mobile client —
have a **safe place to attach**, with the contract already settled and proven.

### Implemented now

| | |
|---|---|
| Versioned namespace | `/api/v1/` |
| Front controller | `api/index.php`, **API only** |
| Explicit route allow-list | 3 routes |
| JSON request/response conventions | `data` / `error` envelopes |
| Centralized error contract | stable codes + correlation id |
| Authentication | reuses the session; 401 JSON for API clients |
| Authorization | reuses `requireAuth()` — no parallel logic |
| Tenant isolation | inherited, not re-implemented |
| Correlation IDs | on every response, success included |
| Method enforcement | 405 with `Allow`; `OPTIONS` handled centrally |
| Input handling | JSON body parsing feeding `DfmsValidator` |

### Explicitly NOT implemented

Token/API-key authentication · OAuth · rate limiting · pagination conventions ·
write endpoints · CORS · OpenAPI spec · webhooks · a public API · endpoints for
animals, milk, health, inventory or alerts · any AI or IoT endpoint.

**Nothing above is scheduled by this document.** Each arrives with the phase
that needs it.

---

## 2. Front controller decision

> Existing HTML pages were **not** changed and are still reached directly.
> The front controller governs `/api/` **only**.

Routing the whole application through one `index.php` would rewrite every page's
entry contract — precisely the rewrite this project forbids. Confining it to
`/api/` gives new endpoints one consistent lifecycle at zero cost to 25 working
pages.

```
Browser  →  /dashboard.php                    direct, unchanged
Browser  →  /modules/cows/index.php           direct, unchanged
Client   →  /api/index.php/v1/me   →  router  →  api/v1/me.php
```

### Routing is an allow-list, not a filesystem scan

```php
$routes = [
    '/v1/health'   => ['methods' => ['GET'], 'handler' => …, 'auth' => false, 'permission' => null],
    '/v1/me'       => ['methods' => ['GET'], 'handler' => …, 'auth' => true,  'permission' => null],
    '/v1/settings' => ['methods' => ['GET'], 'handler' => …, 'auth' => true,  'permission' => 'settings.view'],
];
```

A route not in this table does not exist. A filesystem dispatcher would turn
"someone added a file under `api/`" into "a new public endpoint nobody
reviewed".

Two enforcement layers back this up:

- Each handler requires the `DFMS_API_DISPATCHED` constant and returns 404 if
  reached directly, so a request straight to `/api/v1/me.php` cannot skip the
  gates. **Tested.**
- `tests/phpunit/ApiRouteTableTest.php` parses the table and fails the build if
  a route is public without being on an approved list, omits a handler, or
  declares a permission that is not in `DFMS_PERMISSIONS`.

---

## 3. Request lifecycle

```
1. Method check          http_require_method()      → 405 + Allow, OPTIONS → 204
2. Authentication        isLoggedIn()               → 401 JSON (not a redirect)
3. Tenant context        requireTenantContext()     → 403 JSON
4. Org + farm membership requireAuth()              → 403 JSON
5. Permission            requireAuth($permission)   → 403 JSON
6. Dispatch              handler produces data
```

Steps 3–5 are a **single `requireAuth()` call** — the same one every HTML page
makes.

### The one deliberate difference

`requireAuth()` would *redirect* an unauthenticated caller to the login page:
correct for a browser, useless for an API client. `api/index.php` therefore
checks `isLoggedIn()` first and returns `401`.

It differs only in **how a failure is reported**, never in **whether it is
enforced**. Everything after that point is the shared gate.

### Content negotiation for shared aborts

`dfms_set_response_mode('json')` is set once at the top of an API request. Every
shared abort path — inside `requireAuth()`, `requireTenantContext()`,
`tenantFindOr404()` — then emits the JSON envelope instead of an HTML page.

**The authorization decision still lives in exactly one place.** Only its
presentation follows the caller's content type. HTML pages are unaffected, and a
test asserts they still return HTML.

---

## 4. Response format

### Success

```json
{ "data": { "...": "..." }, "meta": { "...": "..." } }
```

`data` is always present; `meta` only when there is something to say. A
consistent envelope means a client never has to guess whether it received a
result or an error.

### Error

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "correlation_id": "aa4de323f832c0d3",
    "details": { "production_date": "Production date must be a valid date (YYYY-MM-DD)." }
  }
}
```

| Field | Contract |
|---|---|
| `code` | **Stable machine token.** Clients branch on this, never on `message`. |
| `message` | Safe human text. May be reworded; never parse it. |
| `correlation_id` | Matches `X-Request-Id` and the server log line. |
| `details` | **Optional.** Validation field errors only. |

### Codes

| Code | HTTP | Meaning |
|---|---|---|
| `BAD_REQUEST` | 400 | Malformed request or unparseable JSON |
| `UNAUTHENTICATED` | 401 | No valid session |
| `FORBIDDEN` | 403 | Authenticated, but not permitted — or no tenant context |
| `NOT_FOUND` | 404 | No such endpoint, **or a resource outside your tenant** |
| `METHOD_NOT_ALLOWED` | 405 | Method not supported |
| `PAYLOAD_TOO_LARGE` | 413 | Body over 1 MB |
| `VALIDATION_ERROR` | 422 | Input failed validation |
| `INTERNAL_ERROR` | 500 | Unexpected fault — detail is logged, never returned |

`404` for a foreign resource is deliberate and inherited from Phase 1: a `403`
would confirm the record exists and allow enumeration across tenants.

### Never returned

SQL · stack traces · filesystem paths · class names · secrets · database error
text · internal hostnames.

`details` carries only validation messages this application generated about the
caller's own input. Server faults log their detail against the correlation id
and return the generic message. **Tested** for path, SQL and stack-trace
leakage on multiple endpoints.

### Headers

Every response carries `Content-Type: application/json; charset=utf-8`,
`Cache-Control: no-store, private` (responses are per-tenant and must never
reach a shared cache), `X-Content-Type-Options: nosniff`, and `X-Request-Id`.

The correlation id is returned on **success too**, so a client can quote it when
reporting a wrong-looking result — not only a failure.

---

## 5. Authentication and authorization

Authentication is the **existing session**. There is no API key or token
mechanism yet; adding one is a real piece of work (issuance, rotation, scoping,
revocation) and belongs to the phase that needs machine-to-machine access.

Authorization is `requireAuth($permission)` — identical to the HTML pages, using
the same `DFMS_PERMISSIONS` matrix. There is **no parallel authorization path**,
by construction: `api/index.php` has no permission logic of its own, only a
declared permission string per route.

Consequently the Phase 1 guarantees hold unchanged: unknown permissions deny,
membership is re-read from the database every request, and denials are audited.

---

## 6. Tenant isolation

Every identifier comes from the **tenant context**, never the request. There is
no `farm_id` or `organization_id` parameter on any endpoint, so there is nothing
to tamper with.

Verified by `tests/api_test.sh`:

- an org-2 user sees only org 2 in `/v1/me`; no trace of org 1
- adding `?farm_id=1&organization_id=1&admin_id=1` changes nothing — the caller
  still receives their own data
- a membership-less user gets a handled `403 FORBIDDEN`, not a crash
- `/v1/me` excludes `password`, `hash`, `csrf`, `session`, bcrypt strings and
  `is_platform_admin`

Future endpoints that take a resource id **must** resolve it with
`tenantFindOr404()`. That is not optional, and it is what makes a foreign id a
404 rather than a leak.

---

## 7. Endpoints

### `GET /api/v1/health` — public

The **only** unauthenticated endpoint.

```json
{ "data": { "status": "ok", "database": "ok" } }
```

`503` when the database is unreachable, so a load balancer can act on the status
code without parsing the body.

**Deliberately uninformative.** A public health endpoint is reachable by anyone
who can reach the host, so it discloses no version, hostname, schema name,
record count or configuration. Tests assert those absences.

### `GET /api/v1/me` — authenticated

The caller's own identity and resolved tenant context: user id and username,
active farm (id, name, type, role), organization (id, name, role), and how many
farms they can access.

Everything is derived from the tenant context. No id is read from the request.

### `GET /api/v1/settings` — requires `settings.view`

The configuration in force for the active farm **with the source of each value**
(`farm` / `organization` / `platform`), plus the farm's current date.

This endpoint exists to prove the architecture end to end: it reuses the Phase
2A resolver verbatim and contains no business logic of its own. If the
inheritance rules change, it follows for free.

---

## 8. Adding an endpoint

1. Write `api/v1/<name>.php`, opening with the `DFMS_API_DISPATCHED` guard.
2. Register it in the route table with its methods, handler, `auth` and
   `permission`.
3. Use `http_input()` → `DfmsValidator` → `http_validation_failed()`.
4. Resolve any resource id through `tenantFindOr404()`.
5. Respond with `http_success()` or `http_error()` — never `echo json_encode`.
6. Add cases to `tests/api_test.sh`, including a cross-tenant attempt.

`ApiRouteTableTest` will fail the build if step 2 is incomplete or the route is
accidentally public.

---

## 9. Versioning

`/v1/` is in the path. A breaking change means `/v2/`, with `/v1/` retained
until clients migrate. Additive changes — a new field in `data`, a new endpoint
— stay in `v1`; clients must ignore unknown fields.

`ApiRouteTableTest::testAllRoutesAreVersioned` enforces that every route is
versioned.

---

## 10. Evolution path

Roughly ordered by the phase that needs it. **None of this is built.**

| Capability | Needed by |
|---|---|
| Token / API-key authentication with scopes | any non-browser client |
| Rate limiting per token and per tenant | before any external exposure |
| Pagination, filtering and sorting conventions | first collection endpoint |
| Write endpoints (POST/PATCH/DELETE) with idempotency keys | mobile |
| Domain endpoints — animals, milk, health, inventory | Phase 3+ |
| Telemetry ingestion with backpressure | Phase 11 (IoT) |
| OpenAPI specification and generated docs | first external consumer |
| CORS | first browser-based client on another origin |
| Webhooks | integrations |

### Open risks

- **Session-only authentication** means the API is browser-shaped today. A
  machine client would have to hold a session cookie, which is not a real
  integration story.
- **No rate limiting.** `/v1/health` is public and unthrottled; it is cheap and
  discloses nothing, but it is unmetered.
- **No pagination conventions**, so the first collection endpoint will set the
  precedent — decide deliberately rather than by accident.
- **`api/index.php` is reached as `/api/index.php/v1/...`** under the PHP
  built-in server. Clean `/api/v1/...` URLs need a rewrite rule (Apache
  `.htaccess` or nginx `try_files`), which is deployment configuration and is
  not included.
