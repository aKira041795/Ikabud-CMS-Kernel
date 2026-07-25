---
description: "Complete new module checklist: user seeding, DiSyL syntax, capability handler placement, JWT auth, role access matrix, forgot password, log permissions, post-creation validation, debugging guide. Use when creating or auditing any new module."
applyTo: "**/*.php, **/*.disyl, **/module.json"
---
# New Module Checklist — De Facto Process

Applies to all `**/*.php`, `**/*.disyl`, `**/module.json` in new module creation.

## 1. User Data Seeding

Every auth-owned module MUST have seed users for all defined roles.

### Table schema
- `role` ENUM must include ALL intended roles **upfront** (`admin`, `supervisor`, `auditor`, `cashier`, etc.). Adding roles later requires a separate ALTER TABLE migration.
- Always include `email` column (VARCHAR(255) DEFAULT NULL) for password reset support.
- `auth_owned` in `module.json` MUST declare `email_column: "email"` so kernel tenant admin push handlers can update it.
- `auth_owned` MUST also declare `id_column` (e.g., `"user_id"`) and `role_column` (e.g., `"role"`) if they differ from defaults (`id`, `role`). The tenant admin push handlers (`kernelHandleApiTenantAdminEmailPush` / `kernelHandleApiTenantAdminPasswordPush`) use these to build SQL queries — missing `id_column` causes silent failures that skip the table.
- All FK columns must match referenced column types exactly (@mysql57-compat).

### Seed migration
```
database/migrations/XXX_seed_users.sql
```
- Include human-readable passwords in comments (change on first login).
- Pre-compute bcrypt hashes via `php -r "echo password_hash('password123', PASSWORD_BCRYPT);"`
- Provide one user per role with realistic name, email, and store assignment.
- After seeding, create a follow-up migration to backfill emails if the initial seed didn't include them.

### module.json updates
- `auth_owned.admin_roles`: list ALL roles that get admin-level access.
- `migrations`: add ALL migration paths in order.
- `owns_tables`: include **every** table the module owns, INCLUDING password_resets table.
- `nav.roles`: set per-item role visibility — don't forget supervisor and auditor.

---

## 2. DiSyL Syntax Conventions

### Variables — NEVER use `$` prefix
```
✅ {page_title ?? "Default"}       # DiSyL: no $
✅ {user.full_name ?? ''}
✅ {order.total|number_format:2}
❌ {$page_title ?? "Default"}      # PHP syntax — silently crashes DiSyL compiler
❌ {$user.full_name}
```

### Filters — use DiSyL filter pipe syntax (verified working)
| DiSyL filter | Usage | Replaces PHP |
|---|---|---|
| `number_format` | `{x\|number_format:2}` | `number_format($x, 2)` |
| `capitalize` | `{x\|capitalize}` | `ucfirst($x)` |
| `date` | `{x\|date:"M d, Y"}` | date format |
| `round` | `{x\|round:1}` | `round($x, 1)` |
| `default` | `{x\|default:'—'}` | `$x ?? '—'` |

### Entity view configs — use block syntax with closing tag
```
✅ {ikb_entity_view name="dc_product" view="card_grid"}
     {field name="id" type="number" role="id"}
     {field name="name" type="text" role="title"}
     {action name="edit" url="/products/{id}/edit" label="Edit"}
   {/ikb_entity_view}

❌ {ikb_entity_view source="dc_product"}           # use name= not source=
❌ {ikb_entity_field name="id" type="number"}      # use {field} inside block
❌ <missing {/ikb_entity_view}>                     # missing closing tag = fatal crash
```

### Block/extends syntax — NEVER quote block names
```
✅ {extends "parent.disyl"}     # extends path IS quoted (it's a file path)
✅ {block content}              # block name MUST NOT be quoted
   {/block}

❌ {extends parent.disyl}       # extends path without quotes fails
❌ {block "content"}            # quoted block name fails silently — renders as raw text
   {/block}
```

The DiSyL engine regex `/\{block\s+(\w+)\}(.*?)\{\/block\}/s` uses `\w+` which does NOT match quoted names like `"content"`. Quotes around block names cause `{block}` to render as **raw text** with no error or warning — the `{extends}` is processed (stripped) but block replacement silently fails.

