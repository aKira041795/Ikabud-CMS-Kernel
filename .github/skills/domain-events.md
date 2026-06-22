---
name: domain-events
description: Emitting and handling domain events through the kernel event bus
applyTo: "**/services/**"
---

# Domain Events

## Event emission pattern
After any state-changing operation in a service, emit a domain event:

```php
$this->events->emit('project-audit-ledger.expense.approved', [
    'tenant_id' => $tenantId,
    'user_id'   => $userId,
    'entity_id' => $expenseId,
    'timestamp' => date('Y-m-d H:i:s'),
    'meta'      => [
        'amount'   => $amount,
        'project'  => $projectId,
        'previous_status' => 'submitted',
        'new_status'      => 'approved',
    ],
]);
```

## Event structure
Every event should contain:
- `tenant_id` — tenant scope
- `user_id` — who triggered it
- `entity_id` — affected record ID
- `timestamp` — when it happened
- `meta` — relevant metadata (status changes, amounts, references)

## Event types
Group by domain:
- `project.*` — created, updated, completed, cancelled, closed
- `expense.*` — submitted, approved, rejected, returned, voided, reversed
- `purchase.*` — created, approved, cancelled
- `inventory.*` — stocked_in, issued, returned, adjusted, wasted
- `fabrication.*` — allocation_created, due_created, payment_recorded, payment_approved
- `sale.*` — created, collected, cancelled, voided
- `approval.*` — completed, escalated, withdrawn
- `report.*` — generated, exported

## Listener registration
In `module.json`:
```json
"events": {
    "listens": [
        {"event": "project-audit-ledger.expense.approved", "handler": "onExpenseApproved"}
    ]
}
```

## Do not
- Expose sensitive data (passwords, keys) in event payloads
- Emit events inside transactions before commit (emit after success)
- Depend on event delivery order across different event types
