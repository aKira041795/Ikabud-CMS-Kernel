---
description: "PHP module conventions — routing, handlers, entity views, capabilities, and service layer patterns in the Ikabud application. Also covers polyglot module patterns (non-PHP runtimes) and how PHP interacts with them."
applyTo: "**/*.php"
---
# PHP & Module Conventions

## Module Structure
- Module route maps go in `modules/<module-id>/routes.php` — declarative `GET`/`POST` maps only
- Request logic lives in `modules/<module-id>/handlers.php`
- Shared helpers in `modules/<module-id>/helpers.php`
- Builder renderers in `modules/<module-id>/builder-renderers.php`
- Handler references follow `module-id:functionName` format

## Polyglot Modules (Ikabud is polyglot)
- Ikabud modules are **not limited to PHP** — the Kernel OS capability bus dispatches to any runtime via `ServiceProxy` (`kernel/Capabilities/ServiceProxy.php`)
- A polyglot module uses a `module.json` `"service"` block instead of `routes.php`:
  ```json
  {
    "type": "service-module",
    "service": {
      "runtime": "python",
      "endpoint": "http://127.0.0.1:9002",
      "protocol": "http+json",
      "timeout_ms": 10000,
      "retry": { "max_attempts": 2, "backoff_ms": 500 },
      "circuit_breaker": { "failure_threshold": 3, "cooldown_seconds": 30 }
    }
  }
  ```
- Polyglot modules implement the **capability wire protocol**: `POST /capability/call` with `{"capability_id":"...", "payload":{...}, "caller":{...}}` → `{"ok":true, "data":{...}}` or `{"ok":false, "error":"..."}`
- Reference example: `modules/weather-service/` — a Python module exposing `weather.current@1` and `weather.forecast@1`
- Entity views work the same regardless of runtime — `entity_sources` in `module.json` points to capabilities, the kernel handles dispatch transparently
- Routes are `[]` for service modules — they don't handle HTTP directly, they expose capabilities

## Capabilities
- Declare `capabilities.exposes`/`capabilities.depends` in `module.json` first
- For PHP modules: implement handler functions in `helpers.php` via a `*_capability_handlers()` map
- For non-PHP modules: implement the wire protocol endpoint in your language of choice
- Capabilities are validated at module load time in `src/helpers/module-manager.php`

## Entity Views (Kernel OS 6.0+)
- Use `{ikb_entity_list}`/`{ikb_entity_detail}` for all list/detail views
- Register view contracts via `{ikb_entity_view}` DiSyL config files in `helpers/views/`
- Use `filter` on `{ikb_entity_list}` for runtime narrowing; keep filter expressions simple and module-owned
- Use `keyof` in DiSyL templates when iterating unknown object shapes to avoid hard-coded field assumptions
- Entity views handle single-source data display only
- Computed cross-entity metrics, tabs, charts, multi-field filters → handler/composite template layer

## Database / DB Helpers
- Type module DB helpers to `Ikabud\Kernel\Contracts\ModuleDB` (not raw `PDO`)
- `module()->db()` returns the module DB contract
- For tenant-specific databases: use `app()->dbForTenant($tenantId)` or `php ikabud tenant:migrate`

## Security
- Superadmin role is kernel-level — check both `role === 'superadmin'` AND `source === 'kernel'`
- Use `cmsRequireRole()`, `cmsRender()`, `cmsDb()` in CMS handlers — not ad-hoc globals
- All superadmin routes/handlers live in `public/index.php`

## Rendering
- Use `{ikb_entity_list}`/`{ikb_entity_detail}` as primary rendering engine
- For composite pages (dashboards, multi-section detail), use a custom DiSyL template with handler-fetched aggregate data + embedded `{ikb_entity_list}` calls
