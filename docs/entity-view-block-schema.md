# Entity.View Block Schema — Concrete DiSyL Template Specification

> Canonical reference for the universal entity rendering path.  
> Source of truth: `templates/modules/cms/public/entity.view.disyl`

---

## 1. Entity Contract (Template Input Shape)

Every `entity.view.disyl` render receives exactly this context object. No other
root keys are available to capability blocks.

```
{entity}                  — object: the cms_content row + joined author
  .id                     — int: primary key
  .title                  — string: entity title (escaped by engine)
  .slug                   — string: URL slug
  .type                   — string: content type machine name
  .status                 — string: "publish" | "draft" | "trash"
  .featured_image         — string|null: relative filename in uploads/
  .content_type_label     — string: human-readable type label
  .author_name            — string|null: display_name from cms_users
  .published_at           — string|null: formatted publish date

{capabilities}            — map<string, bool>: capability presence flags
  .pricing                — bool
  .inventory              — bool
  .booking                — bool
  .inquiry                — bool
  .progresstracking       — bool
  .lessonsindex           — bool
  .mediagallery           — bool

{capability_data}         — map<string, object>: resolved data per attached capability
  .pricing                — PricingData | absent
  .inventory              — InventoryData | absent
  .booking                — BookingData | absent
  .inquiry                — InquiryData | absent
  .progresstracking       — ProgressData | absent
  .lessonsindex           — LessonsData | absent
  .mediagallery           — GalleryData | absent

{post_html}               — string(raw): rendered body content (builder or classic)
{builder_enabled}         — bool: true when page-builder output is used
{builder_page_settings}   — object|null: builder container settings
  .container_class        — string: CSS class for builder wrapper
{base_url}                — string: site base URL (no trailing slash)
{csrf_token}              — string: CSRF token for forms
{theme_settings}          — object: active theme configuration
  .single_show_author     — bool
  .single_show_date       — bool
{cms_head}                — string(raw)|null: injected <head> content
{structured_data}         — string(raw)|null: JSON-LD structured data
```

---

## 2. Block Rendering Order

The template renders blocks in a fixed, deterministic order. Every block is
either always-present or capability-gated. No other order is valid.

```
1. MEDIA          — gated: capabilities.mediagallery (fallback: entity.featured_image)
2. HEADER         — always present
   2a. META       — always included (sub-block)
   2b. PROGRESS   — gated: capabilities.progresstracking
3. PRICING        — gated: capabilities.pricing
4. INVENTORY      — gated: capabilities.inventory
5. BODY           — always present (builder or prose)
6. LESSONS        — gated: capabilities.lessonsindex
7. ACTION         — gated: capabilities.pricing OR capabilities.booking OR capabilities.inquiry
```

---

## 3. Block Definitions

### 3.1 media-gallery.block.disyl

**Gate:** `{if capabilities.mediagallery}` → include; `{elseif entity.featured_image}` → fallback hero.

**Data contract — `GalleryData`:**

```
capability_data.mediagallery
  .items[]                — array of image objects
    .src                  — string: filename in uploads/
    .url                  — string|null: full URL override
    .thumb                — string|null: thumbnail URL
    .caption              — string|null: image caption
  .columns                — int: grid column count (default 3)
  .lightbox               — bool: enable lightbox (default true)
```

**HTML contract:**
- Root: `div.cms-gallery-block` with optional `data-gallery-lightbox="true"`
- Grid: CSS Grid with `repeat(columns, minmax(0, 1fr))`
- Each item: `<a>` wrapping `<img>` with lazy loading, hover scale, lightbox attrs
- Fallback: single `<img>` hero when gallery has no items but `entity.featured_image` exists

**CapabilityBus provider:** `entity.capability.mediagallery.data@1`  
**PHP function:** `cms_cap_entity_capability_media_gallery_data_1()`  
**Data source:** `cms_content_meta` where `meta_key = '_gallery'` (JSON array)

---

### 3.2 meta.block.disyl

**Gate:** Always included (no capability gate).

**Data contract (reads entity + theme_settings directly):**

