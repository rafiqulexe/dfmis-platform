# DFMIS SaaS Foundation

**Phase:** 2 — International Platform Architecture
**Date:** 27 August 2026
**Status:** Foundation only. Nothing in this document bills, delivers or restricts anything.

Subscription model, feature flags and notification preferences. Each is a
**seam** for a later phase to attach to — not a working subsystem.

**Companion documents:** [`DFMIS_INTERNATIONAL_ARCHITECTURE.md`](./DFMIS_INTERNATIONAL_ARCHITECTURE.md),
[`DFMIS_SECURITY_ARCHITECTURE.md`](./DFMIS_SECURITY_ARCHITECTURE.md),
[`DFMIS_ROADMAP.md`](./DFMIS_ROADMAP.md).

---

## Scope — read this first

| Subsystem | Built | Explicitly NOT built |
|---|---|---|
| Subscription | Plan catalogue, lifecycle states, limit definitions, read-only status display | Payment processing, Stripe/any provider, invoicing, charging, dunning, proration, **enforcement** |
| Feature flags | Registry, three-scope resolution, admin UI, audit | Any of the features the flags gate |
| Notifications | Preference model, category/channel registry, per-user-per-farm storage, preference UI | Delivery of any kind — no email, SMS, push, queue or provider |

**No existing user has been restricted by this phase.** Verified by a test that
walks every module reachable before Phase 2 and asserts it still returns 200.

---

## 1. Subscription foundation

### Extends, does not duplicate

`organizations.plan` and `organizations.status` **already existed** before this
phase. Migration 006 widened both and added lifecycle dates. No parallel
`subscriptions` table was created — an organization has exactly one
subscription, and a second table would be a slower way to read the same row.

| Column | Change |
|---|---|
| `plan` | `starter\|professional\|enterprise` → **+ `free`** |
| `status` | `active\|trial\|suspended\|cancelled` → **+ `past_due`, `expired`** |
| `trial_ends_at` | **new**, `DATE NULL` |
| `current_period_ends_at` | **new**, `DATE NULL` |

`MODIFY` on an ENUM preserves rows whose value remains in the new list; both
existing organizations were verified `active` with a listed plan before the
migration ran. Both new date columns are `NULL` on existing rows — **nobody is
retroactively put on a clock.**

Lifecycle dates are `DATE`, not `DATETIME`: a billing period boundary is a
calendar concept the customer reads in their own timezone, not an instant.

### Plan catalogue

Defined in `includes/subscription.php`. `-1` means unlimited.

| Plan | Farms | Animals | Users | AI calls/month |
|---|---|---|---|---|
| Free | 1 | 25 | 2 | 0 |
| Starter | 1 | 250 | 5 | 100 |
| Professional | 10 | 5,000 | 50 | 1,000 |
| Enterprise | ∞ | ∞ | ∞ | ∞ |

These are **defaults describing the intended shape of each tier, not contractual
commitments**. Commercial terms change far more often than code, so a later
phase should move them into the database where an operator can edit them without
a deploy. Keeping them in PHP now avoids a table nobody can yet administer.

### Lifecycle states

| Status | Grants access | Meaning |
|---|---|---|
| `active` | yes | Paying or free-tier, in good standing |
| `trial` | yes | Evaluating |
| `past_due` | **yes** | Payment failed — grace period, not a lockout |
| `suspended` | no | Administratively halted |
| `cancelled` | no | Customer ended the subscription |
| `expired` | no | Term ended without renewal |

`grants_access` is **recorded but not consumed by any code path.** It exists so
that when access gating is switched on, the policy is already written down in
one place rather than being invented inline at each call site.

`past_due` deliberately grants access: locking a farm out of its own animal
health records because a card expired is a bad outcome for the farm and for the
vendor.

### Limits are advisory

```php
dfms_plan_limit('animals');           // -1 for unlimited
dfms_plan_allows('animals', $count);  // advisory — nothing calls this to deny
```

`dfms_plan_allows()` is **not wired into any existing feature.** When enforcement
arrives it should warn before it blocks, so existing customers are never locked
out by a deploy.

An unknown plan returns `-1` (unlimited) rather than the strictest limit — a data
error must not accidentally throttle a paying customer.

