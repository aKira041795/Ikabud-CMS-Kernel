# Entity Context System

**Subsystem:** `kernel/EntityContext/`  
**Status:** Production  
**Last updated:** 2026-06-11

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

---

## EntityViewResolver — Rendering Behavior

`kernel/EntityContext/EntityViewResolver.php` resolves entity sources to rendered output
via DiSyL entity components (`ikb_entity_detail`, `ikb_entity_list`).

### View Contract Resolution

1. `resolve($source, $view)` parses the source string (e.g., `weather.forecast`)
2. Looks up registered view contracts via `viewContract()` → `builtinDefaults()`
3. Calls the appropriate `entity.get.{type}` or `entity.list.{type}` capability
4. Returns `{entity, view contract}` for detail or `{rows, view contract}` for list

### Filtered Entity Lists

The `{ikb_entity_list}` tag supports a `filter` attribute to pass criteria to
capability handlers:

```disyl
{ikb_entity_list source="pal_expense" filter="project_id={project.id}" /}
```

The filter parser in `TemplateEngine::renderEntityListViaService()`:
1. Parses comma-separated `key=value` pairs from the `filter` attribute
2. Resolves `{var.path}` references from the template context (e.g., `{project.id}` → context variable)
3. Passes resolved filters as `$overrides['filters']` to `EntityViewResolver::resolve()`
4. Capability handlers receive `$args['filters']` and add WHERE clauses accordingly

Multiple filters can be combined:
```disyl
{ikb_entity_list source="pal_expense" filter="project_id={project.id},status=approved" /}
```

### Entity Detail Views

The `{ikb_entity_detail}` tag renders a single entity record using a `detailed` or `summary`
view contract:

```disyl
{ikb_entity_detail source="pal_expense" id="{expense.id}" view="detailed" /}
```

Resolution flow:
1. `resolveDetail($source, $entityId, $view)` calls `entity.get.{type}@1` capability
2. Capability handler returns `['ok' => true, 'data' => $row]`
3. EntityViewResolver unwraps the `data` key for direct field access
4. `DefaultEntityRenderer::renderDetail()` renders each field as a label-value row
5. Fields defined in the view contract are rendered in declaration order

### Field Auto-Detection (Wildcard `*`)

When rendering entity lists, if the resolved view contract has `fields: '*'` (or no
fields at all), `TemplateEngine::renderEntityList()` automatically expands the
wildcard to the actual keys from the first result row:

```php
// In TemplateEngine::renderEntityList():
if ($fields === ['*'] || $fields === '*') {
    $firstRow = $rows[0] ?? [];
    $fields = array_values(array_filter(array_keys($firstRow),
        fn($k) => !str_starts_with($k, '_')));
}
```

This enables **polyglot services** to render entity lists without pre-registering
explicit view contracts. The renderer auto-detects field names from whatever the
external service returns.


### Source Schemas (owner-sources-only)

`registerView()` now accepts an optional `source_schema` block:

```php
'source_schema' => [
    'entity' => 'products',
    'owner' => 'ecommerce',
    'fields' => [
        'id' => 'int',
        'name' => 'string',
        'price' => 'float',
    ],
]
```

Rules enforced by `EntityViewResolver`:
- **Owner-sources-only by default** — `owner` defaults to the provider module when omitted.
- **Cross-module default deny** — a provider cannot claim another module's source unless `cross_module_approved` is explicitly used.
- **Fixed type vocabulary** — source-schema fields use the kernel's fixed type set; invalid field types are rejected at registration time.
- **Field validation** — fields referenced by the view must exist in `source_schema.fields`.
- **Structural kernel fallback preserved** — kernel fallback contracts remain structural-only and do not claim business entities, business fields, or business actions.

Adoption delivered in 6.2: the `ecommerce` module now owns the `products` and `ecommerce_product` defaults in `modules/ecommerce/helpers/43-entity-views.php`, and the resolver preserves a structural-only fallback in `kernel/EntityContext/EntityViewResolver.php`.

Verification: `tests/entity_source_schema_test.php`.

### Built-in Defaults

`builtinDefaults()` provides fallback view contracts for common entity types:

| Entity Type | Fields | View |
|---|---|---|
| orders | id, status, total, created_at | compact |
| products | id, name, price, image | compact |
| cases | id, title, status, updated_at | compact |
| ledger | id, entry_type, amount, created_at | compact |
| appointments | id, title, date, status | compact |
| tickets | id, subject, status, created_at | compact |
| weather | date, high_c, low_c, condition | compact |

### RowRenderContext Value Object

`kernel/EntityContext/RowRenderContext.php` consolidates the 15 shared parameters
across row rendering methods to prevent parameter drift:

```php
$ctx = new RowRenderContext(
    row: $row, fields: $fields, actions: $actions, use: $use,
    actionUrls: $actionUrls, actionMethods: $actionMethods,
    actionConfirm: $actionConfirm, actionShowIf: $actionShowIf,
    actionLabels: $actionLabels, renderers: $renderers,
    rowClick: $rowClick, rowClickTarget: $rowClickTarget,
    userRole: $userRole, actionRoles: $actionRoles,
    hasBulk: $hasBulk,          // table-only
    fieldContracts: $fieldContracts,  // table-only
    roleFields: $roleFields,    // semantic role→field mapping
);
```

Used by `renderCompactRow()`, `renderCardGridRow()`, `renderTableRow()`, and
`renderRowActions()` in `DefaultEntityRenderer`.

### Semantic Role Fields (v4.8)

View contracts can declare semantic roles that override positional field ordering in card_grid:

```disyl
{field name="title"   type="string" role="title"}
{field name="excerpt" type="string" role="subtitle"}
{field name="image"   type="string" role="image"}
```

The `$roleFields` mapping (`title→fieldName`, `subtitle→fieldName`, `image→fieldName`) is stored in the view contract and passed through `RowRenderContext::$roleFields` to `renderCardGridRow()`, which uses them as the primary field ordering. Supported roles: `title`, `subtitle`, `image`, `body`, `description`.

### Render Context Fallback

`renderWithRowContext()` accepts an optional `$fallbackContext` parameter. When a template variable (e.g., `{base_url}`) is not found in the row data, the renderer falls back to the fallback context before rendering a literal `{base_url}`. This enables action URLs like `{base_url}/cms/blog/{slug}` to resolve even when `base_url` isn't in the entity row data.

### Timeout Handling

Polyglot services hitting external APIs may exceed the default 2000ms capability
call timeout. Both `EntityViewResolver::resolve()` and `TemplateEngine::renderEntityDetail()`
pass `timeout_ms => 10000` to capability calls. Adjust in code if your service
needs a different timeout.
