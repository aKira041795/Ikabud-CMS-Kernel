# Kernel 4.5.0 — DiSyL Async Runtime (Phase 1)

**Date:** 2026-05-08
**Codename:** atlas (4.x line)
**Status:** Released

## TL;DR

Three new control structures introduce a deferred-resolution model to
DiSyL templates:

```disyl
{await let=hero src=p}
  <h1>{hero.title}</h1>
{loading}
  <h1 class="skeleton"></h1>
{catch let=err}
  <h1>Welcome</h1>
{/await}

{parallel}
  {await let=a src=p1}{a}{/await}
  {await let=b src=p2}{b}{/await}
{/parallel}

{suspense fallback='LOADING'}
  {await let=detail src=p}{detail.body}{/await}
{/suspense}
```

A minimal Promise A+ subset, a Scheduler, and an injectable HttpClient
are shipped as the runtime. Resolved values bind via `let=` into the
success body. Errors fall through to the nearest `{catch}` arm or
`{suspense}` fallback. Source-order rendering is preserved regardless of
resolution order so output is deterministic.

## What's new

- **`Promise`** ([kernel/DiSyL/Async/Promise.php](kernel/DiSyL/Async/Promise.php))
  - Minimal A+ subset: `then`, `catch`, `wait`, `state`
  - Static factories `resolved` / `rejected`; executor constructor
  - Auto-flattens nested promises in `then` chains
- **`Scheduler`** ([kernel/DiSyL/Async/Scheduler.php](kernel/DiSyL/Async/Scheduler.php))
  - `add(callable)` registers a deferred Promise factory
  - `run()` returns ordered `['value' => …]` / `['error' => Throwable]`
  - Concurrency cap enforced (default 64, throws `DISYL_PARALLEL_LIMIT`)
- **`HttpClient`** ([kernel/DiSyL/Async/HttpClient.php](kernel/DiSyL/Async/HttpClient.php))
  - Default backend: synchronous curl with header parsing + JSON auto-decode
  - 4xx/5xx → rejected Promise (`DISYL_FETCH_HTTP_*`)
  - `setHandler()` test seam — fully deterministic in tests
- **Engine wiring** ([kernel/DiSyL/TemplateEngine.php](kernel/DiSyL/TemplateEngine.php))
  - 3 new control tags: `{parallel}`, `{await}`, `{suspense}`
  - `{await}` body is split into success / `{loading}` / `{catch let=err}` arms
  - Argless `{parallel}` form (no expression required)
  - `setHttpClient()` test seam, public `httpClient()` accessor

## Files added

```
kernel/DiSyL/Async/Promise.php     (143 lines)
kernel/DiSyL/Async/Scheduler.php   (73 lines)
kernel/DiSyL/Async/HttpClient.php  (97 lines)
tests/disyl_v45_async_test.php     (172 lines, 23 assertions)
```

## Files modified

```
kernel/App.php                          KERNEL_VERSION → 4.5.0
kernel/DiSyL/TemplateEngine.php         + httpClient property + accessor
                                        + parallel/await/suspense tag dispatch
                                        + argless {parallel} parser support
                                        + 4 evaluator helpers (~150 lines)
```

## Verification

```
php tests/disyl_v4_test.php             → 36/36 pass
php tests/disyl_v41_match_test.php      → 14/14 pass
php tests/disyl_v41_i18n_test.php       → 12/12 pass
php tests/disyl_v42_types_test.php      → 34/34 pass
php tests/disyl_v43_cache_exp_test.php  → 20/20 pass
php tests/disyl_v44_sandbox_test.php    → 28/28 pass
php tests/disyl_v45_async_test.php      → 23/23 pass
```

Total DiSyL coverage: **167 assertions, 0 failures.** Clean app.log /
error.log on full run.

## Compatibility

- **Backward compatible.** New tags only activate when used.
- Existing templates render unchanged (no behaviour delta).
- `Promise` / `Scheduler` / `HttpClient` are new namespaces; no symbol
  collisions with prior code.

## Honest scope statement

The 4.5 design doc additionally specifies:

- **Fibers-backed scheduler** — true concurrent IO with `\Fiber` round-robin
  ticks until all promises resolve or timeout
- **Multi-curl backend** — `curl_multi_*` selectable handles for parallel
  HTTP fetches (currently sequential)
- **Streaming protocol** — chunked output with `<template id="disyl-slot-N">`
  fallbacks replaced incrementally as awaits resolve, plus the inline
  vanilla-JS replacer
- **`fetch()` template function** — sugar that constructs HttpClient promises
  inside `src=` expressions
- **Per-await `timeout=ms` enforcement** — depends on Fibers driver

**Implemented in 4.5.0:**
- Complete template surface (`{parallel}`, `{await}`, `{loading}`, `{catch}`, `{suspense}`)
- Promise A+ subset with correct chaining + flattening
- Synchronous Scheduler with ordered results, error capture, concurrency cap
- HttpClient with injectable handler (default = sync curl)
- Source-order determinism guarantee
- Diagnostic codes: `DISYL_AWAIT_NO_LET`, `DISYL_AWAIT_NO_SRC`,
  `DISYL_AWAIT_TIMEOUT`, `DISYL_PARALLEL_LIMIT`, `DISYL_FETCH_HTTP_*`,
  `DISYL_FETCH_NETWORK`

**Deferred to 4.5.1:**
- Fibers-based concurrent execution (today: sequential resolve)
- `curl_multi` parallel HTTP I/O
- Chunked HTTP streaming with template-slot replacement
- Inline JS replacer emitted once per response
- `fetch()` as a first-class template-side function bound to HttpClient
- Per-await timeout enforcement (requires async driver)

The 4.5.0 surface is the contract templates code against. When 4.5.1
swaps the synchronous Scheduler for the Fibers driver, no template
changes will be required — the same `{parallel}{await}…{/await}…{/parallel}`
markup that runs serially today will run concurrently with no edits.

The Sandbox `network` capability is reserved for `fetch()` gating in
4.5.1 (currently HttpClient is invoked manually by callers, who can
already gate via `{untrusted}`).

## Migration notes

None required. To opt in:
1. For sequential awaits today: `{await let=x src=somePromise}…{/await}` —
   works immediately; resolves synchronously.
2. For independent fetches: wrap in `{parallel}` for source-order semantics
   that will become true concurrency in 4.5.1.
3. For graceful loading: add `{suspense fallback='…'}` around any subtree
   that may throw during resolution.
