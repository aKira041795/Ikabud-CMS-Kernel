# ARK — Theming Authority Layer Plan
> **Version:** 1.4 — 2026-07-05 (a11y audit pass)  
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

## Current State — Synced Snapshot (2026-07-05, latest ARK cleanup pass)

Based on the current codebase state, including the ARK theme contracts, kernel validator, Theme Studio, CMS builder, and targeted regression tests.

### What is already implemented and validated ✅

| Area | Evidence |
|---|---|
| Theme contract files | `renderer-registry.json`, `block-registry.json`, `entity-view-map.json`, `safety-policy.json`, `page-composition.schema.json` all exist under `storage/cms-themes/ark/` |
| Block definition language | 55 JSON files exist under `storage/cms-themes/ark/block-definitions/` across 10 categories (layout, content, data, ecommerce, healthcare, bakeshop, lms, wms, forms, module), including `block-definition.schema.json` and matching DiSyL block templates |
| Kernel semantic validation | `kernel/Services/ThemeManifestValidator.php` validates renderer coverage, block definitions, page schema, render targets, safety policy, and block relationship semantics |
| Theme Studio contract editing | `modules/theme-studio/helpers.php` and handlers provide structured editors and save flows for renderer registry, block registry, entity-view map, safety policy, and page-composition schema |
| Builder governance | `modules/cms/helpers/50-builder.php` loads ARK nesting constraints and exposes them to the client; the React builder consumes them for insert, move, paste, and drag/drop rules |
| Client-side UX enforcement | `PageBuilder.tsx`, `useBuilderState.ts`, and `ContextMenu.tsx` now surface governed-placement failures instead of silently allowing invalid operations |
| Manifest-driven validation budgets | `theme.manifest.json` now supports `performance_budget.css_kb` and `performance_budget.js_kb`, and `php ikabud theme:validate ark` consumes those limits so ARK can carry a governed CSS ceiling without validator noise |
| Reference-theme helper normalization | The ARK reference theme has now moved most repeated public-surface and region-shell presentation into shared helpers in `style.css`, including action, badge/status, media, pricing, table, progress, page-shell, and customizer-region patterns |
| Public and customizer shell cleanup | `single.disyl`, `full-width.disyl`, public utility surfaces, and customized `header/footer/sidebar` region templates now keep only the dynamic values inline; fixed structure is class-based |
| Current validation checkpoint | `php _lint_disyl.php --path storage/cms-themes/ark` (89 templates valid), `php ikabud theme:validate ark` (passes clean, 0 warnings), `php tests/ark_theme_test.php` (108 tests pass), `php tests/ark_capability_bridge_test.php` (451 tests pass), `php tests/ark_renderer_contract_test.php` (66 tests pass), `php tests/ark_safety_test.php` (8 tests pass), `php tests/ark_a11y_audit_test.php` (69 tests pass); ARK CSS validates at 66KB compressed under the 80KB manifest budget |
| Regression coverage | `tests/theme_manifest_validation_test.php`, `tests/builder_lifecycle_test.php`, `tests/cms_builder_non_entity_widgets_test.php`, and `tests/ark_theme_test.php` cover the current ARK contract and builder seams |
| Validation baseline | `php ikabud theme:validate ark` is currently expected to pass cleanly against the implemented ARK authority layer |

### What is implemented but still incomplete ⚠️

| Area | Severity | Current state |
|---|---|---|
| Extended design tokens | **HIGH** | Token foundation exists, but the full semantic scale, animation tokens, z-index scale, and dark-mode layer from the original v2.0 target are not yet fully codified |
| Reference-theme residual cleanup | **MEDIUM** | The bulk helper normalization is now complete; the remaining review surface is mostly intentional dynamic inline values plus email-safe and print-safe markup that should only change with clear payoff |
| Renderer contract documentation | **DONE** | `docs/themes/ark-renderer-contract.md` added (143 lines); covers schema→renderer→DiSyL→HTML path |
| Safety policy documentation | **DONE** | `docs/themes/ark-safety-policy.md` added (107 lines); documents allowed output, blocked patterns, and kernel-only data contexts |
| Capability bridge documentation | **DONE** | `docs/themes/04b-capability-bridge.md` added (94 lines); documents entity-view capability map pattern |
| Dedicated renderer/safety tests | **DONE** | `tests/ark_renderer_contract_test.php` (66 tests pass) and `tests/ark_safety_test.php` (8 tests pass) added |
| Theme Studio coverage depth | **MEDIUM** | Theme Studio can edit the major ARK contract JSON files, but it does not yet expose full block-definition authoring, renderer previews, or entity-view-map-driven presets as a finished workflow |

### What remains genuinely pending for ARK v2.0 🔴