### ~~VS Code auto-formatter regression risk~~ (Fixed in disyl-lsp v1.2.0)
~`{block` / `{/block}` was previously defined as a bracket pair in `extensions/disyl-lsp/language-configuration.json`, which caused VS Code's formatter to "correct" `{block content}` → `{block "content"}`. The bracket pair entry has been removed — the `{` / `}` pair and indentation rules handle matching/indentation correctly without triggering the false positive.~

**Prevention**: If you're on an older extension version, after any edit to a `.disyl` file with `{block}` tags, verify with:
```bash
grep -rn '{block "' templates/modules/<module>/  # should return nothing
```

### Entity list — uses `source=` not `name=`, needs self-closing ` /`
```
✅ {ikb_entity_list source="dc_order" view="table" /}          # source= + self-closing
✅ {ikb_entity_list source="dc_order" view="table" limit="50" /}
❌ {ikb_entity_list name="dc_order" view="table"}              # name= wrong attr — renders raw
❌ {ikb_entity_list source="dc_order" view="table"}            # missing / — "Missing closing tag" warning
```

### Key attribute distinction
| Tag | Attribute | Purpose |
|---|---|---|
| `{ikb_entity_view name="X" view="Y"}` blocks in `helpers/views/*.disyl` | `name=` | Registers a view contract named "X" |
| `{ikb_entity_list source="X" view="Y" /}` in page templates | `source=` | References registered view "X" to render list |
| `{ikb_entity_list source="X" view="Y" /}` | ` /` (self-closing) | Mandatory — it's NOT a block tag |

---

## 3. Capability Handler Placement (CRITICAL — causes 500)

Capability handler functions MUST be defined or loaded from **helpers.php**, NOT from handlers.php.

```php
// ✅ helpers.php — loaded at MODULE REGISTRATION time (before route dispatch)
require_once __DIR__ . '/helpers/entity-views.php';

function module_capability_handlers(): array {
    return [
        'kernel.auth.authenticate@1' => 'cap_auth_func',
        'entity.list.thing@1'        => 'cap_entity_list_thing',
    ];
}

// ❌ handlers.php — loaded only at ROUTE DISPATCH time (too late for module manager)
```

**How the module manager finds handlers** (`src/helpers/module-routes.php`):
1. Calls `loadModuleHelpers($module)` — loads `helpers.php` only
2. Builds function name from module ID: e.g. `dc_cafe_capability_handlers()`
3. Calls `is_callable($handlersMap[$capId])` on each handler string
4. If the function doesn't exist → warning + handler is NOT registered

---

## 4. Log File Permissions

If `storage/logs/app.log` or `error.log` show 0 bytes after a 500 error:

```bash
sudo chown www-data:www-data storage/logs/*.log
sudo chmod 664 storage/logs/*.log
```

`write_log()` uses `@file_put_contents()` which FAILS SILENTLY when www-data can't write.
The `@` suppresses ALL errors — no exception, no fallback, no error log.

---

## 5. JWT Auth (login/logout)

### DO use JWT + cookie (kernel pattern)
```php
// ✅ Login handler
$payload = [
    'sub' => $role . ':' . $userId,
    'id' => $userId,
    'user_id' => $userId,          // ← CRITICAL: handlers use $user['user_id']
    'username' => $userRow['username'],
    'name' => $userRow['full_name'],
    'role' => $userRow['role'],
    'source' => 'module-name',
    'store_id' => $userRow['store_id'] ?? null,  // ← CRITICAL: handlers use $user['store_id']
];

$token = app()->jwt()->generate($payload);
$cookieName = config('app.cookie_name', 'app_token');
setcookie($cookieName, $token, [
    'expires' => time() + 86400,
    'path' => '/', 'httponly' => true, 'secure' => is_https(), 'samesite' => 'Strict',
]);
app()->csrfRotate(true);
```

```php
// ✅ Logout handler
$cookieName = config('app.cookie_name', 'app_token');
setcookie($cookieName, '', ['expires' => time() - 86400, 'path' => '/']);
```

### DON'T use ModuleContext for auth state
```
❌ $ctx->setUser($result)     # Doesn't exist — no such method on ModuleContext
❌ $ctx->logout()             # Doesn't exist — no such method on ModuleContext
```

