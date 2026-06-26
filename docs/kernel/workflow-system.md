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

```php
$wf = new WorkflowRuntime(app());

// Define a workflow
$wf->ensureDefinition('order_fulfillment', [
    'states' => ['pending', 'processing', 'shipped', 'delivered', 'cancelled'],
    'initial' => 'pending',
    'transitions' => [
        'process'  => ['from' => 'pending',    'to' => 'processing', 'guard' => 'hasInventory'],
        'ship'     => ['from' => 'processing', 'to' => 'shipped',    'action' => 'sendShipNotification'],
        'deliver'  => ['from' => 'shipped',    'to' => 'delivered'],
        'cancel'   => ['from' => ['pending', 'processing'], 'to' => 'cancelled'],
    ],
]);

// Execute a transition
$result = $wf->transition('order_fulfillment', $orderId, 'process');
// → ['success' => true, 'from' => 'pending', 'to' => 'processing']

// Query state
$state = $wf->stateGet('order_fulfillment', $orderId);
// → 'processing'
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
| `ensureDefinition` | `(string $name, array $definition): void` | Register/update workflow definition |
| `transition` | `(string $workflow, string\|int $subjectId, string $transition): array` | Execute state transition |
| `stateGet` | `(string $workflow, string\|int $subjectId): ?string` | Get current state of subject |
| `can` | `(string $workflow, string\|int $subjectId, string $transition): bool` | Check if transition is allowed |
| `history` | `(string $workflow, string\|int $subjectId): array` | Get transition history |

### Workflow Definition Format

```php
[
    'states' => ['state1', 'state2', ...],     // All valid states
    'initial' => 'state1',                      // Starting state
    'transitions' => [
        'transition_name' => [
            'from' => 'state1',                 // String or array of strings
            'to' => 'state2',
            'guard' => 'guardFunctionName',     // Optional: callable guard
            'action' => 'actionFunctionName',   // Optional: post-transition action
            'meta' => [...],                    // Optional: metadata
        ],
    ],
]
```

### Transition Flow

```
transition(workflow, subjectId, transitionName)
       ↓
1. Load workflow definition
2. Get current state of subject
3. Find transition by name
4. Verify current state matches transition's 'from'
5. Evaluate guard (if defined) → must return true
6. Update state in database
7. Execute action (if defined)
8. Emit event: "workflow.{name}.{transition}" via EventBus
9. Return result array
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
$engine = new WorkflowEngine(app());

// Load YAML definitions
$engine->loadDefinitions('/path/to/workflows/*.yaml');

// Start a workflow
$run = $engine->start('report-approval', [
    'subject_id' => $reportId,
    'initiator' => $userId,
]);

// Advance to next step (auto-invoked by steps that don't need manual approval)
$engine->advance($run['id']);

// Cancel a run
$engine->cancel($run['id'], 'Cancelled by user');

// Replay from a specific step
$engine->replay($run['id'], 'validate');

// Subscribe to events for auto-start
$engine->subscribe('report.export.requested', 'report-approval');
$engine->handleEvent('report.export.requested', $eventData);
```

### Workflow YAML Format

```yaml
name: report-approval
label: Report Approval
initial: draft
steps:
  - id: validate
    type: validate
    label: Validate Report
    action: validateReport
    retry:
      max_attempts: 3

  - id: generate
    type: export
    label: Generate Document
    format: docx

  - id: notify
    type: notify
    label: Notify Approvers
    channel: email
    template: report-ready

events:
  subscribe:
    report.export.requested: start
```

### Step Types

| Type | Behavior |
|---|---|
| `validate` | Calls `action` function, must return `true` to advance |
| `transition` | Performs a WorkflowRuntime state transition |
| `notify` | Sends notification via configured channel |
| `webhook` | Calls external URL with step context |
| `export` | Generates document (DOCX/CSV/PDF) via KernelExport |

### Methods

| Method | Signature | Purpose |
|---|---|---|
| `loadDefinitions` | `(string $glob): void` | Load YAML workflow definitions |
| `start` | `(string $name, array $context): array` | Start a new workflow run |
| `advance` | `(string\|int $runId): array` | Advance to next step |
| `cancel` | `(string\|int $runId, string $reason): array` | Cancel active run |
| `replay` | `(string\|int $runId, ?string $fromStep): array` | Replay from step |
| `subscribe` | `(string $event, string $workflow): void` | Subscribe event to workflow |
| `handleEvent` | `(string $event, array $data): void` | Handle incoming event |

### Events

Every step execution emits an event via `EventBus`:

```
workflow.{name}.step.{stepId}  → {runId, subjectId, status, result}
workflow.{name}.completed      → {runId, subjectId, finalStatus}
workflow.{name}.failed         → {runId, subjectId, error, stepId}
```

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

- Workflow names use snake_case: `order_fulfillment`, `content_review`
- Guard functions receive `(subjectId, context)` and return `bool`
- Action functions receive `(subjectId, transitionResult)` for side effects
- Subject IDs can be string or integer (cast to string for storage)
- Callers must be registered before defining workflows
- Definition updates are additive (merge, not replace)
