# Backup & Data-Reset Standard (module-owned databases)

> **Status:** Standard (kernel-provided helpers). DC Cafe (dc-cafe) is the reference
> implementation; daily-ledger has the original backup pattern this standard generalizes.
>
> Applies to any module with its own tenant database (`module.json` `owns_tables`).

Every module that owns tenant data should offer **two** settings capabilities,
backed by shared kernel services so implementations don't drift per module:

1. **Database backup with download** — dump the module's tables to a downloadable SQL file.
2. **Danger-zone data reset** — wipe data while keeping the catalog/config, with an explicit confirm.

## Shared services

| Service | Class | Purpose |
|---|---|---|
| Backup | `Ikabud\Kernel\Services\ModuleBackupService` | Generate data-only SQL dump of `owns_tables`, list backups, secure download, retention cleanup, audit |
| Reset | `Ikabud\Kernel\Services\ModuleDataResetService` | Execute data-wipe ops (`truncate` / `set_zero`) inside `FOREIGN_KEY_CHECKS=0`, audited |

Both autoload via the `Ikabud\Kernel\Services\` PSR mapping in `bootstrap.php`.

## Backup — how a module wires it

```php
// handlers-backup.php (module handlers)
use Ikabud\Kernel\Services\ModuleBackupService;

function apiGenerateBackup(array $params = []): void
{
    $ctx = module('my-module');            // or module context
    $ctx->requireAnyRole('admin', 'supervisor');

    if ((int) dcInput('confirm') !== 1) {  // or $ctx->input('confirm')
        dcJsonError('Confirm the backup request.', 422);
    }

    $result = ModuleBackupService::generate($ctx, 'mymod_', 'manual backup', [
        'download_path' => '/my-module/api/v1/backup/download',
        'retention_days' => 14,
        'event'          => 'my_module.backup.created',
        'by_user'        => (int)($ctx->user()['user_id'] ?? 0),
    ]);

    dcJsonResponse([
        'ok'      => true,
        'backup'  => $result,
        'backups' => ModuleBackupService::list('my-module', '/my-module/api/v1/backup/download'),
        'message' => 'Backup created: ' . $result['file_name'],
    ]);
}

function handleBackupDownload(array $params = []): void
{
    $ctx = module('my-module');
    $ctx->requireAnyRole('admin', 'supervisor');
    ModuleBackupService::download($ctx, 'mymod_', (string)($_GET['file'] ?? ''));
}
```

Routes (add to the module's `routes.php`):

```php
'GET'  => [ '/my-module/api/v1/backup/download' => 'my-module:handleBackupDownload' ],
'POST' => [ '/my-module/api/v1/backup/generate' => 'my-module:apiGenerateBackup' ],
```

Notes:
- Tables are enumerated from **`module.json` `owns_tables`** (NOT `SHOW TABLES`, which
  `ModuleDB` enforcement blocks). Pass a table prefix (`'dc_'`) to scope the dump.
- The dump is **data-only** — schema must already exist. Restore = run the SQL into a
  migrated schema (it emits `DELETE FROM` + batched `INSERT`s inside `FOREIGN_KEY_CHECKS=0`).
- Files live in `storage/backups/{moduleId}/` with a deny-all `.htaccess`.
- `ModuleBackupService::download()` validates the filename (`{slug}-db-backup-YYYYMMDD-HHMMSS.sql`)
  and guards path traversal (400/404). Always route downloads through it — never
  serve backup files directly from the web root.

## Data reset — how a module wires it

```php
use Ikabud\Kernel\Services\ModuleDataResetService;

// In the reset API handler, after role guard + confirm check:
$tables = ModuleDataResetService::reset($ctx->db(), [
    ['table' => 'mymod_movements',  'mode' => 'truncate'],
    ['table' => 'mymod_store_stock','mode' => 'set_zero', 'columns' => ['on_hand_qty', 'reserved_qty']],
    ['table' => 'mymod_products',   'mode' => 'set_zero', 'columns' => ['current_stock']],
], [
    'event'   => 'my_module.data.reset',
    'by_user' => (int)($ctx->user()['user_id'] ?? 0),
]);
```

Operations:
- `['table' => 'x', 'mode' => 'truncate']` → `DELETE FROM \`x\``
- `['table' => 'x', 'mode' => 'set_zero', 'columns' => ['a','b']]` → `UPDATE \`x\` SET a=0, b=0`

**Reset always requires an explicit confirm** (e.g. type `RESET`, or a second confirm
phrase) and should be admin/supervisor-only. Keep catalogs/config (product names,
prices, users, settings) out of the operation list — reset wipes only what you list.

## Settings UI (reference: dc-cafe Settings → Store Profile)

- **Database Backup** card: "Generate Backup" button + list of recent backups with
  per-file **Download** links (filename, size, date).
- **Danger Zone — Reset Product Inventory** card: a `Type RESET to confirm` input that
  enables the destructive button, a native `confirm()` dialog, and a success/error toast.
- The settings page seeds the backup list server-side via `ModuleBackupService::list()`
  in the page handler's initial-data JSON; the generate response refreshes it.

## Adoption checklist (new/refactored module)

- [ ] `POST /{module}/api/v1/backup/generate` + `GET /{module}/api/v1/backup/download` wired
- [ ] `POST /{module}/api/v1/.../reset` (or settings data-reset) with confirm + role guard
- [ ] Backup + reset both call the shared kernel services (no bespoke dump/reset copies)
- [ ] Settings exposes Generate/Download + Danger Zone reset
- [ ] Audit events present (`{module}.backup.created`, `{module}.data.reset`) in app.log
- [ ] `storage/backups/{module}/.htaccess` is deny-all (auto-created by the service)
- [ ] Migration/seed data doesn't recreate dev/test records (e.g. "Test Payment" methods)
