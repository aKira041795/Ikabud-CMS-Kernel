# Entity-View Component Catalog

> **Generated**: 2026-06-20 | **Source**: `kernel/DiSyL/ComponentRegistry.php` (auto-loaded at kernel boot)
> This catalog describes the canonical entity grammar available to DiSyL templates.

---

## Core Entity Components

These components form the "canonical entity grammar" — the stable contract between module truth and theme presentation.

### `ikb_entity_list`
- **Category**: data
- **Description**: Governed entity list resolved from a `source`/`view` declaration. The Kernel resolves the entity type, applies policy, fetches data via the capability bus, and renders each item through the declared view.
- **Key Attributes**:
  - `source` (required) — e.g. `"orders.recent"`, `"products.featured"`
  - `view` (required) — e.g. `"compact"`, `"card_grid"`, `"table"`, `"admin_row"`
  - `limit` — max items
  - `offset` — pagination offset
  - `filter` — contextual filter expression
- **Slots**: default (item template), `empty` (zero-state)
- **Example**:
  ```html
  <ikb_entity_list source="orders.recent" view="compact" limit="10">
      <ikb_card>{order.total}</ikb_card>
      <ikb_slot name="empty">No recent orders</ikb_slot>
  </ikb_entity_list>
  ```

### `ikb_entity_detail`
- **Category**: data
- **Description**: Governed entity detail view for a single entity. Resolves entity identity from context (`entity="current"`) or explicit id.
- **Key Attributes**:
  - `entity` (required) — `"current"` or an entity identifier
  - `view` — presentation variant
- **Example**:
  ```html
  <ikb_entity_detail entity="current" view="product-detail">
      <ikb_entity_pricing />
      <ikb_entity_inventory />
      <ikb_entity_actions />
  </ikb_entity_detail>
  ```

### `ikb_audit_log`
- **Category**: data
- **Description**: Governed audit log viewer. Displays audit trail entries for the current entity or a specified target.
- **Key Attributes**:
  - `target` — entity type and id
  - `limit` — entries per page
- **Example**:
  ```html
  <ikb_audit_log target="order:42" limit="20" />
  ```

---

## Data Display Components

### `ikb_stat_card`
- **Category**: data
- **Description**: Stat card displaying a label, value, trend indicator, and optional icon.
- **Key Attributes**:
  - `label` — stat name
  - `value` — stat value
  - `trend` — `"up"`, `"down"`, `"flat"`
  - `icon` — icon identifier
- **Example**:
  ```html
  <ikb_stat_card label="Revenue" value="{revenue|number_format:2}" trend="up" />
  ```

### `ikb_timeline`
- **Category**: data
- **Description**: Chronological list of events with vertical connector.
- **Key Attributes**:
  - `source` — capability or data source
  - `date_field` — field to use for chronological ordering
- **Example**:
  ```html
  <ikb_timeline source="case.history" date_field="created_at" />
  ```

---

## Interactive Components

### `ikb_export_button`
- **Category**: interactive
- **Description**: Governed export button. Downloads entity data as PDF, DOCX, CSV, or XLSX. Resolves export permissions via the capability bus.
- **Key Attributes**:
  - `format` — `"pdf"`, `"docx"`, `"csv"`, `"xlsx"`
  - `source` — entity source to export
  - `label` — button text
- **Example**:
  ```html
  <ikb_export_button format="csv" source="orders.recent" label="Export Orders" />
  ```

### `ikb_confirm_action`
- **Category**: interactive
- **Description**: Wraps destructive actions with an Alpine.js confirmation dialog.
- **Key Attributes**:
  - `message` — confirmation message
  - `action` — capability to invoke on confirm
- **Example**:
  ```html
  <ikb_confirm_action message="Cancel this order?" action="order.cancel@1">
      <button>Cancel Order</button>
  </ikb_confirm_action>
  ```

---

## AI Components

### `ikb_ai_summary`
- **Category**: data
- **Description**: Governed AI summary block. Generates summaries under kernel AI policy (tier, provider, content mode, review queue).
- **Key Attributes**:
  - `source` — entity or content source to summarize
  - `max_length` — maximum summary length
  - `style` — `"concise"`, `"detailed"`, `"bullet_points"`
- **Example**:
  ```html
  <ikb_ai_summary source="case.notes" max_length="200" style="concise" />
  ```

### `ikb_ai_assist`
- **Category**: data
- **Description**: Governed AI assist block. Drafts content under kernel AI policy with human approval gating.
- **Key Attributes**:
  - `prompt` — instruction for the AI
  - `target` — target field or entity to draft into
- **Example**:
  ```html
  <ikb_ai_assist prompt="Write a product description for this item" target="description" />
  ```

---

## Structural & Layout Components

### `ikb_section`
- **Category**: structural
- **Description**: Main structural container for page sections. Theme-aware via design tokens.
- **Key Attributes**: `padding`, `background`, `max_width`, `alignment`

### `ikb_container`
- **Category**: structural
- **Description**: Responsive container with configurable max-width.

### `ikb_panel`
- **Category**: layout
- **Description**: Semantic panel — theme-aware container with design tokens for borders, shadows, and spacing.

### `ikb_block`
- **Category**: structural
- **Description**: Generic content block with layout options (flex, grid, stack).

### `ikb_drawer`
- **Category**: navigation
- **Description**: Slide-out drawer panel with Alpine.js teleport for mobile navigation.

---

## Media & Content Components

### `ikb_image`
- **Category**: media
- **Description**: Responsive image with optimization (srcset, lazy loading, placeholder fallback).

### `ikb_text`
- **Category**: ui
- **Description**: Text content with formatting (headings, paragraphs, inline styles).

### `ikb_card`
- **Category**: ui
- **Description**: Card component for displaying content with optional header, body, and footer slots.

---

## Form & Query Components

### `ikb_form`
- **Category**: form
- **Description**: Governed form that submits to capability actions with built-in CSRF protection.
- **Key Attributes**:
  - `handler` — capability ID to invoke on submit
  - `method` — `"POST"` (default)
  - `redirect` — URL to redirect after success

### `ikb_query`
- **Category**: data
- **Description**: Query and loop over content items with optional auto-rendering.
- **Key Attributes**:
  - `type` — content type to query
  - `limit`, `offset`, `order_by`

---

## Control Flow Components

### `if`
- **Category**: control
- **Description**: Conditional rendering block.

### `for`
- **Category**: control
- **Description**: Loop over items with index and item variables.

### `include`
- **Category**: control
- **Description**: Include another template at render time.

---

## How Modules Extend This Catalog

Modules register new entity-view components via `TemplateEngine::registerComponent()`:

```php
app()->templateEngine()->registerComponent('ikb_product_gallery', function (array $attrs, string $slot) {
    return render_product_gallery($attrs['product_id']);
});
```

Currently all 22 components are kernel-defined. The `registerComponent()` API exists for modules to add domain-specific components in their `helpers.php` bootstrap phase.

---

## Component Discovery

Components can be introspected at runtime:

```php
// List all registered component names
\Ikabud\Kernel\DiSyL\ComponentRegistry::all();

// Get a single component definition (attributes, slots, description, etc.)
\Ikabud\Kernel\DiSyL\ComponentRegistry::get('ikb_entity_list');

// Check if a component exists
\Ikabud\Kernel\DiSyL\ComponentRegistry::has('ikb_entity_list');
```
