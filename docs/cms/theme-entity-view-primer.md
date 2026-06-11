# Theme Design and Entity Views Primer

**Updated:** June 2026

This primer explains the intended relationship between CMS themes, the theme customizer, and universal entity rendering in Ikabud Kernel OS.

It replaces the weaker mental model of "a theme owns its own entity templates" with the stronger model:

- one canonical entity-view contract
- one shared public design system
- one active theme customizer controlling presentation choices
- modules supplying behavior and data
- the kernel enforcing orchestration, safety, and tenant-aware runtime rules

Primary references:

- `docs/cms/cms-theme-design-architecture.md`
- `docs/cms/entity-view-block-schema.md`
- `docs/cms/capability-driven-theme-design.md`
- `docs/kernel/ARCHITECTURE.md`

---

## 1. The short version

The entity view is not supposed to be redesigned by swapping theme-owned entity templates.

The stronger concept is:

- the CMS owns one canonical entity-view contract
- the active theme package provides design tokens, component styling, and public shell defaults
- the active theme customizer chooses how that canonical entity view is presented, with shell controls in `theme` and canonical list/detail controls in `entity_presentation`
- modules add or change behavior through capabilities, providers, hooks, and contracts

That means a product page, course page, service page, or other entity page should feel like part of one public design system, not like separate CMS and ecommerce worlds.

---

## 2. The core correction

### Older theme-first thinking

The weaker model says:

- a theme ships templates
- a theme overrides entity views
- a customizer only tweaks colors, header, or footer

That still couples presentation too closely to theme file ownership.

### Customizer-first entity presentation

The stronger model says:

- `entity.view.disyl` is the canonical public entity contract
- the active theme package defines the design language and default component rules
- the active theme customizer controls presentation selections such as layout profile, block variants, spacing, visibility, and token values
- behavioral changes still happen in modules or capability providers, not in theme files

This is the direction that best matches the kernel-modular architecture.

### Compatibility note

The runtime may still support template or block override paths for compatibility and migration, but that should not be treated as the long-term customization model for entity presentation.

For entity pages, the design target is:

- one contract
- approved presentation controls
- customizer-owned layout decisions
- module-owned behavior

---

## 3. What each layer owns

### 3.1 Entity views own the public structure contract

The universal entity view defines:

- the root render context shape
- stable block order and gating rules
- which capability-driven blocks may appear
- what each block may assume about runtime data

This is where the public entity structure becomes predictable and safe.

### 3.2 Theme packages own the design system

The theme package is responsible for:

- global public layout shell
- typography, spacing, and component styling
- token defaults
- compatibility with customizer-generated header, footer, sidebar, and runtime CSS variables

The theme package should not be the place where entity-specific behavior or structural divergence is invented.

### 3.2.1 Traditional native themes still use canonical entities

For a traditional native theme, "traditional" should describe the shell and visual language, not a separate family of CMS route templates.

In practice that means a bundled theme like `native-default` should treat these files as its CMS source of truth:

- `layouts/public.disyl`
- `public/entity.view.disyl`
- `public/entity.list.disyl`
- canonical detail/list partials and style fragments that support those templates

It should not keep long-lived `home`, `archive`, `search`, `page`, and `single` template forks for CMS public routes once the runtime has already cut those routes over to canonical entity rendering.

That approach keeps the theme editorial and familiar without polluting it with a second, drifting template architecture.

### 3.3 The theme customizer owns presentation choices

The active theme customizer is the correct place for entity-view presentation changes such as:

- layout profile selection
- approved block variants
- region emphasis or suppression
- token overrides
- shared canonical list/detail presentation choices that should apply consistently across entity types

Within the customizer, Theme Settings should stay shell-only. Canonical article, list, and entity presentation decisions belong in the `entity_presentation` workspace so CMS and ecommerce routes read from one contract.

This is the key shift.

