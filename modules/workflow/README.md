# Workflow Engine (Compatibility Shell)

Legacy compatibility shell for the kernel workflow engine. The authoritative workflow runtime lives in `kernel/WorkflowRuntime.php` — this module preserves backward-compatible helper entry points only.

## Architecture

The workflow system is a kernel primitive (state machine + multi-step engine):

- States, transitions, guards, and actions are defined declaratively
- Workflow instances are persisted with audit trail
- Jobs and escalation are handled by the kernel job queue

## Files

- Manifest: [`module.json`](module.json)

## Kernel documentation

- Workflow system: [`docs/kernel/workflow-system.md`](../../docs/kernel/workflow-system.md)
