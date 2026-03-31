# Entity.View Block Schema — Concrete DiSyL Template Specification

> Canonical reference for the universal entity rendering path.  
> Source of truth: `templates/modules/cms/public/entity.view.disyl`

For a higher-level explanation of how this schema relates to CMS theme design and the kernel modular system, see `docs/theme-entity-view-primer.md`.

This document describes the canonical entity-view contract. It should be read with one architectural rule in mind:

- entity presentation is controlled through approved theme-customizer selections
- entity behavior is controlled through capabilities and modules
- entity structure is not supposed to fork into per-theme entity template families

Compatibility paths for theme overrides may still exist in runtime helpers, but they are not the preferred design model for entity pages.

---

## 1. Entity Contract (Template Input Shape)

Every canonical `entity.view.disyl` render is normalized through the kernel
render-context finalize hook before DiSyL executes. Capability blocks and theme
overrides can rely on the following root keys being present. Theme adapters may
still add extra roots, but this is the stable minimum contract at the entity
render boundary.

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
  .progress_tracking       — bool
  .lessons_index           — bool
  .media_gallery           — bool

{capability_data}         — map<string, object>: resolved data per attached capability
  .pricing                — PricingData | absent
  .inventory              — InventoryData | absent
  .booking                — BookingData | absent
  .inquiry                — InquiryData | absent
  .progress_tracking       — ProgressData | absent
  .lessons_index           — LessonsData | absent
  .media_gallery           — GalleryData | absent

{entity_context}          — object: resolved entity-context profile for the content type
{entity_view_context}     — object: per-render visibility and layout toggles
  .show_header            — bool
  .show_meta              — bool
  .show_media             — bool
  .show_summary           — bool
  .show_lessons           — bool
  .show_taxonomies        — bool
  .show_back_link         — bool | absent

{entity_taxonomies}       — object: taxonomy collections for the canonical footer rail
  .categories[]           — array<{id:int,name:string,slug:string,url:string}>
  .tags[]                 — array<{id:int,name:string,slug:string,url:string}>
{show_entity_categories}  — bool: true when categories exist and presentation enables them
{show_entity_tags}        — bool: true when tags exist and presentation enables them
{entity_back_link_url}    — string: canonical back-link target or empty string
{entity_back_link_label}  — string: canonical back-link label

{post_html}               — string(raw): rendered body content (builder or classic)
{builder_enabled}         — bool: true when page-builder output is used
{builder_page_settings}   — object|null: builder container settings
  .container_class        — string: CSS class for builder wrapper
{base_url}                — string: site base URL (no trailing slash)
{csrf_token}              — string: CSRF token for forms
{theme_settings}          — object: active theme configuration
  .single_show_author     — bool
  .single_show_date       — bool
{entity_presentation}     — object: normalized canonical presentation profile for the entity route
{entity_presentation_settings} — object: persisted entity-presentation customizer settings
{cms_head}                — string(raw)|null: injected <head> content
{structured_data}         — string(raw)|null: JSON-LD structured data
{cart_enabled}            — bool: true when cms.cart.add@1 is registered on CapabilityBus AND entity has pricing
{cart_action_url}         — string: POST endpoint for cart add ("{base_url}/ecommerce/cart/add") or empty string
{action_sections}         — string(raw): output of cms.entity.action_block.sections hook, or empty string
{public_render_origin}    — string: canonical renderer origin (`cms` or `ecommerce`)
{public_route_kind}       — string: canonical route classifier (`post`, `page`, `product`, `search`, ...)
{public_presentation_mode} — string: canonical presentation family (`canonical`, `entity_view`, ...)
{storefront}              — object: storefront adapter context when the route originates from ecommerce
```

Presentation settings generated by the active theme customizer may influence layout, tokens, and surrounding public shell behavior, but they do not change the core entity root contract described above.

---

## 2. Block Rendering Order

The template renders blocks in a fixed, deterministic order. Every block is
either always-present or capability-gated.

Approved layout profiles may suppress or emphasize certain segments, but the
entity-view contract is still canonical. Themes and customizer settings must
not invent ad hoc block orders outside documented profiles.

```
1. MEDIA          — gated: capabilities.media_gallery (fallback: entity.featured_image)
2. HEADER         — always present
   2a. META       — always included (sub-block)
   2b. PROGRESS   — gated: capabilities.progress_tracking
