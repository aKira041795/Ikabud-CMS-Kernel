# ARK — Theming Authority Layer Plan
> **Version:** 1.1 — 2026-07-05  
> **Author:** Systems Architect / GUI Designer  
> **Status:** Active Implementation, Synced To Codebase  
> **Scope:** Kernel OS 6.1.0+ · DiSyL 4.7.0+ · ARK v2.0

---

## Principle Statement

> **ARK is the kernel-owned theming and visual contract layer of Ikabud. It defines how pages, layouts, components, entity views, design tokens, and builder-compatible blocks are declared, validated, rendered, and customized across modules. Page builders do not replace ARK — they implement ARK.**

ARK is not a theme engine. It is not a page builder. It is not CSS presets.

ARK answers exactly one question:

> "How does anything visual become valid, renderable, customizable, portable, and safe inside Ikabud?"

Modules declare intent. Themes decide presentation. Builders edit valid schema. DiSyL renders safely. Kernel enforces boundaries. **ARK defines what "valid" means for each layer.**

---

## Current State — Synced Snapshot (2026-07-05)

Based on the current codebase state, including the ARK theme contracts, kernel validator, Theme Studio, CMS builder, and targeted regression tests.

### What is already implemented and validated ✅

| Area | Evidence |
|---|---|
| Theme contract files | `renderer-registry.json`, `block-registry.json`, `entity-view-map.json`, `safety-policy.json`, `page-composition.schema.json` all exist under `storage/cms-themes/ark/` |
| Block definition language | 36 JSON files exist under `storage/cms-themes/ark/block-definitions/`, including `block-definition.schema.json`, layout/content/data definitions, and module-domain definitions |
| Kernel semantic validation | `kernel/Services/ThemeManifestValidator.php` validates renderer coverage, block definitions, page schema, render targets, safety policy, and block relationship semantics |
| Theme Studio contract editing | `modules/theme-studio/helpers.php` and handlers provide structured editors and save flows for renderer registry, block registry, entity-view map, safety policy, and page-composition schema |
| Builder governance | `modules/cms/helpers/50-builder.php` loads ARK nesting constraints and exposes them to the client; the React builder consumes them for insert, move, paste, and drag/drop rules |
| Client-side UX enforcement | `PageBuilder.tsx`, `useBuilderState.ts`, and `ContextMenu.tsx` now surface governed-placement failures instead of silently allowing invalid operations |
| Regression coverage | `tests/theme_manifest_validation_test.php`, `tests/builder_lifecycle_test.php`, `tests/cms_builder_non_entity_widgets_test.php`, and `tests/ark_theme_test.php` cover the current ARK contract and builder seams |
| Validation baseline | `php ikabud theme:validate ark` is currently expected to pass cleanly against the implemented ARK authority layer |

### What is implemented but still incomplete ⚠️

| Area | Severity | Current state |
|---|---|---|
| Extended design tokens | **HIGH** | Token foundation exists, but the full semantic scale, animation tokens, z-index scale, and dark-mode layer from the original v2.0 target are not yet fully codified |
| Reference-theme foundation polish | **HIGH** | The codebase now has the ARK contract layers, but the earlier visual audit items for the ARK reference theme still need a fresh pass before they can be treated as closed |
| Renderer contract documentation | **MEDIUM** | `renderer-registry.json` and validator support exist, but `docs/themes/ark-renderer-contract.md` has not been added yet |
| Safety policy documentation | **MEDIUM** | `safety-policy.json` exists and is validated, but `docs/themes/ark-safety-policy.md` is still missing |
| Capability bridge documentation | **MEDIUM** | `entity-view-map.json` exists, but the bridge pattern doc planned as `docs/themes/04b-capability-bridge.md` is not present |
| Dedicated renderer/safety tests | **MEDIUM** | The current regression suite covers theme validation and builder seams, but the dedicated `ark_renderer_contract_test.php` and `ark_safety_test.php` files from the plan do not exist yet |
| Theme Studio coverage depth | **MEDIUM** | Theme Studio can edit the major ARK contract JSON files, but it does not yet expose full block-definition authoring, renderer previews, or entity-view-map-driven presets as a finished workflow |

### What remains genuinely pending for ARK v2.0 🔴

