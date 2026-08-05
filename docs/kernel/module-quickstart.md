# Module Quickstart — Build a Notes Module in 30 Minutes

This is a linear, step-by-step tutorial. Follow it from top to bottom.

For deep-dive reference on every feature, see [Module Development Guide](module-development-guide.md).
For cross-module integration patterns, see [Cross-Module Interaction Playbook](cross-module-playbook.md).

---

## Prerequisites

Before starting, you need a working Ikabud installation. If you haven't set
one up yet, follow the [Installation Guide](installation.md) first.

- PHP 8.2+ with PDO/MySQL
- Ikabud Kernel booted (`bootstrap.php` loads, `app()` works)
- MySQL database accessible
- Composer dependencies installed (`composer install`)

---

## Step 1: Scaffold the Module

`php ikabud make:module` is the only supported module scaffolder. The former
`scripts/scaffold-module.php` entry point is a non-operational deprecation
tombstone and must not be used by tooling or documentation.

```bash
php ikabud make:module notes
```

The scaffold currently creates a top-level module directory by default. If the module belongs to a contextual suite such as healthcare, move it after scaffolding so the filesystem paths mirror the suite layout: `modules/healthcare/notes/` and `templates/modules/healthcare/notes/`.

Output:

```
  ✓ mkdir modules/notes
  ✓ mkdir modules/notes/database/migrations
  ✓ mkdir templates/modules/notes/pages
  ✓ mkdir templates/modules/notes/partials
  ✓ create module.json
  ✓ create routes.php
  ✓ create handlers.php
  ✓ create helpers.php
  ✓ create database/migrations/001_initial.sql
  ✓ create templates/pages/home.disyl
  ✓ create tests/notes_module_test.php
  ✓ create README.md

  ✓ Module scaffolded: notes
```

This created:

| File | What it does |
|------|-------------|
| `modules/notes/module.json` | Module manifest — identity, tables, capabilities, events |
| `modules/notes/routes.php` | Maps URL paths to handler functions |
| `modules/notes/handlers.php` | Route handler functions (pages + APIs) |
| `modules/notes/helpers.php` | Scoped helpers: `nCtx()`, `nDb()`, `nInput()`, `nRender()` |
| `modules/notes/database/migrations/001_initial.sql` | Database schema |
| `modules/notes/README.md` | Module documentation |
| `templates/modules/notes/pages/home.disyl` | Admin page template |
| `tests/notes_module_test.php` | Scaffold verification test |

---

For contextual suites, keep the render alias as `modules/notes/...` but mirror the real filesystem layout under the contextual folders after the move.

Example:

- `modules/healthcare/notes/`
- `templates/modules/healthcare/notes/`

---

## Step 2: Define Your Database Schema

Edit `modules/notes/database/migrations/001_initial.sql`:

```sql
-- Notes Module — Initial Schema

CREATE TABLE IF NOT EXISTS n_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_n_notes_status (status),
    KEY idx_n_notes_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Convention: prefix tables with your module's short prefix (`n_` for notes) to avoid collisions.

---

## Step 3: Register Tables in the Manifest

Edit `modules/notes/module.json` — add the table to `owns_tables`:

```json
{
    "id": "notes",
    "name": "Notes",
    "version": "1.0.0",
    "description": "Notes module for Ikabud Kernel",
    "author": "",
    "owns_tables": ["n_notes"],
    "reads_tables": [],
    "migrations": [
        "database/migrations/001_initial.sql"
    ],
    "capabilities": {
        "exposes": [],
        "depends": []
    },
    "events": [
        {
            "key": "notes.note.created",
            "description": "Fired when a new note is created.",
            "available_vars": ["note_id", "title", "created_by"]
        }
    ],
    "nav": [
        {
            "label": "Notes",
            "url": "/admin/notes",
            "icon": "box",
            "roles": ["admin"]
        }
    ]
}
```

Key points:
- **`owns_tables`** — tables this module has full CRUD access to. The kernel enforces this at runtime via `ModuleDB`.
- **`reads_tables`** — tables you only SELECT from (e.g., `users`, `audit_logs`).
- **`migrations`** — paths relative to the module directory.
- **`capabilities.depends`** — leave empty (`[]`) unless your module genuinely depends on capabilities provided by other modules. Kernel-native capabilities (`kernel.*`) are always available and must NOT be listed. See the [module development guide](module-development-guide.md#%EF%B8%8F-critical-depends-rules-read-before-adding-dependencies) for the full rules.
- **`events`** — events your module fires. Other modules can listen to these. `available_vars` documents the payload shape.

---

## Step 4: Run Migrations

```bash
php ikabud migrate notes
```

This creates `n_notes` in the database. If multi-tenancy is enabled, it also syncs to tenant databases.

---

## Step 5: Add a Create Handler

Edit `modules/notes/handlers.php`. The scaffold already has `pageNotesHome`. Add an API handler below it:

```php
/**
 * POST /api/v1/notes/create — Create a new note
 */
