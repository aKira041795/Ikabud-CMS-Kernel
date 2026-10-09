# ADR-006: Kernel Registration / Implementation Separation

## Status
**Proposed (2026-10-09).** This is an architectural decision, **not** implementation approval. It states
the direction and the evidence required before implementing it. The measurements in
[Open questions that gate implementation](#open-questions-that-gate-implementation) come first; until they
are taken, the performance claim behind this ADR is unproven and the ADR must not be cited as if it were.

Informed by an external review of the measurements. That text sits at
`.ai/consult/kernel-perf-module-load-reply.md`, which is **gitignored** (`.gitignore:119` excludes `.ai/*`),
so this ADR deliberately does not depend on it: the conclusions it changed are restated below, and the
central measurement is reproducible with `php tools/chair-module-registration-audit.php`.

## Context

Every request calls `loadModuleRoutes()` (`public/index.php:490`), which loops over **all** enabled
modules and calls `loadModuleHelpers()` on each **before any route is matched**. The handler is resolved
from the merged route table afterwards.

**Measured, not inferred:**

1. **68 modules are enabled.** Including every module's `helpers.php` costs ~310 ms locally with OPcache
   off, against **7.24 ms** in the web SAPI for the same ~5 MB — a ~40× difference, i.e. that cost is
   largely compilation. The live host (`kernelappos.ikabudkernel.com`, PHP 8.3.35) reports **64.06 ms for
   347 files / 5,007 KB**, which is 8.8× *slower* than local-with-OPcache while being **faster at every
   other phase** (module discovery 10.49 vs 116.73 ms; contract drift 1.24 vs 80.67 ms; route match 7.93
   vs 34.67 ms). A slow filesystem would slow the directory walks too. That asymmetry points at
   compilation, and it is unconfirmed — see gate 1.
2. **16 of the 68 modules register something at include time**, measured by diffing EventBus listeners,
   Hooks listeners and user constants around each include (reproduce: `php tools/chair-module-registration-audit.php`):
   - `cms` — 10 events (`cms.content.created/updated/deleted/published/bulk`,
     `cms.builder.document.saved/published/restored`, `cms.settings.updated`, `workflow.transitioned`),
     hooks `kernel.request.before_dispatch`, `kernel.render_context.finalize`, `kernel.home_url`,
     `kernel.auth_cookie_names`, `cms.settings`, plus 7 constants
   - `ecommerce` — 7 `ecommerce.*` events, hooks `kernel.user_service_context`, `cms.admin.nav_items`,
     14 constants
   - `search` — listeners on `cms.content.updated/deleted/published` (this is what reindexes content)
   - `contact-form`, `moodle-integration`, `content-ingestion` — `cms.admin.nav_items` (their admin nav
     entries); `gui-settings` — `kernel.gui_context`; six more register `kernel.home_url`
3. **The kernel cannot know which modules are "in use" without including them.** A module's value is not
   its route: skip `search` and content silently stops reindexing; skip `contact-form` and its admin nav
   entry disappears. No error, no log line.
4. **Each `helpers.php` is an auto-generated require-manifest**: cms's is 1.8 KB / 37 lines and pulls in
   28 files (~1.7 MB). The cost is the transitive library, paid for every module on every request.
5. **`require_once` is currently doing three unrelated jobs**: making definitions available, registering
   behaviour, and **accidentally satisfying dependencies between modules**. Module A calling module B's
   function works only because B happened to be included. The eager loader is hiding a dependency graph.
6. **Capability handlers are resolved by naming convention** (`<module>_capability_handlers()` discovered
   by name in the registration loop), so `function_exists()` is today the source of truth for whether a
   capability exists.
7. **Composition state is observable.** The perf probe counts newly included files via
   `get_included_files()`; modules and templates read global constants. Lazy loading changes what any such
   consumer sees.

## Decision

**Every module contribution to kernel composition must be discoverable without loading the module's
implementation library.** The kernel owns registration, ordering, provider resolution, and deferred
handler activation.

Sub-decisions:

1. A module declares its contributions as **data** — a descriptor (e.g. `registrations.php` returning a
   literal array) specifying schema, handler identity, ordering, visibility, ownership and resolution
   rules.
2. Reading a descriptor registers handlers **without executing their implementation files**. The kernel
   resolves handlers lazily and must **not** call `function_exists()` / `is_callable()` at registration
   time: doing so either forces eager loading or rejects a valid lazy handler.
3. **Capability availability comes from the declared catalog, never from `function_exists()`.**
4. **Cross-module dependencies are declared** (capability/contract), not satisfied by incidental
   inclusion. A file-level "requires module X implementation" bridge is permitted only as a migration
   mechanism, never as the target API.
5. **Registration order is deterministic** and part of the contract.
6. Deployment **may** compose declarations into a deterministic, cacheable artifact **scoped to the
   applicable active-module configuration**. That artifact is **derived data, not the authoritative
   source**, and a deterministic runtime fallback must remain.

**Compatibility rule.** Legacy modules may retain eager initialization. A migrated module must satisfy the
explicit registration contract and pass differential behavioural verification **before** legacy loading is
disabled for it. Nothing is switched off globally.

## Alternatives Considered

- **Lazy inclusion decided by static source scanning.** Rejected. Two static analyses of this question were
  wrong in opposite directions in one session: a comment-blind grep flagged all 13 `cms-akira` modules on
  `->listen(` hits that are **commented-out examples**, and a tokenizer that dropped function bodies then
  **missed a real top-level listener** in `modules/search/helpers.php` because a `{$var}` inside a string
  emits a `}` that corrupts brace tracking. A classifier that cannot be trusted cannot gate behaviour.
  Instance-level audits remain useful as evidence of *which* modules register; they are not a migration basis.
- **Load only the module whose route matched.** Rejected as insufficient and unsafe: the 52 non-registering
  modules are only **~27–30% of the include cost** (two runs: 27.2% and 29.5% — the share varies run to run,
  so it is stated as a range), and the eager loader is currently the only mechanism making cross-module
  function calls work.
- **Keep eager loading and merely make the include cheaper** (aggregate manifests, OPcache preload).
  Insufficient alone — it does not address the hidden dependency graph — though it may be the correct
  *first* move if gate 1 confirms a host-side cause.
- **Optimize the next phase instead** (route scanning/merging/matching, tenant resolution). Deferred:
  these are hypotheses, not measured bottlenecks. Route matching must not be optimized, and no new cache
  added, until post-migration phase measurements show one of them matters.
- **Serialize executed registration state as the long-term design.** Rejected: it freezes accidental
  behaviour into an artifact.

## Consequences

### Positive
- Per-request composition becomes proportional to what the request needs, not to the installed fleet.
- The cross-module dependency graph becomes explicit and checkable instead of accidental.
- A request that never touches a module stops paying for it.

### Negative
- A new module contract and a migration across modules, with a legacy path to maintain meanwhile.
- **First-use loading moves cost into handler activation.** A request that uses cms heavily may pay the
  same total; the gain is what requests *avoid*, not what moves.
- `function_exists()`, `get_included_files()` and global constants stop being reliable for unloaded
  modules — **including the perf probe's own include-count metric**, which will need to report what it can
  see rather than a total that no longer exists.
- Long-lived CLI workers retain loaded definitions and singletons, so composition must not leak
  tenant-dependent state between jobs in such a process.

### Neutral
- The compiled catalog is an optimization of the decision, not the decision.

## Open questions that gate implementation

1. **Is the compilation hypothesis true on the live host?** The probe now reports
   `OPcache scripts compiled this request` — one reload answers it. **If OPcache is the cause, host
   configuration is the larger and cheaper lever and this ADR is not the first thing to do.**
2. **`T_composition`, `T_implementation_load` and `T_total_request`, measured separately.** Moving work
   from startup to handler invocation does not remove it. Without these three, "64% deferrable" is an
   architectural opportunity, not a latency claim.
3. **A request-to-module utilization matrix**: per request class, which implementations are included but
   never used (the prize) versus used by nearly every request (deferral buys nothing). Module count is a
   poor predictor; utilization is the right one.
4. **Is there one central event/hook registration API?** The migration's feasibility depends on it.
5. **Can the consumers of `function_exists()` / `get_included_files()` / global constants be migrated**, or
   must they be declared outside the compatibility contract? If literal equivalence of every PHP-visible
   state is required, **broad lazy loading is not possible**, and this ADR must be rewritten rather than
   approximated.

## Acceptance for the eventual migration

- Identical registry entries **and ordering**.
- Identical route resolution over the golden corpus (`routes=1968 raw_sha=cf47a110930f5733`).
- Identical observable handler outcomes over a defined corpus.
- No unauthorized registration-state change after composition.
- Equivalent tenant isolation and authorization outcomes.
- No new uncaught resolution failures.
- No material latency or memory regression beyond agreed thresholds.
- **Negative cases included**, not only positive ones: an unrelated event must *not* trigger a module's
  handler (e.g. `search` must not reindex on an event it does not listen for), or the test cannot detect an
  over-broad registration.

## Phasing (only after the gates above)

1. Define the registration protocol (schema, handler identity, ordering, visibility, ownership, resolution).
2. **Shadow catalog** — the collector runs *alongside* the eager loader, snapshots compared, eager path
   stays authoritative.
3. Migrate **one low-risk registering module** to validate the resolver and the contract.
4. Migrate **`search`** as the correctness-sensitive test (reindexing propagation plus negative cases).
5. Migrate **`cms` and `ecommerce`** incrementally, retaining legacy eager files meanwhile.
6. Strict-contract certification: reject undeclared registrations, validate cross-module dependencies,
   keep explicit compatibility treatment for legacy modules.
7. Compile the composition catalog (fingerprint, atomic publication, tenant-sensitive selection,
   descriptor-based fallback).
8. Roll out by request class and tenant, keeping rollback to the legacy composition path.

## Reopening trigger

Reopen this ADR if:

- the utilization matrix shows the deferred implementations are needed by most requests, so the prize is
  small;
- **no central registration API exists** and a descriptor would have to wrap arbitrary executable
  initialization — then the contract cannot be enforced and the decision must be revisited rather than
  approximated;
- gate 1 shows the live cost is host configuration, in which case the host fix comes first and this ADR's
  urgency drops.

## Related ADRs

[ADR-001-module-communication.md](ADR-001-module-communication.md) ·
[ADR-002-cms-is-module-not-kernel.md](ADR-002-cms-is-module-not-kernel.md) ·
[ADR-003-reads-tables-alongside-capabilities.md](ADR-003-reads-tables-alongside-capabilities.md) ·
[ADR-005-one-assortment-per-branch.md](ADR-005-one-assortment-per-branch.md)
