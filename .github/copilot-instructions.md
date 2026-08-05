# Copilot Instructions for Ikabud

## Big-picture architecture (read first)
- Runtime entrypoint is [public/index.php](../public/index.php): core routes + dynamic module routes are resolved there, then dispatched (including `module-id:functionName` handlers).
- Bootstrapping and global infra live in [bootstrap.php](../bootstrap.php): env loading, path constants, exception handler, `write_log()`, request IDs, and log paths.
- Module system is manifest-driven via [src/helpers/module-manager.php](../src/helpers/module-manager.php): discover/enable/disable modules, load routes, capability dependency safety checks.
- Entity view system (Kernel OS 6.0+) is the primary rendering engine: `EntityViewResolver` resolves source/view to data via capability bus, `DefaultEntityRenderer` produces HTML. See [docs/kernel/entity-context-system.md](../docs/kernel/entity-context-system.md) and [docs/kernel/entity-view-adoption-plan.md](../docs/kernel/entity-view-adoption-plan.md).
- Kernel OS 6.0/6.1 roadmap status: [docs/kernel/kernel-os-disyl-roadmap-status.md](../docs/kernel/kernel-os-disyl-roadmap-status.md) — check before designing rendering or capabilities.
- CLI tools reference (architecture enforcement, scaffolding, diagnostics): [docs/kernel/cli-tools-reference.md](../docs/kernel/cli-tools-reference.md)
- Workflow system (state machine + multi-step engine): [docs/kernel/workflow-system.md](../docs/kernel/workflow-system.md)
- DiSyL async Fibers scheduler: [docs/kernel/disyl-async-fibers-scheduler.md](../docs/kernel/disyl-async-fibers-scheduler.md)
- DiSyL development loop (canonical workflow for engine-level DiSyL changes): [docs/kernel/disyl-development-workflow.md](../docs/kernel/disyl-development-workflow.md)
- DiSyL VS Code Extension: [extensions/disyl-lsp/README.md](../extensions/disyl-lsp/README.md)
- Script block interpolation safety: [docs/kernel/script-block-interpolation.md](../docs/kernel/script-block-interpolation.md)
- Release notes directory (read the most recent file by date): [docs/releases/](../docs/releases/)
- CMS is the main feature module under [modules/cms](../modules/cms):
  - route map in [modules/cms/routes.php](../modules/cms/routes.php)
  - handlers in [modules/cms/handlers.php](../modules/cms/handlers.php)
  - server rendering + builder helpers in [modules/cms/helpers.php](../modules/cms/helpers.php) and [modules/cms/builder-renderers.php](../modules/cms/builder-renderers.php)
- Visual page builder frontend is a separate React app in [modules/cms/builder-ui](../modules/cms/builder-ui) (Vite + TS), embedded by CMS admin routes.

## Agent delegation system
Custom agents with model assignments live in `.github/agents/`. The full registry and delegation protocol is in `.github/AGENTS.md`. Token budget reference and optimization rules are in `.github/token-budget.md`.

**Model-to-role mapping**:
- **Claude Sonnet 4** (Anthropic) → Code Reviewer, Pattern Explainer, Documentation Writer
- **GPT-5** (OpenAI) → Test Writer, Refactoring Advisor
- **Gemini 2.5 Pro** (Google) → Explore (fast read-only research)

Delegate specialized tasks to the appropriate agent. For complex multi-step work, use Explore first for context, then delegate implementation as needed.

## Skills registry (19 files in `.github/skills/`)
All skills are loaded automatically by their `applyTo` patterns or `description` trigger phrases — treat them as rules, not suggestions.

### Mandatory (always active — apply to all or broad file patterns)

| Skill | Applies to | What it enforces |
|---|---|---|
| `iterative-task-execution` | `**/*` | Todo-driven loop: break down → implement one item → verify → mark done → repeat. Scope discipline, no feature creep. |
| `debug-workflow` | `**/*.php`, `**/*.disyl`, `**/*.sql` | Check both `app.log` + `error.log` on every issue. Systematic debugging for module DB errors, DiSyL warnings, request tracing. |
| `code-review-checklist` | `**/*.php`, `**/*.disyl`, `**/*.sql` | SQL query patterns, migration idempotency, route ordering, entity view registration, form handler completeness, template safety. |
| `testing-strategy` | `**/tests/**` | Four-tier testing: unit → integration → security → acceptance. Required before marking features done. |
| `module-boundaries` | `**/*.php` | Kernel boundary discipline, tenant scoping, capability-based access, route ownership. No bypassing kernel contracts. |
| `service-layer-patterns` | `**/services/**` | Domain service structure (`ServiceResult`), transaction discipline, event emission, audit logging. |
| `module-creation` | `**/module.json` | Module scaffold checklist, manifest essentials, capability handler naming, migration patterns, auth-owned module conventions. |
| `new-module-checklist` | `**/*.php`, `**/*.disyl`, `**/module.json` | Complete new module checklist: user seeding, DiSyL syntax, capability handler placement, JWT auth, role access matrix, forgot password, log permissions, debugging guide. |

