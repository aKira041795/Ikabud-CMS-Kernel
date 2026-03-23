# Ikabud Kernel OS — System Architecture

## Overview

Ikabud is an **application-kernel modular infrastructure framework** — a PHP runtime that owns the full request lifecycle, extension contracts, policy enforcement, and database isolation. Modules (CMS, daily ledger, workflow, etc.) are first-class citizens that register capabilities, listen for events, and declare their own tables — but the kernel owns the rules.

**Version:** v3.1.0 (codename: "clarity")  
**Runtime:** PHP 8.2+ / MySQL 8+ / Apache with mod_rewrite  
**Template Engine:** DiSyL (Declarative Ikabud Syntax Language)

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Runtime | PHP 8.2+ |
| Database | MySQL 8+ (per-tenant isolation) |
| Template Engine | DiSyL v4.0 — layouts, blocks, 40+ filters, reactive client blocks |
| Frontend | HTMX 1.9 + Alpine.js (server-first), React/Vite (page builder UI) |
| Auth | JWT HS256 (cookie-based, httpOnly, secure) |
| CSS | Tailwind CSS |

---

## Directory Structure

```
ikabud/
├── bootstrap.php              # Env loading, path constants, error handler, global helpers
├── config/
│   ├── app.php                # App config (JWT, capabilities, multi-tenancy, AI/SMS)
│   ├── database.php           # Tenant database connection
│   └── control_database.php   # Control-plane database (tenant registry)
├── kernel/                    # Ikabud Kernel — the OS layer
│   ├── App.php                # Singleton core — boot, auth, render, hooks, DB
│   ├── Cache.php              # File-based cache service
│   ├── Crypto.php             # AES encryption (tenant DB password storage)
│   ├── EventBus.php           # Publish/subscribe event system
│   ├── EventTriggers.php      # Declarative event → action wiring
│   ├── Hooks.php              # WordPress-style filter/action hooks
│   ├── JWT.php                # Token generation and verification
│   ├── TenantResolver.php     # Multi-tenant request routing
│   ├── WorkflowRuntime.php    # State-machine workflow engine
│   ├── Capabilities/          # Contract registry and capability bus
│   ├── Contracts/             # Interface definitions
│   ├── Database/              # QueryBuilder, KernelPDO, migrations
│   ├── DiSyL/                 # Template engine core
│   └── Http/                  # TenantEntryRouter, request utilities
├── modules/                   # Feature modules (manifest-driven)
│   ├── ai/                    # AI model integrations
│   ├── anti-spam/             # Spam detection
│   ├── cms/                   # CMS + visual page builder
│   ├── contact-form/          # Form submissions
│   ├── daily-ledger/          # Inventory/financial tracking
│   ├── gui-settings/          # Theme/UI customization
│   ├── guidance/              # Admin dashboard & navigation
│   ├── media/                 # Asset management
│   ├── search/                # Full-text search
│   ├── sms/                   # SMS integration
│   ├── ticketing/             # Support ticket system
│   ├── tinymce/               # Rich text editor service
│   ├── users/                 # User management
│   └── workflow/              # Workflow automation
├── public/
│   ├── index.php              # Front controller — routing, security headers, dispatch
│   ├── lock.php               # One-time web installer
│   └── .htaccess              # Apache rewrite rules
├── src/helpers/
│   ├── module-manager.php     # Module discovery, settings, enable/disable per tenant
│   └── security.php           # CSRF helpers
├── storage/
│   ├── logs/                  # app.log, error.log (request-ID-tagged)
│   └── cache/                 # DiSyL compiled templates, capability cache
└── templates/
    ├── layouts/               # DiSyL layouts (app.disyl, admin.disyl)
    ├── pages/                 # Login, home, 404, superadmin
    └── modules/               # Per-module template directories
```

---

## App Singleton (`kernel/App.php`)

The `App` class is the kernel's central service container — a lazy-loading singleton that owns every shared primitive.

| Category | Key Methods |
|----------|-------------|
| **Lifecycle** | `boot(array $config)`, `getInstance()` |
| **Extension** | `hooks()`, `events()`, `workflow()`, `capabilities()`, `cap()` |
| **Database** | `db()` (tenant PDO), `controlDb()` (control plane PDO), `dbForTenant(int $id)` |
| **Auth** | `user()`, `setUser()`, `isAuthenticated()`, `hasRole()`, `requireAuth()`, `requireRole()` |
| **Request** | `input(?string $key)`, `isHtmx()`, `isHtmxBoosted()` |
| **Response** | `json()`, `html()`, `redirect()`, `htmxResponse()` |
| **Rendering** | `render(string $template, array $context)`, `csrfToken()`, `csrfField()`, `csrfEnforce()` |
| **Config** | `config(string $key, $default)`, `tenant()`, `jwt()`, `cache()` |
| **Logging** | `log(string $message, string $level, array $context)` |

