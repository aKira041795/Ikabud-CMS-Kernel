---
name: approval-workflow
description: Multi-state approval state machine with submission, review, rejection, return, escalation
applyTo: "**/*.php"
---

# Approval Workflow

## State machine
```
                  ┌─────────┐
                  │  Draft   │
                  └────┬─────┘
                       │ submit
                       ▼
                  ┌──────────┐
     ┌────────────│ Submitted │◄────────────┐
     │            └─────┬─────┘             │
     │                  │                   │
     │          ┌───────┼────────┐          │
     │          │       │        │          │
     ▼          ▼       ▼        ▼          │
┌────────┐ ┌────────┐ ┌────────────┐       │
│Approved│ │Rejected│ │ Returned   │───────┘
└───┬────┘ └────────┘ │ for corr.  │  re-submit
    │                  └────────────┘
    │ void/reverse
    ▼
┌─────────┐
│ Voided  │
└─────────┘
```

## Approval record
```php
$approval = [
    'entity_type'    => 'expense',       // what kind of record
    'entity_id'      => $expenseId,      // the record ID
    'submitted_by'   => $encoderId,
    'submitted_at'   => date('Y-m-d H:i:s'),
    'reviewer_id'    => $reviewerId,     // who needs to act
    'decision'       => 'approved',      // approved | rejected | returned
    'decision_date'  => null,
    'remarks'        => '',
    'previous_status'=> 'draft',
    'new_status'     => 'submitted',
];
```

## Self-approval prevention
```php
if ($submittedBy === $reviewerId && !$this->allowSelfApproval) {
    throw new \RuntimeException('Self-approval is not permitted.');
}
```
Self-approval policy configurable by admin only.

## Status transitions by role
| From | To | Role | Action |
|---|---|---|---|
| draft | submitted | encoder | submit |
| submitted | approved | supervisor/admin | approve |
| submitted | rejected | supervisor/admin | reject |
| submitted | returned | supervisor/admin | return for correction |
| returned | submitted | encoder | re-submit |
| approved | voided | admin | void |
| approved | reversed | admin | reverse |

## Guard method
```php
function guardTransition(string $currentStatus, string $targetStatus, string $userRole): void
{
    $allowed = self::TRANSITIONS[$currentStatus][$targetStatus] ?? [];
    if (!in_array($userRole, $allowed, true)) {
        throw new \RuntimeException("Transition {$currentStatus}→{$targetStatus} not allowed for role {$userRole}");
    }
}
```

## Integration
- Each service method checks current status before allowing changes
- Approved records are read-only for editing (must be voided/reversed first)
- Status change triggers audit event and domain event