| Pending | Why it still matters |
|---|---|
| Full extended token system | Required for production-grade theming consistency across modules and surfaces |
| Formal renderer contract document | Needed so future builder and module authors can implement ARK without reverse-engineering JSON and validator behavior |
| Safety-policy reference document | Needed to make theme author restrictions explicit and reviewable |
| Dedicated renderer and safety test suites | Needed to keep future ARK expansion from regressing through indirect coverage only |
| Full Theme Studio authoring loop | Needed before Theme Studio can be considered the complete ARK editor rather than the contract editor baseline |
| Final reference-theme audit closure | Needed before the original UI-polish findings can be considered fully resolved |

---

## ARK Architecture — Five Layers

```
┌──────────────────────────────────────────────────────────────────────┐
│                        ARK AUTHORITY LAYER                           │
│                                                                      │
│  Layer 5: Safety & Policy                                            │
│  ─ allowed output, blocked patterns, kernel-only data contexts       │
│                                                                      │
│  Layer 4: Capability Bridge                                          │
│  ─ EHR, ecommerce, bakeshop, WMS, LMS entity view contracts          │
│                                                                      │
│  Layer 3: Builder Schema (Block Definition Language)                 │
│  ─ what a block is, what controls it has, how it nests               │
│                                                                      │
│  Layer 2: Renderer Contract                                          │
│  ─ ARK schema → DiSyL template, module data → render context         │
│                                                                      │
│  Layer 1: Design Foundation                                          │
│  ─ tokens, components, layouts, entity views, customizer             │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
        ↑                    ↑                      ↑
   Theme Studio          Page Builder          AI Layout Gen
   (implements ARK)    (implements ARK)       (implements ARK)
```

---

## Layer 1 — Design Foundation (Current ARK, Fixed + Matured)

This is the foundation of all the above. Fix current gaps first.

### 1.1 Token System — Extended

**Current: 46 tokens. Target: 100+ semantic tokens.**

Add the missing token categories:

```json
{
  "colors": {
    "primary": { "50": "#eef2ff", "100": "#e0e7ff", "200": "#c7d2fe", "300": "#a5b4fc", "400": "#818cf8", "500": "#6366f1", "600": "#4f46e5", "700": "#4338ca", "800": "#3730a3", "900": "#312e81" },
    "secondary": { "500": "#64748b" },
    "accent": { "500": "#f59e0b" }
  },
  "animation": {
    "duration": { "fast": "80ms", "base": "150ms", "slow": "300ms", "slower": "500ms" },
    "easing": { "standard": "cubic-bezier(0.4,0,0.2,1)", "decelerate": "cubic-bezier(0,0,0.2,1)", "accelerate": "cubic-bezier(0.4,0,1,1)", "spring": "cubic-bezier(0.34,1.56,0.64,1)" }
  },
  "zIndex": {
    "base": 0, "raised": 10, "sticky": 100, "overlay": 200, "modal": 300, "toast": 400, "skipLink": 9999
  },
  "components": {
    "button": { "height-sm": "32px", "height-md": "40px", "height-lg": "48px", "padding-x": "1rem" },
    "input": { "height": "40px", "border-width": "1px", "focus-ring-width": "2px" },
    "badge": { "height": "20px", "font-size": "0.75rem" }
  }
}
```

**Dark mode tokens (required for v2.0):**

```json
{
  "dark": {
    "surface": "#0f172a",
    "surface-muted": "#1e293b",
    "text": "#f1f5f9",
    "text-secondary": "#94a3b8",
    "border": "#334155"
  }
}
```

### 1.2 CSS Architecture — Gaps to Close

| File | Change needed |
|---|---|
| `style.css` | Add `.ark-btn`, `.ark-btn--{variant}`, `.ark-btn--{size}` |
| `style.css` | Add `.ark-input`, `.ark-select`, `.ark-textarea`, `.ark-label`, `.ark-form-group` |
| `style.css` | Add `.ark-badge`, `.ark-badge--{variant}` (semantic token-driven) |
| `style.css` | Add `.ark-header__mobile-toggle` hamburger icon (CSS-drawn or SVG) |
| `style.css` | Add `.ark-header__mobile-panel` drawer with transition |
| `style.css` | Add `:focus-visible` ring consistent with `--color-primary` token |
| `style.css` | Add `@media print` rules (typography reset, hide nav/footer/sidebar) |
| `style.css` | Add `@media (prefers-color-scheme: dark)` dark mode token overrides |
| `style.css` | Add `.ark-pagination`, `.ark-breadcrumb` component classes |
| `style.css` | Add `.ark-skeleton`, `.ark-spinner` loading states |

