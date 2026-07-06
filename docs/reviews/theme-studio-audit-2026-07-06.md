# Theme Studio Module — Architecture & Implementation Audit (2026-07-06)

## Summary
Theme Studio (`modules/theme-studio/`) is a well-structured, test-heavy module that follows Ikabud module conventions closely. With 57 domain functions, 60+ test cases, 8 admin page handlers, 11 API endpoints, and 5 declared capabilities, it is the second-largest module by API surface after CMS itself. The write-through architecture to CMS Customizer is cleanly implemented. Two issues need attention: capability handler registration timing (all 5 capabilities log "no handler callable" during boot), and a CLI crash path in `themeStudioRenderTokenStyle()`.

## What was reviewed
- `modules/theme-studio/module.json` — manifest declarations
- `modules/theme-studio/helpers.php` — domain services, capability handlers, ~2100 lines
- `modules/theme-studio/handlers.php` — 8 admin + 11 API handlers
- `modules/theme-studio/routes.php` — route declarations
- `modules/theme-studio/templates/` — dashboard, presets, tokens, elements, contracts, blocks templates
- `modules/theme-studio/tests/service_test.php` — 60+ test cases, ~2200 lines
- `storage/logs/app.log` — capability handler warnings
- `storage/logs/error.log` — CLI crash trace

## Findings

### ✅ Capability Handler Convention Compliance
- `module.json` declares 5 capabilities in `capabilities.exposes` with proper IDs, priorities, modes, and descriptions
- `helpers.php` implements `theme_studio_capability_handlers()` returning the handler map — matches CMS/bakeshop/guidance pattern exactly
- All 4 admin page handlers check `cap()->call()` with the appropriate capability ID before rendering

### 🟡 Capability Handler Registration Timing
- **All 5 capabilities** log `"[warning] Module 'theme-studio' declares capability 'theme.X@1' but no handler callable was found"` during every boot
- Root cause: `theme_studio_capability_handlers()` is registered via an `app()->hooks()->on('kernel.capability_handlers', ...)` callback, but the capability bus scans `helpers.php` at file-load time, not hook time
- The handler map is loaded but the hook hasn't fired yet when the bus scans
- **Fix**: Register the handler map directly in the global scope of `helpers.php` (like CMS does with its inline `capability_handlers` array) rather than deferring to a hook

### ✅ Route Structure
- `routes.php` uses `module-id:functionName` format for all handler references ✅
- 4 GET routes for admin pages, 9 POST routes for API endpoints
- Route paths are prefixed with `/admin/theme-studio/` and `/api/v1/theme-studio/` — correct convention
- No route ordering issues detected

### ✅ Template Quality
- All admin templates extend `modules/cms/layouts/admin.disyl` — correct
- `dashboard.disyl`, `presets.disyl`, `tokens.disyl`, `elements.disyl`, `contracts.disyl`, `blocks.disyl`, `block-edit.disyl`, `contract-edit.disyl` all present and valid
- No DiSyL lint errors from theme-studio templates

### ✅ Test Coverage (Strong)
- 60+ test cases across `service_test.php`
- Coverage includes: built-in presets (5), route format, token data structure, admin template existence, token grouping, utility functions, editable contract models, form models, structured row validation, settings fields, nav hook registration, render-token CSS output, HTML injection prevention, hook registration, customizer token mapping (15 covered tokens), TS-only token prefixes
- Test 33 verifies all 57 public functions exist — prevents orphaned functions

### 🟡 `themeStudioRenderTokenStyle()` CLI Crash
- `error.log` shows: `PHP Fatal error: Call to undefined function cmsRuntimeTenantId()` when calling `themeStudioRenderTokenStyle()` from CLI
- The function calls `cmsActiveTheme()` which calls `cmsCurrentPublicThemeContext()` which calls `cmsRuntimeTenantId()` — only available during HTTP requests
- Tests wrap calls in `ob_start()`/`ob_end_clean()` to suppress the fatal, and the fallback returns `''` — but this is fragile
- **Recommendation**: Add a guard at the top of `themeStudioRenderTokenStyle()`:
  ```php
  if (!function_exists('cmsRuntimeTenantId')) {
      return ''; // CLI mode — no tenant context
  }
  ```

### ✅ Token Write-Through Architecture (Clean)
- `themeStudioCustomizerCoveredTokens()` defines 15 overlapping token keys
- `themeStudioTokenToCustomizerMap()` maps TS tokens → CMS customizer setting keys
- `themeStudioSyncOverridesToCustomizer()` writes to `module_settings` via `saveTenantModuleSettingsForTenant()`
- Single-authority principle: customizer is the rendering authority for shared CSS vars
- TS-only tokens (prefix `ts-`) are NOT synced — rendered inline via `<style id="cz-theme-studio-override">`

### ✅ Module Manifest Completeness
- `owns_tables`: 3 tables declared (theme_studio_presets, theme_studio_elements, theme_studio_token_overrides)
- `reads_tables`: tenant_module_settings declared
- `settings_fields`: 2 fields (studio_enabled, active_preset)
- `nav`: 5 nav items with proper roles
- `migrations`: 001_create_theme_presets.sql
- No `capabilities.depends` needed — all capabilities are self-contained

## Issues

| # | Severity | Description | File |
|---|---|---|---|
| 1 | 🟡 | All 5 capabilities log "no handler callable" at boot — hook timing issue | `helpers.php` |
| 2 | 🟡 | `themeStudioRenderTokenStyle()` crashes in CLI — missing function guard | `helpers.php:1873` |

## Recommendations
1. Move capability handler registration from hook-callback to global scope to eliminate boot-time warnings
2. Add `function_exists('cmsRuntimeTenantId')` guard to `themeStudioRenderTokenStyle()`
3. Add a smoke test that verifies all 5 capabilities resolve at runtime (not just that they're declared)
4. Consider extracting the largest helpers (`~2100` lines) into focused sub-files (e.g., `10-presets.php`, `20-tokens.php`, `30-elements.php`, `40-contracts.php`) following CMS's pattern
