---
description: Release notes for Ikabud Kernel 3.2.0 "lattice"
---

# Kernel 3.2.0 — "lattice"

**Release date:** 2026-05-08
**Codename:** lattice (succeeds 3.1.0 "clarity")
**Type:** Minor — additive, no breaking API changes.

## Highlights

This release is a documentation-and-hygiene cycle following the Phase 1–4B hardening shipped in 3.1.0. No public APIs were removed. Several internal cleanups reduce surface area and align docs with code.

## Changes

### Versioning
- Bumped `App::KERNEL_VERSION` from `3.1.0` to `3.2.0`.
- Renamed codename `clarity` → `lattice`.
- Synced `kernel/App.php` `@version` doc-block from stale `3.0.0` to `3.2.0`.

### Hooks & events documentation
- `kernel/Hooks.php` doc-block now documents two previously undocumented surfaces:
  - `kernel.database.query.before` (filter) — inspect/modify SQL + params before execution
  - `kernel.database.query.after` (event, EventBus) — emitted after each query with timing + row count
- Bumped `kernel/Hooks.php` doc-block `@version` from `1.0.0` to `1.1.0`.

### DiSyL hygiene
- Removed the aspirational `kernel/DiSyL/_future/DiSyLEngine.php` (v11.1 design stub that threw on call).
- Relocated to `docs/research/disyl/DiSyLEngine-v11.1-research.php` for archival/reference.
- DiSyL schema/compiler versions unchanged: `SCHEMA_VERSION = 4.0.0`, `COMPILER_VERSION = 7`.

### Roadmap
- Marked Phases 1–4B as **COMPLETED** in [docs/kernel/ikabud-roadmap.md](../kernel/ikabud-roadmap.md), with citations to the 2026-04-01 hardening notes and 2026-04-16 DiSyL performance notes.

### Module graduations
- `bakeshop` graduated from `0.1.0` to `1.0.0` (production-ready: 12 migrations, full auth, complex operations).

### Phase 6 — Developer Platform Experience (partial)
- New module scaffolder: [scripts/scaffold-module.php](../../scripts/scaffold-module.php) generates a valid module skeleton (`module.json`, `routes.php`, `handlers.php`, smoke test) in one command.
- Expanded manifest guard ([scripts/guard-module-manifests.php](../../scripts/guard-module-manifests.php)):
  - validates that the manifest's declared routes file exists,
  - warns on `owns_tables` collisions across modules (error in `--strict`),
  - warns on non-semver `version` strings.
- New developer guide: [docs/kernel/choosing-the-right-primitive.md](../kernel/choosing-the-right-primitive.md) — canonical decision flow for capability vs event vs hook vs trigger vs listener.

### Phase 5 — Workflow Runtime (status only)
- No code changes; `WorkflowRuntime` continues to expose `registerCaller()`, `declaredEvents()`, `capabilityPolicy()`, and schema accessors.
- Roadmap marked **PARTIAL**; formal definition model + replay tooling deferred.

### Phase 7 — Productization (deferred)
- Marked **DEFERRED** in the roadmap; requires product direction + UI work beyond a single release cycle.

## Compatibility

- **No breaking changes.** All public contracts (CapabilityBus, ModuleContext, IntegrationBridge, EntityAuthority, EntityContext, Hooks, EventBus, DiSyL TemplateEngine) preserve their 3.1.0 signatures.
- The deprecated `_kernel_db_unguarded` escape hatch (KernelPDO) is still honored with a warning. Removal targeted for kernel **4.0.0**.

## Known follow-ups (deferred)

- Finalize non-final kernel classes (`ModuleContext`, `App`, `EventBus`, `Hooks`, `JWT`, `SecurityHeaders`) — pending a sweep verifying no in-tree subclassing.
- Add TTL or invalidation to `TenantResolver::$controlHostCache` for long-lived workers.
- Resolve `wordpress-importer` duplication between `modules/` and `packages/`.
- Add baseline tests for under-tested modules: `sms`, `tinymce`, `gui-settings`.
- Move PLANNED grammar keyword stubs out of `kernel/DiSyL/Grammar.php` into a separate `Grammar/Planned.php` reference.

## Verification

- `php -l` clean on all touched files.
- No code references to the removed `_future/DiSyLEngine` outside the file itself.
- DiSyL test suites unaffected (no engine behavior changed).
