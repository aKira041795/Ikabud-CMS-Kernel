# Post-6.1 Integration Audit — DiSyL, Kernel, CI & Test Infrastructure (2026-07-06)

## Summary
The commits since the 2026-07-05 system review (85 files, 7003 insertions, 2791 deletions) have advanced three fronts: Theme Studio module completion, ARK ecommerce expansion, and DiSyL/kernel engine hardening. The integration seams between these layers are functioning — Theme Studio writes through to the CMS Customizer, the ARK renderer registry validates at boot via `ComponentRegistry::validateRegisteredComponents()`, and the builder persists DiSyL contract trees alongside React node trees. Three pre-existing DiSyL lint errors persist in admin templates. The new agent delegation system and CI additions are solid. Overall the 6.1 "intercoherence" theme is holding.

## What was reviewed
- `kernel/DiSyL/ComponentRegistry.php` — validateRegisteredComponents() addition
- `kernel/DiSyL/ExpressionEvaluator.php` — arithmetic expression priority fix
- `kernel/DiSyL/v4/Parser.php` — {set} name trim fix
- `modules/cms/helpers/50-builder.php` — cmsBuilderEmitDiSyLContract() addition
- `.github/AGENTS.md` — agent delegation system
- `.github/agents/` — 5 agent configuration files
- `.github/workflows/ci.yml` — CI pipeline updates
- `.github/token-budget.md` — token optimization reference
- `tests/theme_regression_test.php` — cross-theme regression suite (429 lines)
- `tests/disyl_v4_compiler_test.php` — DiSyL v4 compiler tests
- `tests/disyl_v4_parser_test.php` — DiSyL v4 parser tests
- `tests/_disyl_debug.php` — DiSyL debug harness
- `php _lint_disyl.php` — 578 template lint results
- `storage/logs/app.log` — runtime warnings
- `storage/logs/error.log` — crash traces

## Findings

### ✅ ComponentRegistry Validation — New Runtime Safety Net
- `ComponentRegistry::validateRegisteredComponents()` added — validates all 32+ registered components at boot
- Checks: renderer template paths exist on disk, slot names follow dot-notation convention
- Logs warnings via `write_log()` — never throws, designed for inspection
- Called automatically at the end of `registerCoreComponents()`
- Returns warnings list for testing assertions
- Includes `normalizePath()` utility for path resolution

### ✅ ExpressionEvaluator — Arithmetic Before Dot-Path Fix
- `ExpressionEvaluator::resolveValue()` now checks arithmetic expressions (`[+\-*\/%]`) before attempting dot-path resolution
- Fixes the case where `a + b` was being treated as a single dotted path instead of an arithmetic expression
- This is the implementation corresponding to the "fixed 2026-06-29" note in copilot-instructions.md

### ✅ Parser — {set} Name Trim Fix
- `v4/Parser.php` line 866: `$name = $namePart` → `$name = trim($namePart)`
- Fixes whitespace handling in `{set}` name extraction when no type annotation is present
- Small but corrects a subtle parsing edge case

### ✅ Builder DiSyL Contract Emission
- `cmsBuilderEmitDiSyLContract()` added to `modules/cms/helpers/50-builder.php`
- Walks the React node tree post-normalization, extracts governed components (`_governed: true`, `_governedName` starting with `ikb_`)
- Produces `{component, attrs, children}` format compatible with `cmsRenderDiSyLDocument()`
- Emitted `disyl` key is persisted alongside the React node tree in the content JSON
- Non-governed nodes (layout containers, sections) are not emitted — only the governed leaf components
- Multi-governed siblings at the same level are wrapped in an array

### ✅ Theme Regression Test — 429-Line Cross-Theme Suite
- `tests/theme_regression_test.php` — new comprehensive regression test
- Discovers all themes under `storage/cms-themes/` dynamically
- Per-theme tests: DiSyL lint, manifest validation, layout existence, entity-view fallback templates, slot coverage vs `supported_slots`, renderer registry template path resolution
- Final PASS/FAIL summary with log check
- Prevents theme regressions across all installed themes (ARK, entity-native, native-default)

### ✅ Agent Delegation System — Well-Structured
- `.github/AGENTS.md` defines 5 agent roles with model assignments:
  - Code Reviewer → Claude Sonnet 4
  - Pattern Explainer → Claude Sonnet 4
  - Documentation Writer → Claude Sonnet 4
  - Test Writer → GPT-5
  - Refactoring Advisor → GPT-5
  - Explore → Gemini 2.5 Pro
