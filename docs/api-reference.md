# Ikabud Kernel OS — API Reference (V1)

All API endpoints use the `/api/v1/` prefix. Versioning is enforced from V1 onward.

## Content Negotiation

This API serves both the HTMX web app (HTML partials) and mobile/API clients (JSON).

**How it works**: Endpoints that normally return HTML will automatically return JSON when the client:
1. Sends `Accept: application/json` header, OR
2. Uses `Authorization: Bearer <token>` (no cookie)

HTMX requests (`HX-Request: true` header) always receive HTML.

---

## Authentication

### Web (HTMX) Flow

JWT is typically stored in an `httpOnly` cookie (name configured in `config/app.php`). Set automatically on login.

In a multi-module deployment, some modules may use their own page-session cookie (for a standalone UX).
Modules declare this via `auth_cookie` in `module.json`, and the kernel may recognize these cookies when resolving `app()->user()`.
This keeps kernel layout rendering consistent (e.g. navigation) even when the active entry app is a module.

### Mobile / API Flow

1. `POST /api/v1/auth/login` with `Accept: application/json` → receive `token` + `refresh_token`
2. Store both tokens securely (Android Keystore / EncryptedSharedPreferences)
3. Send `Authorization: Bearer <token>` on every subsequent request
4. JWT expires after **4 hours** — use `POST /api/v1/auth/refresh` to get a new one
5. Refresh token expires after **30 days** — re-login required

### POST /api/v1/auth/login

**Rate limit**: 10 attempts per 5 minutes per IP.

**Request** (JSON):
```json
{ "username": "admin", "password": "secret" }
```

**Web response** (browser):
```json
{ "ok": true, "redirect": "/inventory-ledger" }
```

**API response** (when `Accept: application/json`):
```json
{
  "ok": true,
  "token": "eyJhbGciOiJIUzI1NiIs...",
  "expires_in": 14400,
  "refresh_token": "a1b2c3d4e5f6...",
  "refresh_expires_in": 2592000,
  "user": {
    "id": 1,
    "username": "admin",
    "name": "Admin User",
    "role": "admin"
  }
}
```

**Errors**:
- `422` — Missing username/password
- `401` — Invalid credentials
- `429` — Rate limit exceeded (includes `retry_after` field)

### POST /api/v1/auth/refresh

Exchange a refresh token for a new JWT + new refresh token (rotation).

**Request** (JSON):
```json
{ "refresh_token": "a1b2c3d4e5f6..." }
```

**Response**:
```json
{
  "ok": true,
  "token": "eyJhbGciOiJIUzI1NiIs...",
  "refresh_token": "new_refresh_token...",
  "expires_in": 14400,
  "refresh_expires_in": 2592000
}
```

**Errors**:
- `422` — Missing refresh_token
- `401` — Invalid, expired, or revoked refresh token

**Security**: Old refresh token is revoked on each use (rotation). If a stolen token is used after the legitimate user has already refreshed, both tokens are invalidated.

### GET /api/v1/auth/logout

**Web**: Clears cookie, redirects to `/login`.
**API** (when `Accept: application/json`): Returns `{ "ok": true }`.

### GET /api/v1/me

Token introspection / user profile. Returns current user info and assigned branches.

**Response**:
```json
{
  "ok": true,
  "user": {
    "id": 1,
    "username": "admin",
    "name": "Admin User",
    "role": "admin"
  },
  "branches": [
    { "id": 1, "code": "BR001", "name": "Main Branch" }
  ]
}
```

**Errors**:
- `401` — Invalid or expired token

### GET /api/v1/health

Public health check (no auth required).

```json
{ "ok": true, "app": "Ikabud Kernel", "time": "2026-02-21T12:00:00+00:00" }
```

---

## Reference Data Endpoints

### GET /api/v1/modules/inventory-ledger/branches

Returns branches the authenticated user has access to (all branches for supervisor/admin).

**Response**:
```json
{
  "ok": true,
  "branches": [
    { "id": 1, "code": "BR001", "name": "Main Branch" }
  ]
}
```

### GET /api/v1/modules/inventory-ledger/products

Returns all active products.

**Response**:
```json
{
  "ok": true,
  "products": [
    { "id": 1, "sku": "PAN-001", "name": "Pandesal", "base_price": "5.00" }
  ]
}
```

---

## Ledger Endpoints

### POST /api/v1/modules/inventory-ledger/open

**Explicit initialization**: Create ledger rows for a branch/date if they don't exist. Enforces sequential integrity (previous day must be locked) and future date guard.

