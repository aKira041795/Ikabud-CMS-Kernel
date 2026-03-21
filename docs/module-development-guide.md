# Module Development Guide — Ikabud Kernel

This guide explains how to build, package, and install modules for the Ikabud Kernel OS (Ikabud Kernel + DiSyL).

---

## Architecture Overview

```
Kernel (always running)          Modules (plug-in, auto-discovered)
┌────────────────────┐           ┌─────────────────────────┐
│ bootstrap.php      │           │ modules/my-module/      │
│ public/index.php   │◄──loads───│   module.json           │
│ kernel/App.php     │           │   routes.php            │
│ kernel/DiSyL/      │           │   handlers.php          │
│ templates/layouts/  │           │   templates/ (optional) │
│ src/helpers/        │           │   database/ (optional)  │
└────────────────────┘           └─────────────────────────┘
```

The kernel provides: routing, auth (JWT), DiSyL templates, PDO database, audit logging, HTMX support, and module management.

Modules provide: routes, handlers, templates, and (optionally) database migrations.

**Key principle**: If all modules are disabled, the kernel still boots, login works, health check works, and users see a "No modules" landing page. Modules are fully decoupled.

---

## Module Structure

Every module lives in `modules/<module-id>/` and must contain at minimum a `module.json` manifest.

```
modules/my-module/
├── module.json          # REQUIRED — manifest
├── routes.php           # REQUIRED — route definitions
├── handlers.php         # REQUIRED — handler functions
├── helpers.php          # Optional — auto-loaded globally when module is enabled
├── templates/           # Optional — DiSyL templates
│   └── modules/my-module/
│       ├── pages/
│       └── partials/
├── database/            # Optional — SQL migrations
│   └── migrations/
│       ├── 001_schema.sql
│       └── 002_seed.sql
└── assets/              # Optional — CSS, JS, images
```

### Maintainer Pointer (Large Module Organization)

If a module grows large and needs split-by-concern files (instead of single large `handlers.php` / `helpers.php`), follow the CMS pattern documented here:

- [CMS File Organization (Post-Split)](cms-architecture.md#maintainer-guide-cms-file-organization-post-split)

This keeps route handler names stable while making maintenance easier through loader entry files and domain-focused subfiles.

### helpers.php — Auto-Loaded Globals

If your module includes a `helpers.php` file, it is **automatically loaded** when the module is enabled — even on requests that don't hit your module's routes.

`helpers.php` is intended for **module-local utilities**.
**Deprecated**: using `helpers.php` for cross-module communication. Modules should communicate through capability contracts.

Example: the `gui-settings` module exposes module-local utilities via `helpers.php`.

```php
<?php
// modules/my-module/helpers.php
function myModuleGlobalHelper(): string
{
    return 'available everywhere when module is enabled';
}
```

---

## module.json — The Manifest

```json
{
    "id": "my-module",
    "name": "My Module",
    "version": "1.0.0",
    "description": "Short description of what this module does",
    "author": "Your Name",
    "owns_tables": ["my_table", "my_other_table"],
    "reads_tables": ["users", "audit_logs"],
    "migrations": ["database/migrations/001_schema.sql"],
    "auth_cookie": "my_module_token",
    "capabilities": {
        "exposes": [
            { "id": "payments.gateway.charge@1", "priority": 50, "modes": ["first"] }
        ],
        "depends": [
            "kernel.auth.user@1",
            "kernel.audit.record@1"
        ]
    },
    "nav": [
        {
            "label": "My Page",
            "url": "/my-module/page",
            "icon": "box",
            "roles": ["admin", "supervisor"]
        },
        {
            "label": "---",
            "url": "#",
            "icon": "separator",
            "roles": ["admin"]
        },
        {
            "label": "Settings",
            "url": "/my-module/settings",
            "icon": "settings",
            "roles": ["admin"]
        }
    ]
}
```

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Unique module identifier. Lowercase, alphanumeric + hyphens. Must match the folder name. |
| `name` | string | Human-readable display name. |
| `version` | string | Semver version string (e.g. `"1.0.0"`). |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `description` | string | Short description shown in module listing. |
| `author` | string | Module author name. |
| `owns_tables` | string[] | Tables fully owned by the module (full CRUD). Used for ModuleDB enforcement. |
| `reads_tables` | string[] | Tables the module may read (SELECT only). Used for ModuleDB enforcement. |
| `migrations` | string[] | Paths to SQL migration files (relative to module dir). |
| `auth_cookie` | string | Additional auth cookie name this module uses for page sessions. When set, the kernel will recognize this cookie for `app()->user()` so kernel layouts can render `user` and `nav_items` consistently. |
| `capabilities` | object | Capability contracts exposed and required by this module. |
| `nav` | object[] | Navigation items injected into the top nav bar. |

### Table declaration rules

- Declare every table your module touches at runtime.
- This includes shared infrastructure tables, not only tables with your module prefix.
- If your module persists tenant-scoped module settings through `getModuleSettings()` / `saveModuleSettings()` in a multi-tenant request path, declare `tenant_module_settings` in `owns_tables`.
- If your module reads from `audit_logs`, `rate_limits`, workflow tables, or other shared kernel tables through module-scoped DB access, declare those explicitly as `reads_tables` or `owns_tables` based on the actual SQL you run.

### Capability Contracts

Modules communicate synchronously through capability contracts rather than calling each other directly.

#### Capability ID Format

`contract.id@major`

Examples:

- `payments.gateway.charge@1`
- `inventory.ledger.adjust@1`
- `kernel.auth.user@1`

`kernel.*` capabilities are reserved for kernel-provided core contracts.

#### Multi-Provider Support

Multiple modules can expose the same capability contract. Providers are selected deterministically using:

1. Highest `priority`
2. Tie-breaker by `module id` (ascending)

#### Modes

Providers declare supported `modes`:

- `first` — call the selected provider (default)
- `pipeline` — call providers in order, passing output forward
- `fanout` — call all providers and return a summary

### Kernel Core Contracts

The kernel exposes core infrastructure as capabilities (provider `kernel`). Modules depend on these contracts in `module.json`.

Common examples:

- `kernel.auth.user@1`
- `kernel.auth.require@1`
- `kernel.audit.record@1`
- `kernel.http.request_context@1`
- `kernel.render.context@1`

### Nav Item Format

| Field | Type | Description |
|-------|------|-------------|
| `label` | string | Link text. Use `"---"` for a visual separator. |
| `url` | string | Route path (e.g. `/my-module/page`). |
| `icon` | string | Icon name (reserved for future icon support). Use `"separator"` for separators. |
| `roles` | string[] | Which roles see this link. Values: `"admin"`, `"supervisor"`, `"cashier"`, or `"*"` for all. |

---

## routes.php — Route Definitions

Return an associative array with HTTP methods as keys. Each route maps a URL pattern to a handler string in the format `module-id:functionName`.

```php
<?php

declare(strict_types=1);

return [
    'GET' => [
        '/my-module/page'          => 'my-module:handleMyPage',
        '/my-module/settings'      => 'my-module:handleSettings',
    ],
    'POST' => [
        '/api/v1/my-module/save'   => 'my-module:apiSave',
    ],
];
```

### Route Conventions

- **Page routes**: `/module-name/page-name` — renders full HTML via DiSyL
- **API routes**: `/api/v1/module-name/action` — returns JSON
- **Admin routes**: `/admin/module-name/page` — for admin-only pages
- The handler string format is always `module-id:functionName`

### URL Parameters

Use `{param}` placeholders:

```php
'/my-module/item/{id}' => 'my-module:handleItem',
```

The `$params` array will contain `['id' => '...']`.

---

## handlers.php — Handler Functions

Plain PHP functions. Each receives an optional `$params` array from URL parameters.

```php
<?php

declare(strict_types=1);

use PDO;

// ─── Page Handler ─────────────────────────────────────────────────

function handleMyPage(array $params = []): void
{
    // Require authentication + role check
    $user = app()->requireAnyRole('admin', 'supervisor');

    // Query the database
    $stmt = app()->db()->prepare('SELECT * FROM my_table WHERE is_active = 1 ORDER BY name');
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Render a DiSyL template
    echo app()->render('modules/my-module/pages/list.disyl', [
        'page_title' => 'My Module',
        'items'      => $items,
    ]);
}

// ─── API Handler ──────────────────────────────────────────────────

function apiSave(array $params = []): void
{
    header('Content-Type: application/json');

    $user = app()->user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $input = app()->input();
    $name = trim((string)($input['name'] ?? ''));

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Name is required']);
        exit;
    }

    try {
        app()->db()->prepare('INSERT INTO my_table (name) VALUES (:name)')
            ->execute([':name' => $name]);

        echo json_encode(['ok' => true, 'id' => (int)app()->db()->lastInsertId()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
    exit;
}
```

### Available Kernel APIs

| Method | Description |
|--------|-------------|
| `app()->user()` | Get current authenticated user (or `null`) |
| `app()->requireAuth()` | Require auth, redirect to `/login` if not |
| `app()->requireRole('admin')` | Require specific role |
| `app()->requireAnyRole('admin', 'supervisor')` | Require any of the listed roles |
| `app()->db()` | Get PDO database connection |
| `app()->render($template, $context)` | Render a DiSyL template with context |
| `app()->input()` | Get parsed request body (JSON or form data) |
| `app()->input('key')` | Get specific input field |
| `app()->isHtmx()` | Check if request is HTMX (non-boosted) |
| `app()->isHtmxBoosted()` | Check if request is hx-boost navigation |
| `app()->redirect($url)` | Redirect (handles HTMX too) |
| `app()->json($data, $status)` | Send JSON response and exit |

### Template Context (auto-injected)

These variables are always available in templates:

| Variable | Type | Description |
|----------|------|-------------|
| `user` | array/null | Current user (`id`, `username`, `name`, `role`) |
| `base_url` | string | URL base path (empty string or `/subpath`) |
| `is_htmx` | bool | True for non-boosted HTMX requests |
| `module_nav_items` | array | Dynamic nav items from enabled modules |

---

## Templates (DiSyL)

Templates use the DiSyL template engine. Place them in `templates/modules/your-module/`.

### Basic Template

```
{extends "layouts/app.disyl"}

{block head}
<style>
    .my-class { color: var(--primary); }
</style>
{/block}

{block content}
<h2>{page_title}</h2>

{if items | count == 0}
<p>No items found.</p>
{/if}

{foreach items as item}
<div class="card">
    <strong>{item.name}</strong>
    <span class="text-muted">{item.created_at}</span>
</div>
{/foreach}
{/block}

{block scripts}
<script>
    var BASE = '{base_url}';
    // Your JavaScript here
</script>
{/block}
```

### Key DiSyL Syntax

| Syntax | Description |
|--------|-------------|
| `{extends "layouts/app.disyl"}` | Inherit from base layout |
| `{block name}...{/block}` | Define block content |
| `{if condition}...{/if}` | Conditional |
| `{if x == 'val'}...{else}...{/if}` | If/else |
| `{foreach items as item}...{/foreach}` | Loop |
| `{loop.index1}` | 1-based loop counter |
| `{variable}` | Output variable |
| `{variable.property}` | Nested property access |
| `{variable \| count}` | Filter: count array items |
| `{variable \| number_format}` | Filter: format number |

### HTMX Integration

The base layout uses `hx-boost="true"` on `<body>`, so all links are boosted by default. For HTMX-powered interactions:

```html
<form hx-get="{base_url}/my-module/page"
      hx-target="#main-content"
      hx-swap="innerHTML"
      hx-push-url="true">
    <input name="q" value="{search}">
    <button type="submit">Search</button>
</form>
```

---

## Database Migrations

Place SQL files in `database/migrations/` within your module directory. List them in `module.json` under `"migrations"`.

```sql
-- modules/my-module/database/migrations/001_schema.sql

CREATE TABLE IF NOT EXISTS my_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Note**: Migrations are NOT auto-run. The admin must run them manually or through a future migration runner.

---

## Packaging & Installation

There are two distinct module installation systems. Use the one that matches your module type.

---

### Kernel / Application Modules

Kernel modules are installed directly on disk by a developer or sysadmin. They are **not** uploaded via the UI.

**Directory placement:**
```bash
# Copy the module directory to the modules/ folder on the server
cp -r my-module/ /var/www/html/ikabud/modules/my-module/
```

Once in `modules/`, the kernel auto-discovers the module at boot via `discoverModules()`. Enable/disable state is managed via `enableModule()` / `disableModule()` (which write to `tenant_module_settings` in multi-tenant mode and `storage/modules.json` in single-tenant mode).

---

### CMS Sub-Modules (ZIP Upload Flow)

CMS sub-modules are extensions installed through the CMS admin UI or API. They are uploaded as ZIP files and managed per-tenant.

#### ZIP structure requirements

The ZIP **must** contain a single top-level directory named after the module, with `module.json` inside it:

```
my-module/          ← top-level directory, name must match "id" in module.json
    module.json     ← REQUIRED — found by the installer at exactly one level deep
    routes.php
    handlers.php
    ...
```

A flat structure (files at the zip root) is **not** supported for CMS sub-module uploads. The installer scans for `module.json` at exactly one directory level deep.

#### Creating the ZIP

```bash
# From the parent directory containing your module folder
cd /path/to/parent/
zip -r my-module.zip my-module/
```

#### Installing via CMS admin UI

Navigate to **CMS Admin → Modules** (`/cms/admin/modules`) and use the upload form.

#### Installing via API

```bash
curl -X POST https://yourdomain.com/api/v1/cms/modules/upload \
  -H "Cookie: cms_token=<cms_jwt>" \
  -F "module_zip=@my-module.zip"
```

Response on success:
```json
{
    "ok": true,
    "module": { "id": "my-module", "name": "My Module", "version": "1.0.0", ... },
    "upgraded": false
}
```

The module is **auto-enabled for the current tenant** after installation.

#### CMS Sub-Module API reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/cms/admin/modules` | Admin UI — module manager page |
| POST | `/api/v1/cms/modules/upload` | Upload and install a module from ZIP |
| POST | `/api/v1/cms/modules/toggle` | Enable or disable: `{"module_id":"...","enabled":true}` |
| POST | `/api/v1/cms/modules/{module_id}/settings` | Save module settings |
| POST | `/api/v1/cms/modules/{module_id}/delete` | Delete (unregister) a module |

All endpoints require **CMS admin** role and CSRF enforcement.

> **Note:** There is no `GET /api/v1/cms/modules` list endpoint. The module list is rendered server-side on the admin page. Registry state lives in `tenant_module_settings`.

#### Protections enforced at upload time

- ZIP entries are validated before extraction: path traversal sequences (`../`), absolute paths, null bytes, and symlink entries are all rejected.
- `module.json` is required and validated — required fields (`id`, `name`, `version`) must be present and type-correct.
- The module `id` in `module.json` must match the directory name in the ZIP.
- Kernel/application modules that exist on disk (and were **not** installed by CMS) cannot be overwritten via ZIP upload.

#### Cross-tenant module adoption

When tenant A installs a module via ZIP, the module directory is extracted to `modules/<module-id>/` on shared disk. When tenant B later uploads the same module:

1. The installer detects the module already exists on disk with a `.cms-owned` marker.
2. Instead of re-extracting, it registers the module in tenant B's CMS registry and enables it.
3. This is logged as `"Module adopted from shared disk (cross-tenant)."` in the installer audit log.

The `.cms-owned` marker (`modules/<module-id>/.cms-owned`) is written by the installer on first install and identifies the directory as CMS-managed (not a bundled kernel module).

---

## Module Enable/Disable

Module enable/disable state is tracked in `storage/modules.json`:

```json
{
    "daily-ledger": {
        "enabled": true,
        "enabled_at": "2026-02-24 21:00:00"
    }
}
```

- **If `storage/modules.json` doesn't exist**: all discovered modules are enabled by default
- **Disabled modules**: routes not loaded, nav items hidden, handler calls return 404
- **The kernel always works**: login, auth, health check, module management API

### Module settings persistence

Module settings now have two layers:

- `storage/modules.json` remains the global registry and global settings fallback
- `tenant_module_settings` stores tenant-specific overrides when multi-tenancy is enabled and a tenant context is resolved

Runtime behavior:

- `getModuleSettings($moduleId)` returns global settings merged with tenant overrides
- `saveModuleSettings($moduleId, $settings)` writes tenant-scoped settings first when tenant mode is active; otherwise it falls back to the global registry
- CLI scripts only use tenant-scoped settings when a tenant host/context is explicitly provided

#### Cross-tenant settings helpers (superadmin)

The following functions bypass the request-context tenant and operate on an explicitly specified tenant. They connect to the **target tenant's database** via `app()->dbForTenant($tenantId)` — this is critical when tenants have separate databases. They are used by the superadmin settings UI and any tooling that manages settings across tenants:

| Function | Description |
|----------|-------------|
| `readTenantModuleSettingsForTenant(string $moduleId, int $tenantId): array` | Raw read from `tenant_module_settings` in the target tenant's DB. Returns all keys including `_`-prefixed metadata. |
| `saveTenantModuleSettingsForTenant(string $moduleId, int $tenantId, array $settings): bool` | Writes settings to the target tenant's DB (upserts each key into `tenant_module_settings`). |
| `getModuleSettingsForTenant(string $moduleId, int $tenantId): array` | Merged read: lifecycle keys (`_module_enabled`, `_installed_submodules`) come from the target tenant's DB; public keys are merged from global + tenant overrides. Strips `_`-prefix keys. |
| `isModuleEnabledForTenant(string $moduleId, int $tenantId): bool` | Checks `_module_enabled` in the target tenant's DB; falls back to the global registry `enabled` field if no tenant override exists. |

All four live in `src/helpers/module-manager.php`. The underlying DB connection is obtained via `app()->dbForTenant($tenantId)` (defined in `kernel/App.php`), which looks up credentials from `kernel_tenant_db_connections` and caches connections per tenant for the request lifetime.

#### `settings_fields` manifest schema

Modules declare user-editable settings in their `module.json` under `settings_fields`. The superadmin settings UI reads this schema to render form controls and validate input.

```json
{
  "settings_fields": [
    {
      "key": "recipient_email",
      "label": "Recipient Email",
      "description": "Where form submissions are sent",
      "type": "email"
    },
    {
      "key": "max_submissions",
      "label": "Max Submissions Per Day",
      "type": "number"
    },
    {
      "key": "enabled_captcha",
      "label": "Enable CAPTCHA",
      "type": "checkbox"
    },
    {
      "key": "theme",
      "label": "Form Theme",
      "type": "select",
      "options": [
        { "value": "default", "label": "Default" },
        { "value": "minimal", "label": "Minimal" }
      ]
    }
  ]
}
```

**Supported field types:** `text`, `email`, `number`/`int`/`integer`, `checkbox`/`bool`/`boolean`, `select`.

The superadmin save handler enforces type coercion and, for `select` fields, validates against the declared `options` values. Only keys declared in `settings_fields` can be changed through the superadmin API — this prevents modification of internal keys like `allow_kernel_admin`.

---

## Reference: daily-ledger Module

The existing `daily-ledger` module is the canonical reference implementation.

```
modules/daily-ledger/
├── module.json              # 9 nav items, 3 roles, table list, migrations
├── routes.php               # 19 routes (9 GET pages, 10 POST APIs)
├── handlers.php             # ~1100 lines: handlers, helpers, API endpoints
└── (templates in templates/modules/daily-ledger/)
    ├── cashier/
    │   ├── ledger.disyl     # Main cashier ledger page
    │   └── partials/
    │       └── ledger-rows.disyl  # HTMX partial for ledger rows
    └── admin/
        ├── dashboard.disyl  # Admin dashboard
        ├── sales.disyl      # Sales summary
        ├── variances.disyl  # Variance flags
        ├── activity.disyl   # Activity history
        ├── products.disyl   # Product CRUD
        ├── branches.disyl   # Branch CRUD
        └── users.disyl      # User CRUD
```

### Pattern Summary

1. **Manifest** declares ID, nav items per role, required tables
2. **Routes** map URLs to `module-id:functionName` handlers
3. **Handlers** use `app()->requireAnyRole()` for access control
4. **Templates** extend `layouts/app.disyl`, use `{block content}` for page content
5. **API handlers** return JSON with `{"ok": true/false}` convention
6. **Search**: accept `?q=` param, use `LIKE :q` in SQL with parameterized queries
7. **Audit**: log mutations via `kernel.audit.record@1` or a properly declared `audit_logs` access path for traceability

---

## Multi-Tenant Module Standards

This section defines the **required patterns** for all module types when the system is running in multi-tenant mode (`APP_MULTI_TENANT_ENABLED=true`, `APP_TENANT_STRATEGY=control_host`).

These patterns were established as the architectural standard and are enforced across all production modules.

---

### Module Types

| Type | Description | Examples |
|------|-------------|---------|
| **Independent** | Standalone module enabled per-tenant | `daily-ledger`, `ticketing`, `guidance` |
| **Sub-module** | Installed through CMS admin, lives under CMS | `contact-form`, any CMS add-on |
| **Shared / Kernel** | Kernel-owned, settings split across kernel-global and per-tenant | `gui-settings`, `sms`, `anti-spam`, `cms` core |

---

### Settings: The Non-Negotiable Rule

**Never use `getModuleSettings()` results or `saveModuleSettings()` with static variables.**

Settings are tenant-scoped automatically by `getModuleSettings()` / `saveModuleSettings()`:
- In multi-tenant mode, all settings (except `allow_kernel_admin`) come from the `tenant_module_settings` DB table, keyed by `(tenant_id, module_id, setting_key)`.
- In single-tenant or CLI mode, they fall back to `storage/modules.json`.

**Required static cache pattern** — use this for any `*GetSettings()` helper:

```php
function myModuleGetSettings(): array {
    static $cache = [];
    $tid = (function_exists('moduleTenantSettingsTenantId')
        ? moduleTenantSettingsTenantId() : null) ?? 0;
    if (array_key_exists($tid, $cache)) return $cache[$tid];
    $cache[$tid] = getModuleSettings('my-module');
    return $cache[$tid];
}
```

**Why:** PHP static variables live for the process lifetime. In a multi-request or multi-tenant test context (same process), a bare `static $cache = null` caches tenant A's settings and serves them to tenant B on the next call.

**Anti-pattern — never do this:**
```php
// ❌ WRONG — leaks across tenants
static $cache = null;
if ($cache !== null) return $cache;
$cache = getModuleSettings('my-module');
return $cache;
```

---

### Module Enable/Disable State

`isModuleEnabled()`, `enableModule()`, and `disableModule()` are already tenant-scoped.

- State is stored as `_module_enabled` key in `tenant_module_settings` (internal, private).
- Falls back to the global `storage/modules.json` `enabled` field if no tenant-specific override exists.
- **Never write enable/disable state directly to `storage/modules.json`** in a tenant context — use `enableModule()` / `disableModule()` which route correctly.

---

### Global State (GLOBALS / static flags)

Any `$GLOBALS` cache key or `static $flag = false` that is set once per request must be keyed by tenant ID.

**Required pattern for GLOBALS caches:**
```php
$tid = moduleTenantSettingsTenantId() ?? 0;
$cacheKey = 'my_module_cached_t' . $tid;

if (!empty($GLOBALS[$cacheKey])) {
    return $GLOBALS['my_module_value_t' . $tid];
}
// ... compute value ...
$GLOBALS[$cacheKey] = true;
$GLOBALS['my_module_value_t' . $tid] = $value;
```

**Required pattern for single-fire flags (e.g. CSS injection):**
```php
static $done = [];
$tid = moduleTenantSettingsTenantId() ?? 0;
if (!empty($done[$tid])) return;
// ... do the one-time work ...
$done[$tid] = true;
```

---

### Sub-Module Install Registry

Sub-modules (CMS extensions) track their install state per tenant.

- Registry is keyed by `_installed_submodules` in `tenant_module_settings` for `module_id='cms'`.
- Use `_cmsRegisterSubModule($id)` and `_cmsUnregisterSubModule($id)` — both are tenant-aware.
- **Never write directly to `storage/cms-installed-modules.json`** from tenant context. That file is the legacy global fallback used only when `moduleTenantSettingsTenantId()` returns null.

#### CMS ownership marker

When the CMS installer extracts a module to `modules/<module-id>/` for the first time, it writes a `.cms-owned` marker file:

```
modules/my-module/.cms-owned    ← JSON metadata, marks dir as CMS-managed
```

This marker distinguishes CMS-installed directories from bundled kernel/application modules. The function `_cmsIsInGlobalOrAnyTenantRegistry(string $moduleId): bool` checks this marker (and the global file registry) to determine whether a module directory is CMS-managed.

#### Cross-tenant adoption

In multi-tenant deployments, all tenants share the same `modules/` filesystem. When tenant A installs `my-module`, the directory is on shared disk. When tenant B uploads the same module:

1. `_cmsGetKernelModuleIds()` would otherwise classify the on-disk directory as a "kernel module" (not in tenant B's registry).
2. The installer detects the `.cms-owned` marker → cross-tenant adopt path: registers in tenant B's registry and enables for tenant B without re-extracting.
3. Audit entry: `"Module adopted from shared disk (cross-tenant)."`.

Do not remove the `.cms-owned` file manually — deleting it would cause the installer to treat the module as a kernel module and block re-installation by other tenants.

#### Multi-tenant-safe delete

`cmsApiModuleDelete()` is tenant-aware:

- **Multi-tenant mode** (`moduleTenantSettingsModeEnabled()` = true): unregisters from the current tenant's CMS registry and disables for the tenant only. **The `modules/<id>/` directory is preserved** so other tenants are not affected.
- **Single-tenant mode**: unregisters, disables, and deletes the module directory from disk.

This means "delete" in the CMS admin is always safe to call in multi-tenant environments — it only affects the calling tenant's view.

---

### Internal Metadata Keys (`_`-prefix Convention)

Some settings keys are infrastructure metadata used internally by the kernel or module manager. They must follow this convention:

- Stored in `tenant_module_settings` with a `_`-prefixed key (e.g. `_module_enabled`, `_installed_submodules`).
- **Automatically stripped** by `getModuleSettings()` — code consuming settings via that function will never see them.
- **Never accessed directly** by module business logic; only via dedicated kernel APIs (`isModuleEnabled()`, `_cmsGetRegisteredSubModules()`, etc.).

If you need to store private infrastructure metadata in `tenant_module_settings`, prefix the key with `_`. It will be stored but never appear in the public settings API.

---

### File I/O in Tenant Context

| Operation | Allowed? | Use instead |
|-----------|----------|-------------|
| Write to `storage/modules.json` | ❌ Never from tenant code | `saveModuleSettings()` |
| Write to `storage/cms-installed-modules.json` | ❌ Never from tenant code | `_cmsRegisterSubModule()` |
| Write to `storage/` for per-tenant data | ❌ Never | Use DB via `getModuleSettings()` |
| Write to `modules/cms/assets/uploads/t{tid}/` | ✅ Yes (tenant-scoped path) | Use `cmsUploadsPath()` |
| Read from `storage/` as global fallback (no tenant context) | ✅ CLI/fallback only | Keep as-is |

---

### Shared / Kernel-Level Modules

Modules owned by kernel admin (like `sms`, `anti-spam`, `gui-settings`) may have:

- **Kernel-global settings** (e.g. API credentials, `allow_kernel_admin`): stored in `storage/modules.json`, only accessible in non-tenant context or via `allow_kernel_admin` passthrough in `getModuleSettings()`.
- **Tenant-specific settings** (e.g. recipient overrides, appearance): stored in `tenant_module_settings`, accessed via normal `getModuleSettings()`.

Static caches in shared/kernel modules must still use the tenant-keyed pattern, because they run in the same PHP process for every request regardless of which tenant initiated it.

---

### Updated Checklist for New Modules

Add these checks to the standard module checklist:

- [ ] `*GetSettings()` helpers use `static $cache = []; $tid = ...; array_key_exists($tid, $cache)` pattern
- [ ] No `static $flag = false` or similar single-fire booleans that aren't keyed by `$tid`
- [ ] No `$GLOBALS['bare_key']` — always use `'key_t' . $tid` for per-request caches
- [ ] No direct writes to `storage/*.json` from any handler called in a tenant request context
- [ ] Sub-modules: use `_cmsRegisterSubModule()` / `_cmsUnregisterSubModule()`, never write `cms-installed-modules.json`
- [ ] Enable/disable: use `enableModule()` / `disableModule()`, never write `modules.json` directly

---

## Future Module Ideas

| Module | Description | Probable Routes |
|--------|-------------|-----------------|
| `accounting` | Full accounting: GL, AP, AR, journal entries | `/accounting/journal`, `/accounting/reports` |
| `view-permissions` | Granular page/field-level permissions per role | `/admin/permissions` |
| `inventory` | Stock management, PO, receiving | `/inventory/stock`, `/inventory/po` |
| `reports` | Advanced reporting: charts, export, scheduling | `/reports/builder` |
| `notifications` | In-app notifications, email alerts | `/notifications` |
| `backup` | Database backup/restore management | `/admin/backup` |

Each would follow the same structure: `module.json` + `routes.php` + `handlers.php` + templates.

---

## Checklist for New Modules

- [ ] Create `modules/my-module/module.json` with `id`, `name`, `version`
- [ ] Create `modules/my-module/routes.php` returning route array
- [ ] Create `modules/my-module/handlers.php` with handler functions
- [ ] Create templates in `templates/modules/my-module/`
- [ ] Add `nav` items to `module.json` for each role
- [ ] Declare every runtime-touched table in `owns_tables` / `reads_tables`, including shared infrastructure tables
- [ ] Use `app()->requireAnyRole(...)` in every handler
- [ ] Use parameterized queries (never concatenate user input into SQL)
- [ ] Return `{"ok": true/false}` from all API endpoints
- [ ] Test with module disabled — kernel should still boot
- [ ] Package as zip and test install via API
