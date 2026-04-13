# Capability-Driven Theme Design

**Updated:** March 2026

This document defines the capability-driven public theme model for CMS entities.

The core idea is straightforward:

- templates stay universal
- entities declare feature capabilities
- themes provide design-system defaults
- the active theme customizer chooses approved presentation controls
- presets configure behavior without forking template systems

This avoids separate ecommerce, education, portfolio, or business theme stacks while still allowing materially different public experiences.

---

## 1. Design Goals

The design is built around five goals.

### 1.1 Universal templates

One entity view template should be able to render:

- a product
- a course
- a service
- a portfolio item

The rendered output changes because the entity has different attached capabilities, not because a completely different industry template was selected.

### 1.2 Capability-first rendering

Public rendering should depend on explicit feature flags attached to the entity, such as:

- `pricing`
- `inventory`
- `booking`
- `inquiry`
- `progress_tracking`
- `lessons_index`
- `media_gallery`

### 1.3 Customizer-first theming by default

Themes should not be treated as owners of the entity rendering tree.

The preferred model is:

- the theme package provides defaults for a shared design system
- the active theme customizer controls entity presentation choices through a shell-only `theme` section plus canonical `entity_presentation` controls
- the universal entity template stays canonical

The presentation layer should primarily control:

- colors
- spacing
- radii
- typography
- visual block presentation

It should not become a second application layer.

### 1.4 Presets as configuration, not forks

`ecommerce`, `education`, `business`, and `portfolio` are implemented as preset configs that attach default capabilities and token overrides.

They are not separate template engines.

### 1.5 Module-safe extensibility

The design must preserve kernel and module boundaries:

- capability data is fetched through the capability bus
- public rendering remains deterministic
- modules extend through declared contracts rather than direct template mutation

---

## 2. Architecture Summary

The implemented design has four layers.

### 2.1 Entity capability profile layer

Each CMS content entity can have zero or more attached capabilities stored in:

- `cms_entity_capabilities`

Each row contains:

- `entity_id`
- `capability_id`
- `config` as JSON

This is the feature-profile source of truth.

### 2.2 Capability data provider layer

Capability providers expose runtime data through the kernel capability bus using contract IDs such as:

- `entity.capability.pricing.data@1`
- `entity.capability.inventory.data@1`
- `entity.capability.booking.data@1`
- `entity.capability.inquiry.data@1`
- `entity.capability.progress_tracking.data@1`
- `entity.capability.lessons_index.data@1`
- `entity.capability.media_gallery.data@1`

The CMS module ships default providers, but higher-priority modules can override them later.

### 2.3 Universal template layer

Universal templates render by checking capability presence in public context:

- `capabilities`
- `capability_data`

Key templates:

- `templates/modules/cms/public/entity.view.disyl`
- `templates/modules/cms/public/entity.list.disyl`

Supporting blocks:

- `meta.block.disyl`
- `pricing.block.disyl`
- `inventory.block.disyl`
- `progress.block.disyl`
- `lessons.block.disyl`
- `media-gallery.block.disyl`
- `action.block.disyl`

### 2.4 Theme and customizer control layer

Theme resolution should be understood as two cooperating layers:

- the theme package defines public shell and design defaults
- the active theme customizer selects approved entity presentation controls

Relevant policy controls include:

- `restrict_to_tokens`
- `tokens`
- shell layout controls in `theme`
- canonical entity/list/detail controls in `entity_presentation`
- approved layout profiles
- approved block variants

That allows themes to brand the experience without owning the full rendering tree.

---

## 3. Data Model

### 3.1 Entity capabilities table

The migration `021_cms_entity_capabilities.sql` adds the feature-profile table.

Important properties:

- one entity can have many capabilities
- one capability can appear at most once per entity
- config is JSON so each capability can keep typed options
- rows are deleted automatically when the entity is deleted

### 3.2 Capability type registry

The CMS helper layer defines builtin capability types and supports extension through hooks.

Each type includes:

- `id`
- `label`
- `description`
- `icon`
- `config_schema`
- `default_config`

This registry drives both API output and builder UI rendering.

### 3.3 Preset registry

Presets are loaded from:

- `config/entity-presets/*.json`

Each preset may define:

- `default_capabilities`
- `token_overrides`
- `builder_defaults`