---

## Bootstrap Flow (`bootstrap.php`)

Executed on every request before routing:

1. **Path constants** — `BASE_PATH`, `CONFIG_PATH`, `SRC_PATH`, `STORAGE_PATH`, `PUBLIC_PATH`, `KERNEL_PATH`, `TEMPLATES_PATH`
2. **Error handling** — PHP error reporting routed to `storage/logs/error.log`, stack traces never exposed to clients
3. **`.env` loading** — Parse `BASE_PATH/.env` (key=value, comments ignored); supports single- and double-quoted values with backslash escape sequences; only `[A-Z][A-Z0-9_]*` keys are accepted
4. **Config merge** — Load `config/app.php`, `config/database.php`, `config/control_database.php`
5. **Global helpers** — `request_id()`, `is_https()`, `write_log()`, `config()`, `app()`, `db()`, `kernelPdo()`
6. **Autoloader** — SPL autoloader for `Ikabud\Kernel\*` namespace
7. **Exception handler** — Global catch-all → log + generic 500 HTML

---

## Request Lifecycle (`public/index.php`)

```
Browser → Apache mod_rewrite → public/index.php
  ├── bootstrap.php
  ├── Session setup (secure, httpOnly, sameSite=Strict)
  ├── Security headers (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection)
  ├── Request ID injection (X-Request-Id)
  ├── CORS handling (/api/* routes, origin whitelist from env)
  ├── Module static asset routing (/assets/modules/{moduleId}/{path})
  ├── Tenant entry routing (TenantEntryRouter: domain → entry module rewrite)
  ├── Core route matching (auth, health, admin, superadmin)
  ├── Module route matching (loaded from module routes.php files)
  ├── Handler dispatch
  │   ├── Core handlers (login, logout, profile, superadmin)
  │   └── Module handlers (executeModuleHandler → module-id:functionName)
  │       ├── Auth enforcement
  │       ├── Capability / hook invocation
  │       └── Template render or JSON response
  └── 404 fallback
```

### Security Hardening

- **CORS:** Whitelist-only origins from `CORS_ORIGINS` env variable; never `*` with credentials
- **CSRF:** Token generation via `csrfToken()`, server-side enforcement via `csrfEnforce()`
- **Static assets:** Path traversal hardened — `..`, `\`, and empty paths are rejected
- **JWT:** HS256, 4-hour expiration, httpOnly + secure + sameSite=Strict cookies

---

## Module System (`src/helpers/module-manager.php`)

Modules are **manifest-driven**. Each module lives under `modules/{id}/` and declares its identity in `module.json`.

### Module Manifest (`module.json`)

```json
{
  "id": "cms",
  "name": "CMS",
  "version": "1.0.0",
  "entry_point": "handlers.php",
  "routes": "routes.php",
  "owns_tables": ["cms_content", "cms_settings", "cms_builder_documents"],
  "capabilities": {
    "provides": ["cms.content.get@1", "cms.builder.render@1"],
    "requires": []
  },
  "auth_cookie": null
}
```

### Discovery & Loading

1. **Scan** `modules/` for directories containing `module.json`
2. **Registry** persisted in `storage/modules.json` — tracks enabled/disabled state
3. **Dependency check** — Validate all `capabilities.requires` are satisfied before loading
4. **Route loading** — Each module's `routes.php` is merged into the global route map
5. **Handler dispatch** — Routes reference `module-id:functionName` (e.g., `cms:pageContentList`)

### Per-Tenant Module Control

- `isModuleEnabledForTenant(string $moduleId, int $tenantId)` — Check if a module is enabled for a specific tenant
- `enableModuleForTenant(string $moduleId, int $tenantId)` — Enable a module for a tenant
- `disableModuleForTenant(string $moduleId, int $tenantId)` — Disable a module for a tenant
- Settings stored in each tenant's own database via `app()->dbForTenant($tenantId)`

---

## Extension Model

The kernel provides three complementary extension mechanisms:

### 1. Hooks (`kernel/Hooks.php`)

WordPress-style filter/action system for synchronous extension points.

```php
// Module registers a filter during boot
app()->hooks()->addFilter('nav_items', function ($items) {
    $items[] = ['label' => 'CMS', 'url' => '/admin/cms'];
    return $items;
});

// Kernel applies the filter
$nav = app()->hooks()->applyFilters('nav_items', $defaultItems);
```

### 2. Events (`kernel/EventBus.php`)

Asynchronous publish/subscribe notifications.

```php
// Subscribe
app()->events()->listen('content.published', function ($payload) { ... });