GET does **not** auto-generate rows. This POST must be called first to open a ledger date.

**Body**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch |
| `ledger_date` | string (Y-m-d) | yes | Date to initialize |

**Response** (JSON):
```json
{ "ok": true, "ledger_date": "2026-02-21", "branch_id": 1, "is_locked": false, "status": "OPEN", "rows_created": 15 }
```

**Errors**:
- `422` — Future date, previous day not locked

### GET /api/v1/modules/inventory-ledger/rows

Read-only: loads existing ledger rows for a branch/date. Does **not** create rows.

**Query params**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch to load |
| `ledger_date` | string (Y-m-d) | yes | Date to load |

**Web response**: HTML partial (`ledger-rows.disyl`)

**API response** (JSON):
```json
{
  "ok": true,
  "ledger_date": "2026-02-21",
  "branch_id": 1,
  "is_locked": false,
  "status": "OPEN",
  "rows": [
    {
      "id": 1,
      "product_name": "Pandesal",
      "beginning_balance": 100,
      "additional_stock": 50,
      "withdrawals": 10,
      "sales_units": 30,
      "price_snapshot": 5.00,
      "ending_balance": 110,
      "amount": 150.00,
      "status": "OPEN",
      "is_reopened": false,
      "version": 1
    }
  ]
}
```

### POST /api/v1/modules/inventory-ledger/rows/{productId}

Update a single ledger row's editable quantities.

**Body** (JSON):
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch context |
| `ledger_date` | string | yes | Ledger date |
| `additional_stock` | int | yes | Additional stock received |
| `withdrawals` | int | yes | Units withdrawn |
| `sales_units` | int | yes | Units sold |
| `change_reason` | string | if values changed | Reason for correction |
| `version` | int | recommended | Optimistic lock — current row version |

**Optimistic locking**: If `version` is provided and doesn't match the server's current version, the update is rejected with `422` and error `"Version conflict: row was modified by another user. Reload and retry."` The response always includes the new `version` for the next update.

**API response** (JSON):
```json
{
  "ok": true,
  "row": {
    "id": 1,
    "product_name": "Pandesal",
    "additional_stock": 60,
    "ending_balance": 120,
    "amount": 150.00,
    "status": "OPEN",
    "version": 2
  }
}
```

**Errors**:
- `403` — User role cannot edit / not assigned to branch
- `422` — Negative values, negative ending balance, row is locked, version conflict, or missing change_reason

### POST /api/v1/modules/inventory-ledger/lock

Lock all ledger rows for a branch/date. Supports `X-Idempotency-Key`.

**Body**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch |
| `ledger_date` | string | yes | Date to lock |
| `reason` | string | no | Lock reason |
| `signature` | string | no | Authorizer signature |

**Response** (JSON):
```json
{ "ok": true, "ledger_date": "2026-02-21", "locked": true }
```

**Errors**:
- `403` — Only supervisor/admin can lock
- `422` — Negative balances exist (cannot lock)

### ~~POST /unlock~~ — REMOVED

Unlock has been removed in V1 hardening. Use `POST /api/v1/modules/inventory-ledger/reopen` for controlled temporary access. Calling the old endpoint returns `410 Gone`.

---

## Supervisor Backfill Endpoints

### POST /api/v1/modules/inventory-ledger/reopen

Reopen a locked ledger date for backfill corrections (supervisor/admin only).

**Rate limit**: 5 attempts per 10 minutes per user.
**Supports**: `X-Idempotency-Key` header.
**Authentication**: Requires **password re-entry** (not a text signature).

**Body**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch |
| `ledger_date` | string | yes | Date to reopen |
| `reason` | string | **yes** | Justification for reopening |
| `signature` | string | **yes** | **User's current password** for re-authentication |

**Response**:
```json
{
  "ok": true,
  "reopen_expires_at": "2026-02-21T21:30:00",
  "reopen_window_minutes": 30
}
```

**Errors**:
- `403` — Only supervisor/admin
- `422` — Not locked, max reopens reached, invalid password, missing reason
- `429` — Rate limit exceeded

### POST /api/v1/modules/inventory-ledger/backfill

Apply a correction to a reopened ledger row.

**Rate limit**: 20 corrections per 10 minutes per user.
**Supports**: `X-Idempotency-Key` header.

**Body**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch |
| `ledger_date` | string | yes | Ledger date |
| `product_id` | int | yes | Product to correct |
| `additional_stock` | int | yes | Corrected value |
| `withdrawals` | int | yes | Corrected value |
| `sales_units` | int | yes | Corrected value |
| `correction_reason` | string | **yes** | Reason for correction |

