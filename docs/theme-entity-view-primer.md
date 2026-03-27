# Theme Design and Entity Views Primer

**Updated:** March 2026

This primer explains how CMS theme design and entity views fit together in Ikabud Kernel OS.

It is not the canonical schema document for entity blocks and it is not the full theme implementation guide. Instead, it connects those two systems and explains why the relationship is intentionally designed around the application-kernel modular model.

Primary references:

- `docs/cms-theme-design-architecture.md`
- `docs/entity-view-block-schema.md`
- `docs/capability-driven-theme-design.md`
- `docs/ARCHITECTURE.md`

---

## 1. The short version

Theme design controls how public pages look.

Entity views control what public entity pages are allowed to render and in what structural order.

The kernel controls how data, capabilities, modules, routing, tenant isolation, and extension contracts are resolved before either of those layers render anything.

That means:

- themes are presentation-first
- entity views are contract-first
- the kernel is policy-first

This separation is the reason one public rendering system can support products, courses, services, galleries, and future module-driven entity types without creating a separate theme stack for each one.

---

## 2. What each layer owns

### 2.1 Theme design owns presentation

Themes are responsible for:

- public layouts
- public template overrides
- CSS, JS, tokens, and visual language
- approved block-level styling or overrides

Themes do not own:

- capability resolution
- module business logic
- entity capability data loading
- auth rules
- tenant routing
- database schema

This keeps themes lightweight and safe.

### 2.2 Entity views own the public entity contract

The universal entity view defines:

- the render context shape
- the deterministic block order
- which blocks are always present
- which blocks are capability-gated
- which capability providers supply runtime data

In practice, `entity.view.disyl` answers questions like:

- should pricing appear?
- should inventory appear?
- should lessons, gallery, or progress render?
- what data shape is available to each block?

### 2.3 The kernel owns orchestration and safety

Ikabud Kernel OS owns:

- request lifecycle
- module loading
- capability-bus dispatch
- hook and event execution
- tenant-aware settings resolution
- security and policy enforcement
- final render flow into DiSyL templates

The theme and entity view layers only work cleanly because the kernel guarantees that the runtime context is assembled consistently before rendering starts.

---

## 3. The relationship in one diagram

```text
Browser request
  -> Kernel front controller
  -> CMS public handler
  -> cmsPublicContext()
  -> cmsEntityCapabilityContext()
  -> cmsEntityCapabilityData()
  -> entity.view.disyl block gating
  -> active theme layout + theme tokens + approved block overrides
  -> final public HTML
```

Another way to say it:

- the entity view decides the structural content contract
- the theme decides the visual expression of that contract
- the kernel guarantees that both are fed by module-safe runtime data

---

## 4. Why the relationship is designed this way

The system is intentionally not built as "pick an industry theme and let it do everything."

That older model creates coupling problems:

- product pages need one template family
- course pages need another
- service pages need another
- each theme starts owning business logic it should not own
- modules become harder to extend safely
- multi-tenant behavior becomes more fragile

Instead, Ikabud uses universal entity rendering with capability-driven blocks.

That gives a cleaner split:

- modules define capabilities and data providers
- entity views define stable render slots and contracts
- themes apply brand and layout decisions to a stable surface area

This is the key architectural move that lets the CMS leverage the kernel OS model instead of bypassing it.

---

## 5. Sample usage: one theme, different entity behaviors

The same active theme can render two very different entities.

### Example A: product-style entity

Attached capabilities:

- `pricing`
- `inventory`
- `media_gallery`

Rendered outcome:

- hero media or gallery
- title and meta
- pricing block
- inventory block
- body content
- action block with buy CTA

### Example B: course-style entity

Attached capabilities:

- `pricing`
- `lessons_index`
- `progress_tracking`

Rendered outcome:

- header and meta
- learner progress block
- pricing block
- body content
- lessons index
- action block

The theme did not switch engines.

The entity view did not fork into a separate template family.

The difference came from capability data resolved through the kernel capability bus.

---

## 6. Sample usage: a theme manifest that stays in its lane

The recommended theme model is token-first, with narrow override points.

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
  "overridable_blocks": [
    "pricing.block.disyl",
    "action.block.disyl"
  ],
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
  }
}
```

Why this is important:

- the theme brands pricing and action presentation
- the theme does not become the pricing engine
- the theme does not query inventory tables directly
- the theme does not decide whether a capability exists

That responsibility stays with the CMS capability layer and the kernel.

---

## 7. Sample usage: a safe block override

If a theme is allowed to override a block, it should still treat the entity-view schema as the source of truth.

Example concept:

```text
storage/cms-themes/studio-commerce/
  theme.json
  layouts/public.disyl
  public/single.disyl
  public/blocks/pricing.block.disyl
  style.css