// Publish
app()->events()->dispatch('content.published', ['id' => $contentId]);
```

### 3. Capabilities (`kernel/Capabilities/`)

Contract-based service invocation — modules publish typed capabilities that other modules consume via the capability bus.

```php
// Provider registers
app()->capabilities()->register('cms.content.get@1', function ($params) { ... });

// Consumer invokes
$content = app()->cap('cms.content.get@1', ['slug' => 'about']);
```

**Bus features:** timeout, retries, circuit breaker threshold, schema validation mode — all configured in `config/app.php`.

---

## Multi-Tenancy

### Architecture

- **Control plane database** — Contains `tenants` table and `kernel_tenant_db_connections` (encrypted credentials)
- **Per-tenant database** — Each tenant has its own MySQL database; credentials decrypted at connection time via `Crypto.php`
- **Tenant resolution** — `TenantResolver` resolves tenant ID from HTTP header, hostname, or default config. Host lookups are cached in memory and optionally in APCu (`ikabud:tenant_host:*` keys, TTL from `TENANT_HOST_CACHE_TTL` env var).  
  `TenantResolver::clearControlHostCache()` flushes both layers (used after tenant DB credential changes).

### Tenant Entry Routing (`kernel/Http/TenantEntryRouter.php`)

Tenants can designate an **entry module** — a module that acts as the tenant's primary frontend. The `TenantEntryRouter` rewrites incoming URLs so the tenant sees the module's routes at the root path.

**Exemptions:** `/admin/`, `/superadmin/`, `/api/`, `/auth/`, `/assets/` paths bypass rewriting.

### Cross-Tenant Operations

The superadmin (kernel-level role, not declared in any module) can manage settings across tenants:

- `readTenantModuleSettingsForTenant($moduleId, $tenantId)` — Read settings from another tenant's DB
- `saveTenantModuleSettingsForTenant($moduleId, $tenantId, $key, $value)` — Write settings to another tenant's DB
- `getModuleSettingsForTenant($tenantId)` — Get all module settings for a tenant

Guard: Both `role === 'superadmin'` AND `source === 'kernel'` are required.

---

## Authentication

### Flow

1. User visits any page → redirected to `/login` if no valid JWT cookie
2. Login form submits POST to `/auth/login`
3. Server validates credentials (bcrypt) against `users` table
4. JWT token set as auth cookie (httpOnly, secure, sameSite=Strict)
5. JWT payload: `{ sub, id, username, name, role, source }`
6. `app()->user()` decodes JWT from cookie on every request
7. Logout clears the cookie via `/auth/logout`

### Role Hierarchy

| Role | Source | Scope |
|------|--------|-------|
| `superadmin` | `kernel` | Cross-tenant, kernel-level administration |
| `admin` | module | Full module administration |
| `manager` | module | Limited management within a module |
| `viewer` | module | Read-only access |

### JWT Properties

| Property | Value |
|----------|-------|
| Algorithm | HS256 |
| Expiration | 4 hours (configurable via `JWT_EXPIRATION`) |
| Cookie httpOnly | `true` |
| Cookie secure | `true` when HTTPS detected |
| Cookie sameSite | `Strict` (configurable via `APP_COOKIE_SAMESITE`) |
| Token version | Supported via `JWT::verify($token, $expectedVersion)` |

---

## Database Layer

### QueryBuilder (`kernel/Database/`)

Fluent query builder wrapping PDO with prepared statements:

```php
db()->table('users')->where('role', 'admin')->get();
db()->table('cms_content')->insert(['title' => $title, 'slug' => $slug]);
```

### Migration System

- Kernel migrations: `migrations/` (numbered SQL files)
- Module migrations: `modules/{id}/migrations/`
- Control-plane migrations: `control-migrations/`
- Runner: `PdoMigrationRunner` — incremental, tracks applied migrations

### Tenant Migration Auto-Sync

On every HTTP request (when multi-tenancy is active) the kernel automatically
applies any pending module migrations to the current tenant's database:

```
syncTenantMigrationsForCurrentRequest()
  → resolves tenant ID
  → discovers planned modules via tenantProvisionModulePlan(entry_module_id)
  → for each module, compares declared migrations against _migrations tracking table
  → executes any unapplied SQL files, records them in _migrations
