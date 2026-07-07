# Workflow System

**Subsystem:** `kernel/WorkflowRuntime.php` + `kernel/WorkflowEngine.php`  
**Status:** Production  
**Last updated:** 2026-06-26  
**Version:** `WorkflowRuntime` 1.0.0 · `WorkflowEngine` 1.0.0 (new in 6.1)

## Overview

The Workflow Runtime provides a state-machine engine for multi-step business processes. It manages workflow definitions, state transitions, guard evaluation, action execution, and event emission. Modules register as callers to define and operate workflows.

## Core Class

### WorkflowRuntime

`kernel/WorkflowRuntime.php`

The WorkflowRuntime operates as a **capability-driven state machine** — all state queries and transitions go through the capability bus (`workflow.state.get@1` and `workflow.transition@1`), which delegate to `stateGet()` and `transition()`. Both methods accept a single payload array (validated against JSON schemas) and return an `['ok' => bool, ...]` response array.

```php
$wf = app()->workflow();

// Define a workflow (idempotent — safe to call on every boot)
$wf->ensureDefinition(
    workflowKey: 'order_fulfillment',
    module: 'ecommerce',
    entityType: 'order',
    initialState: 'pending',
    states: [
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'processing', 'label' => 'Processing'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ],
    transitions: [
        ['from' => 'pending', 'action' => 'process', 'to' => 'processing', 'roles' => ['admin', 'superadmin']],
        ['from' => 'processing', 'action' => 'ship', 'to' => 'shipped', 'roles' => ['admin', 'superadmin']],
        ['from' => 'shipped', 'action' => 'deliver', 'to' => 'delivered', 'roles' => ['admin', 'superadmin']],
        ['from' => ['pending', 'processing'], 'action' => 'cancel', 'to' => 'cancelled', 'roles' => ['admin', 'superadmin']],
    ],
);

// Execute a transition via capability bus (payload-driven)
$result = app()->cap()->call('workflow.transition@1', [
    'workflow_key' => 'order_fulfillment',
    'module' => 'ecommerce',
    'entity_type' => 'order',
    'entity_id' => (string)$orderId,
    'action' => 'process',
]);
// → ['ok' => true, 'from' => 'pending', 'to' => 'processing']

// Query state via capability bus
$state = app()->cap()->call('workflow.state.get@1', [
    'workflow_key' => 'order_fulfillment',
    'module' => 'ecommerce',
    'entity_type' => 'order',
    'entity_id' => (string)$orderId,
]);
// → ['ok' => true, 'workflow' => ['state' => 'processing', 'allowed_actions' => [...]]]
```

### Constructor

```php
new WorkflowRuntime(App $app)
```

Requires the `App` instance for database access and event emission.

### Registered Callers

Callers are module identifiers authorized to define and execute workflows. Registration is dynamic via `registerCaller()`.

**Default callers:** `cms`, `guidance`, `workflow`, `kernel`

```php
$wf->registerCaller('ecommerce');
```

### Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `registerCaller` | `(string $caller): void` | Register a module as authorized workflow caller |
| `ensureDefinition` | `(string $workflowKey, string $module, string $entityType, string $initialState, array $states, array $transitions): void` | Register/update workflow definition (idempotent, cache-aware) |
| `getDefinition` | `(string $workflowKey, string $module, string $entityType): ?array` | Fetch definition row from DB |
| `transition` | `(mixed $payload): array` | Execute state transition via capability payload. Required keys: `workflow_key`, `module`, `entity_type`, `entity_id`, `action`. Returns `['ok' => bool, ...]` |
| `stateGet` | `(mixed $payload): array` | Get current state + allowed actions. Required keys: `workflow_key`, `module`, `entity_type`, `entity_id`. Returns `['ok' => bool, 'workflow' => [...]]` |
| `getOrCreateInstance` | `(string $workflowKey, string $module, string $entityType, string $entityId, string $defaultState): ?array` | Fetch or auto-create a workflow instance row |
| `allowedActions` | `(array $definition, string $state, ?string $role, array $guardContext = []): array` | Compute allowed transitions from current state for a role |
| `ensureCmsContentWorkflow` | `(): void` | Convenience: define the `cms.content` workflow (draft→review→approved→published) |

> **Note:** There is no `can()` or `history()` method on WorkflowRuntime. Use `stateGet()` to get `allowed_actions` for the current state (equivalent to `can()`). History tracking is via `workflow.transitioned` events emitted on each transition — subscribe via EventBus if you need audit trails.

### Workflow Definition Format

