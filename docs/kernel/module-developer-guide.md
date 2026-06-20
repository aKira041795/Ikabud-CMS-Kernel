# Module Developer Guide

> **Audience**: Developers building a new module for the Ikabud Kernel OS.
> **Prerequisites**: PHP 8.2+, MySQL, familiarity with the Kernel architecture concepts (manifests, capabilities, DiSyL).
> **Reading time**: ~20 minutes. After this guide, you can build a working module from scratch.

---

## 1. Module Anatomy

A minimal module has this structure:

```
modules/my-module/
├── module.json          # Manifest — declares identity, tables, dependencies
├── routes.php           # Route map — URL patterns → handler references
├── handlers.php         # Handler loader — includes handler files
├── handlers/            # Handler implementations (split by domain)
│   ├── 00-bootstrap.php
│   ├── 10-pages.php
│   └── 20-api.php
├── helpers.php          # Helper functions, capability registrations
├── database/
│   └── migrations/      # SQL migration files
└── templates/           # DiSyL templates (mirrored in templates/modules/<id>/)
```

---

## 2. The Manifest (`module.json`)

The manifest is the module's public contract. It declares identity, data ownership, dependencies, and capabilities before any module code executes.

```json
{
    "id": "my-module",
    "name": "My Module",
    "version": "1.0.0",
    "description": "Description of what this module does.",
    "author": "Your Name",
    "type": "php-module",
    "depends": [],
    "owns_tables": [
        "my_records",
        "my_settings"
    ],
    "reads_tables": [
        "users"
    ],
    "co_owns_tables": [],
    "migrations": [
        "database/migrations/001_create_my_records.sql"
    ],
    "routes": "routes.php",
    "handlers": "handlers.php"
}
```

### Key fields