```

Tracking table `_migrations` is created automatically per tenant database:

| Column | Description |
|--------|-------------|
| `module` | Module ID that owns the migration |
| `migration` | SQL filename (basename) |
| `batch` | Incrementing batch number per module |
| `executed_at` | Timestamp of execution |

Superadmin tenant operations (provisioning entry module, saving DB credentials)
also call `syncTenantMigrationsForTenant()` explicitly and surface migration
errors in the API response before returning.

Relevant helpers in `src/helpers/module-manager.php`:
- `tenantSyncModuleMigrations(PDO, moduleId)` — apply pending migrations for one module
- `syncTenantMigrationsForTenant(tenantId)` — apply across all planned modules for a tenant
- `syncTenantMigrationsForCurrentRequest()` — request-lifecycle hook (static once-per-request)

### Tenant Database Pool

`App::dbForTenant(int $tenantId)` maintains a lazy connection pool — each tenant's PDO is created on first access and reused for the request lifetime.

---

## DiSyL Template Engine (`kernel/DiSyL/`)

**DiSyL** (Declarative Ikabud Syntax Language) is the kernel's native template engine.

### Key Features

- **Layouts & blocks** — `{extends "layouts/admin.disyl"}`, `{block content}...{/block}`
- **Variables** — `{$page.title}`, `{$user.name}` (dot notation)
- **Filters** — `{$title|upper}`, `{$content|raw}`, `{$date|date:"M d, Y"}` (40+ built-in filters)
- **Control flow** — `{if $user.role == "admin"}...{/if}`, `{foreach $items as $item}...{/foreach}`
- **Components** — `{component "partials/card" with title=$card.title}`
- **Auto-escaping** — HTML output escaped by default; use `|raw` for trusted content
- **Reactive client blocks** — Bridge to Alpine.js for interactive components
- **Compiled cache** — Templates are compiled to PHP and cached in `storage/cache/`

---

## Superadmin Panel

The superadmin is a **kernel-level role** (not module-declared) with cross-tenant authority.

### Routes

| Method | Path | Handler |
|--------|------|---------|
| GET | `/superadmin/settings` | `pageSuperadminSettings` — Per-tenant module toggle UI |
| POST | `/api/v1/superadmin/modules/toggle` | `apiSuperadminToggleModule` — Enable/disable module |

### Guards

All superadmin endpoints enforce:
- `$user['role'] === 'superadmin'`
- `($user['source'] ?? '') === 'kernel'`

### Features

- Tenant selector dropdown (lists all tenants from control plane)
- Per-module toggle switches with enable/disable state
- DB connectivity status per tenant
- Audit logging for all toggle operations

---

## Logging & Observability

- **App log:** `storage/logs/app.log` — Application-level events via `write_log()`
- **Error log:** `storage/logs/error.log` — PHP errors and uncaught exceptions
- **Request ID:** Every request gets a unique `X-Request-Id` header (accepted from upstream or generated)
- **Correlation:** All log entries include request ID for cross-log tracing
- **Audit trail:** Security-sensitive operations (module toggles, auth, lock/unlock) are logged with actor context

---

## Admin View Caching

Kernel superadmin API responses (tenant list, platform settings, module list) are
optionally cached per-tenant and per-role to reduce repeated DB reads.

**Env var:** `ADMIN_VIEW_CACHE_TTL` (seconds, default `20`, set `0` to disable)

Cache keys are scoped by `role` and `source` so a superadmin and a regular admin
never share a response. Writing through (`adminViewCacheSet`) and invalidation
(`adminViewCacheInvalidate`) happen at the same mutation points so the cache
stays consistent with database state.

Relevant helpers in `public/index.php`:

| Function | Description |
|----------|-------------|
| `adminViewCacheTtl()` | Returns configured TTL (0 = disabled) |
| `adminViewCacheGet(key, user)` | Fetch from cache; returns `null` on miss or disabled |
| `adminViewCacheSet(key, payload, tags, user)` | Write with tag annotations |
| `adminViewCacheInvalidate(tags)` | Purge all cache entries with any of the given tags |

Cache tags used:

| Tag | Invalidated by |
|-----|----------------|
| `admin:view:tenants` | Any tenant create/update/delete |
| `admin:view:platform` | Platform settings change |
| `admin:view:modules` | Module toggle or settings update |

---

## Related Documentation

| Document | Topic |
|----------|-------|
| [api-reference.md](api-reference.md) | REST API reference (auth, content negotiation) |
| [module-development-guide.md](module-development-guide.md) | Building new modules |
| [cms-architecture.md](cms-architecture.md) | CMS module architecture |
| [page-builder-technical-spec.md](page-builder-technical-spec.md) | Page builder specification |
| [disyl-implementation-spec.md](disyl-implementation-spec.md) | DiSyL v4.0 template engine spec |
| [tenancy-roadmap.md](tenancy-roadmap.md) | Multi-tenancy design and roadmap |
| [ikabud-roadmap.md](ikabud-roadmap.md) | Overall project roadmap |
| [kernel-auto-wiring.md](kernel-auto-wiring.md) | Auto-wiring flow and patterns |