Definitions are stored in the `workflow_definitions` table and synced via `ensureDefinition()`. States and transitions are passed as arrays of associative arrays (not keyed maps).

```php
$wf->ensureDefinition(
    'order_fulfillment',       // workflow_key
    'ecommerce',                // module
    'order',                    // entity_type
    'pending',                  // initial_state
    [                           // states: list of {key, label}
        ['key' => 'pending', 'label' => 'Pending'],
        ['key' => 'processing', 'label' => 'Processing'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ],
    [                           // transitions: list of {from, action, to, roles?, guard?}
        ['from' => 'pending', 'action' => 'process', 'to' => 'processing', 'roles' => ['admin', 'superadmin']],
        ['from' => 'processing', 'action' => 'ship', 'to' => 'shipped', 'roles' => ['admin', 'superadmin']],
        ['from' => 'shipped', 'action' => 'deliver', 'to' => 'delivered', 'roles' => ['admin', 'superadmin']],
        ['from' => ['pending', 'processing'], 'action' => 'cancel', 'to' => 'cancelled', 'roles' => ['admin', 'superadmin']],
    ],
);
```

**Guard types** (evaluated in `evaluateGuard()`):
- **callable**: `'guard' => fn($ctx) => $ctx['inventory'] > 0` — invoked with guard context, must return truthy
- **string**: `'guard' => 'functionName'` — resolved as global function name
- **declarative**: `'guard' => ['field' => 'status', 'operator' => 'eq', 'value' => 'paid']` — operators: eq, neq, in, not_in, gt, gte, lt, lte, empty, not_empty
- **absent**: no guard key = always passes

### Transition Flow

```
transition(payload) → capability bus → WorkflowRuntime::transition(payload)
       ↓
1. Validate payload schema (workflow_key, module, entity_type, entity_id, action)
2. Load workflow definition from DB
3. Get or create instance row (auto-creates at initial_state if missing)
4. Resolve caller identity + role
5. Build guard context from payload.guard_context + system state
6. Compute allowed_actions for current state + role (with guard evaluation)
7. Find matching action → get target state
8. UPDATE workflow_instances with optimistic concurrency (WHERE state = expected_from)
9. Emit event: 'workflow.transitioned' via EventBus
10. Return ['ok' => true, ...] or ['ok' => false, 'error' => '...']
```

### Database Resilience

The WorkflowRuntime is fault-tolerant regarding database availability:
- If the database table does not exist, operations degrade gracefully
- State queries return `null` for unknown subjects
- Transition failures return `['success' => false, 'reason' => '...']`

### Events

Every successful transition emits an event via `EventBus`:

```
workflow.order_fulfillment.process  → {subjectId, from: 'pending', to: 'processing'}
workflow.order_fulfillment.ship     → {subjectId, from: 'processing', to: 'shipped'}
```

Modules can subscribe to these events for side effects (notifications, logging, cascading workflows).

---

## WorkflowEngine — Multi-Step Runner (New in 6.1)

**File:** `kernel/WorkflowEngine.php` | **Version:** 1.0.0

The `WorkflowEngine` extends the single-transition `WorkflowRuntime` with
**multi-step ordered workflows** defined in YAML. Each step can be a validate,
transition, notify, webhook, or export action.

### Architecture

```
WorkflowEngine (multi-step runner)
    └── WorkflowRuntime::ensureDefinition() — syncs YAML to DB
            └── WorkflowRuntime::transition() — single state transitions

EventBus events → WorkflowEngine::handleEvent()
    → Auto-starts workflows with matching subscriptions
    → Steps execute in order defined in YAML
```

### Usage

```php
$engine = app()->workflowEngine();

// Load YAML definitions from a module's workflows/ directory
$engine->loadDefinitions('/path/to/modules/reports/workflows', 'reports');

// Start a workflow run
$run = $engine->start(
    workflowKey: 'report-approval',
    module: 'reports',
    payload: ['report_id' => $reportId, 'initiator' => $userId],
    entityType: 'report',
    entityId: (string)$reportId,
);
// → ['ok' => true, 'run_id' => 42]

// Advance to next step (auto-invoked by start() if steps exist)
$engine->advance($run['run_id']);

// Cancel a run
$engine->cancel($run['run_id'], 'Cancelled by user');

// Replay from a specific step
$engine->replay($run['run_id'], 'validate');

// Subscribe to events for auto-start
$engine->subscribe('reports', 'report.export.requested', 'report-approval', ['format' => 'pdf'], 'report');
$engine->handleEvent('report.export.requested', ['id' => $reportId]);
```

### Workflow YAML Format

