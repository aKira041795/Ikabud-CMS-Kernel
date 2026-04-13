# Workflow Next-Iteration Spec

## Purpose

This document defines the next iteration for the legacy `modules/workflow` area after workflow has already been completed and promoted into the kernel.

This is not a spec to re-implement workflow.

This is a spec to:

- stabilize the new kernel-owned workflow model
- minimize compatibility debt in `modules/workflow`
- define deprecation boundaries
- reduce future ownership confusion

## Context

Workflow is now a kernel primitive.

Authoritative ownership has moved to the kernel for:

- runtime behavior
- capability registration
- caller-context-aware execution
- event emission
- migration/install ownership

The remaining `modules/workflow` surface exists only to preserve compatibility for older helper-style entry points and existing assumptions in the codebase.

## Non-goals

This iteration must not:

- redesign the workflow model
- change workflow capability IDs
- change CMS workflow semantics
- introduce a new workflow DSL
- move workflow back into a module

## Desired end state

At the end of the next iteration, `modules/workflow` should be clearly and narrowly defined as a compatibility shell.

That means:

- no authoritative runtime logic in the module
- no capability registration in the module
- no table ownership in the module
- no migration ownership in the module
- no module-owned event registration for workflow domain events
- only minimal wrappers kept for backward compatibility
- documented removal criteria

## Authoritative ownership model

### Kernel owns

- `kernel/WorkflowRuntime.php`
- workflow state and transition behavior
- capability registration in `kernel/App.php`
- event emission for `workflow.transitioned`
- kernel migration/install coverage

### Legacy module owns

- compatibility wrappers only
- optional transitional documentation or notice surfaces if needed later

### Consumers should use

- `app()->cap()->call('workflow.state.get@1', ...)`
- `app()->cap()->call('workflow.transition@1', ...)`

Consumers should not treat `modules/workflow/helpers.php` as the authoritative implementation surface.

## Required next-iteration outcomes

### Outcome 1

Reduce `modules/workflow/helpers.php` to the thinnest practical wrapper layer.

Target behavior:

- wrapper functions call `app()->workflow()` directly
- no duplicated DB mutation logic remains in the compatibility file
- no duplicated event-emission logic remains in the compatibility file

### Outcome 2

Document the compatibility status of `modules/workflow`.

The codebase should make it obvious that:

- workflow is kernel-owned
- the module is preserved for compatibility only
- new workflow behavior must be added in the kernel, not in the module

### Outcome 3

Formalize legacy removal criteria.

Removal criteria should define when the shell can be:

- archived
- disabled by default
- deleted entirely

### Outcome 4

Expand verification coverage around the kernel-owned workflow path.

This should cover:

- capability success paths
- caller-role propagation
- fresh install schema availability
- upgrade migration availability
- event emission after transition
- compatibility wrapper behavior

## Proposed compatibility-shell shape

The preferred final shape of `modules/workflow/helpers.php` is:

- `workflowEnsureCmsContentWorkflow()` delegates to kernel
- `workflowGetDefinition(...)` delegates to kernel
- `workflowGetOrCreateInstance(...)` delegates to kernel
- capability wrapper functions delegate to kernel
- any remaining pure helper utilities are either:
  - removed if unused
  - moved to kernel if they are part of authoritative behavior
  - kept only if they are legacy compatibility shims

## Documentation requirements

The next iteration should leave behind clear documentation in the repo for future contributors.

At minimum, documentation should state:

- workflow is a kernel primitive
- capability contracts remain stable
- legacy module exists for backward compatibility only
- kernel is the only correct place for future workflow behavior changes

## Verification requirements

### Functional verification

- `workflow.state.get@1` resolves through the kernel provider
- `workflow.transition@1` resolves through the kernel provider
- CMS still performs state reads and transitions successfully
- `workflow.transitioned` still emits after successful transition

### Compatibility verification

- legacy helper entry points still behave consistently
- no duplicate provider registration occurs
- no module-owned workflow migration path is required for new installs

### Install verification

- fresh install receives workflow tables via kernel SQL
- upgrade path receives workflow tables via kernel migration

## Deprecation policy for `modules/workflow`

### Stage 1

Compatibility shell retained.

Characteristics:

- module remains in tree
- no runtime ownership
- no schema ownership
- no provider ownership

### Stage 2

Compatibility shell marked as legacy.

Characteristics:

- explicit docs label it as legacy
- internal contributors are directed to kernel APIs only
- wrapper count is minimized

### Stage 3

Removal candidate.

Only when all are true:

- no supported code paths rely on direct workflow module helper calls
- tests cover kernel-owned workflow behavior directly
- no installer or admin flows expect module-owned workflow identity
- documentation clearly points only to kernel ownership

## Recommended implementation order for this iteration

1. Minimize the wrapper surface in `modules/workflow/helpers.php`
2. Add or refine compatibility-status documentation
3. Add focused verification coverage for kernel-owned behavior
4. Define explicit removal criteria for the legacy shell
5. Optionally archive the module in a later cleanup phase

## Acceptance criteria

This iteration is complete when:

- `modules/workflow` no longer appears to be an authoritative runtime owner
- contributors can identify kernel ownership immediately
- the compatibility shell is thin and stable
- verification covers both kernel behavior and legacy wrapper compatibility
- the codebase has a documented path for future removal of the shell

## Summary

The next iteration for `modules/workflow` is a stewardship and cleanup pass.

Workflow itself is already complete.

The remaining job is to make the compatibility layer intentionally small, clearly documented, and safe to retire later without ambiguity.