- Each agent has a `.agent.md` config in `.github/agents/` with model, instructions, and tool restrictions
- `token-budget.md` provides optimization rules (143 lines)
- Agent delegation protocol is clear and well-documented

### 🟡 3 Pre-Existing DiSyL Lint Errors
```
✗ templates/pages/admin-ai.disyl — Mismatched {block}/{\/block}: 2 opening(s), 3 closing(s)
✗ templates/pages/admin-modules.disyl — Mismatched {block}/{\/block}: 2 opening(s), 1 closing(s)
✗ templates/pages/admin-tenants.disyl — Mismatched {block}/{\/block}: 2 opening(s), 3 closing(s)
```
- These errors were introduced by the `ce87882` commit (comprehensive improvement)
- They are in admin templates, not in ARK or module templates
- `admin-ai.disyl` and `admin-tenants.disyl` have extra `{/block}` closings
- `admin-modules.disyl` has a missing `{/block}` closing
- These will cause rendering errors in the admin section — **should be fixed before next deploy**

### 🟡 Capability Handler Warnings at Boot (All Modules)
```
[warning] Module 'contact-form' declares capability 'contact_form.submit@1' but no handler callable was found
[warning] Module 'content-ingestion' declares capability 'content_ingestion.import_content@1' but no handler callable was found
[warning] Module 'ecommerce' declares capability 'entity.list.ecommerce_product@1' but no handler callable was found
[warning] Module 'moodle-integration' declares capability 'moodle.sso.validate@1' but no handler callable was found
[warning] Module 'theme-studio' declares capability 'theme.customize@1' but no handler callable was found
```
- This is a systemic pattern across multiple modules, not just theme-studio
- The capability bus scans `helpers.php` at file-load time, but some modules register handlers via hooks
- The warnings are logged on every request — noisy but not functionality-breaking
- Should be addressed as a platform-level concern in 6.2

### ✅ CI Pipeline — Expanded
- `.github/workflows/ci.yml` updated with additional steps
- CI now seeds 3 tenants (including healthcare_ci) — addresses TD-CI1 from system review
- DiSyL lint step added — addresses TD-CI3
- Architecture boundary check not yet in CI (noted as TD-CI4, pending 9 violation remediation)

## Integration Assessment

### Theme Studio ↔ ARK ↔ Customizer
- ✅ Theme Studio token overrides sync to CMS Customizer via `themeStudioSyncOverridesToCustomizer()`
- ✅ ARK theme renders with customizer values via `cmsActiveThemeCustomizerSettings()`
- ✅ TS-only tokens (ts- prefix) rendered inline via `<style id="cz-theme-studio-override">`
- ⚠️ Single-authority principle maintained: customizer is the rendering authority for shared CSS vars

### DiSyL Engine ↔ Templates
- ✅ Expression evaluator arithmetic fix ensures correct template math
- ✅ Component registry validation catches broken renderer paths at boot
- ✅ 578/581 templates pass lint (3 pre-existing admin errors)

### Builder ↔ DiSyL Contract Emission
- ✅ Governed components in the builder now emit a `disyl` contract tree alongside the React node tree
- ✅ `cmsRenderDiSyLDocument()` can render the contract tree to HTML
- 🟡 Builder still defaults to React node tree rendering — DiSyL path is opt-in via `disyl_document` key

## Issues

| # | Severity | Description | Source |
|---|---|---|---|
| 1 | 🔴 | 3 DiSyL lint errors in admin templates (admin-ai, admin-modules, admin-tenants) | `_lint_disyl.php` |
| 2 | 🟡 | Capability handler warnings logged at boot across 5+ modules | `app.log` |
| 3 | 🟡 | Builder DiSyL contract emission is persisted but not yet the primary render path | `50-builder.php` |

## Recommendations
1. **Fix 3 DiSyL lint errors** — mismatched `{block}`/`{/block}` in `admin-ai.disyl`, `admin-modules.disyl`, `admin-tenants.disyl`. These are rendering errors that affect admin pages.
2. **Address capability handler timing** — investigate why capability handler maps registered via hooks aren't found at boot time. This is a platform-level issue affecting 5+ modules, not an individual module bug.
3. **Promote builder DiSyL rendering** — once the contract emission is proven stable, consider making DiSyL the default render path for governed components in the builder, with React node tree as fallback. This would close gap C3 from the DiSyL/ARK/Kernel gap analysis.
4. **Add architecture boundary check to CI** — now that `php ikabud architecture:check` is clean, add it to CI as a blocking gate (closes TD-CI4).
