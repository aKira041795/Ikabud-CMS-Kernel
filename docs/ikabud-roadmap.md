---
description: Formal roadmap for the Ikabud application kernel platform
---

# Ikabud Platform Roadmap

**Author:** Noah C. Omamalin

## Purpose

This roadmap defines the next evolution of **Ikabud** as a kernel-governed modular application platform.

The aim is to move from a strong modular architecture into a more deterministic, inspectable, governable, and productizable platform runtime.

## Strategic Direction

Ikabud already has the essential foundations of a serious application kernel platform:

- kernel-owned routing and bootstrapping
- module-scoped access via `ModuleContext`
- capability-based integration
- event and trigger wiring
- table ownership enforcement
- runtime hardening and boundary checks

The next roadmap focuses on strengthening five platform qualities:

- **Determinism**
- **Governance**
- **Observability**
- **Developer experience**
- **Product readiness**

## Roadmap Principles

- Keep the kernel small, stable, and authoritative.
- Prefer explicit registration over magic discovery.
- Treat capabilities and events as contracts, not loose conventions.
- Make cross-module flows diagnosable end-to-end.
- Push infrastructure concerns into the kernel, not into business modules.
- Preserve backward compatibility where migration risk is real.

## Phase 1 — Deterministic Capability Runtime

### Outcome

Capabilities behave like true kernel-managed contracts.

### Objectives

- create one canonical capability registry
- make capability registration explicit and module-local
- make provider resolution deterministic and inspectable
- strengthen schema validation for input and output
- improve structured diagnostics for capability failures

### Deliverables

- canonical `CapabilityRegistry` view of all contracts and providers
- explicit provider export path for module capability handlers
- deterministic provider ordering and resolution visibility
- input and output schema validation modes
- improved CLI and admin visibility for capability inspection

### Acceptance Criteria

- a maintainer can list all active capabilities and providers reliably
- capability resolution does not depend on shared global state for migrated modules
- contract violations are logged in structured form with caller and provider context
- high-value capabilities can move from warn-only to enforce mode safely

## Phase 2 — Event and Trigger Governance

### Outcome

Event-driven automation becomes contract-aware, inspectable, and safer to operate.

### Objectives

- define event payload expectations more formally
- validate trigger payload generation against capability input schemas
- trace trigger execution across event, trigger, and capability layers
- surface trigger breakage before it becomes hidden runtime drift

### Deliverables

- event schema descriptors
- trigger save-time preview and validation
- trigger execution tracing with correlation IDs
- structured trigger execution records and diagnostics
- clear trigger failure policies

### Acceptance Criteria

- admins can preview what a trigger will send before saving
- invalid trigger payload mappings are blocked or clearly warned
- a single business action can be traced through event emission and downstream capability execution
- trigger failures are visible without raw log digging

## Phase 3 — Module Graph and Dependency Intelligence

### Outcome

Ikabud can explain how modules depend on each other.

### Objectives

- visualize relationships between modules, capabilities, events, and hooks
- validate dependency and load-order assumptions
- support safer disable/upgrade impact analysis

### Deliverables

- module dependency graph
- provider/consumer graph for capabilities
- emitted/listened event graph
- hook participation map
- CLI and admin diagnostics for missing or fragile dependencies

### Acceptance Criteria

- maintainers can answer “who depends on this module?” quickly
- the platform can detect missing providers and dependency gaps without manual inspection
- disabling a module has predictable, visible impact analysis

## Phase 4 — Platform Observability and Operator Tooling

### Outcome

Ikabud becomes operationally inspectable as a platform, not just as source code.

### Objectives

- expose kernel truth in admin tooling
- reduce dependence on log-only diagnosis
- make platform health visible to operators

### Deliverables

- capability health admin view
- trigger and event health admin view
- module health dashboard
- structured metrics for failure counts and latency
- recent execution traces and error visibility

### Acceptance Criteria

- operators can identify broken integrations without code access
- recent platform failures are attributable by module, capability, provider, and request
- health and dependency issues are visible in one place

## Phase 5 — Workflow Runtime Maturation

### Outcome

Workflows become a first-class reusable runtime primitive.

### Objectives

- formalize workflow definitions and transitions
- support reusable orchestration across modules
- make workflow state and failures inspectable

### Deliverables

- formal workflow definition model
- transition guards and side-effect execution rules
- workflow execution history and traceability
- capability-driven workflow steps
- event emission on workflow state change

### Acceptance Criteria

- workflows can be defined consistently across modules
- workflow runs are auditable and replayable where appropriate
- workflow failures do not silently disappear inside module code

## Phase 6 — Developer Platform Experience

### Outcome

Building an Ikabud-native module becomes faster and safer.

### Objectives

- reduce ambiguity for contributors
- make platform conventions easier to follow by default
- strengthen architecture conformance at dev time

### Deliverables

- improved scaffolding for modules, capabilities, events, and workflows
- stronger manifest linting and architecture checks
- module development playbooks and anti-pattern docs
- contract-aware CLI helpers

### Acceptance Criteria

- new modules can be scaffolded with correct default structure
- manifest and contract issues are caught earlier in development
- developers can choose the right primitive: capability, event, trigger, listener, or hook

## Phase 7 — Productization Layer

### Outcome

Ikabud becomes a more obvious platform product, not only a strong internal architecture.

### Objectives

- support broader platform packaging and extensibility
- create higher-level experiences on top of the kernel runtime

### Potential Deliverables

- capability marketplace or registry UI
- visual automation builder
- AI-assisted module scaffolding
- tenant-aware platform packaging
- edition and distribution strategy

### Acceptance Criteria

- product-layer features build on stable kernel truth rather than side systems
- new ecosystem features do not weaken contract discipline or kernel authority

## Recommended Execution Order

1. Deterministic capability runtime
2. Event and trigger governance
3. Module graph and dependency intelligence
4. Platform observability and operator tooling
5. Workflow runtime maturation
6. Developer platform experience
7. Productization layer

## Immediate Recommendation

The highest-leverage near-term work package is:

- capability registry
- explicit capability exports
- input/output schema enforcement modes
- trigger validation and execution tracing

This package most directly strengthens Ikabud’s identity as a real application kernel platform.

## Positioning Summary

Ikabud is evolving from a modular application architecture into a **deterministic platform runtime** that can govern contracts, automate safely, expose platform truth, and support multiple business modules without collapsing into plugin chaos or premature microservices.
