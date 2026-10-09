# Kernel — Per-Request Cost: Attributed, Then Cut

> **Released:** 2026-10-09
> **Theme:** The cost every request pays before its handler runs is now attributed at four levels, and the largest component — a scan that could not report anything — is gone.
> **Scope:** kernel route loading, table-ensure guards, module discovery, the superadmin performance probe, cache maintenance
> **Commits:** `a20d536e` … `ed8bdafe` (9)
> **Previous:** [DiSyL compiled `{extends}` rendering](release-notes-2026-10-09-disyl-compiled-extends.md)

---

## Executive Summary

Every request, on every page, spent roughly **650 ms of CPU before its route handler ran**, and nothing in the kernel could say where it went. The superadmin performance probe reported all-green while measuring almost nothing: three of its rows could not fail by construction.

This release attributes that cost, then removes the bulk of it. A warm request now pays **~254 ms** before dispatch instead of ~650 ms, and — more importantly — the cost is now *legible*, so the next reduction starts from a measurement rather than a hypothesis.

**Delivered facts:**
- the per-request cost is attributed at four levels, each verified by its segments summing to their parent with diff `0`
- the route-conflict scan — **927,972 pairwise comparisons per request** — was first indexed (10.68× fewer pairs) and then **deleted**, because it was unreachable and could never report anything
- `CREATE TABLE` statements on the request path: **3 → 0**
- five hypotheses about the cause were **disproved by measurement**, and every one is recorded in the commit that rejected it
- new tooling makes the four levels re-measurable on any host, including the client's

---

## 1. The measurement chain

The work started from a probe result that looked healthy. Attribution, level by level:

| level | segment | value | share |
|---|---|---|---|
| 1 | `dispatch` (before the handler) | 625–714 ms | — |
| 2 | `module_routes` | 621 ms | **96% of dispatch** |
| 3 | `module_routes_registration` | ~644 ms | 96% of module_routes |
| 4 | the route-conflict scan inside it | 184.9 ms | 96% of registration |

The disciplines that made this trustworthy, and which are worth keeping:

- **Every level sums to its parent.** `diff = 0.0000` across six independent samples at each level. A breakdown that does not add up is a broken instrument, so the sum became a test assertion rather than a convention.
- **Phases report `null`, never `0.0`, when unmeasured.** A `0.0` reads as "measured, and it was free" — which is how the original probe came to report `0 ms` for two operations it had not performed.
- **A cold scan is measured, not inferred.** The old "Module discover (cold)" row passed an argument `discoverModules()` does not accept and timed the memoised call instead: it reported `0.0027 ms`, while the real scan takes 115 ms over 70 modules.

## 2. The route-conflict scan: indexed, then deleted

`loadModuleRoutes()` walked every previously registered pattern of a method for every pattern of every module. Measured: 1,968 patterns → **927,972 comparisons**, 0.532 µs each, recomputed identically on every request.

**Indexed** (`9126dee4`): candidates are now selected by `(method, segment count, first-segment compatibility)`. Both index rules follow from the predicate's own early exits, so the index can only skip pairs the predicate rejects anyway. Verified: pairs `927,972 → 86,920` (10.68×), **false negatives 0**, and the **route map byte-identical** at `routes=1968 raw_sha=cf47a110930f5733`.

**Deleted** (`0572164a`): the scan was then found to be **unreachable** entirely. `routePatternMatchPriority()` returns the raw pattern string as its final element, and the comparator ends with `strcmp` on it, so "same priority" means "identical pattern string" — and identical patterns were already excluded two lines earlier by the duplicate check. The diagnostic had therefore **never fired**, and `APP_ROUTE_AMBIGUITY_MODE=block` had **never blocked anything**.

Measured over the corpus: 927,972 pairs → 109 `mayConflict` → **0 reachable**. An adversarial search over 36 conflicting pattern shapes found no counterexample. Result: `route_merge` **184.9 → 7.1 ms**, routing unchanged.

`docs/architecture/route-ambiguity-detection-adr.md` records the decision, the evidence, and the trigger that would reopen it.

## 3. Table-ensure DDL: 3 statements per request → 0

Every runtime table-ensure helper was guarded by a `static`, which resets on every request under mod_php/FPM. `moduleControlPlaneEnsureCatalogTables()` alone emitted **3 `CREATE TABLE` statements per request**, occupying the slowest-query slots at 15.6 ms and 6.24 ms.

The guard is now cross-request (`src/helpers/table-ensure-cache.php`): a per-database map keyed on a hash of driver/host/port/database taken from config, so it costs **no database round trip**; APCu when available, otherwise an atomic file stamp. Missing, unreadable, corrupt or unwritable state means **run the DDL** — the failure mode is doing the work, never skipping it. Verified cross-process: `process 1 cold = 3 DDL, process 2 warm = 0`.

## 4. Metrics that reported work they had not done

