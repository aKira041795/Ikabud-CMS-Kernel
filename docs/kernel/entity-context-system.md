# Entity Context System

**Subsystem:** `kernel/EntityContext/`  
**Status:** Production  
**Last updated:** 2026-04-15

## Overview

The Entity Context System provides a registry-driven mechanism for binding entity types (e.g., `cms_content`, `ecommerce_product`) to capability profiles. Modules register context definitions, extend them, and bind entity types to those contexts. At resolution time the registry merges all definitions by priority and returns a complete capability map for a given entity type.

## Core Classes

### ContextProfile

`kernel/EntityContext/ContextProfile.php`

A single context profile that defines capabilities, metadata, and provider sources.

```php
$profile = new ContextProfile('cms.content');
$profile->setLabel('CMS Content')
    ->addCapability('cms.entity.render@1', ['template' => 'page'])
    ->addCapability('cms.entity.edit@1')
    ->addSource('cms')
    ->mergeMeta(['icon' => 'document']);
```

**Constructor:** `__construct(string $id, array $definition = [])`

| Method | Purpose |
|--------|---------|
| `id(): string` | Normalized ID (`strtolower(trim($id))`) |
| `label(): string` | Human label (auto-derived from ID if not set) |
| `addCapability(string $capabilityId, array $definition = []): self` | Add/merge capability definition |
| `addCapabilities(array $capabilities): self` | Bulk add |
| `addSource(string $source): self` | Register provider source (auto-sorted, deduped) |
| `mergeMeta(array $meta): self` | Recursive metadata merge |
| `merge(array $definition): self` | Merge full definition (label, capabilities, meta, sources) |
| `toArray(): array` | Canonical form: `{id, label, capabilities, meta, sources}` |

### ContextRegistry

`kernel/EntityContext/ContextRegistry.php`

Central registry for context definitions, extensions, entity-type bindings, and capability definitions.

```php
$registry = new ContextRegistry();

// Register base context
$registry->registerContext('cms.content', [
    'label' => 'CMS Content',
    'capabilities' => ['cms.entity.render@1' => ['template' => 'page']],
], 'cms', 10);

// Extend with ecommerce capabilities
$registry->extendContext('cms.content', [
    'capabilities' => ['ecommerce.content.gate@1' => []],
], 'ecommerce', 20);

// Bind entity type
$registry->bindEntityType('cms_page', [
    'base' => 'cms.content',
    'extensions' => [],
]);

// Resolve full context
$resolved = $registry->resolve('cms_page');
// → {entity_type, binding, contexts, capabilities, capability_ids, capability_flags, blocks, overrides}
```

**Priority model:** Higher priority values are applied last (override earlier registrations). Uses `array_replace_recursive()`.

| Method | Purpose |
|--------|---------|
| `registerContext(string $contextId, array $definition, string $providerId, int $priority)` | Register context definition |
| `extendContext(string $contextId, array $extension, string $providerId, int $priority)` | Add extensions |
| `bindEntityType(string $entityType, array $binding, string $providerId, int $priority)` | Bind entity type to context |
| `registerCapability(string $capabilityId, array $definition, string $providerId, int $priority)` | Register capability definition |
| `resolve(string $entityType, array $options = []): array` | Resolve entity type to full capability context |
| `buildCustomizerSchema(array $resolvedContext, array $baseSections = []): array` | Build customizer schema from resolved context |
| `hasContext(string $contextId): bool` | Check existence |
| `contextIds(): string[]` | All context IDs (sorted) |
| `capabilityIds(): string[]` | All capability IDs (sorted) |

### Resolution Flow

1. `resolve($entityType)` looks up the binding for the entity type
2. Merges the base context profile with all registered extensions
3. Attaches per-entity-type capability overrides
4. Returns a flat map of all capabilities, blocks, and flags

## Data Flow

```
Module manifest → registerContext() / extendContext()
                         ↓
                  ContextRegistry (priority-sorted)
                         ↓
          bindEntityType() links entity types to contexts
                         ↓
          resolve(entityType) → merged capabilities + blocks + flags
                         ↓
          buildCustomizerSchema() → admin UI sections
```

## Properties

- `$schemas`, `$profiles`, `$modes` — Reserved for Phase 3B introspection (future)
