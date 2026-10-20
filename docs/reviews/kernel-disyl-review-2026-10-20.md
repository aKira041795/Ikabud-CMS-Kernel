# Kernel + DiSyL review — 2026-10-20

## Method

- Read-only Code Reviewer passes over `kernel/` and `kernel/DiSyL/`.
- Independent probes and guards written before dispatch (red on the current tree).
- Chair consultation: `.ai/consult/kernel-disyl-gaps-2026-10-20.md` (`APPROVE WITH CONDITIONS`).
- Two high-risk Sol lanes plus one repair lane, each driven by a
  `tools/autonomy-contract.py` contract and `tools/lane-autonomy.py`.
- Layered verification: targeted guard → DiSyL feature gate → conformance → parity.

The feature gate caught a regression that the first lane's acceptance test did not. That is recorded
below as finding 3, not hidden as a lane failure.

## Findings and fixes

### 1. Stale compiled-eligibility cache across dependency edits (silent content corruption)

`isCompiledEligibleTemplate()` persisted a decision keyed only by the **root** template path and mtime
(`kernel/DiSyL/TemplateEngine.php:4235-4238`), while the decision is made by walking
`{include}`/`{extends}` (`:4243-4294`). Editing only an included partial changed neither the root path
nor its mtime, so the stale entry selected the compiled path.

Reproduced: `page.disyl` includes a plain `partial.disyl`; after rendering once, the partial was
rewritten with `{ikb_badge}` and its mtime bumped. A fresh engine read the stale `eligible=true`
entry and produced:

```text
<main><div class="badge-host">{ikb_badge variant="success"}Boundary/ikb_badge</div></main>
```

The component did not render and raw DiSyL leaked into the page. The reverse direction was also
stale: a persisted `eligible=false` survived removal of the component.

**Fix (`3aa5cb39`):** persist the visited graph as `path => mtime`, validate every dependency before
reusing a decision, recompute on a mismatch or missing file, and bump
`COMPILED_ELIGIBILITY_CACHE_VERSION` 4 → 5.

**Guard:** `tests/disyl_compiled_eligibility_stale_test.php` — 14 assertions covering
positive→negative, negative→positive, and dependency deletion.

### 2. Over-broad compiled-failure rethrow (500 on cache failure)

The compiled render branch caught `\RuntimeException` and rethrew every one
(`kernel/DiSyL/TemplateEngine.php:546-547`) with the comment "re-throw size limit errors". The
exception's own contract (`kernel/DiSyL/Exceptions/TemplateOutputTooLargeException.php`) says only
that type may abort; an unwritable compiled cache, a read-only release directory or a full disk must
fall back to the interpreted pipeline. `TemplateCache::get()` throws plain `RuntimeException` for a
failed cache write (`TemplateCache.php:107`) and an unreadable template (`:401`), so a caching
problem could turn a renderable page into a 500.

Reproduced: reflection replaced the compiled cache with a `TemplateCache` subclass whose `get()`
throws `RuntimeException('simulated cache write failure')`. At HEAD the exception escaped instead of
falling back.

**Fix (`39139906`):** hard-abort only on `TemplateOutputTooLargeException` and
`TemplateLoopLimitException`; every other `Throwable` falls through to the existing interpreted
fallback.

**Guard:** `tests/disyl_compiled_runtime_exception_fallback_test.php` — ordinary `RuntimeException`
falls back, output is rendered once, the reason is logged, 6 MB output still throws, and the loop
limit still throws.

### 3. Gate-found regression: the fallback swallowed the compiled loop limit

The first narrow catch (only the output-size exception) made the compiled unbounded-loop guard's
plain `RuntimeException('DiSyL while-loop exceeded max iterations (100000)')`
(`TemplateCompiler.php:433,507`) fall through to the interpreted path, which completes. The feature
gate caught it:

```text
conformance: 222 passed, 7 failed
poc5: lane_green=PARTIAL runtime_proof_compiled=FAIL
promotion: partial=1 promoted=40
compiled unbounded while throws RuntimeException   ✗
```

The first lane's acceptance test passed; the acceptance criterion was necessary but not sufficient.

**Repair (`39139906`):** new `TemplateLoopLimitException extends RuntimeException`; both compiler
loop guards throw it; the engine catches it with the output-size exception before the fallback;
`COMPILER_VERSION` 18 → 19 invalidates compiled templates that embed the old throw.

After repair: `conformance 229/0`, `promoted=41 partial=0`, `lane_green=YES`, `disagreements: none`.

## Verification

| Gate | Result |
|---|---|
| Eligibility guard | 14/14 |
| Fallback guard | 5/5 |
| Component fallback | 3/3 |
| Compiled instrumentation | 5/5 |
| Template cache | pass |
| DiSyL conformance test | 229/0 |
| DiSyL conformance checker | `promoted=41 partial=0`, `lane_green=YES`, `disagreements: none` |
| DiSyL parity | 178/178 |
| Security fuzz | pass |
| `storage/logs/error.log` | empty |

Full logs: [`evidence/disyl-final-gate.log`](evidence/disyl-final-gate.log),
[`evidence/disyl-parity-final.log`](evidence/disyl-parity-final.log),
[`evidence/disyl-eligibility-gate.log`](evidence/disyl-eligibility-gate.log), and the failed gate
that found the regression: [`evidence/disyl-fallback-gate-failed.log`](evidence/disyl-fallback-gate-failed.log).

## Review boundaries and remaining work

- **Not a kernel defect:** `KernelPDO.php.orig`/`.rej` are inert (not loaded by PHP/Composer). They
  are gitignored but not excluded by `create-bluehost-archive.php:135-140`, so they can ship in
  Bluehost zips. Packaging cleanup only; delete them when that archive path is touched.
- **Not a defect:** `{include}` resolution is cached; the residual per-request `sha1_file` /
  `file_get_contents` in cache validation is performance, not recompilation.
- **Still open (larger than one lane):** synchronous Tailwind JIT on the auth/admin shells is the
  highest mobile critical-path cost from `docs/reviews/kernel-disyl-render-review-2026-10-09.md`.
  Replacing it requires precompiled, same-origin CSS and visual parity work.
- **Still open:** the 2026 kernel review's tenant-entry rewrite fallback / entry-module 404
  diagnostics (`docs/reviews/application-kernel-os-review-2026.md`) are not addressed here.
- **Feature, not fix:** `{cache }` is the only reason four public CMS pages remain interpreted;
  `{ikb_}` compiled support would move the entity-view engine. Both are language features.
- **mtime boundary:** dependency validation uses `filemtime`, matching the compiled cache's existing
  invalidation model. A same-second edit that preserves the timestamp is not detected; `sha1_file`
  was rejected by the chair without evidence that timestamp-preserving deploys are in the supported
  contract. Eligibility files for old root mtimes/versions are not garbage-collected (pre-existing).
- **Asymmetry by contract:** the interpreted path still completes an unbounded `{while}` (returns
  empty) while the compiled path aborts at 100000 iterations. The conformance test pins both.

## Autonomy ledger

- Routine tasks: 3, all autonomous, 100% (the earlier CMS tasks).
- High-risk tasks: 3 — `disyl-eligibility-stale`, `disyl-runtime-fallback` (2 attempts) and
  `disyl-runtime-fallback-r2` (1 attempt) — 0 owner interventions, all merged.
- High-risk tasks are excluded from the autonomy rate by the contract classifier (`^kernel/`); they
  are reported separately rather than inflating the routine rate.

Commits: `3aa5cb39` (eligibility), `39139906` (fallback + loop-limit repair).
