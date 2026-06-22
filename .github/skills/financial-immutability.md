---
name: financial-immutability
description: Reversal, void, adjustment patterns — never overwrite approved financial records
applyTo: "**/services/**"
---

# Financial Immutability

## Core principle
Approved financial records must never be silently overwritten or deleted.

Corrections use:
- **Void** — mark record as void, keep it visible, reference the void reason
- **Reversal** — create an opposite entry that cancels the original
- **Adjustment** — create a separate adjustment record with reason and approval
- **Replacement entry** — create a corrected version after voiding the original

## Void pattern
```php
function voidExpense(int $expenseId, string $reason, string $userId): ServiceResult
{
    $expense = $this->findOrFail($expenseId);
    // 1. Must be in approved state
    // 2. Check void hasn't already happened
    // 3. Record void_* fields on the original record
    // 4. Emit event
    // 5. Audit trail
}
```

## Reversal pattern
```php
function reverseExpense(int $expenseId, string $reason, string $userId): ServiceResult
{
    $original = $this->findOrFail($expenseId);
    // 1. Create a new reversal entry with negative amount
    // 2. Link it: reversal_id = original.id
    // 3. Original remains visible and unchanged
    // 4. Project cost reflects both (original - reversal)
}
```

## What void/reversal affects
| Record | Void | Reversal | Effect |
|---|---|---|---|
| Expense | ✅ | ✅ | Reverses project cost allocation |
| Purchase | ✅ | ✅ | Reverses stock-in movement |
| Material Issuance | ✅ | ✅ | Returns stock to inventory |
| Sale | ✅ | ✅ | Reverses receivable |
| Collection | ✅ | ✅ | Reopens receivable balance |
| Fabrication Payment | ✅ | ✅ | Reopens weekly due balance |

## Columns for immutable records
```sql
approved_at   DATETIME DEFAULT NULL,
approved_by   INT UNSIGNED DEFAULT NULL,
voided_at     DATETIME DEFAULT NULL,
voided_by     INT UNSIGNED DEFAULT NULL,
void_reason   VARCHAR(500) DEFAULT NULL,
reversal_id   INT UNSIGNED DEFAULT NULL COMMENT 'Links to the reversal entry',
```

## Prohibited operations
- `DELETE` on any approved financial record
- `UPDATE` on amount/quantity of an approved record
- Direct `UPDATE` on `inventory_balances.quantity` without a movement record
