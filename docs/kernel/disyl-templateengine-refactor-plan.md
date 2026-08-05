# DiSyL TemplateEngine Refactor Plan (D8)

> **Plan date:** August 6, 2026
> **Target:** `kernel/DiSyL/TemplateEngine.php` (7,941 lines) — God Object decomposition
> **Status:** 🔄 In progress — **ComponentRenderer ✅ + MacroProcessor ✅ (2026-08-06)**;
> TemplateEngine reduced 7,941 → 5,746 lines (−2,195, ~28%). IncludeResolver /
> ExtendsProcessor **deferred** (render-core coupling — see §6b). P4-3 environment-blocked.
> **Guardrail:** This refactor must not run immediately before a live deployment. Each
> extraction is verified by the full DiSyL regression suite (`disyl_engine_test`,
> `disyl_parity_test`, `disyl_hardening_coverage_test`, `disyl_compiled_component_fallback_test`,
> plus all parser/compiler/cache/sandbox suites) before proceeding to the next increment.

---

## 1. Why

`TemplateEngine` is a 7,941-line class implementing: the interpreted rendering pipeline
(regex-based), component rendering (40+ HTML builders), macro processing, include resolution,
extends/layout processing, output caching, security filtering, entity-view rendering,
AI/report/HTMX integration, and the compiled-mode bridge. It is difficult to test, reason
about, and secure as a single unit. Splitting it into focused classes with a thin delegating
facade preserves the public API while restoring single-responsibility.

## 2. Target class decomposition

| New class | Path | Approx. lines moved | Method clusters |
|---|---|---|---|
| `TemplateRenderer` | `kernel/DiSyL/Renderer/TemplateRenderer.php` | ~1,100 | `render()`, `renderString()`, output/shared cache keys, fast fingerprint, `readTemplateSource`, `buildOutputCacheKey`, metrics |
| `TemplateCompiler` *(exists as `Compiler/TemplateCompiler.php`)* | — | — | AST → PHP (already extracted) |
| `ComponentRenderer` | `kernel/DiSyL/Component/ComponentRenderer.php` | ~2,080 | `renderComponent`, all `render*` builders, `buildHtmxAttrs`, `sanitizeHref`, entity-view config/list/detail, state declaration, island, AI summary/assist, report, `entityErrorState` |
| `MacroProcessor` | `kernel/DiSyL/Component/MacroProcessor.php` | ~180 | `extractMacros`, `expandMacroCalls`, `parseMacroParamList`, `parseCallArgList`, `splitMacroCallArgs` |
| `IncludeResolver` | `kernel/DiSyL/Component/IncludeResolver.php` | ~400 | `processIncludes`, `processNextInclude`, `processIncludeTag`, `parseIncludeParams`, `parseKeyValueParams`, `readIncludeSource`, path resolution |
| `ExtendsProcessor` | `kernel/DiSyL/Component/ExtendsProcessor.php` | ~220 | `processExtends`, `getExtendsCache`, `setExtendsCache`, `processBlocks`, `processDebugTags` |

The interpreted expression/control-structure evaluator cluster (`compile`, `processControlStructures`,
`evaluate*Body`, `processVariables`, filters) is the largest remaining chunk (~3,000 lines) and is
deliberately **last** — it is the most interdependent. It may stay in `TemplateEngine` until the
clean clusters above are extracted.

## 3. Extraction order (dependency-driven, each verified)

1. **ComponentRenderer** ✅ — cleanest boundary (only 4 engine touchpoints), largest single
   reduction, fixes the `entityErrorState` latent bug.
2. **MacroProcessor** — small, self-contained; depends on expression helpers via closures.
3. **IncludeResolver** — depends on path resolution + readTemplateSource.
4. **ExtendsProcessor** — depends on include resolution + cache dir.
5. **TemplateRenderer** — top-level facade split; only after the above so the facade is thin.
6. **Interpreted evaluator** (optional/last) — highest risk; may remain as a documented
   "render core" within TemplateEngine.