- Three probe rows could not fail: "Module discover (cold)" measured a memoised call, "Settings preload" measured an already-loaded cache, and the DiSyL row rendered a template with no `{extends}` — so it could not exercise the compiled-layout path it appeared to test.
- `disyl_render_login_ms` reported the `{extends}` probe's timing under a login-page name, from a single measurement assigned to two keys.
- The new attribution listener initially declared `module => 'kernel'`, which placed it on a recursion path through `moduleWithContext() → discoverModules()`; `discoverModules()` published its memo *after* the loop that re-enters it. Both fixed, and asserted.
- `kernelPerfProbeElapsedMs()` clamped its result to a `1e-6` floor, making every `ms > 0` assertion built on it unfalsifiable.

In each case the fix was to make the metric able to fail, and to report `null` or `SKIP` rather than a passing `0`.

## 5. Five hypotheses, all disproved by measurement

Recorded because the honest history is more useful than the successful conclusion:

1. **A lock keyed on the request** — varying the URI (3.365 s) and the route (3.416 s) changed nothing.
2. **OPcache missing for the web SAPI** — it reports `enabled=YES`, 1,260 scripts, 484,405 hits.
3. **EventBus evaluating a `preg_match` per wildcard on every query** — real but negligible: 596 ns/fire, 2,813 ns with three patterns, i.e. 0.07–0.34 ms per 120-query request.
4. **Per-request table-ensure DDL as the concurrency cause** — fixed anyway (3 → 0), and it did not move the concurrency ratio.
5. **"1 MB of module `helpers.php` required on every request"** — **wrong**: 338 files and 4,900 KB are included in **11 ms**, because OPcache works.

What survived measurement was plain: `vmstat` during load showed `r=13–20` runnable on 4 cores, `id=0%`, `wa=0%` — CPU exhaustion by the application's own work, no lock and no disk.

## 6. New tooling

- **Per-request attribution** (`src/http/perf-attribution.php`) — query count, total ms, slowest five, DDL count, and named phase marks; surfaced on `/superadmin/perf` and the API.
- **`tools/concurrency-gate.php`** — sequential vs concurrent latency with three distinct exit codes: `0` PASS, `1` FAIL, **`2` NOT MEASURED**. An unreachable host exits 2 and never PASS, because a gate that cannot run must not report success.
- **`php ikabud cache:prune [--dry-run]`** — removes cache files the read path can no longer serve, agreeing with `has()`/`get()` rather than redefining expiry.
- **Per-module registration breakdown** — so a future module count increase can be attributed without repeating this work.

## 7. Limits — stated, not implied

- **The concurrency ratio is unchanged (~5.7–7.8× at 10-way).** It measures scaling *shape*: a uniform per-request speedup moves numerator and denominator together. What improved is **capacity** — roughly 2.7× less CPU per request, which on a shared plan is roughly 2.7× the concurrent users for the same account. It is not better scaling.
- **The first request after any cache clear is expensive**: ~237 queries, 3 DDL, ~1.7 s, because discovery and the ensure-table guard are both cold. Expected, but operationally relevant — and it is why a deploy should end with a warm-up request rather than a health check.
- **All figures are from a local box** (4 CPUs, memory-pressured, 2.2 GB in swap). Ratios are meaningful; absolutes need confirming on the client's host via `/superadmin/perf`.
- **The ensure-table stamp has a 300 s TTL**, so a cold request re-runs the DDL roughly every five minutes of inactivity rather than never. Bounded staleness was chosen over permanent staleness; a lock is the alternative and was not worth the contention.
- `tests/kernel_hardening_test.php` fails 2 assertions on this box because `storage/cache` is `www-data`-owned and the CLI user cannot write it. Pre-existing and environmental.

## 8. Oracles to reuse

- **Route map golden master:** `routes=1968 raw_sha=cf47a110930f5733` from `loadModuleRoutes(kernelCoreRoutes())`. A moved hash is a routing regression, not a number to re-baseline.
- **Sum-to-parent** at every attribution level.
- **`tools/concurrency-gate.php`** with a deterministic must-refuse: `--fail-on-ratio=0.01` must exit 1.
- **On this box, interleave A/B arms or measure nothing.** The same tree has measured `dispatch` at 606 ms and 2,152 ms. A single sequential before/after produced a false negative on `9126dee4`, and a `git stash`-based A/B was corrupted by an opcache race.

## 9. Verification

Ten kernel suites added or changed, all green: perf probe 6 PASS 1 SKIP, cache prune 10/10, attribution 12/12, concurrency gate 4/4, phase breakdown 8/8, ensure-table 5/5, discovery re-entrancy bounded at depth 2, registration breakdown 6/6, route-conflict equivalence 8/8, route-conflict guard 13/13. Plus cache dashboard 18/18. `kernel/DiSyL`, `templates/`, `modules/` and `tools/` were untouched by the route work.

Warm request, five consecutive samples: `ddl=0`, `queries=72`, `dispatch` 238–268 ms.

## 10. What remains

- `module_routes_discovery` (~130 ms) and `route_match` (~20 ms) are the largest remaining pre-handler segments. Measure before designing — five hypotheses in this release were wrong, and every one of them was plausible.
- Nothing here has been measured on the client's host yet.
