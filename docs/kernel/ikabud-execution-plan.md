---
description: Execution plan for the next Ikabud platform milestones
---

# Ikabud Platform Execution Plan

**Author:** Noah C. Omamalin

> **Status:** All milestones (A–E) are shipped as of Kernel OS 6.0. See [kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md) for the complete implementation status. This plan is retained as the historical design document.

## Purpose

This execution plan turns the Ikabud platform roadmap into a practical delivery sequence with milestones, workstreams, and acceptance criteria.

## Planning Assumptions

- The kernel remains the authoritative owner of contracts, capability dispatch, event wiring, and module governance.
- Module APIs should become more explicit over time, not more magical.
- Observability and diagnostics are part of platform correctness, not optional extras.
- Backward compatibility matters when replacing current capability and trigger behavior.

## Milestone A — Deterministic Capabilities and Registry

### Objective

Create a canonical capability runtime that is explicit, inspectable, and safe to evolve.

### Workstreams

- registry model for capabilities, providers, modes, and policies
- explicit module-local capability exports
- deterministic provider resolution reporting
- structured capability diagnostics
- CLI inspection support

### Deliverables

- canonical capability registry service
- provider export contract for modules
- capability inspection command or equivalent runtime report
- structured logs for registration and resolution failures

### Acceptance Criteria

- every active capability can be traced to one or more explicit providers
- provider ordering is deterministic and explainable
- migrated modules no longer rely on shared global handler state
- registration failures identify module, capability, and reason clearly

### Exit Conditions

- active provider modules are migrated or supported through a documented compatibility bridge
- maintainers can inspect runtime capability state without reading source manually

## Milestone B — Runtime Contract Enforcement

### Objective

Validate capability contracts at call time with controlled rollout.

### Workstreams

- input schema validation
- output schema validation
- warn-only versus enforce modes
- policy configuration per environment or capability
- structured contract violation reporting

### Deliverables

- runtime schema enforcement path
- configuration for warn-only and enforce modes
- contract violation logs with caller/provider attribution
- focused test coverage for valid and invalid payloads

### Acceptance Criteria

- invalid input payloads can be detected before provider execution
- invalid provider responses can be detected after execution
- high-value capabilities can be safely moved into enforce mode
- rollout does not break stable flows unexpectedly

### Exit Conditions

- the platform can distinguish registration problems from contract problems
- schema enforcement is test-backed and operationally visible

## Milestone C — Trigger Validation and Execution Tracing

### Objective

Make trigger automation diagnosable, previewable, and safer to operate.

### Workstreams

- event schema descriptors
- trigger payload preview
- trigger payload validation against capability input contracts
- correlation IDs across event to trigger to capability execution
- trace storage and operator visibility

### Deliverables

- trigger preview/validation service
- correlation-aware trace records
- structured trigger execution logs
- operator-facing trace or health view

### Acceptance Criteria

- a saved trigger can be validated before activation
- a failed automation chain is attributable to a concrete step
- recent trigger executions can be inspected without reconstructing them from raw logs
- payload drift becomes visible before silent breakage spreads

### Exit Conditions

- event-driven flows are inspectable end-to-end
- operators can identify failing triggers quickly

## Milestone D — Module Graph and Dependency Analysis

### Objective

Expose inter-module relationships and reduce hidden coupling.

### Workstreams

- module dependency graph
- capability consumer/provider graph
- event emitter/listener graph
- hook participation graph
- impact analysis tooling

### Deliverables

- graph generation in CLI and/or admin
- dependency validation rules
- impact reporting for disable/change scenarios

### Acceptance Criteria

- maintainers can identify direct and indirect dependencies reliably
- disabling a module has visible impact analysis
- missing dependency conditions are caught before runtime surprises

### Exit Conditions

- module relationships are represented as platform truth
- dependency analysis is useful for architecture reviews and releases

## Milestone E — Operator Tooling and Platform Health

### Objective

Make Ikabud operable as a platform product.

### Workstreams

- capability health panel
- trigger and event health panel
- module health summaries
- metrics and recent error visibility

### Deliverables

- admin views for capabilities, events, triggers, and modules
- latency/failure summaries
- recent execution trace visibility

### Acceptance Criteria

- platform health can be reviewed without code access
- operators can identify broken providers, invalid triggers, and dependency gaps quickly
- health views read authoritative kernel state instead of duplicated data

### Exit Conditions

- operators depend less on raw logs for day-to-day diagnosis
- platform behavior becomes visible to non-core developers

## Milestone F — Workflow Runtime Promotion

### Objective

Elevate workflows into a reusable, first-class platform service.

### Workstreams

- workflow definition formalization
- transition guards and policies
- capability-driven workflow steps
- workflow traceability and replay posture

### Deliverables

- workflow definition model
- execution and audit history
- workflow-to-event and workflow-to-capability integration rules

### Acceptance Criteria

- modules can adopt a common workflow runtime without duplicating orchestration logic
- workflow state transitions are auditable and diagnosable
- workflow failures are visible and recoverable according to policy

## Milestone G — Developer Platform Experience

### Objective

Make platform-native development easier and safer.

### Workstreams

- scaffolding updates
- manifest linting
- contract-aware developer tooling
- docs and anti-pattern guides

### Deliverables

- module/capability/event/workflow scaffolds
- stronger static checks
- updated development guidance

### Acceptance Criteria

- contributors can build modules with less ambiguity
- platform rules are reinforced by tooling, not memory
- new module quality improves by default

## Milestone H — Productization Layer

### Objective

Elevate the kernel runtime into a broader platform product with higher-level user experiences.

### Workstreams

- capability marketplace or UI registry
- visual automation builders
- generative AI-assisted platform experiences

### Deliverables

- interactive web UI for inspecting capability graphs
- administrative workflow designer
- module scaffolding tools powered by registry exports

### Acceptance Criteria

- advanced capabilities can be configured without writing code
- cross-module automations are manageable by superadmins natively
- the platform feels like a cohesive operating environment rather than just a codebase

## Ecosystem & Vertical Alignment

To ensure platform changes land safely, the following vertical tracks must be explicitly sequenced against kernel milestones:

- **Capability Contracts (`roadmap.md`)**: Foundation for all milestones. Core capabilities like `kernel.auth` must be solidified (Phase 1) before Milestone F (Workflows) can scale.
- **Multi-Tenancy (`tenancy-roadmap.md`)**: Impacts all runtime contract enforcement. Tenant DB connection and bound auth MUST be reconciled with capability resolution before Milestone B (Runtime Enforcement).
- **CMS & Page Builder (`cms-roadmap.md`, `page-builder-roadmap.md`)**: Module rendering acts as the primary consumer of kernel capability/trigger features. CMS capability consumption (like the recent ecommerce POC) serves to validate Milestone C (Execution Tracing).

## Recommended Delivery Sequence

### Wave 1

- Milestone A
- Milestone B
- Milestone C

### Wave 2

- Milestone D
- Milestone E

### Wave 3

- Milestone F
- Milestone G

### Wave 4

- Milestone H
- Vertical Hardening Integrations (CMS, Tenancy)

## Suggested Governance Rules

- no new platform feature should bypass the registry or contract model
- no admin view should invent shadow truth outside kernel-owned state
- no workflow or trigger feature should ship without traceability
- all migration paths should have compatibility posture and rollback understanding

## Definition of Platform Progress

Ikabud should be considered to have advanced materially when:

- capabilities are deterministic and inspectable
- triggers are previewable and traceable
- dependencies are visible and analyzable
- platform health is visible in tooling
- workflows are reusable rather than module-specific ad hoc logic