**Response**:
```json
{ "ok": true, "ending_balance": 120, "amount": 150.00 }
```

**Errors**:
- `403` — Only supervisor/admin
- `422` — Not reopened, window expired, no changes, negative balance, missing reason
- `429` — Rate limit exceeded

### GET /api/v1/modules/inventory-ledger/corrections

Get correction summary for a branch/date.

**Query params**:
| Param | Type | Required |
|-------|------|----------|
| `branch_id` | int | yes |
| `ledger_date` | string | yes |

**Response**:
```json
{
  "ok": true,
  "correction_count": 2,
  "corrections": [
    {
      "id": 1,
      "field_name": "additional_stock",
      "old_value": 50,
      "new_value": 60,
      "correction_reason": "Delivery receipt found",
      "corrected_by_name": "Supervisor A",
      "corrected_at": "2026-02-21 21:05:00"
    }
  ]
}
```

### GET /api/v1/modules/inventory-ledger/notifications

Get unread admin notifications (admin only).

**Response**:
```json
{
  "ok": true,
  "notifications": [
    {
      "id": 1,
      "type": "ledger.reopen",
      "title": "Ledger Reopened for Backfill",
      "message": "Supervisor reopened ledger for 2026-02-20...",
      "created_at": "2026-02-21 21:00:00"
    }
  ]
}
```

### POST /api/v1/modules/inventory-ledger/notifications/{notificationId}/read

Mark a notification as read.

**Response**: `{ "ok": true }`

---

## Audit Log

### GET /api/v1/audit-log

Global audit log endpoint (admin/supervisor only). Returns all auditable actions across modules.

**Query params**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `module` | string | no | Filter by module (e.g. `inventory-ledger`) |
| `branch_id` | int | no | Filter by branch |
| `actor_id` | int | no | Filter by user who performed action |
| `date_from` | string (Y-m-d) | no | Start date |
| `date_to` | string (Y-m-d) | no | End date |
| `limit` | int (1–500) | no | Default 50 |
| `offset` | int | no | Default 0 |

**Response**:
```json
{
  "ok": true,
  "entries": [
    {
      "id": 1,
      "tenant_id": 1,
      "module": "inventory-ledger",
      "actor_user_id": 2,
      "actor_username": "supervisor1",
      "branch_id": 1,
      "action": "ledger.reopen",
      "entity_type": "daily_ledger",
      "entity_id": 42,
      "reason": "Missing delivery receipt",
      "old_data": null,
      "new_data": { "status": "REOPENED" },
      "metadata": { "reopen_expires_at": "2026-02-21T21:30:00" },
      "created_at": "2026-02-21 21:00:00"
    }
  ],
  "pagination": { "total": 85, "limit": 50, "offset": 0, "has_more": true }
}
```

**Errors**:
- `401` — Authentication required
- `403` — Only admin/supervisor

---

## Capability Introspection & Reliability (Admin)

These endpoints are intended for **admin-only tooling** (internal dashboards, observability, incident response). They all require an **admin** kernel user and must be called over **HTTPS**.

### GET /api/v1/admin/capabilities

List all registered capabilities and their providers.

**Response**:

```json
{
  "ok": true,
  "capabilities": [
    {
      "id": "kernel.auth.authenticate@1",
      "providers": [
        {
          "provider": "kernel",
          "priority": 1000,
          "modes": ["pipeline"],
          "schema": {
            "type": "object",
            "required": ["username", "password"],
            "properties": {
              "username": { "type": "string" },
              "password": { "type": "string" }
            }
          },
          "policy": {
            "default": {"deny_providers": []}
          }
        }
      ]
    }
  ],
  "request_id": "f6b7fdbe540c9f27"
}
```

Use this to drive admin UIs (e.g. showing which modules provide which capabilities).

### GET /api/v1/admin/capabilities/metrics

Return per-capability/provider reliability metrics collected by the Capability Bus.

**Response**:

```json
{
  "ok": true,
  "metrics": {
    "kernel.auth.authenticate@1|kernel": {
      "count": 120,
      "errors": 3,
      "durations": [85, 82, 90, ...],
      "p95_ms": 110,
      "last_ms": 87
    }
  },
  "request_id": "8338717ae5c8121f"
}
```

- `count` / `errors` let you spot noisy or failing providers.
- `p95_ms` is the rolling 95th percentile latency for that capability+provider.

### GET /api/v1/admin/capabilities/breakers

Inspect the current **circuit breaker** state for capability providers.

**Response**:

```json
{
  "ok": true,
  "breakers": {
    "kernel.auth.authenticate@1|guidance": {
      "failures": 6,
      "first_failure": 1710000000,
      "open_until": 1710000300
    }
  },
  "request_id": "c8efacf7a570367e"
}
```

If `open_until` is in the future, the circuit is currently open and calls will fail fast.

### POST /api/v1/admin/capabilities/breakers/reset

Reset circuit breaker state for a specific capability/provider pair, or for all providers.

**Body** (JSON):

| Field           | Type   | Required | Description                               |
|-----------------|--------|----------|-------------------------------------------|
| `capability_id` | string | no       | Capability id (e.g. `kernel.auth.authenticate@1`) |
| `provider_id`   | string | no       | Provider id (e.g. `daily-ledger`)         |

**Behavior**:

- If **both** `capability_id` and `provider_id` are supplied: reset the breaker **just for that provider**.
- If neither is supplied: reset **all** breakers.

**Response**:

```json
{
  "ok": true,
  "cleared": 3,
  "request_id": "5e1a28f40cb638da"
}
```

`cleared` is the number of breaker entries removed.

---

## Kernel Integrations (Superadmin)

These endpoints back the Kernel Integration Bridge registry UI at `/kernel/integrations`.

- Auth scope: kernel `superadmin` for the page, kernel `admin` or `superadmin` for the JSON API
- CSRF: required for `POST` and `DELETE` via `_token` or `X-CSRF-Token`
- Response correlation: all JSON responses include `request_id`

### GET /api/v1/kernel/integrations

Return the current bridge registry plus the most recent execution logs.

**Response**:

```json
{
  "ok": true,
  "integrations": [
    {
      "id": 12,
      "name": "Ecommerce Order Reserve",
      "trigger_event": "ecommerce.order.created",
      "target_capability": "wms.stock.reserve@1",
      "mapping_json": "{\"reference_type\":\"order\",\"reference_id\":\"{{order.id}}\",\"items\":\"{{order.items}}\"}",
      "is_active": 1,
      "event_source": "eventbus",
      "version_lock": "wms.stock.reserve@1"
    }
  ],
  "logs": [
    {
      "integration_id": 12,
      "integration_name": "Ecommerce Order Reserve",
      "status": "success",
      "request_id": "e4f9a6c2f440f2f1",
      "correlation_id": "4cc4d6bc5f5d7a24",
      "duration_ms": 14,
      "error_message": null
    }
  ],
  "request_id": "e4f9a6c2f440f2f1"
}
```

### POST /api/v1/kernel/integrations

Create a new bridge, or perform an action against an existing bridge.

**Create Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Human-readable bridge name |
| `trigger_event` | string | yes | Event key emitted into the kernel event bus |
| `target_capability` | string | yes | Capability id or alias to invoke |
| `mapping_json` | object or JSON string | yes | Declarative payload map |
| `is_active` | int/bool | no | Defaults to active |
| `event_source` | string | no | Defaults to `eventbus` |
| `version_lock` | string | no | Fully resolved capability id expected at runtime |

**Action Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `_action` | string | yes | `toggle` or `promote` |
| `id` | int | yes | Bridge row id |

**Behavior**:

- Create validates that `mapping_json` is a JSON object.
- Create rejects duplicate `(trigger_event, target_capability)` pairs.
- Create rejects unresolved or unregistered capabilities.
- `toggle` flips `is_active` and records a kernel audit entry.
- `promote` converts the bridge mapping into a Kernel Trigger rule and marks the bridge `event_source` as `promoted`.

**Example Create Response**:

```json
{
  "ok": true,
  "id": 12,
  "request_id": "7bc713fa67204c87"
}
```

**Common Errors**:

- `400` — missing required fields or invalid `mapping_json`
- `403` — forbidden role/source
- `409` — duplicate bridge for the same event/capability pair
- `419` — invalid CSRF token
- `422` — unresolved capability or invalid non-empty contract fields

### DELETE /api/v1/kernel/integrations?id={id}

Delete a bridge row.

**Behavior**:

- Requires a valid CSRF token.
- Returns success even when `id=0`, but only deletes when a positive id is supplied.
- Records a kernel audit entry when an existing bridge row is removed.

**Response**:

```json
{
  "ok": true,
  "request_id": "2d444475451c4a8f"
}
```

---

## Report Endpoints

All report endpoints support content negotiation. All require `branch_id` and date range.

**Validation rules**: `date_to` must be >= `date_from`. Maximum range is 1 year.

