# Workflow Runtime — Phase 5 Plan

Status: **planning** (kernel 4.0.x)
Owner: Workflow module + kernel team
Predecessors: kernel 3.x event bus + capability system

## Why this exists

The `modules/workflow/` module currently ships only a manifest + capability
surface. There is no engine, no persisted state, no scheduler. Phase 5 of the
[ikabud-roadmap](../kernel/ikabud-roadmap.md) calls for a first-class workflow
runtime, but the design has not been pinned down.

This document is the seed for that work. It does not change any code; it
defines the **shape, primitives, and milestones** so contributors can land
incremental PRs with a shared mental model.

## Design principles

1. **Kernel-level orchestration, module-level steps.** The runtime lives in the
   `workflow` module but every step is implemented as a capability call on
   another module — no module-to-module direct calls.
2. **Event-driven by default.** Workflows subscribe to kernel events
   (`module.installed`, `cms.content.published`, `ecommerce.order.placed`,
   etc.). Steps may also fire events for downstream workflows.
3. **Idempotent, replayable steps.** Every step receives an `idempotency_key`;
   re-running a step with the same key must be safe.
4. **Tenant-scoped state.** Workflow runs are stored in the tenant database, not
   the control plane. This keeps tenant isolation intact.
5. **Synchronous-first, asynchronous-later.** v1 runs each step in-process on
   the event-handling request. Async/queue support is opt-in via a runner
   capability.
6. **Observability is non-optional.** Every run, step, retry, and completion
   emits a structured log entry (request-id correlated) and a kernel event.

## Primitives (v1)

| Primitive | Description | Storage |
| --- | --- | --- |
| **Definition** | A YAML/JSON document declaring trigger event(s), steps, retry policy. | File on disk under `modules/<id>/workflows/*.yaml` or DB row in `workflow_definitions`. |
| **Run** | A concrete execution of a definition, scoped to a tenant + trigger payload. | `workflow_runs` (id, definition_id, status, payload, started_at, finished_at). |
| **Step** | One unit of work inside a run; calls a capability with arguments resolved from the run context. | `workflow_run_steps` (run_id, ordinal, capability_id, status, attempts, last_error, finished_at). |
| **Trigger** | Subscription that turns a kernel event into a workflow run. | Registered at module load; persisted only for analytics. |

## Capability surface (planned)

| Capability | Mode | Owner | Purpose |
| --- | --- | --- | --- |
| `workflow.definition.list@1` | first | workflow | Read available definitions for a tenant. |
| `workflow.definition.get@1` | first | workflow | Hydrate a definition by id. |
| `workflow.run.start@1` | first | workflow | Kick off a run from an event payload. |
| `workflow.run.advance@1` | first | workflow | Move a run to its next step (used by the runner). |
| `workflow.run.cancel@1` | first | workflow | Mark a run as cancelled; stop further steps. |
| `workflow.runner.tick@1` | fanout | (any) | Background-runner hook for async steps. |

## Owned tables (planned)

- `workflow_definitions` — declarative source of truth (mirrors disk YAML).
- `workflow_runs` — one row per execution.
- `workflow_run_steps` — one row per step attempt (idempotent retries append).
- `workflow_subscriptions` — `(tenant_id, event_id, definition_id)` map.

All tables are owned by the `workflow` module. No `co_owns_tables` planned.

## Milestones

### M1 — Inert primitives + storage (kernel 4.1)

- Migrations for the four tables above.
- Capability stubs that return `not_implemented` errors.
- `validateWorkflowDefinition($yaml): array` helper + tests.
- No event subscriptions yet.

### M2 — Synchronous runner (kernel 4.2)

- `workflow.run.start@1` accepts a payload, persists a run, and walks steps
  inline on the request thread.
- Each step calls `app()->capabilities()->call($capabilityId, $args)`.
- Failures retry up to N times per definition policy, then mark the step (and
  run) as `failed`.

### M3 — Event-triggered runs (kernel 4.3)

- Workflow module loads each tenant's `workflow_subscriptions` and registers
  `app()->events()->on($eventId, fn(...) => workflowRunStartFromEvent(...))`.
- Add an admin UI page under `/cms/admin/workflows` (capability-gated) to view
  run history.

### M4 — Async runner (kernel 4.4+)

- New capability `workflow.runner.tick@1` that processes pending steps in a
  cron job or worker.
- Definition flag `async: true` defers steps to the runner instead of running
  inline.
- Optional Redis/queue backing.

## Open questions

1. **Definition format.** YAML for hand-authoring or JSON for tooling? (Lean
   toward YAML with strict schema validation.)
2. **Cross-tenant workflows.** Allow a single definition to fan out across
   tenants (e.g. nightly billing)? Probably yes, gated by superadmin.
3. **Step argument resolution.** Use a small expression sublanguage (DiSyL?)
   or restrict to literal/payload-path references in v1?
4. **Cancellation semantics.** Hard-stop in-flight steps, or wait for the
   current step to finish?

## Out of scope (for now)

- Visual workflow builder UI (post-M3).
- Long-running human-task steps (approvals, etc.) — M5+.
- Compensating transactions / SAGA patterns — M5+.

## References

- [ikabud-roadmap.md](../kernel/ikabud-roadmap.md) — Phase 5 placement.
- [co-owned-tables.md](./co-owned-tables.md) — table-ownership rules.
- [release-notes-2026-05-08-kernel-3.2-lattice](../releases/release-notes-2026-05-08-kernel-3.2-lattice.md)
  for the foundational event bus + capability system this runtime depends on.
