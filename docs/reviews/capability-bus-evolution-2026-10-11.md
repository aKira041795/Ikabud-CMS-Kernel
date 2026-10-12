# Capability bus evolution — chair consult summary & verification

**Date:** 2026-10-11
**Sources:**
- Chair consultation, session `capability-bus-improvement` — full transcript: [`.ai/consult/capability-bus-improvement.md`](../../.ai/consult/capability-bus-improvement.md) *(gitignored)*
- Verification pass over the repository the same day (file:line evidence below). Chair advice checked against code where possible.

**One-line verdict:** the capability bus is mature (circuit breaker, retry, metrics, schema validation, signed polyglot dispatch, persisted default-deny authorization); the highest-value next improvement is **provider-resolution truth** — making declarations, readiness, eligibility and authorization *independently explainable* — while **deferring** ADR-006's lazy-loading migration until its benefits are measured.

---

## 1. Where the bus stands today (verified)

| Piece | Status | Evidence |
|---|---|---|
| `CapabilityBus` — circuit breaker, retry, metrics, schema validation | Shipped | `CapabilityBus.php:13`; validation at `:729-834`; **mode defaults to `warn`** (`schema_validation_mode`, `:767`) — enforcement is opt-in |
| `CapabilityRegistry` + `CapabilityCatalog` (read-model merging runtime registry with manifest `exposes`/`depends`/`events`) | Shipped | repo memory `capability-catalog-read-model.md`; admin API serves from catalog |
| `CapabilityAuthorizationRegistry` — persisted, versioned, default-deny, fail-closed, audit | Shipped (6.2) | fail-closed throw paths at `:131/173/240`; tests `capability_authorization_registry_test.php`, `capability_authz_module_context_test.php` |
| `ServiceProxy` v2 — signed polyglot dispatch, nonce, ≤300s skew, fail-closed | Shipped (6.2) | roadmap status; `service_proxy_v2_test.php` |
| `AuthorityScope` / `AuthorityScopeResolver` | Recent (declared authority scope) | commit "kernel: declare authority scope" |
| Handler registration | **Map or convention** — `<module>_capability_handlers()` OR `<module>_cap_<id>`; availability via `function_exists()`/`is_callable()` | `module-routes.php:152`; C3b in `module-manager.php` |
| Load model | **Eager** — all enabled modules' helpers load before route match; 9.79 ms warm include; discovery 10.59 ms warm / 53.45 ms cold | ADR-006 measurements |

**Verified gap:** `kernel/Capabilities/` contains **no** resolution diagnostics — 0 matches for `reason_code|explain|diagnos|readiness|why`. The bus can dispatch, but it cannot explain *why* dispatch would succeed or fail for a given tenant/caller/provider.

## 2. Chair verdict (advice)

| Decision | Verdict | Why |
|---|---|---|
| **Provider-resolution truth** (catalog truth + diagnostics) | **GO — highest next value** | Make declarations, readiness, eligibility and authorization independently explainable |
| **Discovery caching** | **BENCHMARK** | Discovery (10.59 ms warm / 53.45 ms cold) is now the larger measured cost — but only with correct invalidation + deterministic fallback |
| **ADR-006 lazy activation** | **DEFER** | Requires utilization, side-effect completeness, parity and material performance evidence; gate 1 (OPcache) removed the urgency |

Governing principle the chair proposes adding to the architecture contracts:

> *"A manifest establishes what a module claims to provide. The kernel establishes whether that contribution is eligible, executable, and authorized. No implementation-loading technique may weaken those distinctions."*

### Option comparison (condensed)

| Option | Value | Risk | Failure mode if done wrong |
|---|---|---|---|
| A. ADR-006 declared registration + lazy activation | High eventual scalability | High | Missing hooks, changed registration order, invisible globals |
| B. Cache discovery, keep eager loading | Immediate latency | Low–medium | Stale catalog, stale tenant activation |
| C. Provider-resolution & introspection | **High correctness/ops value** | Low–medium | Catalog reports providers that dispatch cannot use |
| D. Wider schema enforcement | Stronger contracts | Medium | Legitimate payloads rejected — note: mechanism exists, default is warn |
| E. Authorization policy improvements | High if a gap is demonstrated | Medium–high | Tenant privilege leakage — a demonstrated allow outranks introspection work |

### Ecosystem lesson (chair)

- **OSGi** — registration, satisfaction and activation are *separate states*; declared ≠ usable.
- **VS Code / Eclipse** — contributions are visible before executable code runs (Ikabud already has this via `module.json`).
- **Drupal** — cache *definitions* deterministically; never cache the current policy decision as if it were a definition.
- **WordPress** — registration order and include-time side effects are observable contracts; removing an include can remove a contribution. This is why ADR-006 is a behavior change, not an optimization.

