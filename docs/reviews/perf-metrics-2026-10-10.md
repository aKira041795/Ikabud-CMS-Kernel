# Perf metrics — 2026-10-10

Raw measurement sets behind the two changes shipped on 2026-10-10. Companion to
[perf-state-2026-10-10.md](perf-state-2026-10-10.md), which records the *state*; this records the
*evidence*, including the samples that did not support a conclusion.

## How to read this (do not skip)

- **Only disjoint ranges are evidence.** Where the two arms' ranges overlap, the delta is a
  direction to re-test, not a result. Two of the seven figures below are in that category and are
  labelled.
- **The local box is ~3x slower than the live host on `route_match`** (22 ms local vs 7.17 ms live).
  **Percentages appear to transfer; absolute milliseconds do not** — but that is a working assumption
  on one supporting observation, not a rule, and section 4c explains why the live confirmation is
  weaker than it first appears.
- `compiled_this_request = 0` on every reading, so every saving here is **execution**, not
  compilation.
- Every comparison is an **interleaved A/B with alternating order** (A-first and B-first alternate
  each round), never a sequential before/after. A sequential pair 4 minutes apart on this host once
  showed 55.88 -> 47.06 ms of dispatch on a byte-identical workload.

---

## 1. Dead-index deletion — commit `34a62e9b`

A = orphaned route-pattern index build deleted. B = index present. 12 samples, order-alternating.

| arm | route_merge | route_match | helpers_load |
|---|---:|---:|---:|
| A | 1.420 | 21.982 | 5.328 |
| A | 2.533 | 28.711 | 9.069 |
| A | 1.603 | 22.867 | 5.991 |
| A | 1.469 | 20.488 | 5.259 |
| A | 1.465 | 27.201 | 5.443 |
| A | 2.064 | 19.800 | 8.044 |
| B | 7.825 | 19.331 | 8.090 |
| B | 6.132 | 17.718 | 7.600 |
| B | 12.715 | 28.096 | 9.595 |
| B | 8.438 | 21.681 | 7.168 |
| B | 6.605 | 18.949 | 7.361 |
| B | 6.645 | 18.837 | 6.664 |

| metric | A median `[range]` | B median `[range]` | delta | verdict |
|---|---:|---:|---:|---|
| `route_merge` | 1.536 `[1.42, 2.53]` | 7.235 `[6.13, 12.72]` | **-5.699 ms (-78.8%)** | **disjoint — evidence** |
| `route_match` | 22.424 `[19.80, 28.71]` | 19.140 `[17.72, 28.10]` | +3.285 ms (+17.2%) | **OVERLAP — not evidence** |
| `helpers_load` | 5.717 `[5.26, 9.07]` | 7.481 `[6.66, 9.60]` | -1.764 ms (-23.6%) | **OVERLAP — not evidence** |

**Net on the two affected phases** — boot's own warning applies, since it spans two runs: -2.414 ms.

**The mechanism, so the `route_match` give-back is not mistaken for noise.** `routePatternSegments()`
is memoised, and the dead loop was incidentally **pre-warming it** for every route. Removing those
calls moves the segment parsing from `route_merge` into `route_match`, where the comparator first
touches each pattern. What was genuinely eliminated is the classification loop and the nested-array
writes (~8k hash inserts across 1,968 routes). A **second** memo was also pre-warmed —
`routeSegmentIsDynamic()` for each route's first segment.

---

## 2. Priority memoisation — commit `aef943d0`

`routePatternMatchPriority()` memoised; the comparator was deliberately left untouched so route
precedence cannot shift. A = memoised, B = not. 8 samples, order-alternating.

| arm | `route_match_sort` | `route_match_total` |
|---|---:|---:|
| A | 11.368 | 11.643 |
| A | 7.536 | 7.868 |
| A | 9.135 | 9.524 |
| A | 8.363 | 9.046 |
| B | 20.732 | 21.075 |
| B | 20.169 | 20.538 |
| B | 22.889 | 23.566 |
| B | 23.597 | 24.127 |

| metric | A median `[range]` | B median `[range]` | delta | verdict |
|---|---:|---:|---:|---|
| `route_match_sort` | 8.749 `[7.54, 11.37]` | 21.811 `[20.17, 23.60]` | **-13.062 ms (-59.9%)** | **disjoint — evidence** |
| `route_match_total` | 9.285 `[7.87, 11.64]` | 22.320 `[20.54, 24.13]` | **-13.036 ms (-58.4%)** | **disjoint — evidence** |

**Why the sort was the target at all.** A `route_match_sort` mark was added the same day, because
the phase had always been one opaque number. It showed the sort is ~98% of `route_match` and the
URI-dependent regex scan only ~2%:

| | median |
|---|---:|
| `route_match_sort` | 20.89 ms |
| `route_match_scan` | 0.37 ms |
| `route_match` total | 21.21 ms |

(20.89 + 0.37 = 21.26, reconciling with the total.) The scan was left alone — 1.7% is not worth the
risk. **Decorate-sort-undecorate stays deferred:** memoisation captured ~60% without touching the
comparator, whereas DSU must reproduce the ordering exactly, including the final ascending `strcmp()`
on the raw pattern.

---

## 3. Boot decomposition — commit `c93446db`

Boot had been a single opaque mark since it was added. The cause was mechanical:
`kernelPerfMarkRequestPhase()` is not defined until `perf-attribution.php` is required at
`public/index.php:155`, so no mark could be placed in the region boot covers. The request **origin was
never wrong** — `public/index.php:6` already sets it before `bootstrap.php`.

6 samples. Cumulative phases, then the segments derived from them:

| metric | median | min | max |
|---|---:|---:|---:|
| `boot_fastpath` | 0.049 | 0.030 | 0.056 |
| `boot_bootstrap` | 1.695 | 1.292 | 2.646 |
| `boot_requires` | 7.093 | 5.967 | 7.781 |
| `boot` (total) | 7.102 | 5.974 | 7.797 |
| segment: fastpath -> bootstrap | 1.646 | 1.246 | 2.593 |
| segment: bootstrap -> requires | 5.216 | 3.971 | 6.323 |
| segment: requires -> boot | 0.012 | 0.008 | 0.016 |

**Reconciliation — the check that the new marks share boot's real origin.** Segments sum to the boot
total exactly on every sample:

```
1: 0.044 + 1.414 + 6.323 + 0.016 = 7.797  vs boot 7.797
2: 0.054 + 1.878 + 5.256 + 0.008 = 7.196  vs boot 7.196
3: 0.030 + 1.262 + 5.706 + 0.010 = 7.008  vs boot 7.008
4: 0.046 + 1.246 + 5.177 + 0.015 = 6.484  vs boot 6.484
5: 0.053 + 2.593 + 4.987 + 0.014 = 7.646  vs boot 7.646
6: 0.056 + 1.939 + 3.971 + 0.008 = 5.974  vs boot 5.974
```

So boot is ~71% the 18 `src/` requires, ~23% the pre-bootstrap region plus `bootstrap.php`, ~1% the
fast-path cache, ~0% tail.

---

## 4. Per-require probe

Temporary probe, 22 samples, `public/index.php` restored clean afterwards. Writes to
`storage/logs/bootprobe.jsonl`.

| require | median ms | share |
|---|---:|---:|
| **`helpers/module-manager.php`** | **3.543** | **95.7%** |
| `http/perf-attribution.php` | 0.044 | 1.2% |
| `http/admin-handlers.php` | 0.022 | 0.6% |
| `http/superadmin-handlers.php` | 0.020 | 0.5% |
| `helpers/page-cache.php` | 0.018 | 0.5% |
| `http/page-handlers.php` | 0.011 | 0.3% |
| `http/auth-handlers.php` | 0.009 | 0.2% |
| remaining 11 files, combined | 0.036 | 1.0% |
| **TOTAL** | **3.703** | 100% |

**It is not one file — it is a cluster.** `module-manager.php` (228 KB, 106 top-level function
declarations) requires five more at top level: `manifest-validation.php` 40 KB, `module-catalog.php`
49 KB, `module-registry.php` 31 KB, `module-routes.php` 27 KB, `module-migrations.php` 72 KB.
**Six files, ~447 KB, 3.54 ms — roughly two-thirds of boot.**

**Not shipped.** The chair ruled STOP: potentially deferrable but not proven safely deferrable, and
even eliminating the entire estimated ~7 ms live is ~15% of a 47.06 ms dispatch, while only the
3.54 ms local is actually measured. Treat as fixed operational cost. Any future split must preserve
capability discovery, authorization and handler resolution exactly — and note the 1,968-route hash is
**not** a sufficient gate, because route equivalence does not establish capability equivalence.

---

## 4b. Post-deploy live reading — 2026-10-10T11:18:35+08:00, PHP 8.5.11

Deployed by the owner; `/superadmin/perf` on `kernelappos.ikabudkernel.com`. **Single sample**, so it
is compared to the 2026-10-09 baseline as *one reading against one reading* — the same mistake this
document warns about everywhere else. It is recorded because a live reading is evidence even when it
is insufficient, and because the direction of one row matters.

| phase | baseline (10-09 20:24) | now (10-10 11:18) | delta |
|---|---:|---:|---:|
| `dispatch` | 47.06 | 44.65 | -2.41 (-5.1%) |
| `route_match` | 7.17 | **8.82** | **+1.65 (+23.0%)** |
| `route_merge` | 3.17 | 2.47 | -0.70 (-22.1%) |
| module routes | 24.76 | 26.17 | +1.41 (+5.7%) |
| – helpers load | 9.35 | 10.73 | +1.38 (+14.8%) |
| – discovery | 9.06 | 8.28 | -0.78 (-8.6%) |
| `boot` | 14.88 | 9.40 | -5.48 (-36.8%) |

**Verdict: INCONCLUSIVE, and one row is the wrong direction.** Reasons, in order of importance:

1. **`route_match` got *worse*, not better** (+23%), against a local A/B showing -58.4% on disjoint
   ranges. This is the signal that matters and it is unexplained.
2. **The host drifts +/-10-30% between readings.** A 5.1% dispatch change cannot be distinguished
   from that. Neither can -22% on `route_merge`.
3. **Live `route_match` is demonstrably unstable**: this repo already records it moving 7.93 -> 12.24
   (+54%) between two readings with no explanation. A single sample cannot settle a 1.65 ms change.
4. **`boot` was not changed by this work** — only *marked*. Its -5.48 ms is therefore not
   attributable to these commits; OPcache holds more scripts now (779 cached vs 724, 1 compiled this
   request) and that is the likelier cause.

**What would settle it:** 6-10 readings of the same deployment, compared as median *and range* —
the discipline that made the local result trustworthy. One sample against one sample cannot.

**Deploy-inclusion check.** This reading was taken from a page whose row list is hardcoded in
`src/http/page-handlers.php`, so the new `Boot: -> require block` and `Route match: sort` rows do not
appear in it and cannot tell us whether the instrumentation shipped. After redeploying, those rows
prove it themselves: **values present = kernel changes live; "not measured" = they are not.**

## 4c. Live, 8 samples — the distribution resolves it

