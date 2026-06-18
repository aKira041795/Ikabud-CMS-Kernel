# Copilot Instructions for Ikabud

## Big-picture architecture (read first)
- Runtime entrypoint is [public/index.php](../public/index.php): core routes + dynamic module routes are resolved there, then dispatched (including `module-id:functionName` handlers).
- Bootstrapping and global infra live in [bootstrap.php](../bootstrap.php): env loading, path constants, exception handler, `write_log()`, request IDs, and log paths.
- Module system is manifest-driven via [src/helpers/module-manager.php](../src/helpers/module-manager.php): discover/enable/disable modules, load routes, capability dependency safety checks.
- CMS is the main feature module under [modules/cms](../modules/cms):
  - route map in [modules/cms/routes.php](../modules/cms/routes.php)
  - handlers in [modules/cms/handlers.php](../modules/cms/handlers.php)
  - server rendering + builder helpers in [modules/cms/helpers.php](../modules/cms/helpers.php) and [modules/cms/builder-renderers.php](../modules/cms/builder-renderers.php)
- Visual page builder frontend is a separate React app in [modules/cms/builder-ui](../modules/cms/builder-ui) (Vite + TS), embedded by CMS admin routes.

## Service boundaries and data flow
- Follow module boundaries: kernel provides routing/auth/hooks/capabilities; modules provide business features.
- Keep route files declarative (`GET`/`POST` maps); place request logic in module handlers/services.
- Builder persistence flows through CMS builder APIs (`/api/v1/cms/content/{id}/builder*`) defined in [modules/cms/routes.php](../modules/cms/routes.php).
- Builder source of truth is structured JSON documents (see [docs/page-builder/page-builder-technical-spec.md](../docs/page-builder/page-builder-technical-spec.md)); avoid HTML-as-source edits.

## Critical workflows (commands)
- PHP dependencies (repo root): `composer install`
- Tenant-local module migrations: `php ikabud tenant:migrate <tenant_id|tenant_key|domain> [module]`
- Builder UI (from `modules/cms/builder-ui`):
  - `npm install`
  - `npm run dev` (local builder UI)
  - `npm run type-check`
  - `npm run build` (emits production assets)
- Kernel test/lint commands (from `ikabud-kernel`):
  - `composer test`
  - `composer lint`
  - `composer lint:fix`

## Mandatory debugging workflow
- Always check logs after running tests/builds or reproducing bugs:
  - app log: [storage/logs/app.log](../storage/logs/app.log)
  - PHP error log: [storage/logs/error.log](../storage/logs/error.log)
- Check **both** logs (app + error) on every debugging session, every test/build run, and every bug reproduction — not just one.
- Use request-id-aware traces (`X-Request-Id`, `request_id()`) when correlating API failures.

## EHR product review stance
- Review the EHR as **one product, not as isolated pages**. When changing or critiquing any EHR module, consider the patient/visit spine, the shell layout archetypes, persistent context, role-aware nav, and clinical safety UX as defined in [docs/ehr/system-design-and-architecture-plan.md](../docs/ehr/system-design-and-architecture-plan.md).
- Page-level changes that conflict with the system design plan should either be aligned to it or call out the deviation explicitly.

## Project-specific conventions
- Do not bypass module routing conventions; keep handler references as `module-id:functionName` in module route maps.
- When a module lives in a contextual subfolder under `modules/`, mirror that relative path under `templates/modules/` and keep render aliases stable as `modules/<module-id>/...`.
- Prefer existing helper/context APIs in CMS handlers (`cmsRequireRole`, `cmsRender`, `cmsDb`, etc.) instead of ad-hoc globals.
- For module DB helpers, type them to `Ikabud\Kernel\Contracts\ModuleDB` rather than raw `PDO`; `module()->db()` returns the module DB contract and strict `PDO` return types can fail only at runtime.
- For modules owned by separate tenant databases, never assume `app()->db()` is the correct migration target.
- Use `app()->dbForTenant($tenantId)`, `syncTenantCliMigrationsForTenant()`, or `php ikabud tenant:migrate <tenant_id|tenant_key|domain> [module]` so migrations run against the tenant DB instead of the primary app DB.
- If a tenant-local module reports a `42S02` missing-table error against the primary app DB but the tenant DB is healthy, treat that as a stale base `_migrations` problem first. Verify the tenant record in `kernel_tenants` / `kernel_tenant_db_connections`, then migrate the tenant DB directly instead of forcing the module onto the base DB.
- Auth-owned or tenant entry modules must own their auth/admin shell. Their settings, recovery, and entry-admin pages must not depend on `cmsRender` / `cmsAdminContext` unless the module is explicitly a CMS extension rather than the tenant shell.
- For builder changes, update source TS/TSX under [modules/cms/builder-ui/src](../modules/cms/builder-ui/src), not generated bundles in `public/admin/assets`.
- For node style/props behavior, preserve default-merge semantics used in [modules/cms/helpers.php](../modules/cms/helpers.php) and [modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx](../modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx).
- Keep public rendering deterministic: changes to builder animation/style attrs must not create duplicate/conflicting HTML attributes.
- For Disyl control-flow leaks or parsing regressions, treat them as a Disyl language or validation problem first: improve Disyl instructions, validation, or tests at the root when the issue is systemic, instead of relying on repeated one-off template patches.

