---
name: service-layer-patterns
description: Domain services with tenant scope, permission checks, transactions, event emission, audit
applyTo: "**/services/**"
---

# Service Layer Patterns

## Service structure
Every domain service follows this pattern:

```php
class ProjectService
{
    public function __construct(
        private PDO $db,
        private AuditService $audit,
        private EventBus $events,
    ) {}

    public function create(array $data, string $userId): ServiceResult
    {
        // 1. Validate tenant scope
        // 2. Validate user permission
        // 3. Validate input
        // 4. Begin transaction
        // 5. Create record
        // 6. Emit domain event
        // 7. Record audit
        // 8. Commit
        // 9. Return structured result
    }
}
```

## ServiceResult contract
```php
class ServiceResult
{
    public readonly bool $ok;
    public readonly mixed $data;
    public readonly ?string $error;
    public readonly ?array $events;  // emitted events
    public readonly ?int $entityId;
}
```

## Every service method must
1. **Scope** — verify `tenant_id` matches current tenant
2. **Permission** — check capability via `app()->capabilities()->has($cap)`
3. **Input** — validate with explicit rules, not just DB constraints
4. **Transaction** — wrap multi-table writes in `beginTransaction`/`commit`/`rollback`
5. **Event** — emit domain event after successful write
6. **Audit** — log before/after values, action, user, timestamp
7. **Result** — return `ServiceResult`, never echo HTML

## Transaction discipline
```php
$this->db->beginTransaction();
try {
    // all writes
    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}
```

## Service file location
```
modules/{module-id}/services/
  ProjectService.php
  ExpenseService.php
  InventoryService.php
  ...
```