**Common query params**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `branch_id` | int | yes | Branch to report on |
| `date_from` | string (Y-m-d) | yes | Start date |
| `date_to` | string (Y-m-d) | yes | End date |
| `product_id` | int | no | Filter by product |

- **GET /api/v1/modules/inventory-ledger/reports/daily-branch-summary** — Daily totals
- **GET /api/v1/modules/inventory-ledger/reports/product-sales-summary** — Sales by product
- **GET /api/v1/modules/inventory-ledger/reports/withdrawal** — Withdrawal rows
- **GET /api/v1/modules/inventory-ledger/reports/monthly-sales-aggregation** — Monthly aggregation

---

## Daily Ledger Production Endpoints (2026)

These endpoints are used by the Daily Ledger production workflow UI.

### GET /daily-ledger/api/v1/production/destinations

Returns branch destinations visible to the authenticated user scope.

### GET /daily-ledger/api/v1/production/movements

Returns production movement history.

Query params:
- `date_from` (optional, default: last 7 days)
- `date_to` (optional, default: today)
- `movement_type` (optional: `withdrawal`, `output`, `reverse`)

### POST /daily-ledger/api/v1/production/withdrawal

Create a withdrawal movement.

Body fields:
- `destination_branch_id` (required)
- `product_id` (required)
- `ledger_date` (required)
- `quantity` (required, > 0)
- `flow_mode` (optional: `production` or `legacy`)
- `reason` (optional)
- `client_op_id` (optional idempotency key)

### POST /daily-ledger/api/v1/production/output

Create an output movement. Body fields are the same as withdrawal.

Notes:
- this endpoint returns `403` when the `production_output_enabled` feature flag is disabled
- feature activation is controlled by Kernel Admin via Daily Ledger settings

### POST /daily-ledger/api/v1/production/reverse

Reverse a prior withdrawal/output movement.

Body fields:
- `reference_movement_id` or `reference_movement_uuid` (required)
- `reason` (required)
- `client_op_id` (optional)

### POST /daily-ledger/api/v1/production/sync-batch

Submit multiple production operations in one request.

Body fields:
- `operations`: array of movement payloads (`type` = `withdrawal`/`output`/`reverse`)

Notes:
- when output feature activation is disabled, operations with `type=output` are rejected per row in `results[]`

---

## Daily Ledger Role Permission Settings (2026)

### GET /daily-ledger/admin/settings

Admin page for configuring role permissions used by Daily Ledger overrides.

### POST /daily-ledger/api/v1/admin/settings/permissions

Updates role permission matrix.

Settings body fields:
- `operating_region` (optional string from predefined admin choices)
- `operating_timezone` (optional string from predefined timezone choices, such as `Asia/Manila`)
- `auto_close_enabled` (optional boolean)
- `close_of_day_time` (optional string in `HH:MM` 24-hour format)
- `production_output_enabled` (optional boolean, Kernel Admin only)
- `supervisor_ledger_override` (optional boolean)
- `supervisor_production_override` (optional boolean)
- `prod_ledger_override` (optional boolean)
- `prod_production_override` (optional boolean)

Supported action permissions:
- `ledger.override`
- `production.override`

Default policy:
- admin: allowed
- supervisor: denied
- production_in_charge: denied
- cashier: denied

Close-of-day behavior:
- business-date calculations use the configured Daily Ledger operating timezone
- when `auto_close_enabled` is true, the cutoff time applies to both cashier ledger and production movement workflows
- previous business day closure is request-driven and occurs on the next relevant request after cutoff
- allowed auto-close cutoff range is `00:00` to `11:59` only
- requests that attempt to save an auto-close cutoff outside that range return `422`
- requests from non-kernel admins attempting to change feature activation return `403`

Product output profile (Option A batch count):
- per product metadata includes `output_pieces_per_batch` and `output_unit_label`
- production output quantity is computed as `batch_count * output_pieces_per_batch`

---

## Pagination (API clients only)

Any endpoint that returns a `rows`, `corrections`, or `notifications` array supports optional pagination:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int (1–500) | none (all rows) | Max rows to return |
| `offset` | int | 0 | Number of rows to skip |

When `limit` is provided, the response includes a `pagination` object:

```json
{
  "ok": true,
  "rows": [...],
  "pagination": {
    "total": 150,
    "limit": 50,
    "offset": 0,
    "has_more": true
  }
}
```

Pagination is ignored for HTMX web requests.

---

## Idempotency

Mutation endpoints (`lock`, `reopen`, `backfill`) support the `X-Idempotency-Key` header.

