# DiSyL 4.3 — Cache + Experimentation

**Target kernel:** 4.3.0
**Risk:** medium (new runtime stores; clear blast radius)
**Depends on:** 4.1

## Goals

1. **Fragment cache** with declared dependencies and TTL — the engine
   invalidates exactly the right fragments when a dependency changes.
2. **Experimentation primitives** — A/B variant rendering with sticky
   bucketing and conversion tracking.

Both are runtime additions sharing one new "engine planner" surface so that
later batches (sandbox, async, federation) can reuse the planner contract.

## 1. Fragment cache

### Syntax

```disyl
{[ cache key='product-card:' + product.id ttl=300 ]}
  {[ depends_on 'product:' + product.id, 'pricing:' + product.id ]}
  <article class="card">…</article>
{[ endcache ]}

{[ invalidate 'product:' + product.id ]}
```

Rules:
- `cache` requires `key` (any DiSyL expression returning string) and `ttl` (seconds, ≥ 0).
- `depends_on` is optional; takes a comma-separated list of dependency tags.
- Dependency tags are opaque strings; conventionally `entity_kind:id`.
- `invalidate` is a side-effecting tag emitted from a handler-rendered template
  (admin save flow). It accepts one or more tags and bumps their version
  counter atomically.

### Storage

New tables (owned by `kernel`):

- `disyl_cache_fragments(key, body, expires_at, dep_version_hash, tenant_id, created_at)`
- `disyl_cache_dep_versions(tag, version, tenant_id, updated_at)`

Both per-tenant. Reads served from APCu hot layer; misses fall through to DB.

### Engine

New `kernel/DiSyL/Cache/FragmentStore.php`:
- `tryGet(string $key, array $deps, int $ttl, string $tenantId): ?string`
- `put(string $key, string $body, array $deps, int $ttl, string $tenantId): void`
- `invalidate(array $tags, string $tenantId): void`
- `dep_version_hash` = sha256 of concatenated current dep versions; on mismatch
  the fragment is treated as miss and refreshed.

### Stampede protection

Single-flight per `key` per process via APCu lock with 250 ms timeout; on
timeout, render through and return. Cross-process stampede acceptable in 4.3;
revisit if metrics show it matters.

### Errors

`DISYL_CACHE_INVALID_TTL`, `DISYL_CACHE_DYNAMIC_DEP_REJECTED`
(when a dep tag would resolve to non-string), `DISYL_CACHE_NESTED_LIMIT`
(max 4 levels nested).

### Tests

`tests/disyl_v43_cache_test.php`:
1. Fragment hit / miss / refresh after TTL
2. `depends_on` invalidation flips immediately
3. Nested `cache` blocks; outer hit serves children
4. Tenant isolation (tenant A invalidate doesn't touch tenant B)
5. APCu unavailable → DB-only path still correct
6. Stampede protection: 50 concurrent renders → ≤ 2 underlying renders

## 2. Experimentation

### Syntax

```disyl
{[ experiment 'checkout-cta-copy' ]}
  {[ variant 'control' weight=50 ]}
    <button>Place order</button>
  {[ variant 'urgent' weight=50 ]}
    <button>Buy now — limited stock</button>
{[ endexperiment ]}

{[ convert 'checkout-cta-copy' goal='order-placed' ]}
```

Rules:
- `experiment` requires a stable string ID.
- Each `variant` requires `weight` (integer ≥ 0). Weights need not sum to 100;
  the engine normalizes.
- Bucketing is **sticky per (experiment_id, subject_id)**. `subject_id` defaults
  to the request's user id, then session id, then a hash of `client_ip + user_agent`.
- `convert` records a goal hit. The engine looks up which variant the subject
  was bucketed into (via cookie + DB) and writes a row to
  `disyl_experiment_conversions`.
- Exposure events deduped per request: rendering the same experiment twice
  in one render emits one exposure.

### Storage

New tables (owned by `kernel`):

- `disyl_experiments(id, status, weights_json, started_at, stopped_at, tenant_id)`
- `disyl_experiment_assignments(experiment_id, subject_id, variant, assigned_at, tenant_id)`
- `disyl_experiment_exposures(experiment_id, subject_id, request_id, exposed_at, tenant_id)`
- `disyl_experiment_conversions(experiment_id, subject_id, goal, converted_at, tenant_id)`

### Determinism

Bucket function: `int(sha256(experiment_id + ':' + subject_id), base16) % total_weight`.
This is required so SSR renders are stable across servers.

### Engine

New `kernel/DiSyL/Experiments/Bucketer.php`:
- `assign(string $experimentId, string $subjectId, array $weights): string`
- `expose(string $experimentId, string $subjectId, string $requestId): void`
- `convert(string $experimentId, string $subjectId, string $goal): void`

### Errors

`DISYL_EXP_ZERO_WEIGHT`, `DISYL_EXP_DUP_VARIANT`, `DISYL_EXP_NO_SUBJECT`
(strict mode only — falls back to first variant otherwise).

### Tests

`tests/disyl_v43_experiments_test.php`:
1. Sticky bucketing: same subject → same variant across renders
2. Weight distribution within 5% over 10k subjects
3. Exposure deduped per request
4. Convert without prior exposure → ignored (warning logged)
5. Stopped experiment serves the `control` variant
6. Tenant isolation

## Acceptance

- All tests pass.
- New tables added to `migrations/`, marked `owns_tables` for `kernel`
  (or `co_owns_tables` if a module wants to read).
- No regression on prior suites.
- `php scripts/guard-module-manifests.php` still 0 / 0.
