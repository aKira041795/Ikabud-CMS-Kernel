---
name: testing-strategy
description: Four-tier testing — unit, integration, security, acceptance — for module development
applyTo: "**/tests/**"
---

# Testing Strategy

## Four tiers

### 1. Unit tests
Test in isolation. Mock dependencies.
- Cost calculations (profit, margin, fabrication allocation)
- Weighted average cost formula
- Weekly due calculations
- Partial payment math
- Stock availability logic
- Permission rule evaluation
- Status transition validation

### 2. Integration tests
Test real DB with transaction rollback.
```php
public function testPurchaseApprovalCreatesStockMovement(): void
{
    $db->beginTransaction();
    // Create purchase → approve → verify stock_in movement exists
    // Assert quantity, unit_cost, material_id
    $db->rollBack();
}
```
Key integration paths:
- Purchase approval → stock-in movement → balance update
- Material issuance → project cost allocation
- Expense approval → project cost update
- Fabrication payment → weekly due balance
- Sales collection → outstanding balance

### 3. Security tests
Test against common vulnerabilities:
- Cross-tenant access (tenant A sees tenant B data)
- Unauthorized approval (encoder tries to approve own record)
- Direct URL access to restricted pages
- CSRF token validation
- Upload of invalid file types
- SQL injection via filter parameters
- Modification of approved records
- Deletion of financial history

```php
public function testCrossTenantAccessIsBlocked(): void
{
    // Login as tenant A user
    // Try to access tenant B's project
    // Assert 403 or error
}
```

### 4. Acceptance tests
Test full workflows end-to-end:
- Admin: create project → configure → close
- Encoder: create expense → submit → view own records
- Supervisor: review → approve → verify project cost updated
- Project lifecycle: draft → approved → in_progress → completed → closed
- Inventory lifecycle: purchase → stock-in → issue → return → adjust
- Fabrication: allocate → weekly dues → partial payment → completion

## Test file location
```
modules/{module-id}/tests/
  unit/
  integration/
  security/
  acceptance/
```

## Coverage targets
- Unit: 90%+ of calculation logic
- Integration: all critical paths
- Security: all permission boundaries
- Acceptance: all user role workflows

## Required per feature
Before marking a feature done:
1. Unit test for calculation
2. Integration test for DB path
3. Security test for permission
4. Manual acceptance check
