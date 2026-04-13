# Workflow Kernel Review

## Purpose

This document formalizes the architectural review of workflow after promotion from `modules/workflow` into a kernel-owned primitive service.

The goal of this review is to capture:

- current architecture
- strengths of the current iteration
- remaining gaps
- follow-up priorities
- decision guidance for future kernel and module cleanup

This is a review artifact, not an implementation plan for rebuilding workflow. Workflow is already completed and operational.

## Executive summary

Workflow now fits the platform direction better as a kernel primitive than as a regular module.

The current iteration successfully establishes:

- kernel-owned runtime behavior
- kernel-owned capability registration
- preserved capability IDs for existing callers
- compatibility wrappers for legacy module-level entry points
- migration/install coverage moving under kernel ownership

This is a meaningful architectural improvement because workflow behaves like a shared platform service rather than a feature module.

The current state is good enough to serve CMS and other consumers, but it is not yet the cleanest final form. The main remaining work is cleanup, boundary tightening, lifecycle documentation, and removal criteria for the legacy `modules/workflow` shell.

## Review scope

Reviewed areas:

- `kernel/WorkflowRuntime.php`
- `kernel/App.php`
- `kernel/Capabilities/CapabilityBus.php`
- `modules/workflow/helpers.php`
- `modules/workflow/module.json`
- `tests/workflow_cms_integration_test.php`
- `database/migrations/001_full_schema.sql`
- `database/migrations/004_bluehost_install_no_create_db.sql`
- `database/migrations/006_kernel_workflow_tables.sql`
- current CMS workflow capability consumption

Out of scope:

- redesigning workflow semantics
- changing capability IDs
- changing CMS workflow behavior contracts
- introducing a new workflow DSL

## Current architecture

### Kernel ownership

Workflow runtime is now implemented in `kernel/WorkflowRuntime.php`.

The kernel owns:

- workflow definition lookup
- workflow instance creation and retrieval
- allowed action resolution
- state reads
- state transitions
- transition event emission
- default CMS workflow seeding

### Kernel registration

`kernel/App.php` registers:

- `workflow.state.get@1`
- `workflow.transition@1`

These are now registered by the `kernel` provider, making workflow a first-class primitive service.

### Caller compatibility

`kernel/Capabilities/CapabilityBus.php` propagates resolved caller context during provider invocation.

This matters because existing consumers such as CMS pass:

- `caller_module`
- `caller_user`

Kernel-owned workflow handlers can now continue to resolve caller role and actor identity correctly.

### Legacy module compatibility shell

`modules/workflow` still exists, but its role is now compatibility-oriented.

The legacy helper entry points delegate into `app()->workflow()`.

The module manifest no longer owns:

- workflow tables
- workflow migrations
- workflow capability providers
- workflow events

This avoids duplicate registration and duplicate ownership while preserving older direct helper call sites.

## Strengths of the current iteration

### Strong architectural alignment

This change aligns workflow with the kernel-first direction of Ikabud.

Workflow is a cross-module primitive, so kernel ownership is a better fit than keeping it in a single feature module.

### Backward compatibility preserved

The promotion preserved the external contracts that matter most:

- `workflow.state.get@1`
- `workflow.transition@1`

This protects current consumers and avoids forced downstream rewrites.

### CMS integration remains viable

CMS can continue to call workflow through the capability bus without changing its business contract.

That is the correct compatibility behavior for a primitive-service promotion.

### Install and upgrade story is improved

Kernel-level schema ownership now exists for:

- fresh installs
- explicit upgrade migration path
- install dump coverage

That reduces reliance on the legacy module migration path.

### Event flow remains coherent

The `workflow.transitioned` event remains part of the system, but now originates from the kernel-owned runtime.

That is the right ownership boundary for workflow domain events.

## Current weaknesses and architectural debt

### Legacy helper file still contains duplicated logic shape

Although the legacy capability entry points now delegate into the kernel runtime, `modules/workflow/helpers.php` still contains helper-era logic such as `workflowAllowedActions(...)`.

That is not harmful by itself, but it leaves more logic in the compatibility shell than is ideal.

The desired end state is for the compatibility shell to be as thin as possible.

### Runtime logic is serviceable but still fairly direct

`kernel/WorkflowRuntime.php` currently centralizes behavior successfully, but it is still a fairly monolithic service class.

Possible future refinements:

- separate repository-style data access
- separate definition normalization
- separate transition policy evaluation
- separate event emission adapter

This is not urgent, but it matters if workflow scope grows.

### Policy model is preserved, but not yet deeply formalized

The current iteration preserves caller policy behavior through kernel registration and caller propagation.

However, workflow-specific access policy is still relatively implicit compared with a stronger contract-oriented design.

If more modules depend on workflow, policy rules should become more explicit and easier to audit.

### Legacy module removal criteria are not yet documented

The system now has a compatibility shell, but there is no formal removal gate documenting when `modules/workflow` can be:

- frozen permanently
- archived
- removed

That should be documented before future cleanup work starts.

## Primary risks

### Drift between kernel runtime and compatibility shell

If future changes touch workflow semantics in the kernel but compatibility wrappers are not kept minimal, stale helper behavior could re-emerge.

### Incomplete migration assumptions on older installs

The kernel now owns workflow schema going forward, but older systems may still carry historical assumptions tied to the legacy module.

That makes upgrade validation important.

### Boundary confusion for future contributors

A future contributor may still treat `modules/workflow` as authoritative unless the documentation clearly states that workflow is kernel-owned and the module is compatibility-only.

## Recommended follow-up priorities

### Priority 1

Document the authoritative ownership model.

Specifically:

- workflow runtime lives in kernel
- module shell is compatibility-only
- capability IDs remain stable
- schema ownership is kernel-owned

### Priority 2

Minimize the legacy helper surface further.

Goal:

- keep only wrapper functions required for compatibility
- avoid maintaining duplicate business logic in `modules/workflow/helpers.php`

### Priority 3

Add explicit deprecation and retirement criteria for the legacy module shell.

### Priority 4

Add focused verification around:

- fresh install schema
- upgrade migration path
- CMS transition behavior
- event emission behavior
- caller-role resolution behavior

## Recommended final target state

Workflow should be treated as a stable kernel primitive with these characteristics:

- authoritative runtime in kernel
- authoritative registration in kernel
- authoritative migrations in kernel
- compatibility-only legacy module shell
- documented downstream consumption pattern through capabilities
- explicit retirement criteria for the legacy module shell

## Conclusion

The current iteration is a successful architectural promotion.

Workflow is now in the correct layer of the system and is materially closer to the intended Ikabud platform model.

The remaining work is not about rebuilding workflow. It is about tightening the ownership boundary, simplifying the compatibility shell, and documenting the end-state operating model clearly enough that future changes do not reintroduce module-owned behavior.
