# Kernel 4.3.0 — DiSyL Cache + Experiments

**Date:** 2026-05-08
**Codename:** atlas (4.x line)
**Status:** Released

## TL;DR

Two new template surfaces:

```disyl
{cache key='product-card:42' ttl=300}
  {depends_on 'product:42', 'pricing:42'}
  <article>…</article>
{/cache}

{invalidate 'product:42'}

{experiment 'cta-copy'}
  {variant 'control' weight=50}<button>Place order</button>
  {variant 'urgent' weight=50}<button>Buy now</button>
{/experiment}

{convert 'cta-copy' goal='order-placed'}
```

`{cache}` stores rendered fragments keyed by a runtime expression with TTL
and dependency tags. `{invalidate}` bumps the version of one or more tags,
which makes any fragment depending on that tag treat its stored body as a
miss and re-render. `{experiment}` picks a sticky variant per
`(experiment_id, subject_id)` using a deterministic hash; `{convert}`
records a goal hit against the subject's prior assignment.

## What's new

- **`FragmentStore`** ([kernel/DiSyL/Cache/FragmentStore.php](kernel/DiSyL/Cache/FragmentStore.php))
  - File-backed JSON storage under `storage/cache/disyl-fragments/<tenant>/`
  - APCu hot-layer when available, disk-only path correct on its own
  - Per-tenant key namespace (`tenantId` argument; default `_global`)
  - `tryGet` / `put` / `invalidate` / `flushAll`
- **`Bucketer`** ([kernel/DiSyL/Experiments/Bucketer.php](kernel/DiSyL/Experiments/Bucketer.php))
  - Deterministic sha256-based bucket function (SSR-stable across servers)
  - Sticky `(experiment_id, subject_id) → variant` assignments persisted
    to JSON
  - `expose` (per-request dedupe), `convert` (no-op when no prior
    assignment), `readEvents` for analytics tooling
- **Engine wiring** ([kernel/DiSyL/TemplateEngine.php](kernel/DiSyL/TemplateEngine.php))
  - `cache` and `experiment` added to control-structure dispatch
  - `invalidate` and `convert` parsed as inline self-closing tags in a
    pre-pass before control structures
  - Public setters: `setFragmentStore`, `setBucketer`, `setTenantId`,
    `setSubjectId`, `setRequestId`
  - `parseAttrPairs`, `splitInlineArgs`, `parseFirstQuoted`,
    `parseVariantArms` shared parser helpers

## Files added

```
kernel/DiSyL/Cache/FragmentStore.php           (170 lines)
kernel/DiSyL/Experiments/Bucketer.php          (160 lines)
tests/disyl_v43_cache_exp_test.php             (180 lines, 20 assertions)
```

## Files modified

```
kernel/App.php                          KERNEL_VERSION → 4.3.0
kernel/DiSyL/TemplateEngine.php         + cache/experiment dispatch
                                        + invalidate/convert inline pass
                                        + 4 setters + 4 parser helpers
                                        + 2 evaluator methods
```

## Verification

```
php tests/disyl_v4_test.php             → 36/36 pass
php tests/disyl_v41_match_test.php      → 14/14 pass
php tests/disyl_v41_i18n_test.php       → 12/12 pass
php tests/disyl_v42_types_test.php      → 34/34 pass
php tests/disyl_v43_cache_exp_test.php  → 20/20 pass
```

Total DiSyL coverage: **116 assertions, 0 failures.** Clean app.log /
error.log on full run.

## Compatibility

- **Backward compatible.** New tags only activate when used; existing
  templates are unchanged.
- No public API renamed or removed; engine constructor signature
  unchanged.
- File storage uses `storage/cache/disyl-fragments/` and
  `storage/cache/disyl-experiments/` (auto-created).
- The 4.3 setters all default to safe values (`tenantId='_global'`,
  `subjectId='_anon'`, `requestId='_req'`) — callers may opt in to per-tenant
  / per-user behaviour incrementally.

## Honest scope statement

The 4.3 design doc specifies six new database tables plus full multi-tenant
DB-backed storage, cookie-based subject resolution, and cross-process
stampede protection. Each is real engineering work (migrations, tenant
helpers, lock semantics) and was scoped out of this release to ship the
engine surface today.

**Implemented in 4.3.0:**
- File-backed FragmentStore with APCu hot layer
- Deterministic Bucketer with file-backed sticky assignments
- All five new tags wired into the engine
- Per-tenant *cache key namespace* (file directory)
- Per-request exposure dedupe
- TTL + dependency-tag invalidation

**Deferred to 4.3.1:**
- DB tables (`disyl_cache_fragments`, `disyl_cache_dep_versions`,
  `disyl_experiments`, `disyl_experiment_assignments`,
  `disyl_experiment_exposures`, `disyl_experiment_conversions`)
- Migration scripts + module manifest `owns_tables` declarations
- Per-process APCu lock for stampede protection
- Cookie-based subject-id resolution from `App` request lifecycle
- Stopped/paused experiment status & dashboard
- Distributed dep-version invalidation across multiple servers

The 4.3.0 surface is correct and useful for a single-host deployment.
4.3.1 will add the multi-host / DB-backed substrate without changing the
template syntax.

## Diagnostic codes

- `DISYL_CACHE_INVALID_TTL` — `ttl` attribute negative
- `DISYL_EXP_ZERO_WEIGHT` — sum of variant weights ≤ 0
- `DISYL_EXP_DUP_VARIANT` — same variant name declared twice
- `DISYL_EXP_NO_VARIANTS` — `{experiment}` body had no `{variant}` markers

## Migration notes

None required. Opt-in per template:
1. Wrap an expensive fragment in `{cache key='…' ttl=N}…{/cache}`.
2. Add `{depends_on '…'}` for tags whose change should bust the cache.
3. From admin save handlers, render a small template containing
   `{invalidate '…'}` to bump those tags.
4. For experiments, set `setSubjectId()` once per request from the
   request middleware (typically session/user id).