## Integration points
- Capability contracts and module dependencies are validated at module load time in [src/helpers/module-manager.php](../src/helpers/module-manager.php).
- Tenant/domain rewrite and maintenance behavior are enforced in [public/index.php](../public/index.php); avoid introducing module logic that bypasses this.
- Security headers, CORS behavior, and auth cookie handling are centralized in [public/index.php](../public/index.php).
- Superadmin role is kernel-level (not declared in any module). All superadmin guards require both `role === 'superadmin'` and `source === 'kernel'`. Routes (`/superadmin/settings`, `/api/v1/superadmin/modules/*`) and handlers live in [public/index.php](../public/index.php). Cross-tenant settings helpers (`readTenantModuleSettingsForTenant`, `saveTenantModuleSettingsForTenant`, `getModuleSettingsForTenant`, `isModuleEnabledForTenant`) live in [src/helpers/module-manager.php](../src/helpers/module-manager.php) and use `app()->dbForTenant($tenantId)` from [kernel/App.php](../kernel/App.php) to connect to each tenant's own database.

## Practical edit strategy
- Prefer minimal, surgical changes in existing files over introducing new patterns.
- When touching CMS builder behavior, verify both preview behavior (React builder) and server-rendered output (PHP renderers/helpers).
- If behavior changes affect persistence schema/format, update docs in [docs/page-builder/page-builder-technical-spec.md](../docs/page-builder/page-builder-technical-spec.md) or related builder docs.

## Security hardening — CSP rules (must check during every hardening review)
- The canonical `script-src` for this app is: `'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com https://maps.googleapis.com`
- **`'unsafe-eval'` is mandatory.** Alpine.js v3 (CDN) uses `new Function()` for directive evaluation; Tailwind CSS CDN (JIT mode) uses eval-based class scanning. Dropping `'unsafe-eval'` silently breaks all Tailwind utility classes and every Alpine-driven component, including login forms.
- **Never add a `nonce-XXXX` to `script-src` while `'unsafe-inline'` is still present.** Per CSP Level 2/3, a nonce in `script-src` causes browsers to ignore `'unsafe-inline'` entirely — any inline `<script>` without the matching `nonce="..."` attribute is blocked. No templates in this repo apply nonce attributes, so adding a nonce immediately breaks all inline scripts.
- When transitioning to nonce-only CSP (future): (1) add `nonce="{csp_nonce}"` to every inline `<script>` in all Disyl/PHP templates, (2) remove `'unsafe-inline'` from `script-src`, (3) then add the nonce. These steps must not be reordered.
- After any change to `SecurityHeaders::buildCspHeaderValue()`, reload both `/login` and `/cms/login` in a real browser with DevTools open to verify Alpine/Tailwind still function before committing.

## Current Stabilization Test Priorities
- Prefer plain PHP integration-style tests under [tests](../tests) that bootstrap the app directly, clear [storage/logs/app.log](../storage/logs/app.log) and [storage/logs/error.log](../storage/logs/error.log), and assert on concrete behavior rather than mocks.
- When adding wrap-up coverage for the current repo state, prioritize these three seams first:
  1. Manifest-settings default contract tests across all settings-bearing modules.
  2. Ecommerce storefront media tests covering featured image, gallery fallback, and placeholder fallback.
  3. CMS entity-list product-card image tests for the `/ecommerce/shop` rendering path.
- Treat `/ecommerce/shop` as a CMS entity-list integration path first and an ecommerce template path second when choosing where to test storefront card behavior.

# Project Instructions

This repository is a modular PHP application kernel with strict module boundaries.

Before editing, understand the affected module, its manifest, owned tables, capabilities, routes, helpers, handlers, migrations, and tests.

Use LeanCTX tools when available:
- Prefer ctx_tree before exploring folders.
- Prefer ctx_search before broad file reads.
- Prefer ctx_read in map/signatures/auto mode before reading full files.
- Prefer ctx_shell for git, test, grep, composer, npm, and CLI commands.
- Avoid loading entire large files unless necessary.

Contextual reviews:
- Use LeanCTX. Inspect only the module routes, helpers, handlers, module.json, and related tests. Do not read unrelated modules. Propose the smallest safe fix first.

Architecture rules:
- Do not bypass the kernel boundary.
- Do not let modules call the kernel directly when hooks/capabilities/events should be used.
- Respect module-owned database tables.
- Use tenant-safe access patterns.
- Keep rendering behind DiSyL/kernel render contracts.
- Preserve existing hooks, events, capabilities, and route conventions.
- Add or update tests when changing kernel, module, or DiSyL behavior.

When modifying code:
1. Identify the smallest affected area.
2. Inspect related contracts first.
3. Propose the change.
4. Apply minimal edits.
5. Run targeted tests or explain why not run.