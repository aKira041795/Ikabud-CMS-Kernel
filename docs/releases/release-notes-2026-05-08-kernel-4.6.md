# Kernel 4.6.0 — DiSyL Federation + AI Primitives (Phase 1)

**Date:** 2026-05-08
**Codename:** atlas (4.x line — final 4.x release before 5.0 planning)
**Status:** Released

## TL;DR

Templates can now compose data from multiple services in one block and
invoke pinned AI models with policy enforcement, all under the existing
sandbox + cache + capability model.

```disyl
{federated_query name='product-and-reviews'}
  {remote service='catalog' query='1' let=p}
  {remote service='reviews' query='1' let=r fallback='N/A'}
  {aggregate let=out}{p.title} ({r.avg}){/aggregate}
{/federated_query}

{ai_generate model='gpt-4o-mini' max_tokens=200 let=blurb}
  Write a 50-word product blurb for: {product.name}.
{/ai_generate}

{ai_query model='gpt-4o-mini' prompt='Suggest one upsell from this cart' let=upsell}{/ai_query}
```

Two new sandbox capabilities — `federation` and `ai` — gate the new tags.
`{untrusted}` blocks all federation and AI calls. A kill switch
(`KERNEL_AI_DISABLED=1`) disables every AI tag globally without code
changes.

## What's new

### Federation

- **`ServiceRegistry`** ([kernel/DiSyL/Federation/ServiceRegistry.php](kernel/DiSyL/Federation/ServiceRegistry.php))
  - In-memory `register(name, callable)` populated by callers
  - `resolve()` invokes the resolver with parsed query + render context
  - Unknown service throws `DISYL_FEDERATION_UNKNOWN_SERVICE`
- **`{federated_query}` / `{remote}` / `{aggregate}`** in [TemplateEngine.php](kernel/DiSyL/TemplateEngine.php)
  - Resolves all `{remote}` children (sequential in 4.6.0; will be parallel
    when 4.5.1 Fibers driver lands — no template changes required)
  - Per-remote `fallback=` and per-block `policy='all-or-nothing'`
  - `{aggregate let=out}` body sees every successfully bound `let=` var
  - Gated by `federation` capability (denied in `{untrusted}`)

### AI primitives

- **`AiProvider`** interface + **`EchoAiProvider`** default
  ([kernel/DiSyL/AI/AiProvider.php](kernel/DiSyL/AI/AiProvider.php),
  [kernel/DiSyL/AI/EchoAiProvider.php](kernel/DiSyL/AI/EchoAiProvider.php))
  - Single-method contract: `complete(req): {text, input_tokens, output_tokens, model}`
  - `EchoAiProvider` returns `[ai:MODEL] PROMPT` truncated by max_tokens — fully
    deterministic so SSR caches and tests stay byte-stable
- **`Policy`** ([kernel/DiSyL/AI/Policy.php](kernel/DiSyL/AI/Policy.php))
  - Model allowlist (`setAllowlist()`)
  - Cost ceiling (`setCostCeiling(usd)`) with per-model rate (`setCostPer1k()`)
  - Per-call `max_tokens` cap (`setMaxTokensCap()`)
  - Kill switch via `KERNEL_AI_DISABLED=1` env var
  - In-memory accumulator (`recordUsage`, `accumulatedCost`, `reset`)
- **`{ai_generate}` / `{ai_query}` / `{ai_complete}`** in TemplateEngine
  - All three required: `model=`, `max_tokens=` (defaults to 200)
  - `ai_generate` body = the prompt; others use `prompt=` attr
  - Optional `let=identifier` binds result into `aiBindings()` sink
  - Without `let=`, scalar results are emitted inline (HTML-escaped)
  - `ai_query` JSON-decodes responses when valid

## Files added

```
kernel/DiSyL/Federation/ServiceRegistry.php   (44 lines)
kernel/DiSyL/AI/AiProvider.php                (21 lines)
kernel/DiSyL/AI/EchoAiProvider.php            (32 lines)
kernel/DiSyL/AI/Policy.php                    (97 lines)
tests/disyl_v46_fed_ai_test.php               (220 lines, 17 assertions)
```

## Files modified

```
kernel/App.php                          KERNEL_VERSION → 4.6.0
kernel/DiSyL/Security/CapabilitySet.php  + 'federation' tag
kernel/DiSyL/TemplateEngine.php          + serviceRegistry/aiProvider/aiPolicy props
                                         + setters + accessors + aiBindings sink
                                         + 6 new tag dispatch entries
                                         + evaluateFederatedQueryBody
                                         + evaluateAiBody
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
php tests/disyl_v46_fed_ai_test.php     → 17/17 pass
```