---

## 6. `requireAnyRole()` — Must Cover ALL Roles in ALL Handlers

Every page handler AND every API handler must include the correct set of roles.

### Role access matrix (standard template)
| Role | Pages | APIs |
|---|---|---|
| **admin** | All pages | All APIs |
| **supervisor** | POS, Dashboard, Orders, Inventory, Customers, Suppliers, Ingredients | Create orders, void, receive/adjust stock, manage suppliers/ingredients |
| **auditor** | Read-only views: Orders, Customers, Inventory, Suppliers, Ingredients, Dashboard | Read-only data + CSV exports |
| **cashier** | POS only | Create orders, search customers, save inventory progress |

### Common mistake — only updating page handlers, forgetting API handlers
```php
// ❌ Wrong — auditor can't access data
$ctx->requireAnyRole('admin');

// ✅ Correct
$ctx->requireAnyRole('admin', 'supervisor', 'auditor');
```

Search across ALL `*handlers*.php` files for `requireAnyRole` and update every single one.

---

## 7. Forgot / Reset Password

### Table
```sql
CREATE TABLE IF NOT EXISTS `<prefix>_password_resets` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requester_ip VARCHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_user_id (user_id),
    KEY idx_expires_at (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
        REFERENCES <users_table>(<pk_column>) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### MUST add table to `module.json` `owns_tables`
Without this, ModuleDB blocks writes to the table.

### Token validation
```php
function resetTokenIsValid(string $token): bool {
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) return false;
    return (bool) $db->query("SELECT id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1", [hash('sha256', $token)]);
}
```

### Always return success (prevent user enumeration)
```php
if (!$user) {
    echo json_encode(['ok' => true, 'message' => 'If the account exists, a reset link has been sent.']);
    exit;
}
```

### Email delivery dependency
The forgot password flow generates a token and emails the reset link. If no mail server is configured (`sendEmail()` in `src/helpers/email.php` fails), the token is stored in `<prefix>_password_resets` but never delivered. During development, retrieve the token from the DB:
```sql
SELECT token_hash FROM dc_password_resets ORDER BY created_at DESC LIMIT 1;
```
Then use it as `?token=XXX` in the reset URL. For production, ensure mail is configured or add a CLI command to output the reset URL.

### Kernel tenant admin email/password push
The kernel's tenant admin management (`/admin/tenants`) has "Set Admin Email" and "Set Admin Password" buttons that push changes to tenant module user tables via `kernelHandleApiTenantAdminEmailPush()` / `kernelHandleApiTenantAdminPasswordPush()`. These handlers iterate `kernelAuthOwnedModules()` and handle tables outside the explicit skip list `['cms_users', 'gm_users', 'wms_users', 'users']`. New auth-owned module tables (like `dc_users`) are automatically picked up — no manual handler update needed.

### Kernel profile update dependencies
The `kernelHandleApiAdminUpdateProfile()` handler updates the kernel `users` table:
- Email changes require migration `020_users_email.sql` (adds `email` column to `users`). Check `kernelUsersHasEmailColumn()` return value.
- Password changes increment `token_version`, which invalidates ALL existing JWT sessions. The user is logged out everywhere.
- JWT cookie is re-issued with updated payload on success.

### Forgot password — reset URL always logged
Both the kernel (`src/http/auth-handlers.php`) and module-level forgot password handlers now log the reset URL via `write_log()` regardless of email delivery status. In development, find the URL in `storage/logs/app.log`:
```
grep 'reset_url' storage/logs/app.log
# → ... "reset_url":"http://dccafe.test/dc-cafe/reset-password?token=..."
```
No mail server needed for development.

---

## 8. First-Run Checklist (before deployment)

- [ ] Role ENUM includes ALL intended roles (ALTER TABLE if not)
- [ ] `module.json` `owns_tables` includes password_resets table
- [ ] `module.json` `migrations` includes all migration files in order
- [ ] `module.json` `auth_owned.admin_roles` includes supervisor, auditor
- [ ] `requireAnyRole()` updated in ALL handlers (pages + APIs)
- [ ] Seed users exist with emails for forgot password
- [ ] Log files writable by www-data
- [ ] Login flow tested end-to-end: login → JWT cookie → authenticated page
- [ ] All DiSyL templates pass `php -l` on compiled output
- [ ] Entity view configs have closing `{/ikb_entity_view}` tags
- [ ] Entity view configs use `name=` not `source=`
- [ ] Capability handler functions loaded from helpers.php
- [ ] JWT payload includes `user_id` and `store_id`

---

## 9. Post-Creation Validation (run after every new module deploy)

- [ ] `php ikabud tenant:migrate <domain> <module>` — run ALL migrations
- [ ] Check `storage/logs/app.log` for capability warnings or missing handler errors
- [ ] Test login with each seeded user
- [ ] Test forgot password — check `storage/logs/app.log` for `reset_url`
- [ ] Verify `kernelAuthOwnedModules()` includes the new module (check `/superadmin/settings`)
- [ ] Test tenant admin email/password push from `/admin/tenants`

## 10. New Module Scaffolding — Proposed CLI Command

Design goal: `php ikabud module:create <module-id> [--auth-owned] [--entities=...]`

This should generate:
```
modules/<module-id>/
├── module.json                          # Manifest with skeleton capabilities, owns_tables, auth_owned
├── routes.php                           # GET/POST route maps with placeholders
├── handlers.php                         # Page + API handler stubs
├── helpers.php                          # dcCtx(), dcDb(), dcInput(), dcRender(), capability handler map
├── helpers/
│   ├── entity-views.php                 # Capability implementations
│   └── views/                           # Entity view config directory
├── database/
│   └── migrations/
│       ├── 001_create_users.sql         # Users table with ENUM roles
│       ├── 002_create_stores.sql        # Store/tenant scoping
│       ├── 003_seed_users.sql           # Seed users with bcrypt hashes
│       └── 004_create_password_resets.sql
├── handlers-*.php                       # API-specific handler files (split by domain)
└── templates/
    └── modules/<module-id>/
        ├── layouts/
        │   ├── auth.disyl               # Auth layout (login, forgot, reset)
        │   └── app.disyl                # App layout (with nav bar, user display)
        ├── login.disyl                  # Alpine.js login form
        ├── forgot-password.disyl        # Forgot password form
        ├── reset-password.disyl         # Reset password form
        ├── dashboard.disyl              # Admin dashboard
        └── ...                           # Domain-specific pages
