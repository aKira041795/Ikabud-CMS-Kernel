# Capability-Driven Theme Design

**Updated:** March 2026

This document defines the capability-driven public theme model for CMS entities.

The core idea is straightforward:

- templates stay universal
- entities declare feature capabilities
- themes style through tokens and approved block overrides
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
- `progresstracking`
- `lessonsindex`
- `mediagallery`

### 1.3 Token-only theming by default

Themes should not be able to replace the entire CMS public rendering system by default.

They should primarily control:

- colors
- spacing
- radii
- typography
- visual block presentation

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
- `entity.capability.progresstracking.data@1`
- `entity.capability.lessonsindex.data@1`
- `entity.capability.mediagallery.data@1`

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

### 2.4 Theme control layer

Theme resolution is constrained by manifest policy:

- `restrict_to_tokens`
- `overridable_blocks`
- `tokens`

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
- `mediagallery`

will render product-like behavior from the same `entity.view.disyl` template.

An entity with:

- `lessonsindex`
- `progresstracking`
- `pricing`

will render course-like behavior without switching to a different template family.

---

## 5. Theme Policy Model

### 5.1 Default rule

Themes are expected to be token-driven first.

The helper layer now supports:

- `cmsActiveThemeManifest()`
- `cmsThemeTokensCss()`
- `cmsResolveBlockTemplate()`

### 5.2 Manifest controls

Recommended manifest fields:

```json
{
  "restrict_to_tokens": true,
  "overridable_blocks": [
    "pricing.block.disyl",
    "action.block.disyl"
  ],
  "tokens": {
    "color-accent": "#0ea5e9",
    "color-accent-dark": "#0369a1",
    "btn-radius": "0.5rem"
  }
}
```

### 5.3 Resolution behavior

If `restrict_to_tokens` is true:

- full-template overrides should not be used as a general escape hatch
- only explicitly allowlisted block templates are theme-overridable
- everything else falls back to CMS defaults

This preserves template determinism and prevents theme packages from silently redefining application behavior.

---

## 6. Capability Providers

The CMS ships default provider implementations for the builtin capabilities.

### `pricing`

Reads `_price`, `_currency`, and `_sale_price` meta.

### `inventory`

Reads `_sku`, `_stock_qty`, and `_track_inventory` meta.

### `booking`

Currently returns stub data and is intended to be overridden by a dedicated booking module.

### `inquiry`

Returns CTA configuration from attached capability config.

### `progresstracking`

Reads per-user progress using `kernel.auth.user@1` plus content meta.

### `lessonsindex`

Builds a child-entity index by querying child content linked through `_parent_id`.

### `mediagallery`

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
- `mediagallery`

### `education`

Attaches:

- `pricing`
- `lessonsindex`
- `progresstracking`

### `business`

Attaches:

- `inquiry`
- `booking`
- `mediagallery`

### `portfolio`

Attaches:

- `mediagallery`
- `inquiry`

These presets can also provide token overrides so the same template system presents differently by use case.

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

---

## 10. Recommended Extension Pattern

When adding a new domain feature:

1. add a new entity capability type
2. define a config schema and defaults
3. expose a `entity.capability.{id}.data@1` provider
4. add or reuse a universal block template
5. optionally add it to one or more presets

Do not start by creating a whole new theme family unless the universal model is provably insufficient.

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

## 12. Future Work

Logical next extensions include:

- richer block token contracts
- server-side block override audit tooling
- dedicated booking module override provider
- capability-aware builder sections and starter layouts
- formal schema/version docs for capability provider payloads

The design should continue to move toward configuration-driven specialization, not template-system fragmentation.