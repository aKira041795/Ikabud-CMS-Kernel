# Entity Authority System

**Subsystem:** `kernel/EntityAuthority/`  
**Status:** Production  
**Last updated:** 2026-04-15

## Overview

The Entity Authority System establishes single-authority ownership for entity types and registers CRUD contracts for cross-module entity synchronization. It ensures that each entity type has exactly one authoritative module, preventing conflicting operations from multiple modules.

## Core Classes

### EntityAuthorityRegistry

`kernel/EntityAuthority/EntityAuthorityRegistry.php`

Central registry that enforces the single-authority model: each entity type can have at most one authoritative module.

```php
$registry = new EntityAuthorityRegistry();

// Register CMS as authority for cms_content
$registry->registerAuthority('cms_content', 'cms', [
    'table' => 'cms_content',
    'capabilities' => ['cms.entity.render@1', 'cms.entity.edit@1'],
]);

// Attempting to register a second authority throws
$registry->registerAuthority('cms_content', 'ecommerce', [...]); // → Exception
```

| Method | Signature | Purpose |
|--------|-----------|---------|
| `registerAuthority` | `(string $entityType, string $moduleId, array $definition): void` | Register authoritative module for entity type. Throws on conflict. |
| `getAuthority` | `(string $entityType): ?array` | Get authority definition: `{module, definition}` |
| `hasAuthority` | `(string $entityType): bool` | Check if entity type has a registered authority |
| `allAuthorities` | `(): array` | Get all registered authorities |
| `authorityFor` | `(string $entityType): ?string` | Get module ID of authority |

### Conflict Prevention

The single-authority model prevents ambiguity in entity operations:

```
Module A registers authority for 'products' → OK
Module B registers authority for 'products' → Exception thrown

Module B can instead:
  1. Use IntegrationBridge to interact with Module A's products
  2. Register a SyncContract to receive notifications
  3. Extend the entity via EntityContext (capabilities, not ownership)
```

### SyncContractRegistry

`kernel/EntityAuthority/SyncContractRegistry.php`

Registers CRUD synchronization contracts between entity authorities and interested modules.

```php
$syncRegistry = new SyncContractRegistry();

// Ecommerce wants to know about CMS content changes
$syncRegistry->registerContract('cms_content', 'ecommerce', [
    'on_create' => 'ecommerce:handleContentCreated',
    'on_update' => 'ecommerce:handleContentUpdated',
    'on_delete' => 'ecommerce:handleContentDeleted',
]);
```

| Method | Signature | Purpose |
|--------|-----------|---------|
| `registerContract` | `(string $entityType, string $subscriberModule, array $handlers): void` | Register sync contract |
| `getContracts` | `(string $entityType): array` | Get all contracts for entity type |
| `hasContracts` | `(string $entityType): bool` | Check if any contracts exist |
| `contractsFor` | `(string $entityType, string $event): array` | Get handlers for specific event |

### Sync Contract Format

```php
[
    'on_create' => 'module:handlerFunction',  // Called after entity creation
    'on_update' => 'module:handlerFunction',  // Called after entity update
    'on_delete' => 'module:handlerFunction',  // Called after entity deletion
    'on_status_change' => 'module:handler',   // Called on status transitions
    'filter' => ['status' => 'published'],    // Optional: only trigger for matching entities
]
```

## Integration with Other Subsystems

### Entity Context System

EntityAuthority defines ownership; EntityContext defines capabilities:

```
EntityAuthorityRegistry: "CMS owns cms_content"
EntityContext/ContextRegistry: "cms_content has capabilities: render, edit, seo, ecommerce-gate"
```

Both are complementary and operate independently.

### IntegrationBridge

Cross-module entity operations route through IntegrationBridge, which validates the entity authority before allowing mutations:

```
Module B → IntegrationBridge.upsert('products', ...) 
           → checks EntityAuthorityRegistry 
           → routes to Module A's handler
```

### Workflow System

Workflow transitions can trigger sync contract handlers:

```
workflow.order.ship → SyncContract.on_status_change → wms:handleOrderShipped
```

## Data Flow

```
Module startup → registerAuthority() for owned entities
              → registerContract() for subscribed entities
                          ↓
Entity mutation occurs in authority module
                          ↓
Authority calls SyncContractRegistry.contractsFor(entityType, 'on_update')
                          ↓
Each subscriber handler invoked with entity data
                          ↓
Subscribers update their own derived data
```

## Conventions

- Each entity type has exactly one authority — no exceptions
- Authority registration happens during module boot (before first request)
- SyncContract handlers use `module:functionName` format (same as route handlers)
- Contracts are advisory: authority modules should call them but failure in a subscriber does not roll back the authority's operation
- Cross-module reads go through IntegrationBridge; writes go through the authority module's API