### Domain-specific (loaded when file pattern or topic matches)

| Skill | Applies to | What it enforces |
|---|---|---|
| `approval-workflow` | `**/*.php` | Multi-state approval state machine: submit, review, reject, return, escalate. |
| `attendance-wage-payroll` | `**/attendance-wage/**` | Payroll computation, benefits deductions, tax, 13th month, module DB query rules. |
| `auth-module-setup` | `**/handlers/05-auth.php` | Auth-owned modules: API routes, JSON input/output, CSRF avoidance for shared login templates. |
| `disyl-engine-first-fix` | `**/*.disyl` | Fix DiSyL at the engine level (`kernel/DiSyL/`) rather than template bandaids. |
| `docs-update-triggers` | *(description-triggered)* | When and what documentation to update after code changes. |
| `domain-events` | `**/services/**` | Emitting and handling domain events through the kernel event bus. |
| `entity-view-system` | `**/entity-views.php` | Entity view pipeline: resolver, renderers, cell types, action wiring, inline editing. |
| `financial-immutability` | `**/services/**` | Reversal, void, adjustment patterns — never overwrite approved financial records. |
| `inventory-costing` | `**/services/**` | Movement-first inventory with weighted average costing, cost snapshots. |
| `migration-workflow` | *(description-triggered)* | Schema changes: file naming, `module.json` registration, CLI apply, safe ALTER TABLE. |
| `report-generation` | `**/reports/**` | PDF and Excel report patterns: A4 format, headers, filters, streaming, audit. |
| `ark-architecture-audit` | *(description-triggered)* | System codebase audit: Kernel OS, DiSyL, ARK theme architecture, convention compliance. |
| `bluehost-mysql-compatibility` | `**/*.php`, `**/*.sql` | Bluehost MySQL 5.7 constraints: no window functions, no CTEs, InnoDB required, FK type matching, pre-deployment audit checklist. |

## Service boundaries and data flow
- Follow module boundaries: kernel provides routing/auth/hooks/capabilities; modules provide business features.
- Keep route files declarative (`GET`/`POST` maps); place request logic in module handlers/services.
- Builder persistence flows through CMS builder APIs (`/api/v1/cms/content/{id}/builder*`) defined in [modules/cms/routes.php](../modules/cms/routes.php).
- Builder source of truth is structured JSON documents (see [docs/page-builder/page-builder-technical-spec.md](../docs/page-builder/page-builder-technical-spec.md)); avoid HTML-as-source edits.

## New module design workflow
When creating a module from scratch, use this per-phase iteration loop:
1. **Research** — Study 2-3 existing modules with similar scope (module.json, routes, handlers, helpers pattern). Consult `docs/kernel/entity-view-adoption-plan.md` for entity view patterns.
2. **Propose phase spec** — List the files, key decisions, and migration SQL for the phase. Include capability IDs and table names. Get approval before writing code.
3. **Build per-phase** — Each phase follows this order:
   a. Migration SQL (schema drives everything)
   b. Domain service with business logic
   c. Unit test for the service (written alongside, not deferred)
   d. Handler + routes
   e. Template (entity view or composite)
   f. Validate: `php -l`, run tests, check both logs
4. **Checkpoint** — Present results, get sign-off before next phase.

Capabilities must be designed before routes. Declare `capabilities.exposes`/`capabilities.depends` in `module.json` first, then implement handler functions in `helpers.php` via a `*_capability_handlers()` map (see bakeshop/guidance/wms for the pattern).