| Field | Required | Description |
|---|---|---|
| `id` | ✅ | Unique module identifier (kebab-case). Must match directory name. |
| `version` | ✅ | Semantic version. Used for migration ordering. |
| `owns_tables` | ✅ | Tables this module owns (full CRUD enforced by ModuleDB). |
| `reads_tables` | — | Tables this module may SELECT from (cross-module reads). |
| `co_owns_tables` | — | Tables shared with another module (full CRUD). See `co-owns-tables-policy.md`. |
| `depends` | — | Other module IDs this module depends on. |
| `migrations` | — | Relative paths to SQL migration files. |
| `auth_owned` | — | Declare this module owns its user authentication. See [auth-owned](#auth-owned-modules). |
| `capabilities` | — | Capabilities this module exposes and consumes. See [capabilities](#capabilities). |

### Table ownership

`owns_tables` and `reads_tables` are **enforced at runtime** by `ModuleDB`. If your handler code tries to INSERT into a table not declared in `owns_tables`, it throws a `RuntimeException`. This is not a recommendation — it's a hard boundary.

Always use `module()->db()` in your handlers rather than raw `app()->db()`:

```php
$db = module()->db();  // Returns ModuleDB — table-ownership-enforced PDO
$rows = $db->query('SELECT * FROM my_records ORDER BY created_at DESC')->fetchAll();
```

---

## 3. Routes (`routes.php`)

Routes map URL patterns to handler references using the `module-id:functionName` convention.

```php
<?php

declare(strict_types=1);

return [
    'GET' => [
        '/my-module'           => 'my-module:pageDashboard',
        '/my-module/records'   => 'my-module:pageRecords',
        '/my-module/records/{id}' => 'my-module:pageRecordDetail',
    ],
    'POST' => [
        '/api/v1/my-module/records' => 'my-module:apiCreateRecord',
        '/api/v1/my-module/records/{id}' => 'my-module:apiUpdateRecord',
    ],
];
```

### Route conventions

- **Pages**: `GET /admin/<module>/*` for admin pages, `GET /<module>/*` for public pages
- **APIs**: `/api/v1/<module>/*` for JSON endpoints
- **Dynamic segments**: `{id}`, `{slug}` — captured and passed as handler parameters
- **Handler reference**: `<module-id>:<functionName>` — the function must exist in the handler files loaded by `handlers.php`

---

## 4. Handlers

Handlers receive a `ModuleContext` and return a response. They're organized by domain in `handlers/`.

### `handlers.php` — The loader

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/handlers/00-bootstrap.php';
require_once __DIR__ . '/handlers/10-pages.php';
require_once __DIR__ . '/handlers/20-api.php';
```

### Handler implementations

```php
<?php
// handlers/10-pages.php

use Ikabud\Kernel\Contracts\ModuleContext;

function my_modulePageDashboard(ModuleContext $ctx): void
{
    // Gate: require authentication
    $user = app()->user();
    if (!$user) {
        app()->redirect('/login');
        return;
    }

    // Fetch data from owned tables
    $db = module()->db();
    $records = $db->query('SELECT * FROM my_records ORDER BY created_at DESC LIMIT 20')->fetchAll();

    // Render a DiSyL template
    echo app()->render('modules/my-module/dashboard', [
        'records' => $records,
        'page_title' => 'My Module Dashboard',
    ]);
}

function my_moduleApiCreateRecord(ModuleContext $ctx): void
{
    header('Content-Type: application/json; charset=utf-8');

    $input = app()->input();
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title is required']);
        return;
    }

    $db = module()->db();
    $stmt = $db->prepare('INSERT INTO my_records (title, created_at) VALUES (:title, NOW())');
    $stmt->execute([':title' => $title]);

    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}
```

### Calling capabilities (cross-module)

```php
// Call a capability from another module
$result = app()->capabilities()->call('reporting.ledger.daily@1', [
    'date' => '2026-06-21',
    'store_id' => 42,
]);
```

### Firing events

```php
// Announce that something happened
app()->events()->fire('my_record.created', [
    'record_id' => $newId,
    'actor_id' => $user['id'],
]);
```

---

## 5. Capabilities

Modules declare capabilities in two places:

### In `module.json` (declaration)

```json
{
    "capabilities": {
        "exposes": [
            {
                "id": "my-module.record.create@1",
                "modes": ["first"],
                "priority": 10,
                "description": "Create a new record",
                "schema": {
                    "input": { "title": "string" },
                    "output": { "id": "int", "title": "string" }
                }
            }
        ],
        "depends": [
            "audit.write@1"
        ],
        "policy": {
            "default": {
                "allow_callers": ["cms", "ecommerce"]
            }
        }
    }
}
```

### In `helpers.php` (handler registration)

```php
<?php
// helpers.php

function my_module_capability_handlers(): array
{
    return [
        'my-module.record.create@1' => function (mixed $payload, string $capId, string $providerId): array {
            $title = trim((string)($payload['title'] ?? ''));
            if ($title === '') {
                throw new \RuntimeException('Title is required');
            }

            $db = module()->db();
            $stmt = $db->prepare('INSERT INTO my_records (title, created_at) VALUES (:title, NOW())');
            $stmt->execute([':title' => $title]);

            return ['id' => (int)$db->lastInsertId(), 'title' => $title];
        },
    ];
}
```

The convention is: `{modulePrefix}_capability_handlers()` returns a map of capability ID → callable. The kernel auto-discovers this function during module load.

---

## 6. Migrations

Migrations are SQL files in `database/migrations/`. They're numbered for ordering.

```
database/migrations/
├── 001_create_my_records.sql
└── 002_add_status_column.sql
```

```sql
-- 001_create_my_records.sql
CREATE TABLE IF NOT EXISTS my_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Run migrations:
```bash
# For the current tenant
php ikabud tenant:migrate <tenant_id> my-module

# For all tenants
php ikabud tenant:migrate --all my-module
```

---

## 7. DiSyL Templates

Templates live in `templates/modules/<module-id>/` and use the `.disyl` extension.

```
templates/modules/my-module/
├── dashboard.disyl
├── record-detail.disyl
└── _card.disyl          # Partial (underscore prefix convention)
```

### Template example

```html
{* templates/modules/my-module/dashboard.disyl *}
{extends 'layouts/admin'}

{block content}
    <h1>{page_title}</h1>

    <ikb_panel>
        {if records|count > 0}
            {foreach records as record}
                <ikb_card>
                    <h3>{record.title}</h3>
                    <span>{record.created_at}</span>
                </ikb_card>
            {/foreach}
        {else}
            <ikb_block>No records found.</ikb_block>
        {/if}
    </ikb_panel>

    <ikb_form handler="my-module.record.create@1" method="POST">
        <ikb_form_field name="title" type="text" required />
        <button type="submit">Create Record</button>
    </ikb_form>
{/block}
```

See [DiSyL Grammar Reference](../disyl/disyl-grammar-v4.7.md) for the full syntax.

---

## 8. Auth-Owned Modules

If your module manages its own users (login, password reset, role management), declare `auth_owned` in `module.json`:

```json
{
    "auth_owned": {
        "users_table": "my_module_users",
        "username_column": "username",
        "email_column": "email",
        "password_column": "password_hash",
        "name_column": "display_name",
        "active_column": "is_active",
        "admin_roles": ["admin", "manager"],
        "default_admin_role": "admin",
        "requires_named_admin_on_provision": false,
        "touch_updated_at": true
    },
    "auth_cookie": "my_module_token"
}
```

This opts your module into the platform's tenant provisioning, password push, and admin email push pipelines automatically.

---

## 9. Testing

Tests live in `tests/` at the repo root. Bootstrap the app, clear logs, and assert on behavior.

```php
<?php
// tests/my_module_smoke_test.php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

// Load modules
$modules = discoverModules();
assert(isset($modules['my-module']), 'Module should be discovered');

$enabled = getEnabledModules();
assert(isset($enabled['my-module']), 'Module should be enabled');

// Test handler
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/my-module';
// ... simulate request and assert on output
```

Run: `php scripts/run-tests.php tests/my_module_smoke_test.php`

---

## 10. Checklist — Before You Ship

- [ ] `module.json` has `id`, `version`, `owns_tables`, `reads_tables`
- [ ] `routes.php` returns route map with `module-id:functionName` handlers
- [ ] `handlers.php` loads all handler files
- [ ] Handlers use `module()->db()` (not raw `app()->db()`)
- [ ] Migrations are numbered and idempotent (`CREATE TABLE IF NOT EXISTS`)
- [ ] Templates are in `templates/modules/<id>/` with `.disyl` extension
- [ ] Capability handlers registered via `{prefix}_capability_handlers()`
- [ ] Cross-module reads declared in `reads_tables`
- [ ] Events fired via `app()->events()->fire()` for cross-module notifications
- [ ] At least one smoke test passes
- [ ] `php ikabud tenant:migrate <tenant> <module>` succeeds

---

## 11. Reference — Key APIs

| API | Purpose |
|---|---|
| `module()->db()` | Module-scoped PDO (table ownership enforced) |
| `app()->db()` | Kernel PDO (for kernel-level operations only) |
| `app()->dbForTenant($id)` | Tenant-specific database connection |
| `app()->capabilities()->call($id, $payload)` | Call a capability from any module |
| `app()->events()->fire($event, $payload)` | Fire an event |
| `app()->events()->listen($event, $callback)` | Listen for an event |
| `app()->render($template, $data)` | Render a DiSyL template |
| `app()->user()` | Current authenticated user array |
| `app()->input()` | Parsed request input (JSON body or POST) |
| `app()->csrfEnforce()` | Validate CSRF token |
| `getModuleSettings($id)` | Read persisted module settings |
| `saveModuleSettings($id, $settings)` | Persist module settings |

---

## 12. Next Steps

- Read the [capability catalog endpoint](../../api/v1/kernel/capabilities) to discover available capabilities
- Read the [entity-view component catalog](../entity-views/component-catalog.md) for available DiSyL components
- Read the [ADR index](../architecture/decisions/) for architectural rationale
- Read the [co-owns-tables policy](../kernel/co-owns-tables-policy.md) if sharing tables