Total DiSyL coverage: **184 assertions, 0 failures.** Clean app.log /
error.log on full run.

## Compatibility

- **Backward compatible.** Six new tags activate only when used.
- New capability `federation` added to `CapabilitySet::ALL_TAGS` —
  `CapabilitySet::strict()` automatically denies it; `full()` allows it.
  No prior callers affected since strict mode is opt-in.

## Honest scope statement

The 4.6 design doc additionally specifies:

- **Federation:** parallel remote execution via 4.5 Scheduler (depends on
  4.5.1 Fibers); per-tenant `config/federation.php` loader; service auth
  tokens via kernel secrets manager; a richer query DSL (currently the
  `query=` string is opaque and resolver-defined).
- **AI:** PII regex redaction with placeholder/restoration; structured
  audit table `disyl_ai_calls` (template, line, prompt_hash, request_id,
  tenant_id, user_id, redactions_count, tokens, USD, cache_hit, latency,
  error); per-tenant DB-backed daily ceiling; JSON-Schema validation on
  `ai_query` outputs with policy-driven fallback; `ai_optimize` deferred
  experimentation pattern.
- **Cache integration:** per-call `cache_ttl=` attribute writing into the
  4.3 FragmentStore with a key of `{model_id, prompt_hash, schema_hash}`,
  enforcing `temperature=0` inside cached blocks for determinism.

**Implemented in 4.6.0:**
- Complete template surface for both features
- ServiceRegistry with injectable resolvers (test-deterministic)
- AiProvider interface + EchoAiProvider default (deterministic)
- Policy: kill switch + allowlist + cost ceiling + max_tokens cap
- Sandbox capabilities `federation` and `ai` enforced
- `{untrusted}` blocks both feature families
- Custom provider injection via `setAiProvider()` (test seam)
- Custom registry injection via `setServiceRegistry()` (test seam)
- Per-remote `fallback=`, per-block `policy='all-or-nothing'`
- `aiBindings()` sink for `let=` capture
- Diagnostic markers in output: `<!-- federation denied -->`,
  `<!-- ai denied: model not allowed -->`,
  `<!-- ai denied: cost ceiling -->`,
  `<!-- ai disabled: KERNEL_AI_DISABLED -->`,
  `<!-- ai denied: capability -->`,
  `<!-- federation failed: ... -->`

**Deferred to 4.6.1:**
- `cache_ttl=` attribute integration with FragmentStore (use `{cache}`
  wrapping in the meantime)
- Parallel remote execution via Fibers (sequential today; correct results,
  not yet faster)
- PII redaction pipeline with placeholder restoration
- DB-backed `disyl_ai_calls` audit table + migration
- Per-tenant cost ceiling persisted across requests
- JSON-Schema validation with structured fallback policy
- `ai_optimize` deferred-experimentation tag
- `config/federation.php` loader + per-tenant override
- Service auth-token resolution via kernel secrets manager
- `let=` propagation into the surrounding template scope (today: captured
  in `aiBindings()` sink — consume via PHP code or use inline emission)

The 4.6.0 surface is the contract templates code against. When the
deferred items land, no template changes will be required. Operators can
already harden production today via:

```php
$engine->setAiPolicy(
    (new Policy())
        ->setAllowlist(['gpt-4o-mini'])
        ->setCostCeiling(5.00)
        ->setMaxTokensCap(500)
);
$engine->setAiProvider(new MyOpenAIProvider($apiKey));
```

## Migration notes

None required. To opt in:

1. **Federation:** call `$engine->setServiceRegistry($r)` and register one
   resolver per service. Wrap calls in `{federated_query}…{/federated_query}`.
2. **AI:** call `$engine->setAiProvider(new MyProvider())`; tighten with
   `$engine->setAiPolicy(...)`. Set env `KERNEL_AI_DISABLED=1` for an
   instant production kill switch.
3. **Hardening:** wrap user-content render boundaries in `{untrusted}` —
   both features are denied automatically inside.

## 4.x line summary

This release closes the 4.x roadmap. Cumulative additions across
4.0 → 4.6:

- Single-pass control structure processor (4.0)
- Pattern matching + i18n (4.1)
- Type system v1 + CLI typecheck (4.2)
- Fragment cache + experimentation (4.3)
- Sandbox + capability scoping (4.4)
- Async runtime: parallel/await/suspense (4.5)
- Federation + AI primitives (4.6)

184 unit tests cover the surface. The 4.x line is feature-complete; the
deferred items in each release's "deferred to X.Y.1" section are the
backlog for point releases.