**Suite modules (product suite + extension model):** If the new module belongs to a product suite (e.g. CMS Akira), read `docs/architecture/product-suite-extension-adr.md` and `modules/cms-akira/README.md` before writing code. Suite module authors must declare `suite`/`kind`/`extends`/`contributes` in `module.json` (plus `extension_points`, `admin_contributions`, `compatibility`, `uninstall` where applicable) and scaffold with `php ikabud make:module <name> --suite=<suite-id>` so the scaffolder places the module inside the suite folder and writes the manifest fields. Only `kind: product-core` modules declare the suite's `extension_points`; extension/adapter modules declare `extends: <core-id>` and consume points via `contributes`; profile modules bundle a coherent install set. These schema-v2 fields are additive — legacy manifests remain valid.

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

## Debug-first rule for runtime bugs (mandatory — do NOT skip)

When a user reports a runtime issue ("data not saved", "form doesn't work", "button does nothing", "page shows wrong data"), follow this protocol **before making any code changes**:

1. **Reproduce first** — Do not read code or theorize. Ask the user what exact steps they took, what they saw, and what they expected. If possible, have them reproduce while you watch logs.

2. **Gather runtime evidence** — Check BOTH log files. Clear logs, have the user reproduce, check logs again. For frontend issues, ask the user to check browser DevTools Console and Network tab.

3. **Narrow the failure point** — Before touching any code, determine which layer failed:
   - Did the request reach the server? (check access logs, app.log)
   - Did the PHP handler execute? (add temporary `write_log()` if needed)
   - Did `$_POST` contain the expected data? (log `array_keys($_POST)`, `count($_POST)`)
   - Did the response reach the browser? (check Network tab status + body)
   - Is there a JS error? (browser Console)

4. **Prove the fix with a direct test** — Before changing production code, write a one-line PHP test that calls the suspect function directly. If it works in CLI but not via HTTP, the problem is in the HTTP/JS layer, not the PHP logic.

5. **Fix the root cause, not the symptom** — Once the failure point is identified, trace upstream to find what caused it. Apply the minimal fix at the source.

6. **Verify** — After the fix, have the user reproduce again and confirm the issue is resolved. Check both logs for new errors.

**Anti-patterns that waste time** (learned 2026-07-19):
- ❌ Reading 500+ lines of PHP backend code when the symptom is a JS/frontend issue
- ❌ Making speculative code changes across multiple files without confirming the failure point
- ❌ Analyzing CSRF managers, input parsers, and route matchers when the form never reaches the server
- ❌ Rewriting the form submission JS without first checking if the original JS is even being called
- ❌ Fixing edge-case data integrity bugs (duplicate column assignments) that have nothing to do with the reported symptom

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
- **Entity view rendering**: Use `{ikb_entity_list}`/`{ikb_entity_detail}` as the primary rendering engine for all list/detail views. Register view contracts via `{ikb_entity_view}` DiSyL config files in `helpers/views/`, loaded by `TemplateEngine::loadViewConfigs()`. For composite pages (dashboards, multi-section detail pages, approval queues), use a custom DiSyL template with handler-fetched aggregate data and embedded `{ikb_entity_list}` calls — see the `attendance-wage` dashboard and `docs/kernel/entity-view-adoption-plan.md` for the proven pattern.
- **Entity view limitation boundary**: Entity views handle single-source data display only. Computed cross-entity metrics, multi-source aggregation, tabs, charts, and multi-field filter forms belong in the handler/composite template layer, not in entity view contracts.
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

## Debugging compiled mode — template changes not reflecting
When editing DiSyL templates (`.disyl`) with `DISYL_COMPILED_MODE=true`, the compiled template cache may not detect layout changes:
- **`TemplateCache::needsRecompile()`** only checks the source template's mtime, NOT ancestor `{extends}` layouts. If you edit a layout file, child templates are NOT automatically recompiled.
- **Fix for developers**: Add `?disyl_nocache=1` to the URL to force recompilation of that specific template.
- **Fix for production**: `TemplateCache::needsRecompile()` now (2026-06-29) scans for `{extends}` and recursively checks all ancestor mtimes. If you encounter stale caches after a layout edit, restart PHP-FPM to clear APCu.
- **APCu** persists across FPM graceful restarts. Use `apcu_clear_cache()` via a web endpoint or `sudo systemctl restart phpX.X-fpm` (force stop, not graceful) to clear it.