Changing how entity pages look should happen in the customizer layer, not by scattering per-theme entity template forks.

### 3.4 Modules own behavior and data

Modules are responsible for:

- capability registration
- capability data providers
- hooks and extension seams
- business logic
- integration with external systems

If pricing, inventory, booking, inquiry, progress, or media behavior changes, that change belongs here.

### 3.5 The kernel owns orchestration and safety

Ikabud Kernel OS owns:

- request lifecycle
- module loading
- capability-bus dispatch
- hook execution
- tenant-aware settings resolution
- policy enforcement
- final render orchestration

That is why the system can support one entity-view contract without collapsing into ad hoc theme logic.

---

## 4. Why ecommerce and CMS should not look like separate systems

If ecommerce pages and CMS pages visibly feel like two different design systems, the architecture is leaking internal ownership boundaries into the public UX.

That is a smell.

The public user should experience:

- one site shell
- one brand system
- one navigation model
- one entity presentation language

The fact that one route originated in ecommerce and another in CMS is an internal concern.

The public rendering model should absorb that difference through:

- shared entity contracts
- shared capabilities
- shared customizer-controlled presentation rules

Not through a storefront-only template family that drifts away from the rest of the site.

---

## 5. The relationship in one diagram

```text
Browser request
  -> Kernel front controller
  -> CMS public context assembly
  -> capability presence + capability data resolution
  -> canonical entity.view contract
  -> active theme customizer selects approved presentation profile
  -> active theme design system styles the result
  -> final public HTML
```

Another way to say it:

- the entity view decides what can render
- the theme customizer decides how the approved presentation is configured
- the theme package supplies the visual system
- the kernel guarantees that runtime data is valid and tenant-safe

---

## 6. Where entity-view changes should happen

### Use the theme customizer when you need to change:

- layout profile
- block variant selection
- visual density
- token values
- shared canonical list/detail presentation rules

### Use a module or capability provider when you need to change:

- pricing logic
- inventory logic
- booking logic
- inquiry logic
- capability data shape
- CTA behavior

### Use the entity-view contract when you need to change:

- block order rules
- allowed block slots
- render context guarantees
- capability-to-block mapping

### Use the kernel when you need to change:

- request lifecycle
- capability-bus policy
- module contracts
- tenant-safe settings flow

This separation is the architectural point.

---

## 7. Sample usage: one theme customizer, different entity behaviors

The same active theme and customizer configuration should be able to render different entity types coherently.

### Example A: product-style entity

Attached capabilities:

- `pricing`
- `inventory`
- `media_gallery`

Rendered outcome through the same canonical entity view:

- media-first profile
- pricing emphasis
- inventory status
- primary commerce CTA

### Example B: course-style entity

Attached capabilities:

- `pricing`
- `lessons_index`
- `progress_tracking`

Rendered outcome through the same canonical entity view:

- progress-aware summary
- content-forward profile
- lessons index
- enrollment-style CTA behavior

What changed was not the theme engine.

What changed was:

- capability presence
- capability data
- approved presentation selections applied to the same entity contract

---

## 8. Sample usage: what a theme package should supply

The recommended theme package should primarily provide:

- a public shell
- token defaults
- component styles
- compatibility with customizer-generated markup

Example concept:

```json
{
  "name": "Studio Commerce",
  "version": "1.0.0",
  "templates": [
    {
      "slug": "landing",
      "label": "Landing Page",
      "types": ["page"],
      "path": "public/landing.disyl"
    }
  ],
  "restrict_to_tokens": true,
  "tokens": {
    "color": {
      "primary": "#0f766e",
      "accent": "#f59e0b",
      "neutral": "#334155"
    },
    "component": {
      "button": {
        "radius": "9999px",
        "padding": "0.75rem 1.5rem"
      }
    }
  },
  "entity_view_defaults": {
    "layout_profile": "commerce",
    "block_variants": {
      "pricing": "featured",
      "action": "inline"
    }
  }
}
```