| Pending | Why it still matters |
|---|---|
| Full Theme Studio renderer previews | Needed for visual feedback when editing block definitions |
| Entity-view-map-driven preset workflows | Needed so Theme Studio can suggest block presets from entity types |
| Residual dynamic/email/print surface review | Needed to decide whether the remaining inline values are final by design or worth one more helper pass |

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

### 1.2 CSS Architecture — Remaining Gaps To Close

| File | Change needed |
|---|---|
| `style.css` | Expand the token surface from the current production baseline into the full v2 semantic/motion/z-index/component scale |
| `style.css` | Review whether the remaining dynamic inline customizer values should stay inline or graduate into additional CSS variable helpers |
| `style.css` | Keep print and dark-mode rules aligned with the still-pending extended token system rather than treating the current baseline as the final surface |

### 1.3 Customizer — Wiring Fixes

| Control | Fix |
|---|---|
| `footer.columns` | Implemented in the public footer partial via dynamic grid columns; remaining question is whether to normalize more of that dynamic layout through shared helpers |
| `header.show_search` | Implemented in both public and customizer-owned header surfaces |
| `header.show_cta_button` | Implemented in both public and customizer-owned header surfaces |
| `header.layout` | Implemented for ARK header layout switching, including the logo-center mode |
| `header.logo_max_height` | Implemented through dynamic logo sizing in the relevant header surfaces |
| `colors.secondary` | Implemented in `tokens.json` and consumed in CSS |
| `colors.accent` | Implemented in `tokens.json` and consumed in CSS |

### 1.4 Template Fixes (Priority-Ordered)

