# Task: make /superadmin/perf measure accurately

## Context

`/superadmin/perf` is a diagnostic page that reports the current request's performance. It is
**misleading in five specific ways**, all confirmed by reading the code, not inferred. The job is to make
each number mean what a reader will assume it means. This is an **instrumentation accuracy** task — do not
optimise the application, only the measuring device.

Entry points:
- `src/http/core-routes.php` → `/superadmin/perf` → `pageSuperadminPerf`
- `src/http/page-handlers.php` → `kernelHandlePageSuperadminPerf()` (starts ~line 224, ends ~473)
- `src/http/perf-probe.php` → the individual probes
- `src/http/perf-attribution.php` → `kernelPerfProbeRequestAttribution()` (~line 185)

## The five defects

### 1. The headline total includes the probe's own cost, unlabelled
The page emits `Total wall time 153.67 ms` while separately emitting `Probe self-measurement 122.89 ms`.
Nothing states that the total *contains* the self-measurement, so a reader concludes the request costs
154 ms when the real request work is ~31 ms. This has already caused a misreading.

**Required:** compute and display **request work = total − probe self** as its own labelled row, and change
the total row's label so it cannot be read as the request cost (e.g. "Total wall time (includes probe
overhead)"). Never emit an unqualified total that includes the probe.

### 2. A read-only diagnostic does real work on every page load
`kernelPerfProbeColdModuleDiscover()` calls `discoverModulesScanAll()` — a full scan of all modules — on
every perf page load, and reports ~64 ms as `Module discover (cold scan)`. This is a probe that *causes*
the cost it reports, on every visit, and it also inflates the probe self-measurement.

**Required:** run it only when `?deep=1` is present. On the default path it must not be called at all, and
the row must say so (e.g. "not run (add ?deep=1)"). When it does run, its cost must be labelled as
probe-induced rather than as request cost.

### 3. Structurally impossible measurements read as missing instruments
`Request phase: render` and `Request phase: shutdown` render `not measured`. Two different facts are
being conflated:

- this page **echoes raw HTML** — it is not template-rendered — so it has no render phase to measure;
- `shutdown` happens **after the response is sent** and cannot be measured inside the request, ever.

A reader cannot tell "we don't measure this" from "there is nothing to measure".

**Required:** label both as structurally not-applicable with the reason, distinct from a missing
measurement. Keep the existing separate `DiSyL render (extends)` row — that IS the render measurement.

### 4. Absent payload facts are indistinguishable from unmeasured phases
Every row is written as `$perfData['x'] ?? 'not measured'`. So a key that the probe never produced looks
identical to a phase that was intentionally not measured.

**Required:** use a distinct sentinel for a missing key (`not collected`), so `not measured` /
`not applicable` / `not collected` each mean one thing.

### 5. The cache hit-rate row does not say what it covers
`Cache hit rate 8.87 %` reads as the whole cache. It is not: `cache:stats` reports 3484 files / 4.93 MB
across many subtrees, while the probe reports 112 active + 38 expired files / 0.27 MB, which matches the
**page-cache family** almost exactly.

**Required:** name the scope in the row label and value (e.g. "Cache hit rate (page cache)").

## Constraints — read carefully

- **Do NOT touch `kernel/`.** There are pre-existing uncommitted changes in
  `kernel/DiSyL/TemplateEngine.php` that are **not yours**. Do not edit, revert, stash, or stage that file.
  It is under separate review.
- Do not change authentication or authorisation on the page.
- Do not edit any `.disyl` template.
- Keep the change surgical: the perf handler, the probes, and the new check script only.
- Preserve existing behaviour and output for anything not listed above.

## Required deliverable: a testable oracle

Add `tools/perf-page-accuracy-check.php`, CLI, that asserts the five fixes and exits non-zero on any
failure. To make the assertions possible, extract the payload→rows assembly out of
`kernelHandlePageSuperadminPerf()` into a pure function (e.g. `kernelPerfPageRows(array $perfData): array`
in `src/http/page-handlers.php`) that the handler then uses, so rows can be asserted without HTTP or auth.
Keep the handler's output byte-compatible apart from the intended label changes.

The check must assert at minimum:
1. a row exists whose label identifies **request work**, and its numeric value equals
   `total − probe_self` within 0.01 ms (feed a synthetic payload where both are known, e.g. 153.67 and
   122.89, and assert ~30.78);
2. the **total** row's label states it includes probe overhead;
3. with `deep` not set, the cold-scan row reports that it did not run, and the string
   `discoverModulesScanAll` is reachable only via the deep path (assert on the row, plus a source-level
   check that the call sits inside the deep branch);
4. `render` and `shutdown` rows are labelled structurally not-applicable and are NOT `not measured`;
5. an absent payload key renders `not collected`, and is distinguishable from `not applicable`;
6. the cache hit-rate row's label names its scope.

Print one `PASS`/`FAIL` line per assertion and a final `N passed, M failed`.

## Verification you must run before reporting

- `php -l` on every file you touch.
- `php tools/perf-page-accuracy-check.php` → must exit 0 with all assertions PASS.
- `php tests/kernel_perf_probe_test.php`, `php tests/kernel_perf_phase_breakdown_test.php`,
  `php tests/perf_gate_test.php` → must not regress. If any already fails, say so explicitly and show that
  it fails identically without your change.
- `git status --porcelain` → must show ONLY your files. If `kernel/DiSyL/TemplateEngine.php` appears in
  your staged set, you have staged something that is not yours: unstage it.

Commit only your files, do not push. Report:
`status`, files changed, the check output, the three test results, and anything you could not verify.