`tools/perf-sample.php --samples=8` against `https://kernelappos.ikabudkernel.com`, 2026-10-10 11:30.
**All new phases were reported**, so the kernel instrumentation is deployed.

| metric | baseline (10-09 20:24, 1 sample) | median | min | max | delta vs baseline |
|---|---:|---:|---:|---:|---:|
| `dispatch` | 47.06 | **33.050** | 29.516 | 86.632 | -14.01 (-29.8%) |
| `route_match` | 7.17 | **2.907** | 2.837 | 4.495 | **-4.26 (-59.5%)** |
| – sort | — | 2.714 | 2.628 | 3.193 | — |
| – regex scan | — | 0.199 | 0.181 | 1.363 | — |
| `route_merge` | 3.17 | **1.672** | 1.618 | 2.673 | **-1.50 (-47.3%)** |
| `boot` | 14.88 | 8.655 | 8.234 | 12.204 | -6.23 (-41.8%) |
| – fast-path | — | 0.084 | 0.072 | 0.093 | — |
| – -> bootstrap.php | — | 5.566 | 5.432 | 7.509 | — |
| – -> require block | — | 8.651 | 8.231 | 12.200 | — |
| `helpers_load` | 9.35 | 7.597 | 7.435 | 11.205 | -1.75 (-18.8%) |
| `discovery` | 9.06 | 7.764 | 6.393 | 62.486 | -1.30 (-14.3%) |

**Every sample on both touched phases beats the baseline.** All 8 `route_match` samples (max 4.495)
are below the 7.17 baseline; all 8 `route_merge` samples (max 2.673) are below 3.17.

**But that is NOT the independent confirmation it looks like, and an earlier draft of this section
claimed it was.** The claim made was "stronger than a median comparison and does not depend on the
baseline having been typical". Both halves are wrong:

- **It *is* a median comparison.** "Every sample beats the baseline" only exceeds a median if the
  baseline is a single point that the ranges straddle — and here the same host's own spread
  (`dispatch` 29.5-86.6 ms across eight *consecutive* samples) is far larger than the effect.
- **It depends on the baseline more than a median would, not less**, because with n=1 the baseline
  *is* the whole reference arm.

**The confound: four metrics this work does not touch moved the same direction.** `boot` -41.8%
(nothing in these commits changes boot — it was only *marked*), `helpers_load` -18.8%,
`discovery` -14.3%. When unrelated metrics all fall, the two readings differ in host or
runtime state as well as in code, and a comparison across them cannot separate the two. The baseline
at 2026-10-09 20:24 plausibly ran with a colder cache (4b records 724 cached scripts then vs 779 now),
which raises *every* metric.

So the honest reading: **the live delta is consistent in direction with a change whose severity was
established elsewhere, but its magnitude here is not attributable to these two commits.** The
load-bearing evidence is section 2 — same machine, both arms interleaved with alternating order,
disjoint ranges. That is what justifies keeping the change; this section corroborates it.

**What the live agreement does and does not show.** Local `route_match` fell 58.4%, live 59.5%. Two
independent measurements landing within 1.1 points of each other is unlikely to be pure coincidence,
so this is *suggestive* that local A/B percentages transfer to the live host even though the absolute
milliseconds do not (local 22 ms vs live 7.17 ms at baseline). It is not proof: a global shift of the
size seen above could produce a similar figure from a smaller true effect. Treat "percentages
transfer" as a working assumption with one supporting observation, not an established rule.

**The decisive test, if the magnitude is ever needed, is interleaved — not before/after.** Reverting
one commit on the live host and alternating it on/off within a single session would isolate the code
from host state. Nothing in the ship/no-ship decision needs it: the local A/B already settled that.

**The earlier single reading was an outlier, and I called its direction wrongly.** Section 4b reported
`route_match` at 8.82 ms and concluded the change had gone the wrong way. It is outside the range of
all 8 subsequent samples; the probable cause is a cold OPcache immediately after deploy (that reading
recorded `compiled_this_request = 1`). A single sample was never going to be adequate, which is the
whole reason this section exists.

**Boot's profile is host-specific — the local conclusion does NOT transfer.** Locally the require
block was 71% of boot and `bootstrap.php` 23%. Live it is inverted: the `bootstrap.php` region is
5.48 ms (63%) and the require block 3.09 ms (36%). The blocks reconcile exactly
(0.084 + 5.482 + 3.085 + 0.004 = 8.655). So "boot is the require block" is a true statement about the
dev box only. The chair's STOP on splitting still stands — this changes where a future attempt would
have to look, not whether it is worth doing.

**The sort share holds live**: `route_match_sort` is 93.4% of `route_match` (2.714 of 2.907), against
96-98% locally.

**Attribution discipline.** Only `route_match` and `route_merge` can be attributed to these commits.
`boot`, `helpers_load` and `discovery` also moved, but nothing in this work changed them, and
`discovery` has a 62.5 ms outlier against a 7.8 ms median — treat those three as unexplained.

**Range warning for future readings:** `dispatch` spans 29.5-86.6 ms and `discovery` 6.4-62.5 ms
across eight *consecutive* samples on a warm host. A single reading of this host is not a measurement
of anything.

## 4d. "Memoisation is moot" — tested, and the sort is the real prize

Raised by the owner 2026-10-10. Tested rather than agreed with, because it is checkable.

**The memoisation is not moot.** It is measured at -58.4% on `route_match` with disjoint ranges
(section 2), and it is what makes the live sort 2.714 ms rather than ~7 ms. Removing it would cost
real time.

**But the owner's instinct points at something real, and my own code already said it.** The memo is a
`static $cache` — per-PROCESS. Every request starts cold and pays the full sort: the memo turns ~43k
calls into 1968 misses plus hits, but it cannot remove the sort itself. `public/index.php:561-563`,
added the same day, reads:

> the sort is request-invariant work that **can be removed entirely**, the scan cannot

So the memo is the weaker rung of a two-rung ladder. Measured with
`probe-route-sort-cache.php` (fresh process per sample, n=8, because the memo is per-process):

| | median | range |
|---|---:|---|
| `array_keys` + memoised `usort`, GET (1061 patterns) | **10.005 ms** | [8.060, 37.002] |
| cache key: `md5(implode("\n", $patterns))` | **0.126 ms** | [0.099, 0.178] |
| net if the sorted order were cached | **~9.88 ms** | — |

Live, the same thing is `route_match_sort` 2.714 ms of a 33.05 ms dispatch — **8.2% of dispatch on
every request**, permanently, whatever the memo does.

### Chair verdict (2026-10-10)

Ranked 1) cache the sorted order, *experimental and flag-disabled by default*; 2) leave it, as the
production fallback; 3) reject the Schwartzian rewrite — "avoid reimplementing precedence semantics".
Explicit: **GO for a flagged experiment, NO-GO for unconditional production deployment.** And a
correction worth keeping: the proposed safeguards give **fail-slow against detectable failures, not a
mathematical guarantee against every wrong-order hit.**

### The three prerequisites it named — two are now proven, one is not

1. **Can the comparator return 0 for distinct patterns?** **No.** `kernel_route_conflict_guard_test.php`
   enumerates all 927,972 same-method pairs in the real corpus and all 109 conflicting pairs, plus 36
   adversarial patterns: `distinct_compare_zero=0`. That is stronger than it looks — **no ties means the
   sorted order is a pure function of the pattern SET, independent of input order**, so `usort`'s PHP 8
   stability is irrelevant and a set-keyed cache is deterministic by construction.
2. **Does its behaviour depend on anything beyond the pattern strings?** **No.** `routePatternSegments()`
   (trim/explode/array_filter) and `routeSegmentIsDynamic()` (one fixed `preg_match`) are pure, and
   `routePatternMatchPriority()` composes only those. Nothing reads config, globals or request state.
   All three live in `module-routes.php`, so one file covers the whole dependency set — which is also
   the natural invalidation key (see below).
3. **Can production APCu deliver a measurable net saving?** **Half answered — and the instrument that
   finishes it now ships.** Three readings, in order of usefulness:

   - **Live, from the kernel admin page: `APCu entries 212 entries`** (owner, 2026-10-10). This is the
     decisive one — it proves APCu is **enabled and populated on the production host**, so a
     cross-request cache has somewhere to live there. It does *not* price an entry.
   - **Local web SAPI (mod_php), the new probe:** `usable=true roundtrip_ok=true`, **store 0.117 ms /
     fetch 0.088 ms for a 51.7 KB entry**, `shm_size=32M`, `cache_hits/misses 58,259 / 175,588` — so
     APCu is genuinely in use there, not merely loaded. 0.088 ms against a 2.714 ms live sort is a
     ~30x margin.
   - **CLI: still unmeasurable, by design.** `apc.enable_cli=0`, so store/fetch are no-ops. The probe's
     first draft printed a **0.003 ms** "hit cost" that was really a failed fetch returning `false`
     immediately — a number about a fetch that never happened. It now reports
     `apcu DISABLED in this SAPI` and discards the timing rather than reporting the cost of a failure
     as the cost of a hit.

   Shipped to close the gap, following the existing `kernelPerfProbeOpcache()` pattern:
   `kernelPerfProbeApcu()` in `src/http/perf-probe.php`, exposed as `perf.apcu` on
   `GET /api/v1/superadmin/perf` and as four rows on `/superadmin/perf`, and reported by
   `tools/perf-sample.php`. The rows had to be added explicitly — the page's row list is hardcoded,
   so a new fact in the payload does not appear on its own.

### Decision: C (leave it) — unchanged, but the blocker is now one deploy wide

Not implemented, because the one remaining prerequisite is a **live** round-trip reading and the
instrument that produces one is not deployed yet. Deploying it and reading it is now a single step,
so this is a held decision rather than an open question.

Should the live reading confirm the local numbers, the design that is already safe by construction:

- key = pattern set **+ `filemtime('module-routes.php')`**, so a change to the comparator *or* either
  helper invalidates automatically — no hand-maintained version constant to forget to bump;
- validate membership on every hit, fall back to the untouched `usort` on any miss, mismatch or
  absent APCu — the failure mode is **slow, never misrouted**;
- the comparator stays byte-identical, so route precedence cannot shift.

**Unlock condition:** one live reading showing `APCu usable ... yes` with a round-trip cost far below
the 2.714 ms sort. The local margin is ~30x, so the expected answer is clear; what the reading removes
is the assumption that Bluehost's APCu behaves like mod_php's.

---

## 4e. Route-order cache — SHIPPED (the sort is now gone on a hit)

The live reading in 4d met the unlock condition, so the deferred decision was taken up.

**Live facts that unlocked it** (`kernelappos.ikabudkernel.com`, 2026-10-10T12:56, one reading):
`APCu usable for a cross-request cache = yes`, `APCu round trip (51.7 KB entry) = 0.064 store /
0.027 fetch ms`, `APCu shared memory = 32M`, and `Route match: sort = 2.971 ms`. **0.027 ms against
2.971 ms is a ~110x margin** — and it is a ratio *within a single reading*, so it does not depend on
the host being in a normal state. Note this reading's `dispatch` (151.4 ms) is ~4.6x the 33.05 ms
median from 4c and it recorded `Module discover (cold scan) 47.63 ms` — a cold-cache reading. That
does not weaken the APCu rows (ratios) and it does not support any claim about regression either.