- Send a unique key (e.g. UUID) with each request
- If the same key is sent again, the server returns the **cached response** without re-executing
- Cached responses include `X-Idempotent-Replayed: true` header
- Keys expire automatically (garbage collected)

This prevents double-submissions from network retries.

---

## Optimistic Locking

Every ledger row includes a `version` field (integer, starts at 1). On each update, the version increments.

**Client flow**:
1. GET rows → each row has `version: N`
2. POST update with `version: N`
3. If another user updated the row first (version is now N+1), server returns `422` with `"Version conflict"`
4. Client reloads rows, gets new version, retries

Version is optional — omitting it skips the check (backwards compatible with web HTMX).

---

## Rate Limiting

| Endpoint | Limit | Window |
|----------|-------|--------|
| `POST /auth/login` | 10 attempts | 5 minutes (per IP) |
| `POST .../reopen` | 5 attempts | 10 minutes (per user) |
| `POST .../backfill` | 20 attempts | 10 minutes (per user) |

When rate limited, the response is `429` with:
```json
{ "ok": false, "error": "Rate limit exceeded. Try again later.", "retry_after": 180 }
```

The `Retry-After` HTTP header is also set.

---

## Error Response Format

All API errors return JSON:

```json
{
  "ok": false,
  "error": "Human-readable error message"
}
```

HTTP status codes:
- `401` — Authentication required
- `403` — Authorization denied (wrong role or branch)
- `409` — Version conflict (optimistic locking — row modified by another user)
- `410` — Endpoint removed (e.g. `/unlock`)
- `422` — Domain rule violation (negative balance, locked row, etc.)
- `429` — Rate limit exceeded
- `500` — Server error

---

## CMS AI Automation API

All endpoints require the CMS capability `ai.automation.manage`.

### GET /api/v1/cms/ai/plans

List all AI content plans.

**Query params**: `limit` (default 50)

**Response** (`200`):
```json
{
    "ok": true,
    "plans": [
        {
            "id": 1,
            "topic": "Sourdough bread baking",
            "content_type": "post",
            "content_mode": "tutorial",
            "cadence": "weekly",
            "is_active": true,
            "next_run_at": "2026-03-28T08:00:00Z"
        }
    ]
}
```

### GET /api/v1/cms/ai/plans/{id}

Get a single plan by ID.

### GET /api/v1/cms/ai/runs

List recent generation run history.

**Query params**: `limit` (default 50), `plan_id` (filter by plan)

### POST /api/v1/cms/ai/plans

Create a new AI content plan.

**Body** (JSON):

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `topic` | string | required | Content topic |
| `content_type` | string | `post` | CMS content type slug |
| `content_mode` | string | `standard` | Generation style: `standard`, `tutorial`, `opinion`, `comparison`, `checklist`, `expert` |
| `prompt_template` | string | built-in | Prompt template with `{topic}`, `{content_type}` placeholders |
| `writing_style` | string | `Clear, specific, and useful.` | Style instruction |
| `target_audience` | string | `""` | Target audience description |
| `keywords` | array | `[]` | SEO/topic keywords |
| `summary_enabled` | boolean | `true` | Generate summary |
| `seo_enabled` | boolean | `true` | Generate SEO title/description |
| `auto_refine_policy` | string | `high_severity_once` | `off`, `high_severity_once`, `always_once` |
| `auto_publish_policy` | string | `off` | `off`, `high_confidence_low_sensitivity` |
| `confidence_threshold` | integer | `85` | 0–100 threshold for auto-refine/publish |
| `visual_mode` | string | `suggest_media` | `none`, `suggest_media` |
| `cadence` | string | `manual` | `manual`, `daily`, `weekly`, `monthly` |
| `cadence_interval` | integer | `1` | Interval multiplier for cadence |
| `publish_offset_minutes` | integer | `0` | Minutes after run to schedule publish |
| `search_grounding_enabled` | boolean\|null | `null` | `null` = global setting, `true`/`false` = override |
| `is_active` | boolean | `true` | Whether plan runs on schedule |

### POST /api/v1/cms/ai/plans/{id}

Update an existing plan. Accepts same fields as create. Only provided fields are updated.

### POST /api/v1/cms/ai/plans/{id}/toggle

Toggle plan active state.

**Response** (`200`):
```json
{ "ok": true, "is_active": false }
```

### POST /api/v1/cms/ai/plans/{id}/run

Manually trigger a generation run for the plan immediately.

**Response** (`200`):
```json
{ "ok": true, "run_id": 42, "content_id": 17 }
```

### POST /api/v1/cms/ai/plans/{id}/delete

Delete a plan and its run history.