function apiNotesCreate(array $params = []): void
{
    header('Content-Type: application/json');

    $user = nCtx()->requireAnyRole('admin', 'supervisor');
    $input = nInput();

    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));

    if ($title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title is required']);
        return;
    }

    $db = nDb();

    try {
        $stmt = $db->prepare(
            'INSERT INTO n_notes (title, body, created_by) VALUES (:title, :body, :uid)'
        );
        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
            ':uid' => (int)($user['id'] ?? 0),
        ]);
        $noteId = (int)$db->lastInsertId();

        // Emit event so other modules can react
        app()->events()->fire('notes.note.created', [
            'note_id' => $noteId,
            'title' => $title,
            'created_by' => (int)($user['id'] ?? 0),
        ]);

        echo json_encode(['ok' => true, 'id' => $noteId]);
    } catch (\Throwable $e) {
        nCtx()->log('Note creation failed: ' . $e->getMessage(), 'error');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
    }
}
```

Notice:
- **`nCtx()`** — scoped module context (generated by scaffold in helpers.php)
- **`nDb()`** — scoped database access, restricted to declared `owns_tables`/`reads_tables`
- **`nInput()`** — parsed request body (JSON or form data)
- **`app()->events()->fire()`** — emit a kernel event for inter-module communication

---

## Step 6: Register the Route

Edit `modules/notes/routes.php`:

```php
<?php

declare(strict_types=1);

return [
    'GET' => [
        '/admin/notes' => 'notes:pageNotesHome',
    ],
    'POST' => [
        '/api/v1/notes/create' => 'notes:apiNotesCreate',
    ],
];
```

Route format is always `'module-id:functionName'`. The kernel resolves the module, loads its handlers, and calls the function.

**Important**: Routes MUST use the nested format shown above (`'GET' => [...], 'POST' => [...]`). The inline format `'GET /path' => 'handler'` is NOT supported by the module route loader — routes in that format are silently ignored.

---

## Step 7: Emit and Listen to Events

Your create handler already fires `notes.note.created`. To listen to events from other modules, add to `modules/notes/helpers.php`:

```php
// React when a user is deactivated (if users module fires this event)
app()->events()->listen('user.deactivated', function (array $payload, string $event) {
    $userId = (int)($payload['user_id'] ?? 0);
    if ($userId > 0) {
        nDb()->prepare('UPDATE n_notes SET status = :s WHERE created_by = :uid')
            ->execute([':s' => 'archived', ':uid' => $userId]);
    }
}, 10, 'notes');
```

The `10` is priority (lower runs first). The `'notes'` is the listener module ID for debugging.

---

## Step 8: Run the Scaffold Test

```bash
php tests/notes_module_test.php
```

Expected output:

```
=== Notes MODULE TEST ===

── Manifest ──
  ✓ module.json exists
  ✓ module.json is valid JSON
  ✓ module id matches
  ✓ module name is set
  ✓ module version is set

── Discovery ──
  ✓ Module discovered by kernel

── Capabilities ──
  ✓ Capability declarations valid