```

A pricing override should only assume the documented pricing contract:

```text
capability_data.pricing.price
capability_data.pricing.currency
capability_data.pricing.sale_price
```

It should not assume:

- direct ecommerce table access
- custom module globals
- tenant-specific path hacks
- undocumented root context keys

That keeps the override compatible with the rest of the modular system.

---

## 8. Sample usage: extending entity views through a module, not a theme

When the platform needs new entity behavior, the preferred extension path is a module capability provider, not a theme hack.

Example:

```php
return [
    'entity.capability.subscription_tier.data@1' => 'my_module_subscription_tier_data_provider',
];
```

That module can then:

1. register the new capability
2. provide runtime data through the capability bus
3. add an approved block or hook-driven action section
4. let themes style the result through tokens or approved overrides

This is exactly the sort of extension path the kernel modular system is designed for.

---

## 9. Advantages of this approach

### 9.1 One rendering path, many business cases

Products, courses, services, memberships, and portfolio entries can all render through one universal entity-view contract.

That reduces template sprawl and keeps public rendering predictable.

### 9.2 Strong module boundaries

Modules expose capabilities and data providers through declared contracts.

Themes do not need to know which module owns the data source internally. They only need the public render contract.

### 9.3 Safer multi-tenant behavior

Active theme resolution, tenant module settings, and public render flow are handled in shared infrastructure.

That makes cross-tenant leaks less likely than a theme-driven runtime that owns its own data access conventions.

### 9.4 Better upgrade safety

Because entity views and capabilities are documented contracts, themes have a narrower surface area to depend on.

That improves compatibility when modules evolve behind the scenes.

### 9.5 Easier capability overrides

If a specialized module needs to replace how pricing, booking, or inquiry data is resolved, it can override the capability provider at the kernel contract layer without requiring a new theme system.

### 9.6 More consistent public UX

Deterministic block order and stable root classes mean themes can produce stronger design systems without guessing what structure will appear on each entity page.

---

## 10. Why this specifically fits the application-kernel modular system

Ikabud is not just a CMS with plugin folders. It is an application-kernel modular infrastructure framework.

That matters here because the entity-view and theme relationship depends on kernel-level guarantees.

### 10.1 Capability bus as the runtime contract layer

The capability bus lets modules publish typed services like:

- `entity.capability.pricing.data@1`
- `entity.capability.inventory.data@1`
- `entity.capability.progress_tracking.data@1`

Entity views consume the results of those contracts.

Themes do not call those contracts directly. They benefit from them through the prepared render context.

This is a clean OS-style separation between service provider, orchestrator, and presentation layer.

### 10.2 Manifest-driven modules keep ownership clear

Because modules declare capabilities, routes, and ownership in manifests, the system can keep feature logic where it belongs.

That prevents themes from quietly becoming shadow modules.

### 10.3 Kernel-owned request lifecycle prevents template drift

The front controller, public handlers, shared context assembly, and DiSyL render path are all kernel-governed.

That means every entity page goes through the same policy and capability resolution path before a theme ever sees it.

### 10.4 Hook and capability extension keeps custom work additive

When a team needs custom CTA sections, specialized pricing, or external service integration, the extension model is additive:

- hook into a documented render seam
- register a capability provider
- provide a block override if allowed

That is much safer than replacing the entire public rendering stack.

### 10.5 Tenant-aware settings fit naturally

Theme activation, module enablement, and module settings already live inside tenant-aware kernel services.

Because entity views consume shared public context, the theme layer inherits that tenant-aware behavior automatically instead of reinventing it.

---

## 11. Practical rules for implementers

If you are designing a theme:

- treat entity views as a stable contract, not as raw free-form markup
- prefer tokens and approved block overrides over full template replacement
- assume capabilities and `capability_data` are the only supported feature switches
- never query module tables directly from theme templates

If you are extending entity behavior:

- add a capability or hook at the module layer
- document the data contract
- keep block structure deterministic
- let themes consume the result as presentation

If you are doing both:

- decide first whether the change is visual or behavioral
- visual changes belong in the theme
- behavioral changes belong in a module or capability provider

---

## 12. Common mistakes this model avoids

### Mistake 1: putting business logic in a theme

Example of the wrong direction:

- theme checks ecommerce tables directly
- theme decides stock logic
- theme injects its own per-product business rules

That breaks modular ownership.

### Mistake 2: creating separate theme families for each content vertical

Example of the wrong direction:

- one product theme
- one course theme
- one service theme

That duplicates render logic that the capability-driven entity view already solves.

### Mistake 3: bypassing documented entity context

If a block override depends on undocumented root keys, it becomes fragile and hard to upgrade.

The schema exists specifically to stop that drift.

---

## 13. Decision guide

Use a theme change when you need to change:

- layout composition
- typography
- color system
- spacing and radii
- approved block presentation

Use an entity-view or module change when you need to change:

- what data is available
- which capability-gated blocks render
- CTA semantics
- pricing, booking, inquiry, or progress behavior
- cross-module entity functionality

Use a kernel-level extension when you need to change:

- capability resolution rules
- module contracts
- tenant-safe settings flow
- request or render lifecycle policy

---

## 14. Summary

Theme design and entity views are complementary, not competing systems.

Entity views give the CMS a universal, capability-driven public structure.

Themes give that structure a brand, layout, and visual system.

The reason this works well in Ikabud is that the kernel OS already provides the hard parts:

- module boundaries
- capability contracts
- request orchestration
- tenant-aware settings
- deterministic public rendering flow

That is why the relationship is stronger than a normal CMS theming model. It is not just a template convention. It is a direct use of the application-kernel modular architecture.