Workflow YAML files live in `modules/<id>/workflows/*.yaml`. The `loadDefinitions()` method parses them with a minimal line-based parser and syncs definitions to the `workflow_definitions` table. The parser expects this structure:

```yaml
key: report.approval
label: Report Approval
entity_type: report_export
initial_state: pending

# Optional: auto-start trigger via EventBus
trigger:
  event: report.export.requested
  filter:
    requires_approval: "1"

states:
  - key: pending
    label: Pending Review
  - key: approved
    label: Approved
  - key: rejected
    label: Rejected
  - key: delivered
    label: Delivered
  - key: failed
    label: Failed

transitions:
  - from: pending
    action: approve
    to: approved
    roles: [administrator, superadmin]
  - from: pending
    action: reject
    to: rejected
    roles: [administrator, superadmin]
  - from: approved
    action: deliver
    to: delivered
    roles: [administrator, superadmin]
  - from: failed
    action: retry
    to: pending
    roles: [administrator]

# Multi-step capability execution (optional)
steps:
  - key: validate_request
    label: Validate Export Request
    capability: report.export.validate@1
    max_attempts: 1
    args:
      export_source: "{payload.export_source}"
      export_format: "{payload.export_format}"
  - key: generate_report
    label: Generate Report
    capability: report.export.generate@1
    max_attempts: 3
    args:
      export_source: "{payload.export_source}"
      export_format: "{payload.export_format}"
      title: "{payload.title}"
```

**Key differences from the old doc format:**
- Top-level key is `key:` (not `name:`)
- Steps use `key:` (not `id:`) and `capability:` (not `type:`)
- Auto-start is configured via `trigger:` with `event:` and optional `filter:`, not an `events:` section
- Step args support `{payload.*}` placeholder resolution

### Multi-Step Execution

When a YAML definition includes `steps:`, the `WorkflowEngine` creates `workflow_run_steps` records on `start()`. Each step references a capability ID — the engine calls it via `app()->capabilities()->call()`. Steps execute sequentially; if a step fails and `max_attempts` is > 1, it retries up to that limit before marking the run as failed.

Step argument values support `{payload.*}` references which are resolved against the run's original payload at execution time.

### Methods

| Method | Signature | Purpose |
|---|---|---|
| `loadDefinitions` | `(string $moduleDir, string $moduleId): array` | Scan `modules/<id>/workflows/*.yaml` and sync to DB. Returns list of loaded workflow keys. |
| `start` | `(string $workflowKey, string $module, array $payload = [], ?string $entityType = null, ?string $entityId = null): array` | Start a new workflow run. Returns `['ok' => bool, 'run_id' => int]` |
| `advance` | `(int $runId): array` | Execute next pending step. Auto-invoked by `start()` |
| `cancel` | `(int $runId, string $reason): array` | Cancel active run |
| `replay` | `(int $runId, ?string $fromStep): array` | Replay from a specific step |
| `subscribe` | `(string $module, string $eventId, string $workflowKey, ?array $filter = null, ?string $entityType = null): void` | Register event→workflow auto-start subscription |
| `handleEvent` | `(string $eventId, array $payload = []): void` | Handle incoming event — auto-starts matching workflows |

### Events

**WorkflowRuntime:** Every successful transition emits `workflow.transitioned` via EventBus with payload: `{workflow_key, module, entity_type, entity_id, from_state, to_state, action}`.

**WorkflowEngine:** Step execution is logged via `write_log()`; no separate per-step events are emitted. Runs that complete emit `workflow.transitioned` when they transition states through the WorkflowRuntime.

### Included Workflows

| File | Purpose |
|---|---|
| `kernel/workflows/report-approval.yaml` | Multi-step report validation, generation, and notification |
| `kernel/workflows/cms-content-publish.yaml` | Content publishing workflow with review steps |

### Test Coverage

`tests/workflow_engine_test.php` — 32 tests covering:
- Start / advance / cancel / replay lifecycle
- Argument resolution from step context
- Retry with max attempts
- Event subscription and auto-start
- Error handling and edge cases

---

## Conventions

- Workflow keys use dot-separated format: `order.fulfillment`, `cms.content`
- Guard functions receive a context array (built from `payload.guard_context` + system state) and return `bool`
- Action functions are not directly invoked by WorkflowRuntime — side effects should subscribe to `workflow.transitioned` events via EventBus
- Entity IDs are stored as strings in `workflow_instances.entity_id`
- Callers must be registered before defining workflows
- `ensureDefinition()` is idempotent and safe to call on every boot (cache-aware with configurable TTL)