3. PRICING        — gated: capabilities.pricing
4. INVENTORY      — gated: capabilities.inventory
5. BODY           — always present (builder or prose)
6. LESSONS        — gated: capabilities.lessons_index
7. ACTION         — gated: capabilities.pricing OR capabilities.booking OR capabilities.inquiry
```

---

## 3. Block Definitions

### 3.1 media-gallery.block.disyl

**Gate:** `{if capabilities.media_gallery}` → include; `{elseif entity.featured_image}` → fallback hero.

**Data contract — `GalleryData`:**

```
capability_data.media_gallery
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

**CapabilityBus provider:** `entity.capability.media_gallery.data@1`  
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

**Gate:** `{if capabilities.progress_tracking}`

**Data contract — `ProgressData`:**

```
capability_data.progress_tracking
  .percent                — int: 0–100 clamped progress value
  .authenticated          — bool: whether a user session exists
```

**HTML contract:**
- Root: `div.cms-progress-block`
- Authenticated: flex row with progress bar (`div.bg-sky-500` width = percent%) + label
- Unauthenticated: `<p>` with sign-in link pointing to `{base_url}/cms/login`
- ARIA: `role="progressbar"`, `aria-valuenow`, `aria-valuemin="0"`, `aria-valuemax="100"`

**CapabilityBus provider:** `entity.capability.progress_tracking.data@1`  
**PHP function:** `cms_cap_entity_capability_progress_tracking_data_1()`  
**Data source:** `cms_entity_progress` table (primary — `entity_id`, `user_id`, `percent` columns; inserted/upserted on progress update, range-clamped 0–100); falls back to `cms_content_meta` where `meta_key = '_progress_user_{userId}'` for pre-migration 022 data

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

**Gate:** `{if capabilities.lessons_index}`

**Data contract — `LessonsData`:**

```
capability_data.lessons_index
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

**CapabilityBus provider:** `entity.capability.lessons_index.data@1`  
**PHP function:** `cms_cap_entity_capability_lessons_index_data_1()`  
**Data source:** `cms_content` joined via `cms_content_meta._parent_id`

---

### 3.7 action.block.disyl

**Gate:** `{if capabilities.pricing or capabilities.booking or capabilities.inquiry}`

This is a compound block — it renders multiple CTAs based on which action
capabilities are attached. Each sub-section has its own inner gate.

**Action-sections override (all sub-blocks)**
- Gate: `{if action_sections}` — when non-empty, renders the hook output verbatim and skips all built-in sub-blocks below
- Hook: `cms.entity.action_block.sections` receives `(entity, capabilities, capability_data, base_url)`; return a raw HTML string to replace the entire action block interior

**Sub-block: Buy (pricing)**
- Gate: `{if capabilities.pricing}` AND (no inventory capability OR `capability_data.inventory.in_stock`)
- Cart availability gate: renders buy form only when `{cart_enabled}` is `true` (requires `cms.cart.add@1` registered on CapabilityBus AND pricing capability attached)
- HTML (cart enabled): `<form method="POST" action="{cart_action_url}">` with CSRF + entity_id; primary CTA button
- HTML (cart disabled): `<p class="cms-cart-pending">`"Price shown — cart coming soon"` </p>` fallback paragraph
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
| `progress_tracking`  | `entity.capability.progress_tracking.data@1`| progress.block.disyl        | slot 2b       | `cms_entity_progress` table (primary); `_progress_user_{id}` meta (legacy fallback) |
| `lessons_index`      | `entity.capability.lessons_index.data@1`    | lessons.block.disyl         | slot 6        | `cms_content` parent join      |
| `media_gallery`      | `entity.capability.media_gallery.data@1`    | media-gallery.block.disyl   | slot 1        | `_gallery` meta (JSON array)   |

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
| progress_tracking  | unit                   | string  | "percent" | —        |
| lessons_index      | child_type             | string  | "lesson"  | —        |
| lessons_index      | show_numbers           | boolean | true      | —        |
| media_gallery      | columns                | integer | 3         | —        |
| media_gallery      | lightbox               | boolean | true      | —        |

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

### 7.4 Action Block Sections Hook

**Hook:** `cms.entity.action_block.sections`

**Args passed to each listener:** `entity, capabilities, capability_data, base_url`

**Return value:** string — raw HTML that replaces the entire interior of `.cms-action-block`. An empty string or absent return leaves built-in sub-blocks intact.

**Evaluation order:** Hook is resolved in `cmsPublicEntityRender()` before the DiSyL render call; result is injected into the context as `{action_sections}`.

**Use cases:**
- Inject custom subscription-tier CTAs (e.g. free / pro / enterprise tier buttons)
- Render comparison CTAs from an external payment provider
- Fully replace all buy/book/inquire sub-blocks with a third-party cart widget

**Example:**
```php
app()->hooks()->on('cms.entity.action_block.sections', function($entity, $caps, $data, $base) {
    if (!$caps['pricing'] ?? false) return '';
    return '<a class="cms-btn-primary" href="' . $base . '/subscribe/' . $entity['slug'] . '">Subscribe</a>';
});
```

---

## 8. Entity.List Block Integration

`entity.list.disyl` renders card-level capability summaries through canonical
list-card block fragments prepared by the handler. Each list item carries its
own `capabilities`, `capability_data`, and pre-rendered list-card HTML:

```
items[]
  .capabilities.pricing               → inline price on card
  .capability_data.pricing.price      → displayed value
  .capability_data.pricing.sale_price → sale display
  .capability_data.pricing.currency   → currency prefix
  .capabilities.inventory             → inventory pill on card when enabled
  .capability_data.inventory.*        → compact stock state summary
  .capabilities.progress_tracking      → progress bar on card
  .capability_data.progress_tracking.percent → bar width
  .capability_data.progress_tracking.authenticated → controls bar visibility
  .list_card_pricing_html             → rendered pricing fragment
  .list_card_inventory_html           → rendered inventory fragment
  .list_card_progress_html            → rendered progress fragment
  .list_card_action_html              → rendered add-to-cart / action fragment (when cart_enabled + pricing)
