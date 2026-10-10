# Perf metrics — 2026-10-10

Raw measurement sets behind the two changes shipped on 2026-10-10. Companion to
[perf-state-2026-10-10.md](perf-state-2026-10-10.md), which records the *state*; this records the
*evidence*, including the samples that did not support a conclusion.

## How to read this (do not skip)

- **Only disjoint ranges are evidence.** Where the two arms' ranges overlap, the delta is a
  direction to re-test, not a result. Two of the seven figures below are in that category and are
  labelled.
- **The local box is ~3x slower than the live host on `route_match`** (22 ms local vs 7.17 ms live).
  **Percentages transfer; absolute milliseconds do not.**
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

**The two phases this work touched improved beyond the entire observed range.** Every one of the 8
`route_match` samples (max 4.495) is below the 7.17 baseline, and every `route_merge` sample
(max 2.673) is below the 3.17 baseline. That is stronger than a median comparison and does not depend
on the baseline having been typical.

**The local prediction transferred almost exactly.** Local `route_match` fell 58.4%; live it fell
59.5%. This is the first evidence that the local A/B percentages do carry to the live host even though
the absolute milliseconds do not (local 22 ms vs live 7.17 ms at baseline).

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