**What shipped.** `routePatternsInMatchOrder()` in `src/helpers/module-routes.php`, called from
`public/index.php` where `array_keys` + `usort` used to be. The comparator is **byte-identical** and
still runs on every miss.

- **Key** = method + the complete ordered input sequence + `filemtime(module-routes.php)`. Both halves
  of the dependency are covered: a changed route map is a different key, and a changed ordering rule
  is a different key — so invalidation needs nothing remembered. Every part of the comparator
  (`routePatternSegments`, `routeSegmentIsDynamic`) lives in that same file, which is what makes one
  mtime sufficient rather than a hand-maintained version constant.
- **Validation** = length **plus distinct membership**, which together are set equality. Both halves
  are required: without the duplicate check, a cached `[A, A]` matches a map of `{A, B}`, passes on
  length, and silently drops `B` from the scan order — a misroute, not a slowdown.
- **Fallback** = the untouched `usort` on every failure path: no APCu, miss, malformed entry, changed
  map, changed comparator source. The worst outcome of any failure is the sort we already had.

**Measured locally — same function, same machine, disjoint ranges:**

| path | median | range | n |
|---|---:|---|---:|
| CLI, fallback (APCu off, so the real sort runs) | **9.730 ms** | [8.246, 12.616] | 6 |
| web SAPI, cache hit | **0.597 ms** | [0.447, 0.988] | 6 |

**-93.9%, ranges disjoint.** The two arms are different *paths of the same function* rather than
different builds, which is why this comparison survives the host-drift problem that made 4b and 4c so
careful. On the live host the equivalent is 2.971 ms -> ~0.18 ms, i.e. ~2.8 ms of a 33 ms dispatch.

**Correctness, which is the part that matters here.** A speed test would have been the wrong test:

- **Order equivalence on the real corpus**: the production path returns the comparator order for all
  **1,968 patterns across 5 methods**, position for position.
- **The hit path, by composition.** The CLI cannot reach the cache (`apc.enable_cli=0`), so a CLI
  test of it would have silently tested only the fallback — the vacuous-check trap. Instead: the
  fallback's output *is* the comparator's order (asserted), and that exact order passes
  `routeOrderCachedIsValid()` (asserted), so a cache holding it is accepted and returned verbatim.
  The test prints which case it actually covered rather than implying more.
- **Must-refuse cases**, all six asserted: duplicate-that-matches-length, shorter, longer, foreign
  pattern, non-string element, non-array, null.
- **Must-allow cases**: key stable for identical input, comparator order accepted.
- **Key discriminates**: source stamp changed, route map changed, method changed, input order changed.
- **Route map unchanged**: `{"count":1968,"hash":"cf47a110930f5733"}` identical in warn and block
  modes; `/nonexistent-route-xyz` still resolves to a 404.
- Suites: 56/56 across the route and perf tests; both logs clean.

**Unrelated, observed while smoke-testing:** `disyl.strict.Blank compiled include rejected`
(`kernel/DiSyL/Compiler/CompiledTemplate.php:239`) appears when the themed 404 renders. Route order
cannot influence a template name, and the order is proven identical, so the handler and render path
are unchanged — this is a DiSyL compiled-template concern for a separate look, not a regression here.

---

## 4f. First map of the render path — and why it is the blind spot

Asked 2026-10-10: where else, aside from the kernel? The answer is that rendering is the **least
observed** part of the system, so the first task is instrumentation, not optimisation.

### Why there is no render attribution at all

`kernelPerfMarkRequestPhase('render')` is called from inside `register_shutdown_function()`
(`src/http/perf-attribution.php:381-384`), i.e. after the response is built. The perf payload is
assembled *during* the request, so `render` and `shutdown` can never appear in the request's own
payload — which is exactly why every reading, local and live, shows

    Request phase: render    not measured
    Request phase: shutdown  not measured

Everything after handler dispatch is therefore unattributed. `dispatch` is marked at
`public/index.php:600`, before the handler runs, so the entire handler + render + output cost is
invisible. The kernel work was possible because the kernel was measurable; the render path is not.

### The harness measures ops, not pages

`composer benchmark:disyl` (µs/op, PHP 8.5.11, iterations=3000 samples=5):

| scenario | median µs/op |
|---|---:|
| `processControlStructures nested` | **184.93** |
| `renderString script-aware` | 111.00 |
| `processVariables filtered` | 83.03 |
| `renderString variables` | 44.63 |
| `processVariables simple` | 32.90 |
| `processScriptVariables simple` | 12.77 |
| `buildOutputCacheKey` fast / fallback | 10.05 / 13.45 |
| `resolveValue` plain / dot-path / filtered | 3.03 / 4.28 / 11.44 |

Useful per-op, but it cannot say where a page's render time goes, because **nothing counts the
operations per page**. µs/op without ops/page is not a budget. That is the missing half, and it is
cheap to add.

### A hypothesis of mine that the probe FALSIFIED

Reading `IncludeResolver::processIncludes()` (`kernel/DiSyL/Component/IncludeResolver.php:58-76`)
I concluded it was O(N x content): `processNextInclude()` finds ONE `{include }`, replaces it and
`return`s (line 168-170), so N sibling includes need N passes each re-scanning from position 0.
That would be quadratic, and worth fixing.

`probe-include-scaling.php` measured it at a fixed page size over N = 1..20:

| includes | content B | median ms | ms per include |
|---:|---:|---:|---:|
| 1 | 834 | 0.018 | 0.0182 |
| 4 | 3336 | 0.073 | 0.0182 |
| 8 | 6672 | 0.162 | 0.0202 |
| 20 | 16690 | 0.397 | 0.0198 |