```
Approved list-card block variants follow the same resolver contract as entity
view blocks. Current list-card variants are:

- `list-card-pricing`: `featured`, `minimal`
- `list-card-inventory`: `compact`
- `list-card-progress`: `inline`
- `list-card-action`: (default only)

These variants change presentation only; they do not alter capability gates or
data contracts.

### 8.1 Draft Entity Card Preset Contract

This section defines a proposed contract for **entity card presets**. It is a
design draft for architecture and contributor guidance, not a statement that a
runtime registry already exists.

Entity card presets are meant to solve one problem: give modules and builders a
reusable, named card composition model without pushing behavior back into theme
files.

#### Purpose

An entity card preset should let the platform say:

- use this named card composition for a commerce-style list item
- keep pricing, inventory, progress, and action logic in canonical fragments
- let the active theme customizer keep final control over approved variants
- let the page builder opt into the same card language without inventing a
  second card system

#### Ownership rule

Entity card presets should be **module-owned composition metadata**.

- modules define which slots and fragments belong to a named card preset
- entity presets may nominate a default card preset
- themes may style the resulting card through tokens and approved variants
- the customizer may select approved runtime presentation values
- builders may choose or inherit the preset by ID

Themes must not become the source of truth for card behavior.

#### Proposed contract shape

```json
{
  "id": "commerce-standard",
  "label": "Commerce Standard",
  "description": "Balanced product card with pricing, inventory, and CTA.",
  "contexts": ["commerce"],
  "entity_types": ["product"],
  "slot_order": [
    "media",
    "header",
    "excerpt",
    "pricing",
    "inventory",
    "action"
  ],
  "fragments": {
    "pricing": "list-card-pricing",
    "inventory": "list-card-inventory",
    "progress": "list-card-progress",
    "action": "list-card-action"
  },
  "requires": {
    "pricing": ["pricing"],
    "inventory": ["inventory"],
    "progress": ["progress_tracking"],
    "action": ["pricing"]
  },
  "defaults": {
    "entity_presentation": {
      "entity_list_card_density": "comfortable",
      "entity_list_show_excerpt": true,
      "entity_list_excerpt_length": 120,
      "entity_list_card_min_width": 240
    },
    "block_variants": {
      "list-card-pricing": "featured",
      "list-card-inventory": "compact",
      "list-card-progress": "inline"
    }
  },
  "builder_defaults": {
    "entity_list": {
      "card_preset": "commerce-standard"
    },
    "products_grid": {
      "card_preset": "commerce-standard"
    }
  },
  "preview": {
    "entity_id": null,
    "example_context": "commerce"
  }
}
```

#### Field meanings

| Field | Meaning |
|-------|---------|
| `id` | Stable machine name used by presets, builder widgets, or future APIs |
| `label` | Human-readable name shown in admin or builder UI |
| `description` | Short explanation of the card intent |
| `contexts` | Allowed entity-context IDs this preset is designed for |
| `entity_types` | Optional narrower type allowlist when a context is still too broad |
| `slot_order` | Ordered list of approved card regions; structural order must stay deterministic |
| `fragments` | Mapping from slot names to canonical fragment IDs |
| `requires` | Capability gates for optional slots; these gates supplement but never replace the underlying fragment gate |
| `defaults.entity_presentation` | Suggested canonical list settings; runtime ownership still belongs to `entity_presentation` |
| `defaults.block_variants` | Suggested block variant defaults; runtime ownership still belongs to the customizer |
| `builder_defaults` | Suggested widget defaults for builder surfaces that consume the same card language |
| `preview` | Optional preview hint for admin tooling or builder previews |

#### Resolution rules

If entity card presets are implemented, resolution should follow this order:

1. resolve the entity context and runtime capabilities first
2. resolve the named card preset only after context/type compatibility is known
3. render card slots through canonical fragments such as `list-card-pricing` and `list-card-action`
4. let `entity_presentation` and theme-manifest defaults supply runtime density, excerpt, width, and approved variants
5. let the active customizer own the final selected variant values
6. never let a card preset bypass fragment capability gates or provider contracts

This keeps card presets compositional rather than imperative.

#### Relationship to existing presets

Entity presets and entity card presets solve different problems.

- entity presets attach capabilities and token defaults
- entity card presets describe named card composition for canonical list or builder rendering
- an entity preset may nominate a default card preset, but it should not redefine the card structure inline

That means a future `ecommerce` entity preset could say "use `commerce-standard` by default" while the card preset itself stays module-owned and reusable.

#### Allowed and forbidden behavior

Allowed in an entity card preset:

- selecting approved slot order from documented regions
- mapping slots to canonical list-card fragments
- suggesting default canonical list settings
- suggesting approved block variants
- providing builder defaults or preview hints

Forbidden in an entity card preset:

- raw HTML payloads
- arbitrary template paths
- PHP callbacks or hook handlers
- custom capability resolution logic
- bypassing `cmsEntityCapabilityRuntimeState()`
- bypassing customizer-owned variant resolution

If a card needs new behavior, the fix belongs in a module capability, fragment,
or builder extension point, not in a richer preset blob.

#### Recommended storage model

When implemented, the registry should be loaded from module-owned declarations
or a dedicated card-preset registry source. It should not be stored inside theme
manifests.

Suggested ownership pattern:

- modules publish card presets
- CMS exposes resolved card preset metadata to admin and builder surfaces
- entity presets reference card preset IDs as defaults
- themes and customizer remain presentation-only participants

This preserves the same boundary rule used everywhere else in the entity system.

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

Theme packages MUST NOT freeform-rewrite structural layout for entity pages.
The active theme customizer MAY select approved layout profiles and block
variants. Theme styling MAY override tokens, border-radius, spacing, and
typography through the design-token system.

---

## 10. Capability Dependency Rules

Some capabilities are not independent — they modify or constrain each other's
rendering behaviour. This layer is currently encoded inside blocks; as the
system grows it will be extracted into an explicit rules table.

**Current implicit rules (documented for auditing):**

| Capability      | Relationship        | Affected capability / block |
|-----------------|---------------------|-----------------------------|
| `inventory`     | modifies            | `pricing` CTA (disables buy button when `in_stock = false`) |
| `pricing`       | required by         | action block buy sub-block (gate: `capabilities.pricing`) |
| `pricing`       | required for        | `cart_enabled` evaluation |
| `progress_tracking` | requires auth context | user session must be checked before percent is meaningful |

**Conflict pairs (only one should be attached per entity):**

| Pair                        | Reason                                  |
|-----------------------------|-----------------------------------------|
| `booking` + `inquiry`       | Both produce secondary CTA buttons; simultaneous use creates UX ambiguity. Allowed but discouraged. |

**Planned: explicit rules schema**

When an external module or adapter attaches capabilities programmatically, it
should declare relationships in its module manifest:

```json
{
  "capability_rules": {
    "inventory": { "modifies": ["pricing", "action"] },
    "booking":   { "conflicts_with": ["inquiry"] }
  }
}
```

The kernel will validate these rules at capability-attach time via
`cmsEntityAttachCapability()`. Not yet enforced — tracked in cms-roadmap.md.

---

## 11. Layout Profiles

The block rendering order defined in §2 is fixed and MUST NOT be freely
reordered by themes. However, distinct canonical orderings for different
editorial contexts are valid — these are called **layout profiles**.

A layout profile is selected through the active theme customizer, with optional
theme-package defaults and explicit per-entity editorial exceptions where the
platform allows them. It selects among a fixed set of approved orderings rather
than allowing arbitrary rearrangement.

**Currently defined profiles:**

| Profile ID  | Block order                                          | Use case              |
|-------------|------------------------------------------------------|-----------------------|
| `default`   | media → header → pricing → inventory → body → lessons → action | General purpose        |
| `commerce`  | media → header → pricing → inventory → body → action | Product / shop entity  |
| `content`   | header → media → body → lessons → action             | Course / article       |

**Rules:**
- The active theme customizer owns the final selected profile for public entity presentation.
- A theme package may declare a default profile, but that is only a default.
- If no customizer or default profile is declared, `default` applies.
- Profiles that do not appear in this table are rejected at render time.
- New profiles require a doc update here AND a kernel-level registration.

The customization unit is the approved profile selection, not a separate per-theme entity template.

**Adding a new profile:** update this table, register the order in
`cmsEntityViewRender()`, add a test case to `cms_entity_capability_view_test.php`.

---

## 12. Capability Data Versioning

CapabilityBus data providers return typed arrays. As capabilities evolve (new
fields, changed semantics), a versioning field allows downstream consumers
(adapters, importers, analytics) to handle data safely.

**Convention:** every provider return array SHOULD include a `_version` integer
key matching the `@N` suffix of its CapabilityBus key.

**Current target shape (not yet emitted — planned for next iteration):**

```
capability_data.pricing = {
  "_version": 1,
  "price":      float|null,
  "currency":   string,
  "sale_price": float|null
}