### POST /api/v1/cms/content/{id}/ai/refine

Run an on-demand AI refinement pass on existing content.
Returns updated content fields (title, body, summary, seo_title, seo_description).

---

## CMS Extensions Installer API (Admin)

These endpoints are available when the CMS module is installed and the requester has CMS settings-management capability.

All mutating calls require a valid CSRF token (`_token`) in form payloads.

### POST /api/v1/cms/themes/upload

Upload and install/upgrade a theme ZIP.

**Content type**: `multipart/form-data` with file field `theme`.

**Success response** (`200`):
```json
{
  "ok": true,
  "theme": { "name": "My Theme", "slug": "my-theme" },
  "upgraded": false,
  "message": "Theme \"My Theme\" installed."
}
```

**Common errors**:
- `400` — Missing file / non-zip / size limit exceeded
- `400` — ZIP safety failure (path traversal, absolute path, null byte, or symlink entry)
- `400` — Invalid `theme.json` (malformed JSON or schema validation failure)
- `400` — Invalid theme slug

### POST /api/v1/cms/themes/activate

Activate a theme by slug, or pass `slug=default` to revert.

**Body** (form):
- `slug` (string)
- `_token`

**Common errors**:
- `404` — Theme not found

### POST /api/v1/cms/themes/{slug}/delete

Delete an installed theme.

**Common errors**:
- `400` — Missing slug
- `400` — Cannot delete active theme
- `404` — Theme not found

### POST /api/v1/cms/modules/upload

Upload and install/upgrade a CMS sub-module ZIP.

**Content type**: `multipart/form-data` with file field `module`.

**Success response** (`200`):
```json
{
  "ok": true,
  "module": { "id": "my-module", "name": "My Module" },
  "upgraded": false,
  "message": "Module \"My Module\" installed and enabled."
}
```

**Common errors**:
- `400` — Missing file / non-zip / size limit exceeded
- `400` — ZIP safety failure (path traversal, absolute path, null byte, or symlink entry)
- `400` — Invalid `module.json` (malformed JSON or schema validation failure)
- `400` — Invalid `id` format
- `400` — Attempt to overwrite kernel/application module

### POST /api/v1/cms/modules/toggle

Enable/disable a module that was installed via CMS.

**Body** (form):
- `module_id` (string)
- `enable` (`1` or `0`)
- `_token`

**Common errors**:
- `400` — Missing `module_id`
- `403` — Module is kernel-managed and cannot be toggled from CMS

### POST /api/v1/cms/modules/{module_id}/delete

Delete a CMS-installed module (must be disabled first).

**Common errors**:
- `400` — Missing `module_id`
- `400` — Module is enabled (disable first)
- `403` — Module is kernel-managed and cannot be deleted from CMS
- `404` — Module directory not found

### Curl examples

Use an authenticated CMS admin cookie jar and a valid CSRF token.

```bash
# Example session values
BASE_URL="http://cmsmodule.test"
COOKIE_JAR="/tmp/cms_cookies.txt"
CSRF_TOKEN="<csrf_token_here>"

# 1) Upload theme ZIP
curl -s -b "$COOKIE_JAR" -F "theme=@/path/to/theme.zip" -F "_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/themes/upload"

# 2) Activate theme
curl -s -b "$COOKIE_JAR" -X POST -d "slug=native-default&_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/themes/activate"

# 3) Delete theme
curl -s -b "$COOKIE_JAR" -X POST -d "_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/themes/native-default/delete"

# 4) Upload module ZIP
curl -s -b "$COOKIE_JAR" -F "module=@/path/to/module.zip" -F "_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/modules/upload"

# 5) Disable module (enable=0) or enable (enable=1)
curl -s -b "$COOKIE_JAR" -X POST -d "module_id=my-module&enable=0&_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/modules/toggle"

# 6) Delete module
curl -s -b "$COOKIE_JAR" -X POST -d "_token=$CSRF_TOKEN" \
  "$BASE_URL/api/v1/cms/modules/my-module/delete"

# 7) Inspect recent installer audit logs
tail -n 50 storage/logs/app.log | grep -i "CMS installer audit"
```

### Installer audit logs

Installer lifecycle events (success and blocked/failed attempts) are logged to:

- `storage/logs/app.log`

Look for lines containing:

- `CMS installer audit`

---

## Superadmin API