── Routes ──
  ✓ routes.php exists
  ✓ routes.php returns array
  ✓ GET routes defined

── Helpers ──
  ✓ helpers.php exists
  ✓ nCtx function exists
  ✓ nDb function exists
  ✓ nInput function exists
  ✓ nRender function exists

── Logs ──
  ✓ No errors in app.log
  ✓ No errors in error.log

──────────────────────────────────────────────────
  Result: 17 passed, 0 failed
```

---

## Step 9: Validate Your Module

Run the kernel's built-in validators:

```bash
# Check capability declarations are well-formed
php ikabud capability:validate

# Check table ownership boundaries
php ikabud module:check-boundaries

# See your module in the module list
php ikabud module:list

# See your routes registered
php ikabud routes
```

---

## Step 10: Build the Admin Page

Edit `templates/modules/notes/pages/home.disyl` to display notes:

```
{extends "layouts/app.disyl"}

{block content}
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
    <h2>Notes</h2>
    <button class="btn btn-primary" onclick="createNote()">+ New Note</button>
</div>

{if notes | count == 0}
<div class="card">
    <div class="card-body" style="padding:32px;text-align:center;color:var(--text-muted);">
        No notes yet. Create your first one!
    </div>
</div>
{/if}

{foreach notes as note}
<div class="card" style="margin-bottom:12px;">
    <div class="card-body">
        <strong>{note.title}</strong>
        <div class="text-sm text-muted" style="margin-top:4px;">{note.body}</div>
    </div>
</div>
{/foreach}
{/block}