capability_data.progress_tracking = {
  "_version": 1,
  "percent":       int,
  "authenticated": bool
}
```

**Migration strategy when a provider bumps to v2:**

1. Register a new bus key `entity.capability.pricing.data@2` alongside the old one.
2. Keep v1 registered until all consumers confirm v2 compatibility.
3. Remove v1 after one minor release cycle.
4. The `_version` field allows consumers to branch: `if ($data['_version'] >= 2) ...`

**Implementation note:** `_version` must be treated as opaque metadata by
DiSyL templates — never render it directly.

---

## 13. External CMS Normalization Contract

When data originates from an external CMS (WordPress, headless APIs, import
adapters) rather than from native CMS capabilities, it passes through a
**normalization layer** before reaching `cmsEntityCapabilityData()`.

The rendering engine (DiSyL + PHP blocks) assumes ALL capability_data is
already normalized. It MUST NOT contain:

- `null` values where a typed default is defined
- Missing required keys (e.g. `percent` absent from `progress_tracking`)
- Unexpected key types (e.g. `price` as string `"29.99"` instead of float)

**Normalization guarantees each provider MUST enforce:**

| Field                            | Guarantee                                   |
|----------------------------------|---------------------------------------------|
| `pricing.price`                  | `null` or `float` — never missing key       |
| `pricing.currency`               | Non-empty string, default `"USD"`           |
| `inventory.in_stock`             | Always present bool (never absent)          |
| `inventory.track_inventory`      | Always present bool                         |
| `progress_tracking.percent`      | Integer, clamped `0–100`                    |
| `progress_tracking.authenticated`| Bool derived from session, never absent     |
| `lessons_index.items`            | Array (empty array if no children)          |
| `media_gallery.items`            | Array (empty array if no images)            |
| `media_gallery.columns`          | Integer ≥ 1                                 |

**Adapter responsibility:** external system adapters (e.g. `WordPressCapabilityAdapter`)
are responsible for normalizing raw source data to these contracts before
calling `cmsEntityAttachCapability()` or injecting `capability_data` into the
render context. The CMS rendering layer never normalizes — it only validates
at the template block gate level.

---

## 14. Action Block Semantic Slots

The action block currently renders three contextually gated sub-blocks. As new
action types are introduced (subscriptions, downloads, external checkout,
API-triggered flows), the interior of `.cms-action-block` should be organised
into semantic slots rather than growing as a flat list.

**Planned slot model (not yet implemented — feeds §7.4 hook design):**

```
action.primary    — single dominant CTA (buy, subscribe, enroll)
                    → only ONE provider wins this slot
                    → priority: cart_enabled buy > subscription > enrollment

action.secondary  — supporting CTAs (book, inquire, download sample)
                    → multiple providers; rendered as outline buttons

action.external   — third-party or API-triggered flows (external checkout,
                    affiliate links, webhook-triggered purchase)
                    → rendered with rel="noopener" and explicit trust indicator
```

**Transition plan:**
1. The `cms.entity.action_block.sections` hook (§7.4) is the current escape
   hatch — use it to prototype new slot content.
2. Once slot patterns stabilise, promote to first-class named hooks:
   `cms.entity.action_block.primary`, `...secondary`, `...external`.
3. The built-in buy/book/inquire sub-blocks become default providers for
   `primary` (buy) and `secondary` (book, inquire) slots respectively.

**Constraint:** exactly one `action.primary` provider renders at a time.
Multiple providers must declare their priority; the highest-priority registered
provider that passes its own inner gate wins the primary slot.