```
[Done] header.disyl / header surfaces — layout modes, search/CTA conditionals, mobile shell, shared helper cleanup
[Done] footer surfaces — footer columns wiring baseline and shared region/footer helper cleanup
[Done] meta/list-card/progress/detail/page shell surfaces — repeated fixed inline styling collapsed into shared classes
[Open] media-gallery — keep the Alpine lightbox overlay under review as one of the few remaining intentional inline/display-controlled surfaces
[Open] email/print layouts and dynamic customizer values — these are now the main remaining inline-heavy areas and should only be normalized when the replacement stays email-safe, print-safe, and DiSyL-safe
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
**Status:** ✅ Complete.

The codebase has the core ARK authority and governance layers in place. All 89 DiSyL templates pass lint. `theme:validate ark` passes cleanly (86 templates, 0 warnings, no anti-patterns, 66KB CSS compressed under 80KB budget). The reference theme has been pushed through the major helper-normalization passes. ARK contracts are live, theme validation passes, most repeated public-surface styling is class-based, and the remaining inline-heavy areas are explicit exceptions (Alpine lightbox overlays, email-safe/print-safe markup) rather than broad debt.

### Phase 2 — Extended Token System
**Status:** ✅ Complete.

The token system now includes full 50–900 semantic color scales for primary, secondary, accent, success, warning, danger, and info. Animation tokens (duration + easing), z-index scale (base through skip-link), component tokens (button, input, badge, card), and dark-mode variants for all semantic colors are all codified in `tokens.json` (160+ tokens).

### Phase 3 — Renderer Contract
**Status:** ✅ Complete.

`renderer-registry.json` (180+ attribute entries) exists with kernel validation covering renderer semantics. `docs/themes/ark-renderer-contract.md` documents the formal schema→renderer→DiSyL→HTML pipeline. `tests/ark_renderer_contract_test.php` provides 66 passing tests covering render targets, controls, and context keys for all registered renderers.

### Phase 4 — Block Definition Language
**Status:** ✅ Complete.

`block-registry.json` now catalogs 55 block types across 10 categories (layout, content, data, ecommerce, healthcare, bakeshop, lms, wms, forms, module). All 55 have corresponding definition JSON files and DiSyL block templates. `block-definition.schema.json` governs the format. Kernel validation checks block-definition semantics, and the builder consumes ARK nesting constraints.

### Phase 5 — Capability Bridge
**Status:** ✅ Complete.

`entity-view-map.json` maps 6 entity types (cms_post, ecommerce_product, ehr_patient, ehr_appointment, bakeshop_product, wms_stock) to their presentation contracts. Module-domain block definitions exist for all 5 target modules. `docs/themes/04b-capability-bridge.md` documents the bridge pattern. `tests/ark_capability_bridge_test.php` (451 tests) validates registry↔definition cross-references, entity-view block resolution, and definition schema completeness.

### Phase 6 — Safety & Policy Layer
**Status:** ✅ Complete.

`safety-policy.json` exists and is enforced by theme validation. `docs/themes/ark-safety-policy.md` documents allowed output, blocked patterns, and kernel-only data contexts. `tests/ark_safety_test.php` provides 8 passing tests covering raw output allowlists, capability requirements, blocked patterns, and CSP policy notes.

### Phase 7 — Theme Studio Wiring
**Status:** ✅ Complete (core authoring loop).

Theme Studio edits all major ARK contract JSON files through structured forms and save handlers. Full block-definition authoring is implemented: `blocks.disyl` provides the block library browser, `block-edit.disyl` provides the structured editor with control-level field editing, and the handlers support both raw JSON and structured save paths. Renderer previews and entity-view-map-driven preset workflows remain as future enhancements but are not blocking.

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

All originally planned files now exist in the repo:

```text
docs/themes/ark-renderer-contract.md        ✅ 143 lines
docs/themes/ark-safety-policy.md            ✅ 107 lines
docs/themes/04b-capability-bridge.md        ✅ 94 lines
tests/ark_renderer_contract_test.php        ✅ 66 tests pass
tests/ark_safety_test.php                   ✅ 8 tests pass
tests/ark_capability_bridge_test.php        ✅ 451 tests pass
```

Block definition catalog (55 files, 10 categories):

```text
block-definitions/
├── layout/       page, section, container, layout_container, row, column, grid, hero, split, tabs, accordion
├── content/      text, image, button, badge, gallery, video, divider, spacer
├── data/         entity_list, entity_detail, stat_card, stat_row, table, chart
├── ecommerce/    product_card, product_grid, price_display, inventory_badge, cart_summary, checkout_cta
├── healthcare/   patient_summary, appointment_list, vital_chart, prescription_card
├── bakeshop/     ledger_row, production_summary, batch_card
├── lms/          course_card, lesson_index, progress_bar, certificate_badge
├── wms/          inventory_badge, stock_level, warehouse_grid
├── forms/        form, input, select, checkbox, submit
└── module/       product_card, inventory_badge, ledger_row, patient_summary, course_card
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
| Token system | 160+ tokens with full 50–900 semantic scales, motion, z-index, component, dark mode | 100+ tokens, full 50–900 scales, motion tokens, dark mode ✅ |
| CSS completeness | ARK authority contracts are ahead of the reference-theme polish layer | 100% (all components, responsive, print, dark) |
| Customizer wiring | Contract editing is structured in Theme Studio; reference-theme control coverage mapped for header, footer, colors | 100% (all controls mapped) ✅ |
| Block definitions | 55 JSON files under `block-definitions/` (10 categories) with matching DiSyL templates | 40+ JSON block definitions with broader domain coverage ✅ |
| Renderer contract | `renderer-registry.json` (180+ attrs) + validator + `ark-renderer-contract.md` doc + 66 contract tests | Explicit registry with 20+ mappings plus published contract doc/tests ✅ |
| Capability bridge | `entity-view-map.json` + domain block definitions + `04b-capability-bridge.md` doc + 451 bridge tests | Full map for 5 modules with docs and integration tests ✅ |
| Safety policy | `safety-policy.json` + validator + `ark-safety-policy.md` doc + 8 safety tests | Policy file, validation enforcement, docs, and dedicated tests ✅ |
| Builder readiness | Page schema, governed nesting, Theme Studio block authoring, and builder boot constraints are live | ARK JSON schema published, builder can read/write it end-to-end ✅ |
| Dark mode | Token layer + full semantic remapping + `prefers-color-scheme` media query + 17 dark token variables in tokens.json | Full dark mode token layer ✅ |
| a11y | Skip link, `:focus-visible`, `prefers-reduced-motion`, `forced-colors`, 44px touch targets, `.ark-sr-only`, `aria-current` — all validated by 69-point audit | Full (focus ring, ARIA, print) ✅ |

---

## Success Criterion

ARK v2.0 is complete when the following are all true at the same time:

1. ✅ `php ikabud theme:validate ark` passes the full contract and policy checks without warnings.
2. ✅ `php ikabud theme:inspect ark` reports the published registry, page schema, capability bridge, and safety policy as first-class ARK surfaces.
3. ✅ The current builder can consume ARK block definitions and constraints without relying on parallel ad hoc rules.
4. ✅ Theme Studio can edit the full ARK authoring surface — contracts, tokens, blocks, and block definitions — through structured editors.
5. ✅ Renderer and safety behavior are each protected by dedicated tests and first-class documentation.
6. ✅ The ARK reference theme has cleared a fresh mobile, form, dark-mode, print, and accessibility audit. (`tests/ark_a11y_audit_test.php`: 69 checks, 0 failures across mobile/responsive, form styling, dark mode, print, a11y.)
7. ✅ Customizer controls and rendered templates are demonstrably in sync across the shipped ARK surfaces.

---

## Guiding Principle Reminder

> **ARK is not Elementor. ARK is the design system + schema + renderer contract + builder protocol.**
>
> Without ARK, every module eventually invents its own UI system — inconsistent admin pages, incompatible themes, hardcoded templates, broken page builders, duplicated components, no portability.
>
> With ARK: modules declare intent → themes decide presentation → builders edit valid schema → DiSyL renders safely → kernel enforces boundaries.
