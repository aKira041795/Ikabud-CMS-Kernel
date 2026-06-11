---
 description: Kernel triggers and capabilities hardening plan v2
---

# Kernel Triggers and Capabilities Plan v2

**Author:** Noah C. Omamalin

> **Status:** Shipped. `TriggerService`, `IntegrationBridge`, and the full capability bus (with circuit breaker, retry, metrics, schema validation) are all in production as of Kernel OS 6.0. See [kernel-os-disyl-roadmap-status.md](kernel-os-disyl-roadmap-status.md).

## Purpose

This plan defines the next hardening and evolution path for Ikabud's capability and trigger runtime.

Its purpose is to make capabilities and automations:

- deterministic
- contract-driven
- observable
- diagnosable
- safer to evolve without hidden coupling

This document is a focused implementation plan that supports the broader Ikabud platform roadmap.

## Strategic Goal

Move Ikabud from a capable modular runtime into a **deterministic platform execution layer** where:

- capability contracts are explicit and inspectable
- provider resolution is predictable
- trigger behavior is previewable and traceable
- automation failures are attributable
- operators can inspect platform truth without reconstructing it from logs

## Scope

This plan covers:

- capability registration and provider resolution
- runtime contract enforcement
- trigger validation and delivery behavior
- event-to-trigger-to-capability tracing
- admin and operator visibility for these flows

This plan does not attempt to fully define:

- the complete workflow engine roadmap
- broader module graph intelligence
- product-layer features like visual marketplaces

## Guiding Principles

- Capability registration must be explicit and module-local.
- Capabilities and events should be treated as contracts, not best-effort conventions.
- Trigger automation must be diagnosable before and after execution.
- Kernel tooling should expose authoritative state rather than duplicate shadow state.
- Backward compatibility must be preserved where migration risk is material.
- Prefer progressive enforcement with clear rollout modes when runtime strictness increases.

## Current Gaps

The current runtime still has several maturity gaps:

- capability registration paths are not yet fully canonicalized
- capability schemas are not yet enforced as true runtime contracts everywhere
- trigger payloads can drift from source events or target capability expectations
- cross-module automation traces are still harder to inspect than they should be
- operators rely too heavily on raw logs for platform diagnosis

## Milestone 1 — Deterministic Capability Exports

### Objective

Make provider registration explicit, canonical, and less dependent on fragile discovery behavior.

### Problems Addressed

- shared global handler maps create avoidable coupling
- fallback naming conventions are harder to audit
- registration order can be harder to reason about than necessary

### Deliverables

- kernel loader supports module-local export functions for capability handlers
- explicit export shape becomes the preferred provider registration path
- provider resolution reports show where a handler came from
- active provider modules migrate to explicit exports
- backward compatibility bridge remains during migration

### Acceptance Criteria

- migrated modules do not rely on shared global handler state
- provider registration origin is inspectable
- registration failures identify module, capability, and reason clearly
- runtime behavior remains backward compatible during transition

## Milestone 2 — Capability Registry and Introspection

### Objective

Create one canonical registry view for capabilities, providers, modes, and policies.

### Problems Addressed

- maintainers cannot always answer capability questions from one authoritative place
- provider availability and resolution are not yet exposed as platform truth

### Deliverables

- canonical capability registry service or equivalent kernel-owned registry view
- inspection support for capability ID, provider, mode, schema, and policy metadata
- CLI and/or admin-level introspection path
- structured registry diagnostics for missing providers or invalid declarations

### Acceptance Criteria

- maintainers can list all active capabilities and providers from one place
- capability/provider state is derived from kernel-owned truth
- invalid or incomplete declarations are surfaced clearly

## Milestone 3 — Runtime Contract Enforcement

### Objective

Validate capability payloads and responses at runtime with controlled rollout semantics.

### Problems Addressed

- schema metadata exists but is not consistently enforced at call time
- invalid payloads and invalid responses can drift deeper into execution than necessary

### Deliverables

- explicit `schema.input` and `schema.output` handling
- input validation before provider execution
- output validation after provider execution
- structured contract violation logging
- rollout modes:
  - warn-only
  - enforce
  - configurable by environment or capability

### Acceptance Criteria

- invalid input payloads are attributable to a caller and capability
- invalid provider responses are attributable to a provider and capability
- warn-only mode supports safe rollout
- high-value capabilities can move into enforcement without surprising failures

## Milestone 4 — Trigger Payload Validation and Preview

### Objective

Prevent trigger drift and make automation mappings inspectable before activation.

### Problems Addressed

- source event payloads can change over time
- target capability requirements can drift independently
- broken trigger mappings are too easy to discover late

### Deliverables

- trigger validation against source event variables
- validation of resolved trigger payload against target capability input schema
- save-time preview and validation feedback
- clearer admin/operator feedback for invalid mappings

### Acceptance Criteria

- broken mappings are blocked or clearly warned before save
- admins can inspect what a trigger will send
- payload drift becomes visible before silent runtime damage occurs

## Milestone 5 — Correlation and Execution Tracing

### Objective

Trace cross-module execution from request to event to trigger to capability.

### Problems Addressed

- distributed platform flows are difficult to inspect end-to-end
- failures are visible, but not always easy to connect causally

### Deliverables

- correlation ID propagation across request, event emission, trigger dispatch, and capability call
- structured trigger execution records
- structured capability execution records tied to correlation context
- recent execution traces visible to operators

### Acceptance Criteria

- a single business action can be traced end-to-end
- trigger failures are attributable to a concrete step
- operators can inspect recent cross-module execution without rebuilding the flow manually

## Milestone 6 — Capability and Trigger Health Visibility

### Objective

Expose platform automation health through kernel-owned tooling.

### Problems Addressed

- operational insight currently depends too much on raw log reading
- broken integrations are harder to detect early than they should be

### Deliverables

- capability health view showing:
  - declared capabilities
  - providers
  - missing or invalid handlers
  - dependencies
  - policy summary
- trigger health view showing:
  - enabled state
  - source event
  - target capability
  - provider selection
  - last run status
  - duration
  - recent errors

### Acceptance Criteria

- operators can identify broken integrations without log digging
- health views reflect kernel-owned truth
- recent failures can be attributed quickly by module, capability, and trigger

## Milestone 7 — Trigger Delivery Hardening

### Objective

Make trigger delivery behavior predictable under retries, duplicate risk, and failure conditions.

### Problems Addressed

- event-driven automation needs clearer delivery semantics
- replay safety and retry behavior are not yet first-class enough

### Deliverables

- optional idempotency keys
- retry and backoff metadata
- explicit failure policy per trigger:
  - log-only
  - retry
  - escalate
  - fail-request when appropriate
- clearer delivery outcome recording

### Acceptance Criteria

- duplicate execution risk is reduced
- trigger behavior under failure is predictable and inspectable
- retry behavior is explicit rather than implicit

## Recommended Implementation Order

1. Deterministic capability exports
2. Capability registry and introspection
3. Runtime contract enforcement
4. Trigger payload validation and preview
5. Correlation and execution tracing
6. Capability and trigger health visibility
7. Trigger delivery hardening

## Suggested Immediate Work Package

Start with the highest-leverage bundle:

- deterministic capability exports
- capability registry and inspection
- runtime schema enforcement modes
- trigger validation preview
- correlation-aware execution tracing

This package creates the strongest jump in platform maturity with the least ambiguity about value.

## Definition of Success

This plan should be considered successful when:

- capabilities are explicit and inspectable
- contract violations are attributable
- triggers can be previewed before activation
- execution chains can be traced end-to-end
- broken integrations are visible through tooling rather than discovered late through logs