The important point is not the exact manifest keys.

The important point is the responsibility split:

- the theme package supplies defaults
- the customizer activates or changes presentation choices
- the entity contract remains stable

---

## 9. Sample usage: extending behavior without theme hacks

When the platform needs new entity behavior, the preferred extension path is a module capability provider, not a theme-specific entity template.

Example:

```php
return [
    'entity.capability.subscription_tier.data@1' => 'my_module_subscription_tier_data_provider',
];
```

That module can then:

1. register the new capability
2. provide runtime data through the capability bus
3. expose a documented slot or block in the entity-view contract
4. let the active theme customizer decide how the approved presentation should look

That is the kernel-modular extension path.

---

## 10. Advantages of the stronger model

### 10.1 One rendering path, one public language

Products, courses, services, memberships, and other entities can all render through one public presentation contract.

### 10.2 Less divergence between ecommerce and CMS

The site no longer drifts into separate storefront and CMS visual systems.

### 10.3 Stronger upgrade safety

Themes depend on stable tokens, variants, and customizer controls instead of fragile markup forks.

### 10.4 Cleaner module boundaries

Behavior stays in modules, presentation stays in the theme and customizer layers.

### 10.5 Better tenant consistency

Customizer-controlled presentation fits naturally with tenant-aware settings and shared public render infrastructure.

---

## 11. Why this fits the application-kernel modular system

This model works because Ikabud is not just a themeable CMS. It is a kernel-governed modular application platform.

### 11.1 The capability bus is the service contract layer

Modules can expose typed services such as:

- `entity.capability.pricing.data@1`
- `entity.capability.inventory.data@1`
- `entity.capability.progress_tracking.data@1`

The entity-view contract consumes those results.

Themes and customizer controls do not own those data contracts.

### 11.2 Manifest-driven modules keep ownership clear

Modules declare what they provide and what they depend on.

That prevents themes from becoming shadow application modules.

### 11.3 Kernel-owned request flow prevents public drift

Every entity request goes through the same kernel-governed render pipeline before presentation decisions are applied.

That is what makes one canonical entity contract viable.

### 11.4 Tenant-aware settings fit naturally

Theme activation, customizer settings, and module settings already live inside tenant-aware infrastructure.

The entity-view system can inherit that model cleanly.

---

## 12. Practical rules for implementers

If you are designing a theme package:

- think in design system defaults, not entity template ownership
- support customizer-generated markup and variables
- avoid creating separate entity template families for different business verticals

If you are working on the theme customizer:

- treat it as the control surface for entity presentation
- expose approved layout profiles and block variants
- keep controls shared and predictable across entity types

If you are extending behavior:

- add or override a capability provider
- document the capability contract
- keep behavior out of theme files

If you are changing the entity-view contract:

- update the schema docs
- preserve deterministic block rules
- avoid one-off route-specific template logic

### 12.1 Operating model by audience

For day-to-day implementation, the intended operating model is:

- contributors improve modules when they need new entity behavior, new capability-aware card patterns, new context bindings, or new builder-aware entity widgets
- tenant admins use presets plus the active theme customizer when they need controlled presentation changes without touching theme files
- advanced editors use the page builder when they need page-level composition, curated landing layouts, or explicit placement of approved entity/list widgets

In shorthand:

- modules own behavior and data
- the customizer owns controlled presentation
- the builder owns advanced composition
- the kernel enforces contracts and runtime safety

This is why a new entity view, card preset, or storefront behavior should usually begin in the module layer rather than in a new theme-owned template family.

---

## 13. Summary

Theme design and entity views are not competing systems.

The entity view defines the canonical public contract.

The theme package defines the design language.

The theme customizer controls how that contract is presented.

Modules provide behavior.

The kernel enforces the whole pipeline.

That is the architectural direction that best supports a decoupled, multi-tenant, capability-driven storefront and CMS under one public experience.