## 4. ComponentRenderer extraction — design

### 4.1 Boundary
Move `TemplateEngine` lines 5860–7941 (`renderComponent` through `renderEntityDetailViaService`
plus all helpers) into `kernel/DiSyL/Component/ComponentRenderer.php`.

### 4.2 External dependencies (the only engine touchpoints)
| In TemplateEngine | In ComponentRenderer | Mechanism |
|---|---|---|
| `$this->components` (property) | custom component registry | `TemplateEngine::internalComponents(): array` |
| `$this->debug` (property) | debug flag | `TemplateEngine::isDebug(): bool` |
| `$this->logError()` (private) | error logging | `TemplateEngine::logError()` made `public` |
| `$this->aiProvider()` (public) | AI provider factory | already public |

All other `$this->X` calls in the block resolve to methods moved **within** the new class.
Global `app()` and `function_exists()` remain available.

### 4.3 New class shape
```php
namespace Ikabud\Kernel\DiSyL\Component;

final class ComponentRenderer
{
    private TemplateEngine $engine;
    public function __construct(TemplateEngine $engine) { $this->engine = $engine; }
    // ... moved render* methods ...
}
```

### 4.4 Facade delegation in TemplateEngine
```php
private ?ComponentRenderer $componentRenderer = null;
private function componentRenderer(): ComponentRenderer
{
    return $this->componentRenderer ??= new ComponentRenderer($this);
}
private function renderComponent(string $component, array $attrs, string $children, array $context): string
{
    return $this->componentRenderer()->renderComponent($component, $attrs, $children, $context);
}
```
`processComponents` and `renderRegion`/`renderIkbComponent` callers are unchanged — they call
`$this->renderComponent(...)`, which delegates.

## 5. Latent bug fixed by this extraction

`TemplateEngine::renderAiSummary`/`renderAiAssist` call `$this->entityErrorState(...)` but the
method is **not defined** on `TemplateEngine` (it only exists on
`DefaultEntityRenderer`). Any AI-disabled / policy-denied / provider-error path throws
`Error: Call to undefined method`. The `entityErrorState()` implementation is ported into
`ComponentRenderer` (from `DefaultEntityRenderer::entityErrorState`, line 1052), fixing the
bug for the AI component renderers.

## 6. Regression verification (mandatory before each merge)

- `php -l` on the new class + TemplateEngine
- `tests/disyl_engine_test.php` (282)
- `tests/disyl_parity_test.php` (97 interpreted↔compiled)
- `tests/disyl_hardening_coverage_test.php` (44 — covers `ikb_ai_assist`/`ikb_ai_summary`)
- `tests/disyl_compiled_component_fallback_test.php` (3 — component fallback)
- `tests/disyl_v4_parser_test.php`, `tests/disyl_v4_compiler_test.php`,
  `tests/disyl_template_cache_test.php`, `tests/disyl_v44_sandbox_test.php`,
  `tests/disyl_security_fuzz_test.php`, `tests/disyl_loop_control_test.php`
- A real-template smoke render through compiled + interpreted paths
- Check **both** `storage/logs/app.log` and `storage/logs/error.log`

## 6a. ComponentRenderer extraction — verification result (2026-08-06)

- `php -l` clean on `ComponentRenderer.php` + `TemplateEngine.php`
- `disyl_engine_test`: ✅ ALL 282 TESTS PASSED
- `disyl_parity_test`: ✅ 97/97 passed
- `disyl_hardening_coverage_test`: ✅ 44/44 passed (exercises `ikb_ai_assist`/`ikb_ai_summary`)
- `disyl_compiled_component_fallback_test`: ✅ passed
- `disyl_v4_parser_test` 61 ✓ · `disyl_v4_compiler_test` 55 ✓ · `disyl_template_cache_test` 5 ✓ ·
  `disyl_v44_sandbox_test` 28 ✓ · `disyl_loop_control_test` 6 ✓ · `disyl_v4_test` 36 ✓ ·
  `disyl_extends_block_test` 10 ✓ · `disyl_circular_include_test` 9 ✓ · `disyl_v43_cache_exp_test` 20 ✓
