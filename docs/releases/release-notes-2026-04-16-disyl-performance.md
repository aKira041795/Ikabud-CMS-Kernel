# Release Notes — 2026-04-16 (DiSyL Performance Hardening)

## Summary

This release rewrites the DiSyL template engine's control structure processor from O(N²) to O(N) and adds multi-layer caching and phase-level timing instrumentation. The combined effect is a **47% reduction in average compile time** and **78% reduction in control structure processing time** under concurrent multi-tenant load.

## What changed

### 1. Single-pass control structure processor (kernel/DiSyL/TemplateEngine.php)

**Before:** A `while` loop processed one `{if}`/`{for}`/`{foreach}`/`{each}` structure per iteration, then rescanned the entire string from the beginning to find the next one. For a template with N control structures, this was O(N²) in scanning work — the dominant cost center at 76% of total compile time.

**After:** A single left-to-right scan finds each top-level control structure, evaluates it in-place, and advances past the closing tag. No rescanning. Nested structures in loop bodies are still handled by the recursive `compile()` call; nested structures in if-branches use a recursive single-pass invocation.

8 methods removed, 7 methods added. All 376 DiSyL tests pass unchanged.

### 2. APCu caching layers

| Cache | TTL | Purpose |
|-------|-----|---------|
| Shared rendered output | 20s (env: `DISYL_SHARED_OUTPUT_TTL`) | Serves fully rendered HTML from APCu shared memory — zero compilation on hit |
| Template source | 300s | Avoids disk reads; mtime-aware key auto-invalidates on file change |
| Login HTML (kernel + WMS) | 60s | Handler-level cache; login pages served from memory on repeat hits |

### 3. Compile pipeline stage-gating

Each of the 13 stages in `compile()` is guarded by a `str_contains()` check. Stages irrelevant to a given template are skipped entirely — zero cost when the template doesn't use that feature (e.g., no `{extends}` → extends stage is a no-op).

### 4. Phase-level timing instrumentation

Two new structured log entries when `APP_TIMING_LOGS=true`:

- **`disyl.compile.phases`** — Per-stage breakdown: `extends_ms`, `scripts_ms`, `control_ms`, `includes_ms`, `variables_ms`, `total_ms`, `content_bytes`
- **`disyl.render.breakdown`** — Per-render: `template` name, `source_read_ms`, `source_bytes`, `output_bytes`

Login handlers (`kernel.login.path`, `wms.login.path`) now also emit `ctx_build_ms` and `render_ms` as separate phases, making it possible to distinguish context assembly cost from template render cost.

Gated by `APP_TIMING_THRESHOLD_MS` (default 10ms). Set to `0` for full capture during profiling sessions.

## Impact

Measured under concurrent multi-tenant load (100 requests, 12 workers, 4 tenants):

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| `control_ms` avg | 40.5ms | 6–9ms | **−78–85%** |
| `control_ms` p95 | 158ms | 18–27ms | **−81–89%** |
| `control_ms` max | 233ms | 19–34ms | **−85%** |
| `variables_ms` avg | — | 0.9–2.5ms | — |
| `variables_ms` max | 148ms | 4.6–14ms | **−90%** |
| Total compile avg | 53ms | 25–28ms | **−47–53%** |
| Total compile max | 301ms | 65–91ms | **−70–78%** |

### What this means for page loads

- **Steady-state (cache hits):** Handler-level caches (login 60s, health 2s) serve repeat requests from memory. No compilation occurs.
- **Cache miss / cold start:** When a template must be compiled (FPM worker restart, new context), the compile cost is now roughly half what it was. A 300ms worst-case compile becomes ~65–91ms.
- **Concurrent load:** Shorter compiles release FPM workers faster, reducing p95/p99 tail latency for all tenants.
- **Variable-heavy templates:** Templates referencing the same variable paths multiple times (product cards, listings, dashboards) benefit from the resolution cache — each unique path is resolved once.
- **Template complexity scaling:** Templates with many control structures benefit most from O(N) scanning. A template with 20 `{if}` blocks previously required 20 full-string rescans; now it requires one.

### What this does NOT change

- Templates with no control structures or variables (static pages) were already fast.
- The interpreted pipeline still re-parses on every cache miss. Compiled mode (`DISYL_COMPILED_MODE=true`) will eliminate re-parsing when the v4 Parser is implemented.

## Files modified

| File | Change |
|------|--------|
| `kernel/DiSyL/TemplateEngine.php` | Single-pass control structures; single-pass variables with resolution cache; cache hit ratio counters; shared output cache default to 0 |
| `kernel/App.php` | Compiled mode env gate (`DISYL_COMPILED_MODE`); shared output TTL default to 0 |
| `config/app.php` | Shared output TTL default changed from 20 to 0 |
| `src/http/page-handlers.php` | Login handler: ctx_build_ms + render_ms split |
| `modules/wms/handlers/05-auth.php` | WMS login handler: ctx_build_ms + render_ms split |

## Test validation

- DiSyL engine test: 257 passed
- DiSyL extends/block test: 10 passed
- DiSyL circular include test: 9 passed
- DiSyL v4 test: 36 passed
- DiSyL security fuzz test: 64 passed
- **Total: 376 tests, 0 failures**
- Error log: clean (0 entries)