```

The scaffold must:
1. Use the module ID to generate prefix (e.g., `dc-cafe` → `dc_cafe_`, `dcCtx()`, `dcDb()`)
2. Generate bcrypt-compatible password hashes for seed users
3. Generate correct DiSyL `{ikb_entity_view}` block syntax (not `source=`, not `{ikb_entity_field}`)
4. Generate JWT-based login/logout handlers (not nonexistent `setUser()`/`logout()`)
5. Include password_resets table in `owns_tables`
6. Generate `requireAnyRole` calls with full role matrix commented
7. Include `requires_named_admin_on_provision: false` and `touch_updated_at: true`

---

## 10. Debugging Checklist (if 500 occurs with empty logs)

| Symptom | Most Likely Cause |
|---|---|
| 500 on first load, logs empty | Log file permissions (www-data can't write) |
| 500 on first load, logs show `Missing closing tag` | Entity view config has `{ikb_entity_view}` without `{/ikb_entity_view}` |
| 500 on first load, logs show view config error | `{ikb_entity_view source="..."}` — should be `name=` |
| Handlers not found, capability warnings | Handler functions in entity-views.php NOT loaded from helpers.php |
| Login returns 500 `Call to undefined method setUser()` | Using `$ctx->setUser()` — should use JWT cookie pattern |
| Session start returns FK violation | JWT payload missing `user_id` or `store_id` |
| "No handler callable found" warnings | Capability handlers in handlers.php not helpers.php |
| 419 on POST after GET | Page cache serving stale CSRF — add path to PAGE_CACHE_SKIP_PREFIXES |
| SQL error on password_resets write | Table not in `module.json` `owns_tables` |
| **`{block}` shows as raw text in rendered page** | **Block name quoted: `{block "content"}` → use `{block content}` (no quotes). The DiSyL regex `\w+` doesn't match quoted names — fails silently with no error.** |