---

## 4. Rendering Flow

Public rendering follows this sequence.

1. CMS resolves the entity being rendered.
2. `cmsPublicContext()` enriches render context with:
   - `capabilities`
   - `capability_data`
3. `cmsEntityCapabilityContext()` produces a flat boolean map for DiSyL conditionals.
4. `cmsEntityCapabilityData()` calls the capability bus for each attached capability.
5. The universal template includes the relevant blocks.
6. The active theme may provide token CSS and approved block overrides.

### Example

An entity with:

- `pricing`
- `inventory`
- `media_gallery`

will render product-like behavior from the same `entity.view.disyl` template.

An entity with:

- `lessons_index`
- `progress_tracking`
- `pricing`

will render course-like behavior without switching to a different template family.

---

## 5. Theme and Customizer Policy Model

### 5.1 Default rule

Themes are expected to be design-system driven first, with the customizer as the public control surface for entity presentation.

The helper layer now supports:

- `cmsActiveThemeManifest()`
- `cmsThemeTokensCss()`
- `cmsResolveBlockTemplate()`

Conceptually, these helpers should support a stronger end state where entity presentation choices are selected through the active theme customizer instead of by relying on per-theme entity template forks.

### 5.2 Package defaults and runtime controls

Recommended manifest fields:

```json
{
  "restrict_to_tokens": true,
  "tokens": {
    "color": {
      "primary":   "#0ea5e9",
      "secondary": "#0369a1",
      "accent":    "#f59e0b",
      "danger":    "#ef4444",
      "neutral":   "#6b7280"
    },
    "component": {
      "button": {
        "radius":  "0.5rem",
        "padding": "0.5rem 1.25rem"
      },
      "card": {
        "radius":  "0.75rem",
        "shadow":  "0 1px 3px rgba(0,0,0,.12)"
      }
    },
    "typography": {
      "font-sans":   "'Inter', sans-serif",
      "size-base":   "1rem",
      "leading-body":"1.6"
    }
  }
}
```

Recommended control split:

- theme manifest defines defaults
- theme customizer owns active runtime selections
- entity views consume those selections only through approved schema controls

### 5.3 Resolution behavior

If `restrict_to_tokens` is true:

- full-template overrides should not be used as a general escape hatch
- only explicitly allowlisted block templates are theme-overridable
- everything else falls back to CMS defaults

This preserves template determinism and prevents theme packages from silently redefining application behavior.

Even where compatibility override paths exist, entity presentation should move toward approved customizer controls rather than deeper template ownership.

### 5.4 Token hierarchy rules

Theme tokens are resolved in a three-level hierarchy:

1. **Kernel defaults** — baseline values in CMS core (deepest, always present)
2. **Theme manifest tokens** — overrides declared in the theme's `tokens` block
3. **Preset token_overrides** — per-preset adjustments applied on top of theme values

When a preset and a theme both declare a token, the preset wins (it is more specific). When neither declares a token, the kernel default applies.

Flat legacy token keys (e.g. `"color-accent"`) are accepted for backwards compatibility but the nested form is canonical for new themes. The CSS emitter flattens both forms into CSS custom properties: `--cms-color-primary`, `--cms-component-button-radius`, etc.

---

## 6. Capability Providers

The CMS ships default provider implementations for the builtin capabilities.

### `pricing`

Reads `_price`, `_currency`, and `_sale_price` meta.

### `inventory`

Reads the attached inventory capability data. In Ecommerce this is normally sourced from the entity capability config (`sku`, `stock_qty`, `track_stock`), but when the active integration mode is `wms_authoritative_products` the shared read model overlays live WMS stock snapshot values by SKU instead of trusting the local quantity alone.

### `booking`

Currently returns stub data and is intended to be overridden by a dedicated booking module.

### `inquiry`

Returns CTA configuration from attached capability config.

### `progress_tracking`

Reads per-user progress using `kernel.auth.user@1` plus content meta.

### `lessons_index`

Builds a child-entity index by querying child content linked through `_parent_id`.

### `media_gallery`

Reads gallery data from `_gallery` meta.

---

## 7. Builder and API Integration

### 7.1 API endpoints

The CMS exposes capability management APIs for builder/editor use:

- `GET /api/v1/cms/entity-capabilities`
- `GET /api/v1/cms/entity-presets`
- `GET /api/v1/cms/content/{id}/capabilities`
- `POST /api/v1/cms/content/{id}/capabilities`
- `POST /api/v1/cms/content/{id}/capabilities/preset`
- `POST /api/v1/cms/content/{id}/capabilities/{cap_id}/detach`

### 7.2 Builder UI

The builder UI includes a dedicated capability panel that:

- lists capability types
- toggles attachment state
- edits capability config using schema-driven fields
- applies presets quickly

This keeps the editor workflow aligned with the universal template system.

---

## 8. Preset Model

Presets describe entity archetypes without introducing specialized rendering stacks.

### `ecommerce`

Attaches:

- `pricing`
- `inventory`
- `media_gallery`

Implementation note:

- the dedicated ecommerce storefront should resolve product imagery in this order: `featured_image`, then the first `media_gallery` item, then a static placeholder asset
- product detail views should render attached `media_gallery` items when available instead of ignoring them

### `education`

Attaches:

- `pricing`
- `lessons_index`
- `progress_tracking`

### `business`

Attaches:

- `inquiry`
- `booking`
- `media_gallery`

### `portfolio`

Attaches:

- `media_gallery`
- `inquiry`

These presets can also provide token overrides so the same template system presents differently by use case.

### 8.1 Entity / Preset Matrix

The current runtime supports a small set of entity types especially well for commerce-aware presets.

| Entity type | Context path | Baseline preset | Commerce-aware preset | Intended use |
|-------------|--------------|-----------------|-----------------------|--------------|
| `product` | ecommerce binds `product -> commerce` | `ecommerce` | `ecommerce` | first-class storefront product |
| `service` | CMS binds `service -> business`; ecommerce extends `business` with `pricing` | `business` | `service-commerce` | sellable or bookable service |
| `course` | Guidance binds `course -> guidance + commerce` | `education` | `course-commerce` | paid or enrollable course |

Important boundary:

- presets do not create entity types
- presets configure existing entities by attaching capabilities and defaults
- entity type bindings and context ownership still come from modules

### 8.2 How presets are used

Presets already have a concrete runtime path.

1. An editor opens a content entity in CMS or a module creates one programmatically.
2. The preset is applied through the capability panel or `POST /api/v1/cms/content/{id}/capabilities/preset`.
3. The preset attaches its `default_capabilities[]` into `cms_entity_capabilities`.
4. Runtime capability resolution merges attached capabilities with the entity-context profile for that entity type.
5. Canonical entity view, entity list, and builder widgets render from the same capability-aware contract.
6. The active theme customizer controls approved presentation choices on top of that behavior.

Current implementation notes:

- `product` already auto-applies the `ecommerce` preset on creation in the ecommerce module
- `service` and `course` presets are currently best applied by editors or by future module-specific creation flows
- builder `Quick Presets` already exposes available presets from `GET /api/v1/cms/entity-presets`

### 8.3 Concrete Commerce-Aware Presets

To make the entity-target story explicit, the repo now includes two concrete commerce-aware presets in addition to the broader `business` and `education` baselines:

- `service-commerce` — priced service with booking, inquiry, and gallery support
- `course-commerce` — priced course with lessons, learner progress, and gallery support

These are not new rendering systems. They are convenience bundles for existing capability contracts.

---

## 9. Constraints and Tradeoffs

### Strengths

- strong reuse across industries
- consistent rendering contracts
- safer theming model
- extensible through capabilities
- easy preset-driven onboarding

### Tradeoffs

- capability names become part of the render contract and must stay stable
- some highly specialized layouts may still need explicit block allowlisting
- modules that override capability providers must preserve response shape discipline

### Preset governance boundary

Presets **configure**. They must not **redefine structure**.

Allowed in a preset:
- `default_capabilities[]` — which capabilities to attach and with what defaults
- `token_overrides` — style token values
- `builder_defaults` — default page-builder container class

Allowed as a suggested theme-package default, but owned by the active theme customizer at runtime:
- `block_variants` — which display variant to select for a block (see §13)
- `layout_profile` — which approved rendering order to use (see entity-view-block-schema.md §11)

