# DiSyL 4.6 — Federation + AI Primitives ✅ Shipped

**Target kernel:** 4.6.0  
**Status:** Released — see [release notes](../releases/release-notes-2026-05-08-kernel-4.6.md)
**Risk:** high (new external dependencies; policy-critical)
**Depends on:** 4.4 (sandbox), 4.5 (async)

## Goals

1. **Federation**: cross-service / cross-tenant data composition with partial-failure semantics.
2. **AI as a governed primitive**: LLM calls with built-in caching, redaction,
   cost ceilings, model pinning, and audit — under the same sandbox + cache
   model as everything else.

## 1. Federation

### Syntax

```disyl
{[ federated_query name='product-and-reviews' timeout=400 ]}
  {[ remote service='catalog' query='product(id: ' + product.id + ')' let=product ]}
  {[ remote service='reviews' query='for(product_id: ' + product.id + ')' let=reviews ]}
  {[ aggregate let=summary ]}
    {{ product | merge({ avg_rating: reviews | avg('score') }) }}
  {[ endaggregate ]}
{[ endfederated_query ]}
```

Rules:
- `federated_query` runs all `remote` children in parallel (reuses 4.5 scheduler).
- Each `remote` requires `service`, `query`, `let`. Optional `fallback=EXPR`.
- `aggregate` runs after all `remote`s resolve, has access to all bound vars.
- Partial failure: by default a failed remote sets `let=` to its `fallback` (or
  null) and execution continues. Set `policy='all-or-nothing'` to fail the whole
  block.

### Service registry

`config/federation.php` (per-tenant overridable):

```php
return [
  'catalog' => [
    'endpoint' => 'https://internal.catalog.svc/graphql',
    'auth'     => 'service-token:catalog',
    'timeout'  => 500,
  ],
  'reviews' => [...],
];
```

### Sandbox interaction

`federated_query` requires `network` and `federation` capabilities. `untrusted`
blocks may not federate.

### Tests

`tests/disyl_v46_federation_test.php`:
1. Two remotes in parallel resolve in `max(t1, t2)`
2. Failed remote with fallback returns fallback value
3. `policy='all-or-nothing'` raises on any failure
4. Aggregate sees all let-bound vars
5. Sandbox denies federation in untrusted

## 2. AI primitives

### Syntax

```disyl
{[ ai_generate model='gpt-4o-mini' max_tokens=200 cache_ttl=3600 let=blurb ]}
  Write a 50-word product blurb for: {{ product.name }}.
  Tone: {{ brand.tone }}. Audience: {{ brand.audience }}.
{[ endai_generate ]}

<p>{{ blurb }}</p>

{[ ai_query
   model='gpt-4o-mini'
   data=cart
   prompt='Suggest one upsell from this cart, return JSON {sku,reason}'
   schema='{sku: string, reason: string}'
   let=upsell ]}
{{ upsell.reason }}

{[ ai_complete model='claude-haiku' prompt='Summarize: ' + article.body let=summary ]}
{[ ai_optimize template='product-card' goal='ctr' baseline='control' ]}
```

Rules:
- All AI tags require the `ai` sandbox capability.
- `model` is required and pinned (no auto-routing in 4.6).
- `max_tokens` is required for `ai_generate` and `ai_complete`.
- `cache_ttl` integrates with 4.3 cache; the cache key includes prompt hash + model + data hash.
- `schema` (JSON-Schema subset) on `ai_query` enforces structured output; failure → fallback or raise per `policy=`.
- `ai_optimize` is a deferred experimentation pattern: it generates variants and
  feeds them through the 4.3 experimentation pipeline keyed on `goal`.

### Policy engine

`kernel/DiSyL/AI/Policy.php`:
- Per-tenant cost ceiling (USD/day).
- Per-template max_tokens cap.
- PII redaction pre-call: regex pass for emails, phones, SSN, payment patterns;
  replaced with placeholders before submission, restored in response.
- Model allowlist per tenant.
- Hard kill switch: `KERNEL_AI_DISABLED=1` env disables every AI tag globally.

### Audit

Every AI call writes to `disyl_ai_calls`:
template, line, model, prompt_hash, request_id, tenant_id, user_id, redactions_count,
input_tokens, output_tokens, usd_cost, cache_hit, latency_ms, error.

### Determinism for SSR

`temperature=0` enforced when called inside a `cache` block (so the cached
fragment is deterministic). Higher temperatures allowed only outside cache.

### Tests

`tests/disyl_v46_ai_test.php`:
1. Generate caches by prompt hash + model
2. PII in input is redacted before call, restored after
3. Schema-mismatched JSON triggers fallback
4. Model not in allowlist → denied
5. Daily cost ceiling exceeded → denied with audit row
6. `KERNEL_AI_DISABLED=1` blocks all AI tags
7. Sandbox without `ai` capability denies all AI tags
8. Inside a cache block with `cache_ttl > 0`, two renders produce identical bytes

## Acceptance

- All tests pass.
- Live integration smoke (gated by env): one round-trip to a configured
  provider returns expected JSON.
- Audit table populated for every call.
- No regression on prior suites.
- Sandbox + cache + audit integration verified by an end-to-end scenario:
  untrusted template attempting `ai_generate` is denied with comment in dev,
  500 in strict; trusted template with `ai` cap and within budget renders +
  caches.