**Per-include cost is flat — linear, not quadratic.** The loop really does make N passes, but the
per-pass scan is a C-level `strpos`, so the quadratic term is negligible at page sizes; what remains
is the per-include work in `processIncludeTag()`. So include *resolution* is not a hotspot:
9 ark includes = ~0.17 ms.

**Scope limit of that probe, stated because it changes the conclusion:** it deliberately passed a
trivial `$compile` to isolate the resolver's own scanning. It therefore does **not** measure the
cost that actually matters — see below.

### Where the known render cost really is

Not include scanning: **per-request compilation of included content**. `{include}` has no compiled
cache — `processIncludeTag()` recurses into the compile path per request. The 2026-09-26 measurement
of two style partials (113.47 ms + 53.43 ms locally, `styles_ms: 20.77` / `includes_ms: 30.81` live)
was `compileStyleBody()` over 38 KB of CSS, i.e. **proportional to bytes, not to include count**.

**Status of that specific case — corrected 2026-10-10, the note was stale:**

| theme | then | now |
|---|---|---|
| `native-default` | 24,554 B + 13,714 B partials | **partials removed, 0 includes — fix held** |
| `ark` | same pattern, "larger" | 324 B + 5,268 B, **9 includes remain** |

So the big win was already taken on both; ark retains ~5.6 KB compiled per request. The residual cost
has not been measured and will not be extrapolated from bytes.

### The trap that must be controlled for

`TemplateEngine::render()` carries an **APCu shared output cache**
(`kernel/DiSyL/TemplateEngine.php:395` fetch, `:482`/`:559`/`:575` store). This is what produced the
retracted "compiled is 2.5x faster" claim on 2026-09-16 (interpreted 950 vs compiled 376 ms) — the
real alternating A/B was 912/919 and 967/924, i.e. noise. **Any render-path A/B must defeat that
cache or it will measure cache hits.** The related sanity rule stands: the render is a fraction of the
request, so a claimed 2.5x page-load speedup is arithmetically impossible and should be refused on
sight.

### What is actually logged — and the pipeline that logs NOTHING (corrected twice)

There are **two** timing lines in the render path, and they sit on **different pipelines**.

**1. `disyl.render.breakdown`** — emitted in `render()`, from two sites: the APCu output-cache hit
(`TemplateEngine.php:400`) and the interpreted path (`:540`).

| field | meaning |
|---|---|
| `template` | template name |
| `cache_path` | `apcu_output_hit` or `interpreted_cached` — which path served it |
| `source_read_ms` | read cost |
| `source_bytes` / `output_bytes` | in/out sizes |
| `duration_ms` | added by `log_timing()` |

**2. `disyl.compile.phases`** — emitted at the end of `compile()` (`:935-938`), once per template
**and once per include**, since `processIncludeTag()` recurses into `compile()`.

| field | meaning |
|---|---|
| `extends_ms` | extends/block-inheritance resolution |
| `scripts_ms` | `<script>` body compilation |
| `styles_ms` | `<style>` body compilation (`compileStyleBody()`) |
| `control_ms` | control-structure scan |
| `includes_ms` | `{include}` processing |
| `variables_ms` | variable/expression resolution |
| `total_ms`, `content_bytes` | whole-compile total and output size |
| `duration_ms` | added by `log_timing()` (same start as `total_ms`) |

A field appears only if that step ran — a template with no `{include}` has no `includes_ms`.

**The correction that matters: the compiled pipeline emits NEITHER.** `render()` lines 421-484 take the
compiled branch and `return` at :484 without calling `compile()`, and there is no timing on that branch
at all. So on the compiled path a render produces **no measurement whatsoever** — no phase split, no
`cache_path`, no byte counts.

**And the compiled path is the default.** `DISYL_COMPILED_MODE=true` in `.env`, 52 compiled templates
present in `storage/cache/compiled`, and the source itself notes "compiled mode is the default (v4.7+)"
at `:415`.

**Empirical confirmation, with the controls stated.** I cleared `app.log`, forced
`$_ENV['APP_TIMING_LOGS']='true'` and `APP_TIMING_THRESHOLD_MS=0` in-process (no `.env` change — the
getters read `$_ENV` per call), and rendered `pages/_perf-probe.disyl` through the real
`app()->render()`: **ok=true, 72.41 ms, and zero `disyl.*` lines written**. A separate diagnostic in
the same SAPI confirmed `timing_logs_enabled()=true`, threshold 0, and that both `write_log()` and
`log_timing()` do write. So the mechanism works and the compiled path was taken — which is why nothing
was logged.

**Two errors in my previous revision of this section, both from trusting the stale 2026-09-26 note:**

1. I wrote that `{extends}` blocks compiled mode fleet-wide and every theme page is on the interpreted
   pipeline. **False.** `templateGraphUsesComponentTags()` only blocks extends when
   `!compiledExtendsEnabled()` (`:4125`), and that flag is env-driven
   (`DISYL_EXTENDS_COMPILED`, `:4154-4161`). With it true — as here — extends does **not** block.
2. I wrote that "the render breakdown already exists, so read the log instead of building
   instrumentation". **Half true, and the wrong half mattered.** The split exists only for the
   INTERPRETED pipeline. The default (compiled) pipeline has no instrumentation, so the render path is
   not "already measured" — it is measured on the legacy path and blind on the default one.

The 2026-09-26 note's live `styles_ms: 20.77` / `includes_ms: 30.81` are therefore evidence about
templates that were on the interpreted pipeline **at that time**, not a standing property.