### 1.3 Customizer — Wiring Fixes

| Control | Fix |
|---|---|
| `footer.columns` | Wire to `{set cols = customizer.footer_columns\|default:3}` → use in `grid-template-columns: repeat({cols}, 1fr)` |
| `header.show_search` | Add search form conditional to header.disyl |
| `header.show_cta_button` | Add CTA button conditional to header.disyl |
| `header.layout` | Add `{if header.layout == 'logo-center'}` conditional class switching |
| `header.logo_max_height` | Apply `style="max-height:{customizer.logo_max_height\|default:32}px"` to logo |
| `colors.secondary` | Add `--color-secondary` to tokens.json and CSS |
| `colors.accent` | Add `--color-accent` to tokens.json and CSS |

### 1.4 Template Fixes (Priority-Ordered)

```
[P1] header.disyl     — :aria-expanded binding, mobile CSS, layout modes
[P1] media-gallery    — complete lightbox overlay (Alpine modal)
[P1] footer.disyl     — footer.columns wiring, remove hardcoded attribution
[P2] meta.block       — replace all inline styles with CSS classes
[P2] entity-views/    — replace inline styles in default-detail.disyl
[P2] list-card blocks — .ark-card__badge class, progress overlay containment
[P3] entity.view      — .ark-detail__featured-image class, fix history.back()
```

---

## Layer 2 — Renderer Contract

**The formal ARK schema → DiSyL render path.**

ARK must define how any schema-valid block becomes a rendered DiSyL output. This is the missing link between "what a builder writes" and "what DiSyL renders."

### 2.1 Renderer Contract Spec

Define in `docs/themes/ark-renderer-contract.md`:

```
ARK Schema → Renderer → DiSyL → HTML

For each block type:
  1. Schema defines: type, controls, allowed children
  2. Renderer maps: schema attrs → DiSyL template include path
  3. DiSyL template renders: semantic HTML + slot/token references
  4. Kernel validates: render context is from approved source only
```

### 2.2 Renderer Registry

New file: `storage/cms-themes/ark/renderer-registry.json`

```json
{
  "renderers": {
    "hero": {
      "template": "blocks/hero/hero.block.disyl",
      "controls": ["title", "subtitle", "alignment", "background"],
      "allowed_children": ["button", "image", "text"],
      "context_keys": ["hero_title", "hero_subtitle", "hero_image_url", "hero_cta"]
    },
    "entity_list": {
      "template": null,
      "renders_as_component": "ikb_entity_list",
      "controls": ["source", "view", "filter", "limit"],
      "context_keys": ["source", "view", "filter"]
    },
    "stat_card": {
      "template": "blocks/stat-card/stat-card.block.disyl",
      "controls": ["title", "value", "variant", "icon"],
      "context_keys": ["stat_title", "stat_value", "stat_variant", "stat_icon"]
    }
  }
}
```

### 2.3 Render Context Contract

Each block template must declare what context keys it reads. This makes context validation possible.

```disyl
{* Block: hero
   Required context: hero_title (string), hero_subtitle (string?)
   Optional context: hero_image_url (string?), hero_cta_label (string?), hero_cta_url (string?)
   Capability gate: none
   Safety: all output escaped unless hero_body|raw (CMS-only) *}
```

---

## Layer 3 — Builder Schema (Block Definition Language)

**This is the schema that future page builders must follow.**

### 3.1 ARK Block Definition Format

`storage/cms-themes/ark/block-definitions/` directory.

Example: `block-definitions/hero.json`

```json
{
  "type": "hero",
  "label": "Hero Section",
  "category": "layout",
  "icon": "layout-banner",
  "allowed_parents": ["section", "page"],
  "allowed_children": ["button", "image", "text", "badge"],
  "max_children": 5,
  "controls": {
    "title": {
      "type": "text",
      "label": "Heading",
      "placeholder": "Welcome to our store",
      "required": true,
      "max_length": 120
    },
    "subtitle": {
      "type": "textarea",
      "label": "Subheading",
      "placeholder": "Discover our collection",
      "max_length": 300
    },
    "alignment": {
      "type": "select",
      "label": "Text Alignment",
      "options": ["left", "center", "right"],
      "default": "center"
    },
    "background": {
      "type": "media_or_color",
      "label": "Background",
      "default": { "type": "color", "value": "--color-primary" }
    },
    "min_height": {
      "type": "select",
      "label": "Minimum Height",
      "options": ["compact", "standard", "tall", "screen"],
      "default": "standard"
    }
  },
  "renders_with": "ark.blocks.hero",
  "preview_thumbnail": "docs/screenshots/hero.png"
}
```