```
entity.content_type_label   — string|null: type badge text
entity.author_name          — string|null: author display name
entity.published_at         — string|null: formatted date
theme_settings.single_show_author — bool: controls author visibility
theme_settings.single_show_date   — bool: controls date visibility
```

**HTML contract:**
- Root: `div.cms-entity-meta` flex row
- Type badge: `<span>` with sky-colored pill styling
- Author: conditional on `theme_settings.single_show_author`
- Date: conditional on `theme_settings.single_show_date`

**CapabilityBus provider:** None (direct entity fields).

---

### 3.3 progress.block.disyl

**Gate:** `{if capabilities.progresstracking}`

**Data contract — `ProgressData`:**

```
capability_data.progresstracking
  .percent                — int: 0–100 clamped progress value
  .authenticated          — bool: whether a user session exists
```

**HTML contract:**
- Root: `div.cms-progress-block`
- Authenticated: flex row with progress bar (`div.bg-sky-500` width = percent%) + label
- Unauthenticated: `<p>` with sign-in link pointing to `{base_url}/cms/login`
- ARIA: `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax="100"`

**CapabilityBus provider:** `entity.capability.progresstracking.data@1`  
**PHP function:** `cms_cap_entity_capability_progress_tracking_data_1()`  
**Data source:** `cms_content_meta` where `meta_key = '_progress_user_{userId}'` (JSON or int)

---

### 3.4 pricing.block.disyl

**Gate:** `{if capabilities.pricing}`

**Data contract — `PricingData`:**

```
capability_data.pricing
  .price                  — float|null: base price
  .currency               — string: ISO currency code (default "USD")
  .sale_price             — float|null: discounted price
```

**HTML contract:**
- Root: `div.cms-pricing-block` flex row
- Sale active: sky-colored sale price + gray line-through original + "Sale" pill
- Normal: bold gray price
- Prices formatted with `number_format:2` filter

**CapabilityBus provider:** `entity.capability.pricing.data@1`  
**PHP function:** `cms_cap_entity_capability_pricing_data_1()`  
**Data source:** `cms_content_meta` keys `_price`, `_currency`, `_sale_price`

---

### 3.5 inventory.block.disyl

**Gate:** `{if capabilities.inventory}`

**Data contract — `InventoryData`:**

```
capability_data.inventory
  .sku                    — string|null: SKU identifier
  .stock                  — int|null: current stock quantity
  .track_inventory        — bool: whether stock tracking is enabled
  .in_stock               — bool: true when stock > 0 or untracked
```

**HTML contract:**
- Root: `div.cms-inventory-block`
- In stock: green pill with checkmark SVG + optional "(N left)" when tracked
- Out of stock: red pill
- SKU: gray suffix text when present

**CapabilityBus provider:** `entity.capability.inventory.data@1`  
**PHP function:** `cms_cap_entity_capability_inventory_data_1()`  
**Data source:** `cms_content_meta` keys `_sku`, `_stock_qty`, `_track_inventory`

---

### 3.6 lessons.block.disyl

**Gate:** `{if capabilities.lessonsindex}`

**Data contract — `LessonsData`:**

```
capability_data.lessonsindex
  .items[]                — array of child entity rows
    .id                   — int: child entity id
    .title                — string: child entity title
    .slug                 — string: child URL slug
    .status               — string: "publish" | "draft"
  .child_type             — string: content type of children (default "lesson")
```

**HTML contract:**
- Root: `div.cms-lessons-block` bordered card with header + ordered list
- Header: `h3` "Contents"
- Each item: `<li>` with index number, linked title, optional "Draft" badge
- Draft items: reduced opacity + pointer-events disabled
- Max 200 children (query LIMIT)

**CapabilityBus provider:** `entity.capability.lessonsindex.data@1`  
**PHP function:** `cms_cap_entity_capability_lessons_index_data_1()`  
**Data source:** `cms_content` joined via `cms_content_meta._parent_id`

---

### 3.7 action.block.disyl

**Gate:** `{if capabilities.pricing or capabilities.booking or capabilities.inquiry}`

This is a compound block — it renders multiple CTAs based on which action
capabilities are attached. Each sub-section has its own inner gate.