### UI

`modules/settings/index.php` displays plan, status and limits read-only, with an
explicit note that limits are **not enforced**. There is no plan-change UI:
changing a commercial plan is a billing operation, and billing does not exist.
`dfms_update_subscription()` exists for a later phase and is gated on
`org.billing` (owner only).

---

## 2. Feature flag architecture

### Purpose

Controlled rollout for modules that do not exist yet. **Every flag in the
registry gates unbuilt functionality** — switching one on changes nothing today.

### Resolution

```
Farm flag           feature_flags(scope='farm',         scope_id=<farm_id>)
    ↓
Organization flag   feature_flags(scope='organization', scope_id=<org_id>)
    ↓
Platform flag       feature_flags(scope='platform',     scope_id=0)
    ↓
Registry default    DFMS_FEATURE_REGISTRY
```

This mirrors the settings hierarchy deliberately, so there is one mental model
rather than two.

**A row is an explicit ON or OFF at that level; absence means "defer downward".**
The resolver therefore checks row **existence** (`array_key_exists`), not
truthiness. A farm row with `enabled=0` must override an organization row with
`enabled=1` — a naive `if ($enabled)` gets this wrong. Tested in both directions.

### Registry

| Key | Default | Gates | Phase |
|---|---|---|---|
| `future_ai_copilot` | off | Conversational assistant | 7 |
| `future_disease_detection` | off | Image-based diagnostics | 6 |
| `future_iot` | off | Device telemetry | 11 |
| `advanced_reporting` | off | Report builder, benchmarking | 10 |
| `multi_farm_switching` | **on** | Farm switcher (built in Phase 1) | 1 |

**Unbuilt features default to OFF everywhere.** An unregistered key cannot be
read or written: reading returns `false` and logs; writing throws; posting one
to the UI returns HTTP 400, because an unregistered key is a tampered request,
not a user mistake.

### Security boundary

> **A feature flag is not an authorization mechanism and must never be used as one.**

```php
requireAuth('reports.view');                        // authorization
if (dfms_feature_enabled('advanced_reporting')) {}  // then rollout
```

Flags are resolved **after** authentication, authorization and tenant scoping,
never instead of them:

- `dfms_feature_rows()` queries only `scope_id` values belonging to the **active
  tenant**, so another organization's flags are never even loaded. Tested: a flag
  set by another tenant does not leak into this one.
- `dfms_set_feature()` takes the scope id from the tenant context, never from
  the request, so it cannot write a flag onto another tenant.
- The UI checks authorization **per scope**: farm scope needs
  `settings.features` (farm manager); organization scope needs `org.manage`
  (owner/admin).

### Storage

```sql
feature_flags(flag_id, flag_key, scope, scope_id, enabled, updated_by,
              created_at, updated_at,
              UNIQUE KEY uq_flag_scope (flag_key, scope, scope_id))
```

`scope_id` is polymorphic (0 for platform, otherwise an organization or farm id)
and therefore carries **no foreign key** — one column cannot reference two
tables. The alternative, nullable `organization_id`/`farm_id` columns, cannot be
covered by a `UNIQUE` key because MySQL treats NULLs as distinct, which would
permit duplicate flag rows. **Uniqueness matters more here than referential
integrity** on a row that is discarded when its scope disappears.

Setting a flag to `inherit` **deletes** the row, so an override can be removed
rather than only inverted.

---

## 3. Notification foundation

### Alerts vs notifications — the distinction this phase draws

| | Alert | Notification |
|---|---|---|
| **What** | A business event needing attention | Delivery of information to a person |
| **Belongs to** | The **farm** | A **user**, through a channel |
| **Storage** | `alerts` (pre-existing) | Not built |
| **Example** | "Antibiotic stock is low" | The email telling Rahim about it |
| **Status** | Working, **unchanged by this phase** | Preference model only |

One alert may produce zero, one or several notifications depending on who is
watching and how they want to hear.

Conflating the two is why notification systems become impossible to reason
about: **the alert must exist and persist regardless of whether anyone was
successfully emailed about it.** A test asserts the existing alert list and
mark-as-read flow still work after Phase 2.