### 3.2 Block Definition Registry

`storage/cms-themes/ark/block-registry.json` — master list of all ARK block types.

```json
{
  "version": "2.0",
  "blocks": {
    "layout": ["page", "section", "container", "grid", "hero", "split", "tabs", "accordion"],
    "content": ["text", "image", "video", "gallery", "button", "badge", "divider", "spacer"],
    "data": ["entity_list", "entity_detail", "stat_card", "stat_row", "table", "chart"],
    "ecommerce": ["product_card", "product_grid", "cart_summary", "checkout_cta", "price_display"],
    "ehr": ["patient_summary", "appointment_list", "vital_chart", "prescription_card"],
    "bakeshop": ["ledger_row", "production_summary", "batch_card"],
    "lms": ["course_card", "lesson_index", "progress_bar", "certificate_badge"],
    "wms": ["inventory_badge", "stock_level", "warehouse_grid"],
    "forms": ["form", "input", "select", "checkbox", "submit"]
  }
}
```

### 3.3 Nesting Rules

```json
{
  "nesting": {
    "section": { "can_contain": ["container", "hero", "grid", "text", "image"] },
    "container": { "can_contain": ["grid", "entity_list", "entity_detail", "hero", "text", "image", "button", "badge"] },
    "grid": { "can_contain": ["product_card", "course_card", "patient_summary", "stat_card", "text", "image"] },
    "entity_list": { "can_contain": [] },
    "entity_detail": { "can_contain": ["text", "image", "gallery", "button"] }
  }
}
```

---

## Layer 4 — Capability Bridge

**ARK defines the visual contract for each module's entity types.**

This turns ARK from a "CMS theme" into a cross-module design authority.

### 4.1 Entity View Capability Map

For each major module, ARK declares the entity presentation contract:

```json
{
  "entity_views": {
    "ecommerce.product": {
      "compact": { "fields": ["name", "price", "image", "stock_status"], "actions": ["view", "add_to_cart"], "block": "product_card" },
      "table": { "fields": ["name", "price", "sku", "stock_status", "category"], "actions": ["view", "edit"] },
      "detail": { "fields": ["name", "price", "images", "description", "stock_status"], "actions": ["add_to_cart", "wishlist"], "blocks": ["pricing.default", "inventory.default", "action.default"] }
    },
    "healthcare.patient": {
      "compact": { "fields": ["name", "dob", "status"], "actions": ["view"], "block": "patient_summary" },
      "table": { "fields": ["name", "dob", "sex", "status", "last_visit"], "actions": ["view", "schedule"] },
      "detail": { "fields": ["name", "dob", "sex", "identifiers", "contacts", "status"], "actions": ["edit", "schedule", "new_encounter"] }
    },
    "bakeshop.product": {
      "compact": { "fields": ["name", "price", "yield_unit"], "actions": ["view"], "block": "bakeshop_card" },
      "ledger": { "fields": ["name", "produced", "sold", "variance"], "actions": ["view"] }
    },
    "wms.stock": {
      "compact": { "fields": ["sku", "qty", "location"], "actions": ["view"], "block": "inventory_badge" },
      "table": { "fields": ["sku", "name", "qty", "location", "status"], "actions": ["view", "move"] }
    },
    "lms.course": {
      "compact": { "fields": ["title", "instructor", "progress"], "actions": ["view", "enroll"], "block": "course_card" },
      "detail": { "fields": ["title", "description", "lessons", "progress", "certificate"], "blocks": ["lessons.default", "progress.default"] }
    }
  }
}
```

Store as `storage/cms-themes/ark/entity-view-map.json`.

### 4.2 Module Block Definitions

Each module-domain block type gets a block definition:

```
block-definitions/
├── ecommerce/
│   ├── product-card.json
│   ├── cart-summary.json
│   └── price-display.json
├── healthcare/
│   ├── patient-summary.json
│   ├── appointment-list.json
│   └── vital-chart.json
├── bakeshop/
│   ├── ledger-row.json
│   └── production-summary.json
├── lms/
│   ├── course-card.json
│   ├── lesson-index.json
│   └── progress-bar.json
└── wms/
    ├── inventory-badge.json
    └── stock-level.json
```

---

## Layer 5 — Safety & Policy

**ARK must define what is and is not safe for theme templates.**

### 5.1 Safety Rules (declarative)

Define in `storage/cms-themes/ark/safety-policy.json`:

```json
{
  "version": "1.0",
  "policy": {
    "raw_output": {
      "allowed_keys": ["post_html", "content_html", "builder_content", "structured_data"],
      "requires_capability": ["cms.content.render_raw@1"],
      "note": "All |raw usage requires explicit allow_callers in module manifest"
    },
    "allowed_context_sources": ["kernel", "cms", "entity_view", "customizer", "theme"],
    "blocked_patterns": [
      "direct database queries",
      "PHP function calls via template",
      "session access",
      "cookie write",
      "file system access"
    ],
    "allowed_js_bridges": ["alpine", "htmx", "custom"],
    "csp_note": "Theme must not add inline onclick handlers. Use Alpine x-on: or htmx hx-get:"
  }
}
```

### 5.2 Theme Certification Checklist

Extend `php ikabud theme:validate` to check against `safety-policy.json`:

| Check | Method |
|---|---|
| No `\|raw` on user-submitted content | AST scan of templates |
| No direct DB pattern in templates | Grep for `PDO`, `mysqli`, `db()` in .disyl files |
| All slots declared in manifest | Cross-reference `ikb_slot name=` vs `supported_slots` |
| All `{include}` paths exist | File existence check |
| No unregistered components | Cross-reference `{ikb_*}` against ComponentRegistry |
| Token coverage ≥ 90% | Check CSS for hardcoded colors/spacing |
| Accessibility: skip link present | HTML landmark check |
| No hardcoded tenant data | Grep for tenant IDs in templates |

---

## Phase Status

### Phase 1 — Foundation Fixes
**Status:** Partial.

The codebase now has the core ARK authority and governance layers in place, but the original ARK reference-theme visual audit items should be revalidated as a separate closeout pass. Current success for this phase should be measured as: ARK contracts are live, theme validation passes, and theme-surface polish items are tracked explicitly rather than assumed complete.

### Phase 2 — Extended Token System
**Status:** Pending.

The current token layer is a valid base, but the expanded semantic color scales, motion tokens, z-index scale, component tokens, and full dark-mode layer remain open work.

### Phase 3 — Renderer Contract
**Status:** Partial.

`renderer-registry.json` exists and kernel validation covers renderer semantics, so the runtime half of this phase is implemented. The missing pieces are the formal renderer contract document, block template context declarations, and the dedicated renderer contract test file.

### Phase 4 — Block Definition Language
**Status:** Implemented baseline.

`block-registry.json`, `block-definition.schema.json`, and the current block-definition catalog are in place. Kernel validation now checks block-definition semantics, and the builder consumes ARK nesting constraints instead of relying on ad hoc client-only rules.

### Phase 5 — Capability Bridge
**Status:** Partial.

`entity-view-map.json` and module-domain block definitions exist, which establishes the baseline capability bridge in code. Documentation, deeper template coverage, and dedicated entity-view integration tests are still open.

### Phase 6 — Safety & Policy Layer
**Status:** Partial.

`safety-policy.json` exists and is part of theme validation, so the policy baseline is live. The remaining work is explicit documentation, dedicated safety-focused tests, and any stricter future policy scans that should move from convention into enforced checks.

### Phase 7 — Theme Studio Wiring
**Status:** Partial.

Theme Studio now edits the major ARK contract JSON files through structured forms and save handlers. It still needs full block-definition authoring, richer renderer preview loops, and entity-view-map-driven preset workflows before this phase can be considered complete.

---

## Key Files In Repo And Still Missing

```
Already present in the repo:

```text
storage/cms-themes/ark/
├── renderer-registry.json
├── block-registry.json
├── entity-view-map.json
├── safety-policy.json
├── page-composition.schema.json
├── tokens.json
└── block-definitions/
  ├── block-definition.schema.json
  ├── layout/
  ├── content/
  ├── data/
  ├── ecommerce/
  ├── healthcare/
  ├── bakeshop/
  ├── lms/
  ├── wms/
  └── module/