### Ranked candidates in the render path

0. **Instrument the compiled path.** This supersedes both earlier framings. The default pipeline emits
   nothing, so the render is unmeasurable exactly where it now runs — the same class of gap as
   `render` never being marked on the perf page, and the reason the earlier "just read the log"
   conclusion did not hold.
1. Expect the interpreted-pipeline log to shrink toward zero as templates migrate; do not read its
   absence as "rendering is free".
2. **Count ops per page** in the harness, turning the µs/op table above into an actual budget.
3. **Compiled output cache for `{include}`** — the measured mechanism, affects every theme.
4. **`getCompiledEligibilityCachePath()`** keys on root template path + root mtime while the walk
   covers includes/extends -> stale eligibility when a partial changes. Correctness.
5. Ops, no repo code: `APP_TIMING_THRESHOLD_MS=0` in production (~4-6 locked log appends per page
   view).

---

## 4g. Instrumenting the compiled path — test-first, then Sol review

The gap in 4f was that the compiled branch emits nothing. Closed in three steps, in this order.

**1. The test came first, and it was run against the unfixed tree.** `tests/disyl_compiled_render_instrumentation_test.php`
renders a compiled-eligible fixture twice and asserts a timing line appears. On the unfixed tree it
reported **2 PASS / 1 FAIL**, `log lines total: 0` — two successful renders that produced no log output
at all. A test that passes before the change asserts something already true, so this is the evidence
that it discriminates.

It also carries a **vacuity guard**: "no compiled line" has two very different causes — the compiled
path ran and is uninstrumented, or the fixture was interpreted so the compiled path never ran. The test
distinguishes them by asserting that no `disyl.compile.phases` line appeared, and fails with a distinct
reason if the fixture drifts onto the interpreted pipeline rather than passing quietly.

**2. The instrument.** `disyl.render.breakdown` is now emitted on the compiled branch with
`cache_path => 'compiled'` — the same message and field as `apcu_output_hit` and `interpreted_cached`,
so `cache_path` reads as **one timeline with three values** rather than a third log dialect.

Measured on `pages/_perf-probe.disyl`: **3.16 ms cold, 0.33 ms warm.** The earlier 72 ms number could
not separate those, which is exactly why the instrument was needed.

**3. Sol review — CHANGES_REQUIRED, two items accepted.** Both were real:

- **`log_timing()` sat inside the compiled `try`.** Had it thrown, the `catch` below would have treated
  it as a compiled failure, **discarded an already-successful render**, and re-rendered on the
  interpreted path. Instrumentation able to change what is rendered is a defect, and it contradicts the
  invariant the perf probes state explicitly ("instrumentation must never affect the request"). Now
  wrapped in its own `try`/`catch (Throwable)`.
- **The test was not discriminating enough, with a concrete false pass named:** a mutation that logged
  only on the *first* render would still satisfy `$compiledLines > 0` while the warm path stayed
  unmeasured — and warm is the case that matters in production. Tightened to `$rendered === 2` and
  `$compiledLines === 2`, plus matching the `template` field so an unrelated compiled render cannot
  satisfy the assertion.

Also accepted: document the **timing boundary**, since `duration_ms` deliberately excludes
`enableCompiledMode()` boot and `isCompiledEligibleTemplate()`'s graph walk (moving the clock earlier
would charge their cost to interpreted renders too). It means "execution after eligibility was
decided", not the whole render.

**Deferred, recorded rather than done:** a failed compiled attempt is still untimed, so total fallback
latency is under-reported. Sol's point that it should be a **separate event** (`disyl.render.compiled_failed`)
rather than another successful-looking `cache_path=compiled` line is right and is the shape to use.

**Pre-existing defect Sol noticed while reviewing:** the compiled `catch` comment reads
`// re-throw size limit errors`, but the clause rethrows **all** `RuntimeException`s, not only
size-limit ones. Not introduced here; worth a separate look.

**Operational note:** with `APP_TIMING_LOGS=true` and `APP_TIMING_THRESHOLD_MS=0` (the production
setting) this adds one **locked** log append per top-level compiled render. It is per render, not per
include. `log_timing()` computes `duration_ms` *before* `write_log()`, so the append's own latency is
excluded from the number it reports — the cost is real and invisible in the measurement. The threshold
is the operational control.

**Pre-existing test failures — established by A/B, so they are not misattributed later.** Each of these
fails identically with the change stashed, so none is caused by this work:

| suite | status |
|---|---|
| `disyl_assoc_test`, `disyl_engine_test`, `disyl_v4_compiler_test`, `disyl_v4_test` | exit=1 with AND without the change |
| `phase0_disyl_script_expression_leak_test` | `6 passed, 1 failed` both ways |

The single `phase0` failure is *"ordinary apostrophe value preserved"*. **Investigated before ranking it,
and it is not the bug it looks like — do not "fix" it on sight.**

```php
$engine->renderString("<script>var n='{name}';</script>", ['name' => "O'Brien"]);
// expected: <script>var n='O'Brien';</script>      <- what the test asserts
// actual:   <script>var n='O\u0027Brien';</script>  <- what the engine emits
```

`\u0027` **is** U+0027, so the emitted JavaScript evaluates to the string `O'Brien` — the correct value,
and it cannot terminate the single-quoted literal early. **So this is not data corruption**, and the
2026-09-16 note's `&amp;`/`&lt;`/`&quot;` HTML-entity claim does not describe current behaviour
(someone has evidently moved it to JSON-style escaping). The test encodes the interpreted path's raw
output; the compiled path escapes. That is a **test/implementation disagreement about escaping style**,
which per this repo's own rule means "a red test is evidence of disagreement, not of a bug — establish
which side is stale before choosing one". It needs a decision, not a patch.