### Preference model

```sql
notification_preferences(preference_id, admin_id, farm_id, category, channel,
                         enabled, created_at, updated_at,
                         UNIQUE KEY (admin_id, farm_id, category, channel))
```

Scoped **per user per farm**: someone who is a vet at one farm and a viewer at
another will not want the same notifications from both.

The table stores **deviations only**. A category/channel pair with no row uses
the channel's registry default, so adding a new category later does not require
backfilling a row for every user on every farm.

### Categories and channels

| Category | Covers |
|---|---|
| `health` | Urgent health events, treatments, vet follow-ups |
| `inventory` | Low stock and reorder warnings |
| `production` | Yield anomalies, missing daily records |
| `system` | Sign-in activity, membership and settings changes |

| Channel | Available today | Default |
|---|---|---|
| `in_app` | **yes** — the alerts page and topbar badge | on |
| `email` | no | off |
| `sms` | no | off |
| `push` | no | off |

**Availability is recorded explicitly and enforced.** `dfms_wants_notification()`
returns `false` for an unavailable channel regardless of the stored preference,
the UI renders those checkboxes disabled, and the server refuses to write them
rather than trusting the UI. A preferences screen that lets someone switch on SMS
which then silently never arrives is worse than one that says "not yet
available".

### Tenant scope

`dfms_set_notification_preference()` takes both the user id and the farm id from
the tenant context, never from the caller — a user can only ever change **their
own** preferences on **their active** farm.

### Not built

Delivery of any kind. No provider integration, no queue, no retry, no template
system, no digest batching, no unsubscribe handling.

---

## 4. Verification

| Area | Assertions |
|---|---|
| Feature flag scope precedence (farm > org > platform > default) | in `international_unit_test.php` |
| Cross-tenant flag isolation | unit + HTTP |
| Unregistered flag rejected (read, write, HTTP) | unit + HTTP |
| Subscription plans, statuses, `grants_access` | unit |
| Existing enterprise org keeps unlimited access | unit |
| Every pre-Phase-2 module still reachable | HTTP |
| Notification defaults and unavailable-channel handling | unit |
| Existing alerts + acknowledge still work | HTTP |
| Per-scope authorization on feature writes | HTTP |
| CSRF on every new POST surface | HTTP |

All passing — 412 assertions across 6 suites. Run with `bash tests/run_all.sh`.

---

## 5. Remaining risks and deferred work

### Accepted for now

| Risk | Why acceptable | Closes in |
|---|---|---|
| Plan limits in PHP, not the database | No operator UI exists to edit them yet; a table nobody can administer is worse | Phase 9/14 |
| `grants_access` has no consumer | Deliberate — enforcement is a commercial decision, and switching it on silently would lock out existing users | Phase 14 |
| No plan-change UI | Changing a plan is a billing operation and billing does not exist | Phase 14 |
| Feature flags have no expiry or audit-of-rollout | Five flags, none active; a rollout dashboard would be over-engineering | Phase 10+ |
| `feature_flags.scope_id` has no FK | Polymorphic by necessity; uniqueness was the higher-value constraint | — |
| Notification preferences have no delivery | The point of the phase — preferences must exist before delivery can read them | Later |
| Only `in_app` is available | Honest: three channels are modelled and visibly marked unavailable | Later |

### Deferred work identified during this phase

- **`alerts.alert_time` / `temperature_readings.reading_time`** are `DATETIME`
  columns holding a mix of two host clocks from before Phase 2. Converting them
  to `TIMESTAMP` needs an explicit, documented backfill — see
  [`DFMIS_INTERNATIONAL_ARCHITECTURE.md` §6](./DFMIS_INTERNATIONAL_ARCHITECTURE.md#6-timezone-strategy).
- **Organization member management UI.** Migration 006 backfilled
  `organization_users` as `member` (no authority); `seed_admin.php` explicitly
  elevates the demo `admin` to `owner`. There is no UI to change org roles, so
  ownership can currently only be granted by seeding or by hand.
- **Locale coverage.** ~45 strings externalized; module templates untouched.
- **Monetary `currency_code` convention** is documented but unenforced, because
  no monetary column exists yet to enforce it on.