**Sub-block: Buy (pricing)**
- Gate: `{if capabilities.pricing}` AND (no inventory capability OR `capability_data.inventory.in_stock`)
- HTML: `<form method="POST" action="{base_url}/api/v1/cms/cart/add">` with CSRF + entity_id
- Disabled state: gray button with "Out of Stock" when inventory attached and not in stock

**Sub-block: Book (booking)**
- Gate: `{if capabilities.booking}`
- HTML: `<a>` link to `{base_url}/{entity.slug}/book`
- Data contract — `BookingData`: `{ available_slots: array, stub: bool }` (stub until booking module)

**Sub-block: Inquire (inquiry)**
- Gate: `{if capabilities.inquiry}`
- HTML: `<a>` link to `{base_url}/{entity.slug}/inquire`
- Data contract — `InquiryData`:
  ```
  capability_data.inquiry
    .label                — string: CTA button text (default "Inquire")
    .form_fields          — string[]: field names for the inquiry form
  ```

**HTML contract:**
- Root: `div.cms-action-block` flex row with gap
- Primary CTA: `.cms-btn-primary` (buy) — sky-600 filled button
- Secondary CTAs: `.cms-btn-secondary` (book, inquire) — bordered outline buttons
- All buttons use inline SVG icons

**CapabilityBus providers:**  
- `entity.capability.pricing.data@1` (for stock check)  
- `entity.capability.booking.data@1` (stub)  
- `entity.capability.inquiry.data@1`

---

## 4. Capability → Block Mapping Table

| Capability ID       | CapabilityBus Key                          | Block File                  | Gate Position | Data Source                    |
|---------------------|--------------------------------------------|-----------------------------|---------------|--------------------------------|
| `pricing`           | `entity.capability.pricing.data@1`         | pricing.block.disyl         | slot 3        | `_price`, `_currency`, `_sale_price` meta |
| `inventory`         | `entity.capability.inventory.data@1`       | inventory.block.disyl       | slot 4        | `_sku`, `_stock_qty`, `_track_inventory` meta |
| `booking`           | `entity.capability.booking.data@1`         | action.block.disyl (sub)    | slot 7        | stub (future module)           |
| `inquiry`           | `entity.capability.inquiry.data@1`         | action.block.disyl (sub)    | slot 7        | config passthrough             |
| `progresstracking`  | `entity.capability.progresstracking.data@1`| progress.block.disyl        | slot 2b       | `_progress_user_{id}` meta     |
| `lessonsindex`      | `entity.capability.lessonsindex.data@1`    | lessons.block.disyl         | slot 6        | `cms_content` parent join      |
| `mediagallery`      | `entity.capability.mediagallery.data@1`    | media-gallery.block.disyl   | slot 1        | `_gallery` meta (JSON array)   |

---

## 5. Capability Config Schemas (Admin Input)

Registered in `cmsBuiltinEntityCapabilities()`. Each schema defines what the
admin configures when attaching a capability to an entity.

| Capability        | Config Field           | Type    | Default   | Required |
|-------------------|------------------------|---------|-----------|----------|
| pricing           | price                  | number  | —         | no       |
| pricing           | currency               | string  | "USD"     | —        |
| pricing           | sale_price             | number  | —         | no       |
| inventory         | track_stock            | boolean | true      | —        |
| inventory         | sku                    | string  | —         | no       |
| inventory         | stock_qty              | integer | 0         | —        |
| booking           | slot_duration_minutes  | integer | 60        | —        |
| booking           | advance_days           | integer | 30        | —        |
| inquiry           | label                  | string  | "Inquire" | —        |
| inquiry           | form_fields            | string  | "name,email,message" | — |
| progresstracking  | unit                   | string  | "percent" | —        |
| lessonsindex      | child_type             | string  | "lesson"  | —        |
| lessonsindex      | show_numbers           | boolean | true      | —        |
| mediagallery      | columns                | integer | 3         | —        |
| mediagallery      | lightbox               | boolean | true      | —        |

---

## 6. Data Resolution Pipeline