All superadmin endpoints require authentication with a user whose role is `superadmin` **and** whose auth source is `kernel`. Returns `403` with `{"ok": false, "error": "Superadmin only"}` if the caller does not meet both conditions. This prevents module-defined `superadmin` roles (e.g., CMS's own superadmin role) from accessing kernel-level settings.

### GET /superadmin/settings

Renders the superadmin feature-settings page (HTML). In multi-tenant mode, accepts an optional `tenant_id` query parameter to scope the view.

**Query parameters:**

| Param | Type | Description |
|-------|------|-------------|
| `tenant_id` | integer (optional) | Target tenant. Defaults to the first active tenant if omitted. |

**Behavior:**
- Queries `kernel_tenants` + `kernel_tenant_domains` to populate the tenant picker dropdown.
- Builds a relevance whitelist from the selected tenant's `entry_module_id`, CMS `_installed_submodules`, capability-provider dependencies, explicit tenant module overrides, and modules that declare table overlap with the tenant's entry module.
- Filters modules by both whitelist relevance and `isModuleEnabledForTenant()`.
- Only modules with non-empty `settings_fields` in their manifest are shown.

### GET /api/v1/superadmin/modules

Returns JSON listing all discovered modules.

**Response:**
```json
{
  "ok": true,
  "modules": [
    { "id": "contact-form", "name": "Contact Form", "version": "1.0.0", "enabled": true }
  ]
}
```

### POST /api/v1/superadmin/modules/settings

Updates settings for a specific module, optionally scoped to a tenant.

**CSRF:** Required (enforced via `csrfEnforce()`).

**Request body (JSON):**

```json
{
  "module_id": "contact-form",
  "tenant_id": 202,
  "settings": {
    "recipient_email": "info@example.com",
    "max_submissions": "50"
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `module_id` | string | Yes | ID of the target module. |
| `tenant_id` | integer | Yes (multi-tenant) | Target tenant ID. Must be an active tenant. |
| `settings` | object | Yes | Key-value pairs matching the module's `settings_fields` manifest. |

**Validation:**
- Only keys declared in the module's `settings_fields` manifest are accepted.
- Type coercion is applied (`checkbox` → bool, `number` → numeric, `select` → validated against declared options).
- Internal keys (e.g., `allow_kernel_admin`) cannot be modified through this endpoint.

**Success response:**
```json
{
  "ok": true,
  "module_id": "contact-form",
  "settings": { "recipient_email": "info@example.com", "max_submissions": "50" }
}
```

**Error responses:**
- `403` — Not superadmin
- `404` — Module not found/disabled, or tenant not found
- `422` — Missing `module_id`/`settings`, or missing `tenant_id` in multi-tenant mode
- `500` — Could not verify tenant

**Audit:** Mutations are recorded via `kernel.audit.record@1` with `action: superadmin.module.settings.update`, including old/new settings and target `tenant_id`.

---

## Android Integration Quick Start

```kotlin
// 1. Login — get token + refresh_token
val login = api.post("/api/v1/auth/login",
    json = mapOf("username" to user, "password" to pass),
    headers = mapOf("Accept" to "application/json")
)
val token = login.getString("token")
val refreshToken = login.getString("refresh_token")
prefs.edit().putString("jwt", token).putString("refresh", refreshToken).apply()

// 2. Initialize ledger (first time only)
api.post("/api/v1/modules/inventory-ledger/open",
    json = mapOf("branch_id" to 1, "ledger_date" to "2026-02-21"),
    headers = mapOf("Authorization" to "Bearer $token")
)

// 3. Load rows (read-only, safe to retry)
val rows = api.get("/api/v1/modules/inventory-ledger/rows",
    params = mapOf("branch_id" to "1", "ledger_date" to "2026-02-21"),
    headers = mapOf("Authorization" to "Bearer $token")
)

// 4. Update with optimistic locking + idempotency
api.post("/api/v1/modules/inventory-ledger/rows/1",
    json = mapOf("branch_id" to 1, "ledger_date" to "2026-02-21",
        "additional_stock" to 60, "withdrawals" to 10, "sales_units" to 30,
        "change_reason" to "Corrected count", "version" to 1),
    headers = mapOf(
        "Authorization" to "Bearer $token",
        "X-Idempotency-Key" to UUID.randomUUID().toString()
    )
)

// 5. Refresh token when JWT expires
val refresh = api.post("/api/v1/auth/refresh",
    json = mapOf("refresh_token" to refreshToken),
    headers = mapOf("Accept" to "application/json")
)
// Store new tokens, retry failed request

// 6. Validate on app launch
val me = api.get("/api/v1/me", headers = mapOf("Authorization" to "Bearer $token"))
if (!me.getBoolean("ok")) { /* token expired, try refresh or re-login */ }
```
