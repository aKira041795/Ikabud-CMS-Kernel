# DiSyL TemplateEngine Refactor Plan (D8)

> **Plan date:** August 6, 2026
> **Target:** `kernel/DiSyL/TemplateEngine.php` (7,941 lines) — God Object decomposition
> **Status:** ✅ **Core decomposition complete (2026-08-06)** — `ComponentRenderer` ✅ +
> `MacroProcessor` ✅ + `SourceCache` ✅ + `IncludeResolver` ✅ + `ExtendsProcessor` ✅ +
> `TemplateRenderer` ✅ *(partial — output-cache/metrics/fingerprint cluster; see §6d)*;
> TemplateEngine reduced 7,941 → 5,073 lines (−2,868, ~36%). The previously-deferred
> IncludeResolver / ExtendsProcessor item is **done** (see §6b) via the `SourceCache`
> abstraction the plan prescribed. P4-3 environment-blocked → resolved with Symfony Yaml.
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
2. **MacroProcessor** ✅ — small, self-contained; depends on expression helpers via closures.
3. **SourceCache** ✅ — source-reading/caching abstraction introduced first (2026-08-06) so
   the include/extends clusters could be extracted without carrying render-core cache state.
4. **IncludeResolver** ✅ — depends on path resolution + SourceCache-backed readIncludeSource.
5. **ExtendsProcessor** ✅ — depends on include resolution + cache dir.
6. **TemplateRenderer** 🔄 *(partial)* — output-cache/metrics/fingerprint cluster extracted
   (see §6d); the top-level `render()`/`renderString()` orchestration + interpreted evaluator
   remain in the engine as the documented "render core" (deliberately last / highest risk).
7. **Interpreted evaluator** (optional/last) — highest risk; may remain as a documented
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

## 6b. IncludeResolver / ExtendsProcessor — ✅ done (2026-08-06)

The 2026-08-06 assessment found these clusters coupled to the render core's source-cache
state, and prescribed a `SourceCache` abstraction before extraction. That path was
followed exactly and completed the same day:

| Step | Commit | Outcome |
|---|---|---|
| `SourceCache` abstraction | `d8ffff15` | `kernel/DiSyL/Cache/SourceCache.php` — owns template/include source reading + caching (in-memory per-request + APCu cross-request, 100-entry cap, 300s TTL). Metric counters still feed `TemplateEngine::$cacheMetrics` via an injected closure. `templateSourceCache` / `includeSourceCache` properties and `TEMPLATE_SOURCE_CACHE_MAX` removed from the engine. |
| `IncludeResolver` extraction | `826b8b65` | `kernel/DiSyL/Component/IncludeResolver.php` — `processIncludes`, `processNextInclude`, `processIncludeTag`, `parseIncludeParams`, `parseKeyValueParams`. Decoupled via closures (compile, resolveTemplatePath, parseInlineObject, resolveValueWithFilters, logError, readIncludeSource). Now owns `includeStack` for circular detection (was render-core state). Dead `$scan`/`$depth` remnants in old `processNextInclude` removed during extraction. |
| `ExtendsProcessor` extraction | `d55afe19` | `kernel/DiSyL/Component/ExtendsProcessor.php` — `processExtends`, `getExtendsCache`, `setExtendsCache`, `processBlocks`, `processDebugTags`. Decoupled via closures (resolveTemplatePath, readTemplateSource, resolveValue, logError, current-template-path / extends-cache-dir / cache-enabled getters). Owns `EXTENDS_CHAIN_MAX` + `MAX_BLOCK_MERGE_PASSES`; the versioned mtime-validated cross-request cache and atomic writes moved with it. `extendsCacheDir` stays on the engine (shared with the compiled eligibility cache) and is injected. |

The engine keeps thin `processIncludes` / `processExtends` / `processBlocks` /
`processDebugTags` delegates; all call sites (render, component bodies) are unchanged.
Verified: engine 282, parity 97, v4 36, parser 61, loop 6, sandbox 28, hardening 44,
compiler 55, async 23, fed_ai 17, template_cache 5, v43_cache_exp 20, extends_block 10,
circular_include 9, compiled_component_fallback 3 — all pass; smoke render OK.
`TemplateEngine` reduced to 5,213 lines.

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
## 6d. TemplateRenderer — partial extraction (2026-08-06)

The full `render()`/`renderString()` move is coupled to the `compile()` evaluator and the
compiled-mode machinery (the "render core" the plan keeps in the engine). The **genuinely
self-contained** part of the TemplateRenderer row — the output-cache + metrics +
fast-fingerprint + shared-TTL cluster — was extracted cleanly instead:

| Moved to `kernel/DiSyL/Renderer/TemplateRenderer.php` (248 lines, zero engine deps) |
|---|
| `buildOutputCacheKey`, `buildSharedOutputCacheKey` |
| `tryBuildFastContextFingerprint`, `hashContextValue` |
| `logCacheMetricsPeriodic` + `CACHE_METRICS_LOG_INTERVAL` |
| statics `$cacheMetrics` / `$rendersSinceMetricsLog` / `$cacheAuthorityWarningEmitted` |
| `$outputCache` (now via `outputCacheGet` / `outputCacheSet` / `hasOutputCacheKey`) |
| `$sharedOutputCacheTtl` (now via `sharedOutputCacheTtl()` / `setSharedOutputCacheTtl`) |
| `getCacheMetrics` / `resetCacheMetrics` / `incrementMetric` (statics) |
| consts `OUTPUT_CACHE_MAX`, `OUTPUT_CACHE_KEY_FAST_DEPTH` |

`TemplateEngine` keeps thin delegates (`setSharedOutputCacheTtl`, `getCacheMetrics`,
`resetCacheMetrics` — public API preserved) and routes `render()`'s cache/metrics/ttl calls
through `$this->templateRenderer()`. `compile()` and the SourceCache metric closure now
increment via `TemplateRenderer::incrementMetric()`, so the aggregate counters stay unified.
`hasApcuCache()` remains on the engine (shared with the SourceCache factory).

Verified: engine 282, parity 97, v4 36, parser 61, compiler 55, sandbox 28, hardening 44,
loop 6, async 23, fed_ai 17, cache_exp 20, extends_block 10, circular_include 9,
template_cache 5, hydration 22 — all pass; file-based render smoke `[Hello Ikabud! big]`
with `compiles=1` after two renders (second hit in-memory cache — identical to prior
behavior); error.log clean. `TemplateEngine` reduced to 5,073 lines.

**Remaining for full TemplateRenderer:** move `render()`/`renderString()` orchestration —
only after (or together with) the interpreted-evaluator cluster, per §3 item 7.
## 7. Non-goals / deferred

- Removing the interpreted pipeline entirely (P4-1) — keep as fallback; migration is driven
  by the `disyl.interpreted.deprecated` production warning.
- The interpreted evaluator cluster extraction — highest risk, last.
- Public API changes — the facade keeps `TemplateEngine`'s method signatures stable.

> **Signed:** Documentation + plan — August 6, 2026