```
HTTP request → route match → handler
  ├─ cmsEntityGetCapabilities(entityId)     → map<capId, config>
  ├─ cmsEntityCapabilityContext(entityId)    → map<capId, bool>   → {capabilities}
  └─ cmsEntityCapabilityData(entityId, entity)
       └─ for each attached cap:
            bus.call("entity.capability.{capId}.data@1", {entity, config, entity_id})
            → provider function returns typed array → {capability_data.{capId}}
  ├─ cmsPublicContext(extra)                → theme_settings, menus, site meta
  └─ DiSyL::render("entity.view.disyl", mergedContext)
       └─ block includes resolve from templates/modules/cms/public/blocks/
```

Provider function naming convention:
```
cms_cap_entity_capability_{snake_case_id}_data_1()
```
Registered in `cms_capability_handlers()` → mapped to CapabilityBus key
`entity.capability.{capId}.data@1`.

---

## 7. Extension Points

### 7.1 Adding a New Entity Capability

1. **Register the type:** Hook `cms.entity.capabilities.register` to append a
   new `{id, label, description, icon, config_schema, default_config}` entry.

2. **Register the data provider:** Add a capability handler mapping in the
   providing module's `capability_handlers()`:
   ```php
   'entity.capability.newcap.data@1' => 'my_module_newcap_data_provider'
   ```

3. **Declare the capability in module.json** `exposes`:
   ```json
   { "id": "entity.capability.newcap.data@1", "handler": "my_module_newcap_data_provider" }
   ```

4. **Create the block template:** `templates/modules/{module}/public/blocks/newcap.block.disyl`
   reading from `capability_data.newcap`.

5. **Add the gate in entity.view.disyl** at the correct render slot:
   ```
   {if capabilities.newcap}
       {include "modules/{module}/public/blocks/newcap.block.disyl"}
   {/if}
   ```

### 7.2 Overriding a Built-in Provider

Register the same capability ID at higher priority. The CapabilityBus resolves
the highest-priority handler. Example: a dedicated ecommerce module overriding
the default pricing provider with Stripe-integrated pricing.

### 7.3 Preset Integration

Presets (`config/entity-presets/*.json`) declare `default_capabilities[]` that
are batch-attached via `cmsApplyEntityPreset()`. Each preset entry:
```json
{ "id": "pricing", "config": { "currency": "USD" } }
```
The preset system calls `cmsEntityAttachCapability()` per entry, merging
provided config over the type's `default_config`.

---

## 8. Entity.List Block Integration

`entity.list.disyl` renders card-level capability summaries inline. Each list
item carries its own `capabilities` and `capability_data` sub-objects:

```
items[]
  .capabilities.pricing               → inline price on card
  .capability_data.pricing.price      → displayed value
  .capability_data.pricing.sale_price → sale display
  .capability_data.pricing.currency   → currency prefix
  .capabilities.progresstracking      → progress bar on card
  .capability_data.progresstracking.percent → bar width
  .capability_data.progresstracking.authenticated → controls bar visibility
```

Only pricing and progress are rendered on list cards. Other capabilities are
detail-view only.

---

## 9. CSS Class Contract

Block root elements use stable CSS classes for theme token targeting:

| Block              | Root Class              | Theme-Safe Targets                     |
|--------------------|------------------------|----------------------------------------|
| Entity wrapper     | `.cms-entity-view`     | `max-w-*`, `px-*`, `py-*`              |
| Hero image         | `.cms-entity-hero`     | `max-h-*`, `rounded-*`                 |
| Header             | `.cms-entity-header`   | typography tokens                       |
| Meta               | `.cms-entity-meta`     | `text-*`, `gap-*`                       |
| Pricing            | `.cms-pricing-block`   | `text-*` color tokens                   |
| Inventory          | `.cms-inventory-block` | pill color tokens                       |
| Body               | `.cms-entity-body`     | prose tokens, builder overrides         |
| Gallery            | `.cms-gallery-block`   | `gap-*`, `rounded-*`                    |
| Lessons            | `.cms-lessons-block`   | border, divide tokens                   |
| Progress           | `.cms-progress-block`  | bar color token (`bg-sky-500`)          |
| Action             | `.cms-action-block`    | `.cms-btn-primary`, `.cms-btn-secondary`|

Themes MUST NOT override structural layout (grid, flex, order). They MAY
override color tokens, border-radius, and typography tokens via the design
token system.