## Known DiSyL limitations (current as of 2026-07-05)
1. **`{math equation="..."}` tag does not exist** — it is referenced in a template but was never implemented. Use DiSyL arithmetic directly: `{(value)|round}` or `{a * b}`. See TD-D1 in `docs/reviews/system-review-2026-07-05.md`.
2. **Tight pipe/filter binding**: `{a + b | filter}` applies the filter to `b` only, not `a + b`. Always parenthesize: `{(a + b) | filter}`.
3. **Typed `{set}` syntax (`{set name: string = ...}`) is planned for DiSyL 4.8** and is NOT active in the current 4.7 runtime. Do not use typed assignment syntax in production templates.

> **Resolved (2026-08-05):** `{json_encode(...)}` and `{json_decode(...)}` **function calls** are now supported at the engine level (previously only the `|json` filter existed). `json_encode` mirrors `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; `json_decode` returns an associative array and supports dot-path access (`json_decode(...).key`). The 3 remaining limitations above stay intact.

> All `{set}` logical operator, ternary-with-filter, `isset()`/`empty()`, and array literal `{['a','b']}` issues that were listed here previously are **fixed as of 2026-06-29 / 2026-07-05** and no longer restrictions.

## Bluehost / MySQL 5.7 compatibility (must check on every SQL change)
> **@mysql57-compat:** All rules in this section are MySQL 5.7 constraints belonging to the **Compatibility database profile** (see [docs/kernel/database-profiles.md](../docs/kernel/database-profiles.md)). When Bluehost upgrades to MySQL 8.0+, grep for `@mysql57-compat` to find features to unlock and switch to Enterprise profile.

The production deployment target is **Bluehost shared hosting**, which runs **MySQL 5.7** (or MariaDB <10.2) — the Compatibility profile. The following MySQL 8.0+ features are **unavailable** in Compatibility mode and must never be used without a profile gate:

| Forbidden | Use instead |
|---|---|
| @mysql57-compat: Window functions (`COUNT(*) OVER()`, `ROW_NUMBER() OVER()`, `RANK()`, `LAG()`, `LEAD()`, etc.) | Separate `SELECT COUNT(*)` query, or app-level aggregation |
| @mysql57-compat: Common Table Expressions (`WITH ... AS (...)`) | Derived tables, temporary tables, or app-level logic |
| @mysql57-compat: `JSON_TABLE()` | App-level JSON decode + loop |
| @mysql57-compat: `CHECK` constraints (enforced only in 8.0.16+) | App-level validation or triggers |
| @mysql57-compat: `EXCEPT` / `INTERSECT` set operators | `NOT EXISTS` / `IN` / `JOIN` equivalents |

### Migration compatibility rules
- @mysql57-compat: Every `CREATE TABLE` must include `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` — Bluehost defaults to MyISAM, which silently drops FOREIGN KEY constraints.
- @mysql57-compat: Foreign key columns must have **exactly** the same type (including signedness and width) as the referenced column. `BIGINT UNSIGNED` cannot reference `INT UNSIGNED`.
- @mysql57-compat: Use `SET FOREIGN_KEY_CHECKS = 0` / `SET FOREIGN_KEY_CHECKS = 1` around cross-module CREATE TABLE statements where the referenced table may not exist yet (kernel migrations run before module migrations).

### Pre-deployment SQL audit checklist
- [ ] `grep -rn "OVER()" modules/ src/ kernel/` returns nothing
- [ ] `grep -rn "WITH.*AS\s*\(" modules/ src/ kernel/ --include="*.php"` returns nothing (unless it's a non-CTE use like `WITH GRANT OPTION`)
- [ ] Every migration `CREATE TABLE` ends with `ENGINE=InnoDB`
- [ ] FK column types match referenced column types exactly
- [ ] No `JSON_TABLE()`, `EXCEPT`, `INTERSECT` in queries

## Current Stabilization Test Priorities
- Prefer plain PHP integration-style tests under [tests](../tests) that bootstrap the app directly, clear [storage/logs/app.log](../storage/logs/app.log) and [storage/logs/error.log](../storage/logs/error.log), and assert on concrete behavior rather than mocks.
- When adding wrap-up coverage for the current repo state, prioritize these three seams first:
  1. Manifest-settings default contract tests across all settings-bearing modules.
  2. Ecommerce storefront media tests covering featured image, gallery fallback, and placeholder fallback.
  3. CMS entity-list product-card image tests for the `/ecommerce/shop` rendering path.
- Treat `/ecommerce/shop` as a CMS entity-list integration path first and an ecommerce template path second when choosing where to test storefront card behavior.