This replaces an earlier draft of this paragraph that called it user-visible corruption. Checking the
actual output is what caught that — the same lesson as the rest of this document.

---

## 5. Live baseline — for scale, not comparisonFrom [perf-state-2026-10-10.md](perf-state-2026-10-10.md), 2026-10-09, `kernelappos.ikabudkernel.com`:

| block | ms | share of dispatch |
|---|---:|---:|
| module routes | 24.76 | 52.6% |
| – helpers load | 9.35 | 19.9% |
| – discovery | 9.06 | 19.3% |
| – route merge | 3.17 | 6.7% |
| boot | 14.88 | 31.6% |
| route match | 7.17 | 15.2% |
| **dispatch** | **47.06** | |

**Translating the two shipped changes to live (extrapolation, clearly labelled as such).** Applying
the *percentages* to the live phase costs gives roughly -2.5 ms on `route_merge` and -4.2 ms on
`route_match`, i.e. **~6.7 ms, about 14% of dispatch**. This is arithmetic on percentages, not a
live measurement — the local and live hosts differ by ~3x on this phase.

---

## 6. Reproducing

Raw samples and the probe reproducer live next to this file, so the evidence is durable rather than
session-local:

| file | contents |
|---|---|
| [`perf-metrics-2026-10-10/ab2.tsv`](perf-metrics-2026-10-10/ab2.tsv) | A/B #1 raw rows — arms A (index deleted) and B (index present) |
| [`perf-metrics-2026-10-10/abmemo.tsv`](perf-metrics-2026-10-10/abmemo.tsv) | A/B #2 raw rows — arms A (memoised) and B (not) |
| [`perf-metrics-2026-10-10/boot.tsv`](perf-metrics-2026-10-10/boot.tsv) | boot decomposition rows |
| [`perf-metrics-2026-10-10/bootprobe.jsonl`](perf-metrics-2026-10-10/bootprobe.jsonl) | 22 per-require samples (one JSON object per request) |
| [`perf-metrics-2026-10-10/probe-per-require.py`](perf-metrics-2026-10-10/probe-per-require.py) | the per-require probe: patches `public/index.php`, backs it up to `/tmp/index.php.bootprobe.bak`, emits one row per request |
| [`perf-metrics-2026-10-10/probe-route-sort-cache.php`](perf-metrics-2026-10-10/probe-route-sort-cache.php) | sort vs cache-key cost, one JSON row per process. Run as `for i in $(seq 1 8); do php probe-route-sort-cache.php; done` — one process per sample, because the memo is per-process. Read-only; also asserts the comparator is deterministic and that the key discriminates. Reports `apcu DISABLED ... not measured` rather than a timing from a fetch that did not happen |

The probe **modifies `public/index.php`** and must be reverted afterwards — it writes a backup first,
and the tree was verified identical to HEAD after the run. It exits without writing if its anchor is
not found, so a changed front controller fails loudly rather than silently mis-timing.

**Sampling the live host:** `tools/perf-sample.php` takes N readings of the perf API and prints
median + min + max per phase, because one reading of `/superadmin/perf` cannot distinguish a real
change from this host's drift.

```
PERF_USER=... PERF_PASS='...' \
  php tools/perf-sample.php --base=https://kernelappos.ikabudkernel.com --samples=8
```

**It takes no credentials as arguments and prints none** — they come from the environment. Do not
paste a production password anywhere it can be recorded; the output is numbers, which is all that is
needed. If a phase is missing from the host the script names it, which doubles as a deploy-inclusion
check: `boot_requires` / `route_match_sort` present means the 2026-10-10 instrumentation shipped.

Live phase readings: `/superadmin/perf` with a superadmin session; "Scripts compiled this request" is
the row that distinguishes warm from cold.

**Two traps hit while measuring, both worth repeating:**

1. **`write_log()` from a WEB request wrote nothing, silently — fixed 2026-10-10.** `app.log` was
   `-rw-rw-r-- kajagogoo:kajagogoo` while the web user is `uid=33(www-data) groups=33(www-data)` —
   not the owner and not in that group — so `@file_put_contents` (`bootstrap.php:747`) failed
   invisibly. Every "0 new log lines" check made against a curl/web request **before** the fix is
   therefore **vacuous**; CLI-run tests are unaffected.
   - Proven, not inferred: `write_log()` from a web request produced 0 lines, while a plain write to
     a **new** file in the same directory (which is 777) produced 22. The directory was never the
     problem — only the file's mode.
   - **Mode fixed on this host** (`666`, matching `error.log`, and matching the directory's existing
     777 so the delta is nil). This is **host state, not code** — it is not in git, so a deploy or
     rotation that recreates the file can regress it.
   - **Guard added** (`926829a7`): a failed append now reports itself once per request via
     `error_log()`. Verified both directions — `chmod 444` gave 3 attempts / 3 messages / 0 lines
     written; a writable target gave 1 line and 0 messages.
2. **`error_log()` does not go to stderr here.** `bootstrap.php:70` calls
   `ini_set('error_log', STORAGE_PATH . '/logs/error.log')`, so anything written with `error_log()`
   after bootstrap lands in `storage/logs/error.log`. A probe that watches stderr will report such a
   guard as **not firing when it is working** — which is how this guard was nearly mis-diagnosed as
   broken. Check `error.log`, not stderr.
3. **OPcache `revalidate_freq=2`** means a newly patched PHP file is not served for ~2 s. A probe
   must warm with real requests and *verify the file appears* before collecting, or it reports zero
   samples for a reason that has nothing to do with the code under test.
