---
name: entity-view-system
description: Entity view pipeline: resolver, renderers, cell types, action wiring, inline editing
applyTo: "**/entity-views.php"
---

# Entity View System

## Pipeline
1. `{ikb_entity_list source="entity.qualifier" view="table"}` in template
2. `DefaultEntityRenderer::renderList()` called via `app()->entityRenderers()`
3. `EntityViewResolver::resolve("entity.qualifier")` parses source → looks up `viewContract` → falls back to `builtinDefaults`
4. Capability bus calls `entity.list.{entity}@{version}` handler
5. Handler returns `['rows' => [...], 'total' => N]`
6. `DefaultEntityRenderer` renders table/card/compact view with actions, renderers, pagination

## View registration order (module overrides kernel)
1. `$views->registerView('entity', 'view', [...])` in module `helpers/entity-views.php`
2. `EntityViewResolver::builtinDefaults()` in `kernel/EntityContext/EntityViewResolver.php`

Module-level view contracts are checked first, then builtin defaults.

## Cell renderers
| Renderer | Format | Example |
|---|---|---|
| text | auto | (default) |
| badge | `badge:{"key":"Label|color"}` | `badge:{"active":"Active|green","pending":"Pending|amber"}` |
| money | `money:{decimals}` | `money:2` |
| datetime | `datetime:{format}` | `datetime:date`, `datetime:time`, `datetime:full` |
| boolean | `boolean` | Shows ✓ or ✗ |
| location | `location` | Place name + 📍 lat,lng |
| image | `image:{arg}` | `image:modal` for lightbox |

## Action wiring
```php
'actions' => ['view', 'edit', 'delete'],
'action_urls' => ['view' => '/path/{id}', 'edit' => '/path/{id}'],
'action_methods' => ['delete' => 'post'],
'action_labels' => ['view' => 'View', 'delete' => 'Delete'],
'action_confirm' => ['delete' => 'Are you sure?'],
'action_show_if' => ['edit' => 'status == "pending"'],
'action_roles' => ['delete' => ['admin']],
```

## Row-click navigation
```php
'row_click' => ['action' => 'view'],  // click row → triggers view action
```

## Inline editing
```php
'field_contracts' => [
    'hours' => ['editable' => 'true', 'update_capability' => 'capability.id@1'],
],
```
Renders via `renderCellEditable()` → Alpine.js component `ikb-inline-edit`.

## Key considerations
- `key_field` defaults to `'id'`; always ensure key field + `id` in query fields for URL interpolation
- Use `rawurlencode()` for URL params containing names (spaces, special chars)
- Module DB layer blocks table aliases — use full table names
- `CONCAT_WS` with `NULLIF(col, '')` to avoid trailing spaces in computed names
