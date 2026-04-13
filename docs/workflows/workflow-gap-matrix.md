# Workflow Gap Matrix

## Purpose

This document provides a compact execution matrix for the post-promotion workflow architecture.

It is intended to show:

- current state
- target state
- exact code areas to change or review
- recommended implementation order

Workflow itself is already completed. This matrix is for stabilization, cleanup, and long-tail architectural convergence.

## Summary view

| Area | Current state | Target state | Priority |
| --- | --- | --- | --- |
| Runtime ownership | Kernel runtime exists and is active | Kernel is the only authoritative runtime | High |
| Capability ownership | Kernel registers workflow capabilities | Kernel remains sole provider owner | High |
| Legacy module role | Compatibility shell exists | Compatibility shell is minimal and explicitly legacy | High |
| Migration ownership | Kernel migration/install coverage exists | Kernel is the only documented schema owner | High |
| Documentation clarity | Ownership is knowable but not yet fully explicit everywhere | Ownership is obvious to any contributor | Medium |
| Verification depth | Basic evidence exists | Focused kernel-owned verification is formalized | High |
| Removal criteria | Not yet explicit | Legacy shell retirement gates are documented | Medium |

## Detailed gap matrix

| Topic | Current state | Gap | Target state | Exact code areas | Recommended action |
| --- | --- | --- | --- | --- | --- |
| Kernel runtime authority | `kernel/WorkflowRuntime.php` contains the runtime | Runtime is centralized but not yet fully documented as sole authority | All future workflow behavior changes happen only in kernel | `kernel/WorkflowRuntime.php`, `kernel/App.php`, docs | Treat kernel runtime as authoritative and document that rule clearly |
| Capability registration | `workflow.state.get@1` and `workflow.transition@1` are kernel-registered | Need to ensure no future duplicate module provider returns | Kernel remains sole provider owner for workflow capabilities | `kernel/App.php`, `modules/workflow/module.json`, `src/helpers/module-manager.php` | Keep module manifest neutral and verify no exposed workflow capabilities are reintroduced |
| Caller context propagation | Capability bus now carries caller context into provider execution | Needs explicit verification coverage | Kernel-owned providers always receive correct caller info | `kernel/Capabilities/CapabilityBus.php`, workflow tests | Add tests for caller role and actor propagation |
| Legacy module helper surface | `modules/workflow/helpers.php` delegates some behavior but still contains helper-era logic | Compatibility shell is still fatter than ideal | Legacy file becomes near-zero logic wrapper layer | `modules/workflow/helpers.php` | Remove or migrate remaining helper logic that is not needed for compatibility |
| Legacy module identity | Module still exists in tree | Future contributors may mistake it for authoritative | Module is clearly compatibility-only | `modules/workflow/module.json`, docs | Add explicit compatibility/deprecation wording in docs or module-facing notes |
| Schema ownership | Kernel install SQL and upgrade migration now cover workflow tables | Ownership should be consistently treated as kernel-only | Kernel is sole schema authority for fresh install and upgrades | `database/migrations/001_full_schema.sql`, `database/migrations/004_bluehost_install_no_create_db.sql`, `database/migrations/006_kernel_workflow_tables.sql` | Keep workflow schema changes in kernel migration path only |
| Event ownership | Kernel runtime emits `workflow.transitioned` | Event contract is preserved but ownership should be documented | Kernel is the canonical event source | `kernel/WorkflowRuntime.php`, docs | Document workflow events as kernel-owned domain events |
| CMS dependency path | CMS consumes workflow via capabilities | Good shape, but should remain the canonical consumption example | CMS remains a capability consumer, not a runtime owner | `modules/cms/handlers.php`, `tests/workflow_cms_integration_test.php` | Preserve capability usage and add it to docs as the recommended pattern |
| Fresh install validation | SQL coverage exists | Needs formal validation pass | Fresh installs always receive workflow schema via kernel path | install SQL files, test/install scripts | Add install verification to release checklist or tests |
| Upgrade validation | Upgrade migration exists | Needs formal migration verification | Upgrades never require legacy module migration ownership | `database/migrations/006_kernel_workflow_tables.sql`, migration runner usage | Validate migration path on a pre-promotion database state |
| Legacy shell retirement | No explicit removal gate | Future cleanup could be premature or indefinite | Removal criteria are documented and auditable | docs, tests, module references | Define retirement gates and defer deletion until gates are met |

## Exact code areas to change or review next

### Kernel runtime

- `kernel/WorkflowRuntime.php`

Review for:

- separation of responsibilities
- transition validation clarity
- long-term maintainability if workflow scope expands

### Kernel bootstrap

- `kernel/App.php`

Review for:

- sole provider ownership
- seeding behavior timing
- event registration clarity

### Capability dispatch path

- `kernel/Capabilities/CapabilityBus.php`

Review for:

- caller propagation correctness
- nested capability-call safety
- compatibility with kernel-owned providers beyond workflow

### Legacy compatibility shell

- `modules/workflow/helpers.php`
- `modules/workflow/module.json`

Review for:

- remaining non-wrapper logic
- clarity that the module is no longer authoritative
- absence of duplicate capability or migration ownership

### Install and migration coverage

- `database/migrations/001_full_schema.sql`
- `database/migrations/004_bluehost_install_no_create_db.sql`
- `database/migrations/006_kernel_workflow_tables.sql`

Review for:

- consistent table definitions
- upgrade safety
- install parity across paths

### Consumer verification

- `modules/cms/handlers.php`
- `tests/workflow_cms_integration_test.php`

Review for:

- kernel-provider resolution
- state and transition correctness
- actor/role propagation behavior

## Recommended implementation order

### Phase 1

Stabilize the ownership model.

Actions:

- confirm kernel remains sole workflow capability provider
- confirm module manifest stays neutral
- confirm workflow schema changes go only through kernel migrations

### Phase 2

Minimize compatibility-shell logic.

Actions:

- reduce `modules/workflow/helpers.php` to wrappers only
- eliminate remaining duplicated helper behavior where safe

### Phase 3

Deepen verification.

Actions:

- add targeted tests for caller propagation
- add targeted tests for transition event emission
- validate fresh install and upgrade schema paths

### Phase 4

Document retirement criteria.

Actions:

- define when `modules/workflow` can be archived or removed
- document what evidence is required before deletion

## Priority ranking

### High priority

- kernel remains sole capability owner
- compatibility shell is minimized
- migration/install ownership remains kernel-only
- verification covers the kernel-owned path explicitly

### Medium priority

- documentation makes ownership obvious
- event ownership is described clearly
- legacy shell retirement criteria are defined

### Low priority

- deeper internal refactoring of `WorkflowRuntime` into smaller collaborators
- optional archival mechanics for the legacy module

## Exit criteria

This gap matrix is satisfied when:

- workflow is unmistakably kernel-owned in practice and documentation
- `modules/workflow` is a thin compatibility shell only
- install and upgrade paths are kernel-owned and validated
- downstream consumers use workflow through capability contracts
- removal criteria for the legacy shell are documented

## Final note

The key architectural point is simple:

Workflow no longer needs feature-module ownership.

The remaining work is convergence work:

- simplify
- document
- verify
- define retirement gates

That is the correct next step after a successful promotion to a kernel primitive.