Forever forbidden in a preset:
- injecting raw HTML into blocks
- altering CapabilityBus key resolution
- declaring new PHP functions or hook callbacks
- bypassing `restrict_to_tokens` policy

If a preset needs to change rendering logic it must become a module — not a richer preset.

---

## 10. Recommended Extension Pattern

When adding a new domain feature:

1. add a new entity capability type
2. define a config schema and defaults
3. expose a `entity.capability.{id}.data@1` provider
4. add or reuse a universal block template
5. optionally add it to one or more presets

Do not start by creating a whole new theme family unless the universal model is provably insufficient.

### 10.1 Operating model by audience

As the platform converges on entity-context and capability-driven rendering, each audience should work through a different layer.

**Contributors and module authors**

- If you want a new entity behavior, card pattern, or view capability, improve the module.
- Typical changes belong in module manifests, entity-context definitions/bindings, capability providers, preset defaults, canonical block templates, or builder/widget registrations.
- Do not start by creating a new theme-owned entity template family.

**Tenant admins and non-technical operators**

- Use presets plus the active theme customizer.
- Presets attach default capabilities and token defaults.
- The customizer selects approved layout profiles, block variants, and shell presentation through `theme` and `entity_presentation`.
- This path is intentionally constrained so presentation can change without mutating structure or business logic.

**Advanced editors**

- Use the page builder when a page needs higher-control composition, custom landing layouts, or explicit block placement.
- Builder work should reuse approved entity/list widgets, canonical capability contracts, and theme/customizer rules wherever possible.

### 10.2 Card and override policy

Capability-aware cards are part of the canonical entity/list system, not a return to ad hoc storefront-only templates.

- Prefer canonical list-card fragments and approved block variants for reusable card patterns.
- Prefer builder widgets or blocks when a card needs page-specific composition.
- Treat theme overrides as design-system customization only; they must not become the place where pricing, inventory, inquiry, or other module behavior is reimplemented.

---

## 11. Current Implementation Artifacts

The current implementation lives across these areas:

- capability storage and provider helpers in `modules/cms/helpers/56-entity-capabilities.php`
- capability registration in `modules/cms/helpers/55-capabilities.php`
- public context injection in `modules/cms/helpers/78-public-context.php`
- theme policy helpers in `modules/cms/helpers/40-theme-settings.php`
- universal templates under `templates/modules/cms/public/`
- presets under `config/entity-presets/`
- capability API handlers in `modules/cms/handlers/88-entity-capabilities.php`
- builder integration in `modules/cms/builder-ui/src/builder/components/CapabilityPanel.tsx`

---

---

## 12. Capability Contract Spec

Every capability data provider must declare a formal contract. This is the enforcement layer that makes external system integration (WordPress, future adapters) safe.

### 12.1 Contract structure

```json
{
  "capability":       "pricing",
  "version":          1,
  "required_fields":  ["price", "currency"],
  "optional_fields":  ["sale_price"],
  "guarantees": {
    "price":      "number|null — null when not set, float >= 0 when present",
    "currency":   "non-empty ISO 4217 string, default 'USD'",
    "sale_price": "number|null"
  }
}
```

### 12.2 Contracts for all built-in capabilities

| Capability         | Required fields                                  | Guaranteed types / defaults                            |
|--------------------|--------------------------------------------------|--------------------------------------------------------|
| `pricing`          | `price`, `currency`                              | price: float\|null; currency: string (default "USD")   |
| `inventory`        | `in_stock`, `track_inventory`                    | both always bool; stock: int\|null; sku: string\|null   |
| `booking`          | `available_slots`, `stub`                        | slots: array (possibly empty); stub: bool               |
| `inquiry`          | `label`, `form_fields`                           | label: string (default "Inquire"); fields: string[]     |
| `progress_tracking`| `percent`, `authenticated`                       | percent: int 0–100 clamped; authenticated: bool         |
| `lessons_index`    | `items`, `child_type`                            | items: array (never null, possibly empty); type: string |
| `media_gallery`    | `items`, `columns`, `lightbox`                   | items: array; columns: int >= 1; lightbox: bool         |

### 12.3 Enforcement point

Contracts are enforced in `cmsEntityCapabilityData()` before the data is merged into the render context. If a provider returns a value that violates a required-field or type guarantee, the CMS logs a warning and substitutes the default value rather than passing broken data to templates.

