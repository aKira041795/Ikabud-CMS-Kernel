# Copilot Instructions for Baron Bakeshop / Ikabud

## Big-picture architecture (read first)
- Runtime entrypoint is [public/index.php](public/index.php): core routes + dynamic module routes are resolved there, then dispatched (including `module-id:functionName` handlers).
- Bootstrapping and global infra live in [bootstrap.php](bootstrap.php): env loading, path constants, exception handler, `write_log()`, request IDs, and log paths.
- Module system is manifest-driven via [src/helpers/module-manager.php](src/helpers/module-manager.php): discover/enable/disable modules, load routes, capability dependency safety checks.
- CMS is the main feature module under [modules/cms](modules/cms):
  - route map in [modules/cms/routes.php](modules/cms/routes.php)
  - handlers in [modules/cms/handlers.php](modules/cms/handlers.php)
  - server rendering + builder helpers in [modules/cms/helpers.php](modules/cms/helpers.php) and [modules/cms/builder-renderers.php](modules/cms/builder-renderers.php)
- Visual page builder frontend is a separate React app in [modules/cms/builder-ui](modules/cms/builder-ui) (Vite + TS), embedded by CMS admin routes.

## Service boundaries and data flow
- Follow module boundaries: kernel provides routing/auth/hooks/capabilities; modules provide business features.
- Keep route files declarative (`GET`/`POST` maps); place request logic in module handlers/services.
- Builder persistence flows through CMS builder APIs (`/api/v1/cms/content/{id}/builder*`) defined in [modules/cms/routes.php](modules/cms/routes.php).
- Builder source of truth is structured JSON documents (see [docs/page-builder-technical-spec.md](docs/page-builder-technical-spec.md)); avoid HTML-as-source edits.

## Critical workflows (commands)
- PHP dependencies (repo root): `composer install`
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
  - app log: [storage/logs/app.log](storage/logs/app.log)
  - PHP error log: [storage/logs/error.log](storage/logs/error.log)
- Use request-id-aware traces (`X-Request-Id`, `request_id()`) when correlating API failures.

## Project-specific conventions
- Do not bypass module routing conventions; keep handler references as `module-id:functionName` in module route maps.
- Prefer existing helper/context APIs in CMS handlers (`cmsRequireRole`, `cmsRender`, `cmsDb`, etc.) instead of ad-hoc globals.
- For builder changes, update source TS/TSX under [modules/cms/builder-ui/src](modules/cms/builder-ui/src), not generated bundles in `public/admin/assets`.
- For node style/props behavior, preserve default-merge semantics used in [modules/cms/helpers.php](modules/cms/helpers.php) and [modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx](modules/cms/builder-ui/src/builder/components/NodeRenderer.tsx).
- Keep public rendering deterministic: changes to builder animation/style attrs must not create duplicate/conflicting HTML attributes.
- For Disyl control-flow leaks or parsing regressions, treat them as a Disyl language or validation problem first: improve Disyl instructions, validation, or tests at the root when the issue is systemic, instead of relying on repeated one-off template patches.

## Integration points
- Capability contracts and module dependencies are validated at module load time in [src/helpers/module-manager.php](src/helpers/module-manager.php).
- Tenant/domain rewrite and maintenance behavior are enforced in [public/index.php](public/index.php); avoid introducing module logic that bypasses this.
- Security headers, CORS behavior, and auth cookie handling are centralized in [public/index.php](public/index.php).
- Superadmin role is kernel-level (not declared in any module). All superadmin guards require both `role === 'superadmin'` and `source === 'kernel'`. Routes (`/superadmin/settings`, `/api/v1/superadmin/modules/*`) and handlers live in [public/index.php](public/index.php). Cross-tenant settings helpers (`readTenantModuleSettingsForTenant`, `saveTenantModuleSettingsForTenant`, `getModuleSettingsForTenant`, `isModuleEnabledForTenant`) live in [src/helpers/module-manager.php](src/helpers/module-manager.php) and use `app()->dbForTenant($tenantId)` from [kernel/App.php](kernel/App.php) to connect to each tenant's own database.

## Practical edit strategy
- Prefer minimal, surgical changes in existing files over introducing new patterns.
- When touching CMS builder behavior, verify both preview behavior (React builder) and server-rendered output (PHP renderers/helpers).
- If behavior changes affect persistence schema/format, update docs in [docs/page-builder-technical-spec.md](docs/page-builder-technical-spec.md) or related builder docs.

## Current Stabilization Test Priorities
- Prefer plain PHP integration-style tests under [tests](tests) that bootstrap the app directly, clear [storage/logs/app.log](storage/logs/app.log) and [storage/logs/error.log](storage/logs/error.log), and assert on concrete behavior rather than mocks.
- When adding wrap-up coverage for the current repo state, prioritize these three seams first:
  1. Manifest-settings default contract tests across all settings-bearing modules.
  2. Ecommerce storefront media tests covering featured image, gallery fallback, and placeholder fallback.
  3. CMS entity-list product-card image tests for the `/ecommerce/shop` rendering path.
- Treat `/ecommerce/shop` as a CMS entity-list integration path first and an ecommerce template path second when choosing where to test storefront card behavior.