- End-to-end: component render OK (346B); AI-disabled path renders without throw (652B) —
  confirming the `entityErrorState` latent-bug fix
- `TemplateEngine` reduced 7,941 → 5,896 lines (−2,045)

## 6b. IncludeResolver / ExtendsProcessor — deferred (architectural assessment)

Assessment on 2026-08-06 found the remaining method clusters are **coupled to the
render core's state**, making mechanical extraction poor risk/reward:

| Cluster | Engine dependencies (beyond logError) | Verdict |
|---|---|---|
| IncludeResolver (`processIncludes`, `processNextInclude`, `processIncludeTag`, `parseIncludeParams`, `parseKeyValueParams`, `readIncludeSource`, `readTemplateSource`) | `compile`, `resolveTemplatePath`, `parseInlineObject`, `resolveValueWithFilters`, `hasApcuCache`, `includeStack` (circular detection), `includeSourceCache` + `templateSourceCache` (shared with `render()`), `cacheEnabled`, `cacheMetrics`, `TEMPLATE_SOURCE_CACHE_MAX` | ⏳ Deferred — source-cache state is shared with the top-level `render()` path; a clean split needs a `SourceCache` abstraction, not a method move |
| ExtendsProcessor (`processExtends`, `getExtendsCache`, `setExtendsCache`, `processBlocks`, `processDebugTags`) | `readTemplateSource`, `resolveTemplatePath`, `resolveValue`, `extendsCacheDir`, `currentTemplatePath`, `cacheEnabled`, `EXTENDS_CHAIN_MAX` | ⏳ Deferred — layout cache + current-template state is render-core state |

**Recommended future work:** introduce a `SourceCache` (template + include source
reading/caching) abstraction first; then IncludeResolver and ExtendsProcessor can be
extracted cleanly on top of it. Not a pure method-move, so it is intentionally parked
until after the live deployment.

## 6c. P4-1 / P4-3 status

- **P4-1 (interpreted pipeline deprecation):** ✅ effective — the production
  `disyl.interpreted.deprecated` warning (added 2026-08-05) makes interpreted-pipeline
  usage observable per template; all new features are compiled-mode-only by policy.
  Full removal remains a template-migration exercise driven by that log.
- **P4-3 (replace custom YAML parser with a library):** ✅ **done (2026-08-06)** — added
  `symfony/yaml:^6.4` (pure PHP, Bluehost-safe; reuses existing polyfill-ctype /
  deprecation-contracts, so it adds only 1 new package). `WorkflowEngine::parseYamlDefinition`
  now prefers `Yaml::parse()` with the legacy line parser kept as a fallback for installs that
  cannot run composer. **Behavior note:** the legacy parser produced no YAML transitions (they
  were auto-generated from steps) and captured `roles:` as an unparsed string (never enforced).
  Symfony parses both correctly — YAML-declared transitions and their `roles` are now honored.
  Verified: workflow_engine 32, report_approval 18, lifecycle 12, cms_integration 10,
  contact_form 9 — all pass. (`cms_trigger_ai_workflow_search_integration_test` has 4
  pre-existing/environmental failures unrelated to this change, confirmed via `git stash`.)

## 7. Non-goals / deferred

- Removing the interpreted pipeline entirely (P4-1) — keep as fallback; migration is driven
  by the `disyl.interpreted.deprecated` production warning.
- The interpreted evaluator cluster extraction — highest risk, last.
- Public API changes — the facade keeps `TemplateEngine`'s method signatures stable.

> **Signed:** Documentation + plan — August 6, 2026
