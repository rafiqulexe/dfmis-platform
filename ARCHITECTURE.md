# DFMS Architecture

DFMS is being built as a multi-tenant SaaS platform. It currently serves one
customer (Green Valley Dairy Group), but every architectural decision assumes many
independent customers will share this codebase and database.

**If you read only one section, read [Tenancy rules](#tenancy-rules-non-negotiable).**

---

## Tenant hierarchy

```
Platform
└── Organization        the customer / billing boundary
    └── Farm            a physical operation; belongs to exactly one organization
        └── Barn        (planned)
            └── Cow
                └── all operational data
```

### Why business tables carry `farm_id` and not `organization_id`

A farm belongs to exactly one organization, so an organization is always
derivable from a row's `farm_id`. Storing both on business rows would create
two independent tenant keys that can drift apart — and a stale one is a
security hole, because a query filtering on the wrong key silently returns
another customer's data.

**One tenant key per row. Organization is resolved by joining up through
`farms`, never denormalized down onto business tables.**

### Membership model

Roles live on the membership rows, not on the user:

| Table | Purpose |
|---|---|
| `organizations` | The customer. Carries `plan` and `status` for future billing. |
| `farms` | Belongs to one organization via `organization_id`. |
| `organization_users` | User ↔ organization, with `owner` / `admin` / `member`. |
| `farm_users` | User ↔ farm, with the farm-level role. **Authoritative for permissions.** |
| `admin_users` | Credentials only, plus `is_platform_admin`. |

This is what makes the following possible without a rewrite:

- one organization → many farms
- one user → many organizations
- **one user → different roles at different farms** (a vet at Farm A1 may be a viewer at Farm B1)

`admin_users.role` is legacy. It is retained only so the migration is
reversible — **never read it.** There is deliberately no global `currentRole()`.

---

## Tenancy rules (non-negotiable)

Applies to every new module, endpoint, report and background job.

1. **Never read a farm or organization id from the client.** Not from a query
   string, POST body, hidden field, header or cookie. Tenant context comes
   only from `includes/tenant.php`.
2. **Never write a raw tenant query.** Use the helpers in
   `includes/repository.php`. If you type `WHERE farm_id =` by hand, you are
   working around the layer that exists to stop leaks.
3. **Every id from a URL or form is hostile** until `tenantFind*()` confirms
   it belongs to the active farm — including ids in POST bodies used as
   foreign keys (e.g. `cow_id` on a health event).
4. **Cross-tenant misses return 404, never 403.** A 403 confirms the record
   exists, which lets an attacker enumerate other customers' data.
5. **Permission checks are server-side.** Hiding a button is presentation;
   `requirePermission()` on the action is the control.

### The API

```php
// tenant.php — context
currentUserId(); currentFarmId(); currentOrganizationId();
currentFarmRole();          // role on the ACTIVE farm
allowedFarmIds();           // resolved live from farm_users each request
switchFarm($id);            // rejects farms the user isn't a member of
assertFarmAccess($farmId);  // hard gate for a farm id from elsewhere

// repository.php — data access ({scope} expands to the farm filter)
tenantQuery($conn, "SELECT * FROM cows WHERE {scope} AND deleted_at IS NULL");
tenantQuery($conn, "SELECT h.* FROM health_events h JOIN cows c ON …
                    WHERE {scope}", [], 'h');   // 4th arg = table alias
tenantFind($conn, 'cows', 'cow_id', $id);       // null if missing OR foreign
tenantFindOr404($conn, 'cows', 'cow_id', $id);  // 404s instead
tenantExec($conn, "UPDATE cows SET … WHERE cow_id = ? AND {scope}", [$id]);

// permissions.php — RBAC against the active farm's role
hasPermission('cows.edit');       // for conditional UI
requirePermission('cows.delete'); // for the action itself
```

Membership is re-read from the database on every request and only cached for
that request, so revoking a user's access takes effect immediately rather
than lingering until their session expires.

### Standard page shape

```php
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('cows.edit');

$conn = getDBConnection();
$cow = tenantFindOr404($conn, 'cows', 'cow_id', $_GET['id'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    tenantExec($conn, "UPDATE cows SET name = ? WHERE cow_id = ? AND {scope}",
               [$_POST['name'], $cow['cow_id']]);
}
```

---

## Roles

Farm-level (`farm_users.role`): `farm_manager`, `veterinarian`, `staff`,
`accountant`, `viewer`.
Organization-level (`organization_users.role`): `owner`, `admin`, `member`.
Platform: `admin_users.is_platform_admin` — operates above the tenant layer
and bypasses farm permission checks.

The matrix lives in `DFMS_PERMISSIONS` (`includes/permissions.php`). Add new
capabilities there rather than inlining role comparisons.

---

## File storage

Uploads never sit under the web root. Public directories plus sequential ids
make files guessable across tenants.

```
storage/org_{organization_id}/farm_{farm_id}/{category}/{random}.{ext}
```

`includes/storage.php` enforces content-sniffed MIME validation (not the
client's claim), an 8 MB cap, random filenames, and a containment check so a
resolved path can never escape the tenant's directory. Files are served only
through a PHP handler that re-checks ownership.

---

## AI architecture

Every AI feature goes through `aiComplete()` in `includes/ai_service.php`.
Nothing may call a model directly. The helper, in order:

1. resolves organization / farm / user context,
2. **checks the plan allowance before spending anything**,
3. records the call in `ai_usage` with tenant, feature, model and tokens.

That ordering is what lets plan limits, usage dashboards and per-customer
cost reporting be added later without touching a single call site.
`DFMS_PLAN_AI_LIMITS` holds monthly allowances per plan. Billing is not
built — only the seam it attaches to.

When the model is unavailable or the tenant is over their limit, the caller
receives `ok => false` with a reason and **must render a degraded state.**
An unavailable model must never be presented as a confident answer.

---

## Audit log

`auditLog()` in `includes/audit.php` writes farm-scoped entries. Reads are
scoped like any other tenant data (`audit.view`, farm managers only), with
platform admins able to look across tenants. Audit writes never break the
user's action — a failure is logged server-side and swallowed.

---

## Adding a new business table

1. Add `farm_id INT NOT NULL` with an FK to `farms`.
2. Index the columns you filter on, leading with `farm_id`.
3. Add the table name to `DFMS_TENANT_TABLES` in `repository.php` —
   `tenantFind()` refuses tables not listed there.
4. Access it only through the repository helpers.

---

## What is deliberately NOT built yet

Foundations exist; the product surfaces do not. None of these require schema
or query rewrites to add:

- billing, invoicing, payment providers
- platform admin UI, tenant onboarding, customer support tooling
- the farm/organization switcher UI (`switchFarm()` already backs it)
- organization-level settings screens (`organizations.settings` JSON exists)
- feature flags, public API, webhooks, integrations

---

## Testing

```bash
bash tests/tenant_isolation_test.sh
```

Covers cross-tenant reads by URL id, cross-tenant writes, IDOR through POST
foreign keys, list-page leakage, RBAC enforcement per role, and
unauthenticated access. **Run it after any change to auth, tenancy,
permissions or data access.** Add a case whenever you add a module.

---

## Local setup

```bash
mysql -u root < database.sql
mysql -u root dfms < database/migrations/002_tenancy_and_extensions.sql
mysql -u root dfms < database/migrations/003_organizations_and_membership.sql
php database/seed_admin.php
php database/seed_demo_data.php
php -S localhost:8000 -t .
```

Demo accounts (dev only): `admin`/`admin123` (farm manager + platform admin),
`manager`/`manager123` (veterinarian), `staff`/`staff123` (staff).
Copy `.env.example` to `.env` for real credentials and the Anthropic API key.