This means: **DiSyL templates may always assume the contract is satisfied.** They must never defensively handle missing keys beyond the gates already defined in each block.

### 12.4 External adapter obligation

Any module feeding capability data from an external CMS (see entity-view-block-schema.md §13) is responsible for mapping and normalizing source data to these contracts before calling `cmsEntityCapabilityData()`. The render layer does not normalize — it only validates at the contract boundary.

---

## 13. Block Variant System

Blocks exist in one canonical form today. As the system grows, a given block may need multiple approved display modes without requiring template duplication or block overrides.

### 13.1 Variant model

Variants are selected by the active theme customizer, with optional theme-package defaults exposed under `block_variants`:

```json
{
  "block_variants": {
    "pricing":       "featured",
    "media_gallery": "carousel"
  }
}
```

The block resolver (`cmsResolveBlockTemplate()`) checks for a variant-specific template before falling back to the default:

```
modules/cms/public/blocks/pricing.featured.block.disyl  ← variant
modules/cms/public/blocks/pricing.block.disyl           ← default fallback
```

### 13.2 Approved variants per block (current)

| Block               | Available variants               | Default     |
|---------------------|----------------------------------|-------------|
| `pricing`           | `compact`, `featured`, `minimal` | *(none)*    |
| `media_gallery`     | `carousel`, `grid`               | `grid`      |
| `action`            | `inline`, `sticky-footer`        | `inline`    |
| `inventory`         | `compact`                        | *(none)*    |
| `list-card-pricing` | `featured`, `minimal`            | *(none)*    |
| `list-card-inventory` | `compact`                      | *(none)*    |
| `list-card-progress` | `inline`                        | *(none)*    |

Variants not in this table are rejected by `cmsResolveBlockTemplate()`. New variants require: (a) a block template file, (b) an entry in this table, (c) a test.

### 13.3 Rules

- Variants control **presentation** only (layout density, visual style)
- Variants must never alter the capability data contract or block gate conditions
- A missing variant template file is a hard error, not a silent fallback
- Theme packages may supply defaults; the active theme customizer owns the final variant selection; entity-level freeform overrides are not supported

---

## 14. External CMS Readiness

This design is intentionally compatible with the WordPress adapter plan and future external system integrations. The key design choices that enable this are already in place:

1. **Hard input contract** — `{entity}`, `{capabilities}`, `{capability_data}` are the only root keys the render engine consumes. External adapters must produce exactly these three objects.

2. **Normalization guarantee** — the §12 contract layer ensures capability data is validated before it reaches DiSyL. External data is no different from native data from the template's perspective.

3. **CapabilityBus override** — external modules can register higher-priority providers for any capability ID. The WordPress adapter will register `entity.capability.pricing.data@1` at priority 20, returning Woo-sourced pricing data normalized to the same contract.

4. **No HTML-as-source** — the render engine consumes structured data, not raw HTML. External CMSes that store rich-text blocks must normalize through `{post_html}` (the single allowed raw-HTML injection point).

For the detailed normalization contract (per-field guarantees), see entity-view-block-schema.md §13.

---

## 15. Future Work

Remaining extensions, in priority order:

1. **Entity card preset registry** — formalize module-owned named card compositions for canonical list cards, customizer defaults, and builder reuse without turning themes into behavior owners. Draft contract: `docs/cms/entity-view-block-schema.md` §8.1.
2. **Block variant templates** — create the actual `.featured.block.disyl`, `.carousel.block.disyl`, etc. files declared in §13.2
3. **Contract enforcement in cmsEntityCapabilityData()** — implement the runtime validation described in §12.3 (currently documented as a requirement; enforcement code not yet written)
4. **CSS token flattener** — update `cmsThemeTokensCss()` to handle nested token hierarchy (§5.4) in addition to the legacy flat form
5. **Server-side block override audit** — tooling to verify a theme's `overridable_blocks` entries all have matching template files
6. **Booking module override provider** — real `entity.capability.booking.data@1` replacement when a booking module ships
7. **Customizer entity-view controls** — expose layout profiles and approved block variants directly in the theme customizer

The design continues to move toward configuration-driven specialization. The invariant is: **the theme package supplies defaults, the customizer configures presentation, modules add behaviour, and the kernel enforces contracts.**