## 3. Recommended next improvement — Capability Resolution & Readiness Contract

Introduce three separately-answerable questions instead of one "available" boolean:

| Assertion | Meaning | Evidence source |
|---|---|---|
| **Declared** | The manifest claims the module exposes it | validated manifest |
| **Eligible / Ready** | Provider selectable; implementation or service binding valid | registry, resolution rules, dependency checks |
| **Authorized** | This caller may invoke it in this tenant | *current* policy — contextual, never permanent |

A read-only diagnostic record (illustrative) exposes: declaration validity, selected provider, module enabled, dependency status, implementation status, policy status, readiness, and a `reason_code` — produced by **the same decision functions as dispatch**, caller-scoped so ordinary tenants cannot enumerate other tenants' providers or policies. Critical invariant: **introspection must not become a parallel execution path with weaker checks.**

### Sequence with gates (chair)

| Gate | Work | Pass condition |
|---|---|---|
| 0 | Truth baseline — capture declarations, registry, selection outcomes, ordering; inventory `function_exists()`/`is_callable()`/`get_included_files()`/constants consumers | reproducible baseline; zero unexplained discrepancies (read-only) |
| 1 | Normalize declared provider metadata (identity, versions, dependencies, deterministic ordering) | deterministic catalog output; parity with current effective resolution |
| 2 | Resolution diagnostics using the same core decision functions | every dispatch outcome reconciles with diagnostics, incl. negatives; **no capability becomes newly callable** |
| 3 | Benchmark discovery caching (manifest/definition level only — never tenant enablement or authorization) | byte-equivalent definitions; correct invalidation + fallback; improvement exceeds noise |
| 4 | Harden authorization/schema boundaries (negative matrix: cross-tenant, cache invalidation, version change, malformed payloads, v2 downgrade) | zero unauthorized allows, zero silent v2 downgrades |
| 5 | Reconsider lazy activation **only if justified** | identical registry entries + ordering, golden route corpus `routes=1968 raw_sha=cf47a110930f5733`, all negatives passing, material repeatable savings |

### ADR-006 parts adoptable now vs deferred

| ADR-006 sub-decision | Recommendation |
|---|---|
| Manifest-driven contribution discovery | Adopt — initially shadow/read-only |
| Catalog as authoritative declaration source | Adopt — but declaration ≠ executable readiness (C3b still needs executable evidence; service modules need transport checks) |
| Deterministic registration order | Adopt, preserving existing observable ordering |
| No reliance on `function_exists()` for declared existence | Adopt semantically — keep callable verification for PHP execution |
| Cacheable derived composition artifact | Prototype only after gates 1–3 |
| Demand-driven loading / eliminating eager side effects | **Defer** |

## 4. Anti-case and falsifier (chair)

**Anti-case:** ADR-006 risks spending *correctness* capital to recover an unproven amount of performance — 9.79 ms warm include is the upper bound of the direct saving, not a prediction, and discovery is already the larger component. If the migration silently drops one business event handler, the performance win is irrelevant.

**ADR-006 becomes clearly worthwhile when:** typical requests use substantially fewer implementations than are loaded; every required contribution has a reproducible declaration and deterministic initialization strategy; representative warm requests improve ≥ ~10% (proposed investment criterion, adjustable); semantic equivalence is zero-diff (registry entries, event order, routes, authorization outcomes); cache-free fallback works on shared hosting; cross-tenant negative tests stay green.

The key falsifier is a representative prototype showing measured savings survive the added composition + activation costs.

## 5. Evidence status

- **Verified in-repo this session:** schema validation + warn-by-default mode; absence of any diagnostics/readiness machinery; registration map/convention + `function_exists()` availability; catalog read-model; authorization fail-closed paths; measurement figures from ADR-006; golden route corpus invariant.
- **Chair-acknowledged limitation:** the consult could not cite path:line (context pack limit) and labelled its resolution-record schema as illustrative. Treat the mechanics above as verified; treat the *proposed* states/records as design advice.
- **Not established:** request-to-module utilization matrix; total composition vs implementation-load split; exhaustive inventory of include-time side effects; central registration-API existence. These remain ADR-006's open gates.

## 6. Artifacts

- Full transcript (resumable via `--session capability-bus-improvement`): `.ai/consult/capability-bus-improvement.md`
- ADR-006 (proposed, not approved): `docs/architecture/decisions/ADR-006-registration-implementation-separation.md`
- Companion settings review: `docs/reviews/settings-change-stability-2026-10-11.md`