modules/theme-studio/
├── helpers.php
├── handlers.php
└── templates/contract-edit.disyl

modules/cms/
├── helpers/50-builder.php
└── builder-ui/src/
```

Still missing from the original plan:

```text
docs/themes/ark-renderer-contract.md
docs/themes/ark-safety-policy.md
docs/themes/04b-capability-bridge.md
tests/ark_renderer_contract_test.php
tests/ark_safety_test.php
```
```

---

## The Mental Model for Future Builders

```
A future drag-and-drop builder is just one client of ARK.

Builder asks: "What blocks can I use here?" → reads block-registry.json
Builder renders: "How does this block look?" → reads block-definitions/hero.json
Builder edits:   "What controls does this have?" → reads block-definitions/hero.json controls
Builder saves:   "Store user's choices" → writes ARK-compatible JSON to cms_content builder column
Builder loads:   "Render this for visitors" → Renderer Registry → DiSyL template → HTML

The builder never invents its own block model.
The builder never invents its own control schema.
The builder never outputs its own HTML directly.

ARK defines. Builder edits. DiSyL renders. Kernel approves.
```

Other future ARK clients use the same pattern:
- **AI layout generator** → reads block-registry.json, writes ARK JSON, hands to renderer
- **Theme Studio** → reads entity-view-map.json, exposes module presets
- **CMS admin screen generator** → reads entity-view-map.json for field discovery
- **Report layout builder** → reads data block definitions for chart/table schemas
- **Ecommerce product page composer** → reads ecommerce capability bridge

---

## Scorecard Target (ARK v2.0)

| Dimension | Current | v2.0 Target |
|---|---|---|
| Token system | Base token layer exists, but still below the full semantic/dark-mode target | 100+ tokens, full 50–900 scales, motion tokens, dark mode |
| CSS completeness | ARK authority contracts are ahead of the reference-theme polish layer | 100% (all components, responsive, print, dark) |
| Customizer wiring | Contract editing is structured in Theme Studio; reference-theme control coverage still needs re-audit | 100% (all controls mapped) |
| Block definitions | 36 JSON files under `block-definitions/` including schema | 40+ JSON block definitions with broader domain coverage |
| Renderer contract | Explicit `renderer-registry.json` plus validator support; formal doc/test still missing | Explicit registry with 20+ mappings plus published contract doc/tests |
| Capability bridge | `entity-view-map.json` and domain block definitions exist | Full map for 5 modules with docs and integration tests |
| Safety policy | `safety-policy.json` plus validator enforcement baseline exists | Policy file, validation enforcement, docs, and dedicated tests |
| Builder readiness | Page schema, governed nesting, Theme Studio contract editors, and builder boot constraints are live | ARK JSON schema published, builder can read/write it end-to-end |
| Dark mode | Not yet a complete token/CSS layer | Full dark mode token layer |
| a11y | Builder governance feedback is improved; reference-theme a11y closure still needs a dedicated pass | Full (focus ring, ARIA, print) |

---

## Success Criterion

ARK v2.0 is complete when the following are all true at the same time:

1. `php ikabud theme:validate ark` passes the full contract and policy checks without warnings.
2. `php ikabud theme:inspect ark` reports the published registry, page schema, capability bridge, and safety policy as first-class ARK surfaces.
3. The current builder can consume ARK block definitions and constraints without relying on parallel ad hoc rules.
4. Theme Studio can edit the full ARK authoring surface, not only the top-level contract JSON files.
5. Renderer and safety behavior are each protected by dedicated tests and first-class documentation.
6. The ARK reference theme has cleared a fresh mobile, form, dark-mode, print, and accessibility audit.
7. Customizer controls and rendered templates are demonstrably in sync across the shipped ARK surfaces.

---

## Guiding Principle Reminder

> **ARK is not Elementor. ARK is the design system + schema + renderer contract + builder protocol.**
>
> Without ARK, every module eventually invents its own UI system — inconsistent admin pages, incompatible themes, hardcoded templates, broken page builders, duplicated components, no portability.
>
> With ARK: modules declare intent → themes decide presentation → builders edit valid schema → DiSyL renders safely → kernel enforces boundaries.