{block scripts}
<script>
function createNote() {
    const title = prompt('Note title:');
    if (!title) return;

    fetch('{base_url}/api/v1/notes/create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: title, body: '' })
    })
    .then(r => r.json())
    .then(d => { if (d.ok) location.reload(); else alert(d.error); });
}
</script>
{/block}
```

Then update `pageNotesHome` in handlers.php to pass `notes` to the template:

---

## Auth-Owned Modules: Standard Password Reset Directive

If your new module owns its own users table and includes a public login, wire password recovery the same way every time:

- `GET /<module-id>/forgot-password`
- `GET /<module-id>/reset-password`
- `POST /api/v1/<module-id>/auth/forgot-password`
- `POST /api/v1/<module-id>/auth/reset-password`

Implementation rules:

- Return generic success from forgot-password (`{ok: true, message: ...}`) to avoid account enumeration.
- Hash reset tokens in storage, expire them after 30 minutes, and rate-limit both issuance and reset attempts.
- Invalidate every older unused token when a new reset is requested so the latest link is the only live one.
- Validate reset tokens before rendering the reset form so expired, reused, or stale links show a recovery message instead of a dead submit button.
- Require `password` and `confirm_password`, enforce a minimum length of 8, and return a login redirect on success.
- Keep kernel admin password-push as the trusted admin recovery flow for tenant admins.

The full contract lives in [module-development-guide.md](module-development-guide.md#module-owned-authentication-auth_owned).

```php
function pageNotesHome(array $params = []): void
{
    nCtx()->requireAnyRole('admin', 'supervisor');

    $stmt = nDb()->prepare('SELECT * FROM n_notes WHERE status = :s ORDER BY created_at DESC');
    $stmt->execute([':s' => 'active']);
    $notes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    echo nRender('pages/home.disyl', [
        'page_title' => 'Notes',
        'notes' => $notes,
    ]);
}
```

---

## What You've Built

In ~30 minutes you created a module with:

- **Manifest-declared contracts** — table ownership, capabilities, events
- **Scoped database access** — `nDb()` only allows tables listed in `owns_tables`
- **Inter-module events** — other modules can listen to `notes.note.created`
- **Kernel-enforced auth** — `nCtx()->requireAnyRole()` checks roles
- **Structured templates** — DiSyL with inheritance, loops, conditionals
- **Automated test** — verifies manifest, discovery, capabilities, helpers

> **Want more?** DiSyL 4.x adds pattern matching (`{match}`), i18n (`{trans}`),
> fragment cache (`{cache}`), A/B experiments (`{experiment}`), capability
> sandboxing (`{untrusted}`/`{sandbox}`/`{trusted}`), an async runtime
> (`{parallel}`/`{await}`/`{suspense}`), federation
> (`{federated_query}`/`{remote}`/`{aggregate}`), and pinned AI primitives
> (`{ai_generate}`/`{ai_query}`/`{ai_complete}`). See the **DiSyL 4.x
> Capabilities** table in
> [module-development-guide.md](module-development-guide.md#disyl-4x-capabilities-kernel--40).

---

## Declaring a Product Suite

If your module belongs to a product suite (e.g. CMS Akira, PAL), scaffold it
with the `--suite` flag so the scaffolder places it inside the suite folder and
writes the suite manifest field:

```bash
php ikabud make:module cms-akira-analytics --suite=cms-akira
```

This creates `modules/cms-akira/cms-akira-analytics/` and writes at minimum:

```json
{
    "id": "cms-akira-analytics",
    "suite": "cms-akira"
}
```

Declare the full suite contract in `module.json` — these schema-v2 fields are
**additive and optional** (legacy manifests stay valid). See
`docs/architecture/product-suite-extension-adr.md` for the full model.

| Field | Meaning |
|---|---|
| `suite` | Normalized suite id (e.g. `cms-akira`) |
| `kind` | `product-core`, `extension`, `adapter`, `profile`, `service`, `integration`, `standalone-application` |
| `extends` | Host core id this module extends (required for extension/adapter) |
| `extension_points` | Declared **only on `kind: product-core`** — point ids the host exposes (e.g. `cms.sidebar`) |
| `contributes` | `[{extension_point, provider}]` consuming a host's declared points |
| `admin_contributions` | `[{host, location, group, label, icon, route, permission, order}]` — dynamic admin sidebar entries |
| `compatibility` | `{kernel, suite}` semver ranges |
| `uninstall` | `{disable_safe, retain_data_by_default, supports_data_export, requires_confirmation_to_drop_data}` |

Rules:

- Only `kind: product-core` declares `extension_points`. Extensions/adapters
  declare `extends: <core-id>` and consume points via `contributes`.
- `kind: profile` bundles a coherent install set with `installs: [...]`.
- Contribution `host`s must be declared extension points of the host — the
  kernel validates this at install and certification time
  (`php ikabud module:certify <id>`).

**Composer-first toolchain hardening loop:** when `make:module --suite=...` or a
subsequent scaffold/composer step fails, do not patch around the failure.
Capture the error, add a regression test that reproduces it, fix the toolchain
at the root, and rerun the scaffold. The scaffolder stays the single source of
truth for new modules.

---

## CLI Cheat Sheet

| Command | What it does |
|---------|-------------|
| `php ikabud make:module <name>` | Scaffold a complete module |
| `php ikabud make:migration <mod> <name>` | Create next numbered migration |
| `php ikabud make:handler <mod> <fn> [METHOD]` | Add handler + auto-wire route |
| `php ikabud migrate [module]` | Run pending migrations |
| `php ikabud migrate:status` | Show migration status |
| `php ikabud module:list` | List all modules + status |
| `php ikabud module:enable <id>` | Enable a module |
| `php ikabud module:disable <id>` | Disable a module |
| `php ikabud module:check-boundaries` | Validate table ownership rules |
| `php ikabud capability:validate` | Validate capability schemas |
| `php ikabud event:list` | List registered event listeners |
| `php ikabud routes` | List all registered routes |
| `php ikabud disyl:lint [path]` | Lint templates for syntax errors |
| `php ikabud platform:describe` | Full platform manifest (JSON) |

---

## Next Steps

- Add more routes with `php ikabud make:handler notes apiNotesList GET`
- Expose a capability so other modules can create notes: see [Capability Contracts](module-development-guide.md#capability-contracts)
- Add settings fields to `module.json`: see [settings_fields manifest schema](module-development-guide.md)
- Package for distribution: see [Packaging & Installation](module-development-guide.md#packaging--installation)
