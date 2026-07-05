# ARK — Theming Authority Layer Plan
> **Version:** 1.0 — 2026-07-05  
> **Author:** Systems Architect / GUI Designer  
> **Status:** Active Planning  
> **Scope:** Kernel OS 6.1+ · DiSyL 4.7+ · ARK v2.0

---

## Principle Statement

> **ARK is the kernel-owned theming and visual contract layer of Ikabud. It defines how pages, layouts, components, entity views, design tokens, and builder-compatible blocks are declared, validated, rendered, and customized across modules. Page builders do not replace ARK — they implement ARK.**

ARK is not a theme engine. It is not a page builder. It is not CSS presets.

ARK answers exactly one question:

> "How does anything visual become valid, renderable, customizable, portable, and safe inside Ikabud?"

Modules declare intent. Themes decide presentation. Builders edit valid schema. DiSyL renders safely. Kernel enforces boundaries. **ARK defines what "valid" means for each layer.**

---

## Current State — Honest Assessment (2026-07-05)

Based on a comprehensive code audit of `storage/cms-themes/ark/`.

### What is already solid ✅

| Area | Evidence |
|---|---|
| 70-file structure | Layouts, pages, blocks, entity-views, docs, admin |
| Theme manifest | Full manifest with kernel compat, slots, fallback views, customizer schema |
| Token foundation | 46 CSS custom properties, fully token-driven CSS |
| Shell architecture | 16 `{ikb_slot}` markers, semantic landmarks, Alpine.js, Tailwind CDN |
| Block library | 18 blocks, 6 list-card variants, capability-gated patterns |
| Entity-view fallbacks | 4 fallback templates (card/table/detail/compact) |
| Multi-surface support | Print + email layouts exist |
| Customizer schema | 6 sections, ~70 controls, proper JSON schema |
| Kernel integration | `ikb_slot` registered, `theme:validate`, `theme:inspect` CLI, Theme Studio module |
| 12 docs | Developer documentation complete |
| 104 test assertions | `tests/ark_theme_test.php` |

### What is broken or incomplete ⚠️

| Gap | Severity | Details |
|---|---|---|
| Mobile header CSS | **CRITICAL** | `.ark-header__hamburger` has no CSS — hamburger is visually invisible |
| `aria-expanded` is hardcoded | **HIGH** | Should be `:aria-expanded="mobileMenuOpen"` Alpine binding |
| Zero form element styles | **HIGH** | No `.ark-input`, `.ark-select`, `.ark-label`, `.ark-form-group` |
| 3 orphaned customizer controls | **HIGH** | `footer.columns`, `show_search`, `show_cta_button` write values that templates ignore |
| Lightbox incomplete | **MEDIUM** | `galleryOpen` state exists, no overlay/modal renders |
| `--color-secondary` / `--color-accent` orphaned | **MEDIUM** | In customizer schema but absent from tokens.json and CSS |
| `.ark-card__badge` class undefined | **MEDIUM** | Used in templates, not in style.css |
| Footer columns hardcoded | **MEDIUM** | Customizer offers 1–5, template renders 3 always |
| Hardcoded Ikabud attribution in footer | **LOW** | Not toggleable; wrong for production |
| `logo_max_height` setting ignored | **LOW** | Schema has it, header ignores it |
| Inline status badge colors | **LOW** | Not using `--color-success`/`--color-danger` tokens |

### What is missing from ARK-as-design-authority 🔴

| Missing | Impact |
|---|---|
| No ARK component schema (block definition JSON) | Builders can't read/write ARK-compatible structure |
| No full color scale (50–900) | Token system too coarse for production theming |
| No animation/transition tokens | Global motion control impossible |
| No dark mode token layer | Theme can't adapt to system dark preference |
| No `@media print` rules | Manifest claims print surface, no print CSS |
| No semantic component-level tokens | Button, input shape/color can't be globally tuned |
| No capability bridge schema | EHR/bakeshop/ecommerce entity view contracts not codified |
| No page builder schema definition | Future builders have no ARK contract to follow |
| No renderer contract spec | ARK schema → DiSyL translation rules undocumented |
| No safety/policy layer | No declared allowed/blocked patterns for theme authors |

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

## Phased Implementation Plan

### Phase 1 — Foundation Fixes (Sprint 1, ~2 days)
**Target:** Current ARK actually works at production quality.

| Task | Priority | Owner |
|---|---|---|
| Fix mobile header CSS (hamburger + drawer) | P1 | Frontend dev |
| Fix `:aria-expanded` Alpine binding | P1 | Frontend dev |
| Add form element styles (input/select/label/group) | P1 | Frontend dev |
| Wire `footer.columns` to template | P1 | Template dev |
| Wire `header.show_search` / `show_cta_button` to template | P1 | Template dev |
| Complete lightbox modal overlay in media-gallery | P1 | Frontend dev |
| Add `--color-secondary`, `--color-accent` to tokens.json + CSS | P2 | Frontend dev |
| Add `.ark-card__badge` CSS definition | P2 | Frontend dev |
| Add `.ark-btn`, `.ark-badge` CSS components | P2 | Frontend dev |
| Fix `logo_max_height` binding | P2 | Template dev |
| Remove hardcoded footer attribution | P2 | Template dev |
| Add `@media print` rules | P3 | Frontend dev |
| Add `:focus-visible` ring system | P3 | Frontend dev |

Validation: `php ikabud theme:validate ark` must pass all checks. `php tests/ark_theme_test.php` must pass 104/104.

---

### Phase 2 — Extended Token System (Sprint 2, ~1 day)
**Target:** ARK tokens support the full design authority role.

| Task | Details |
|---|---|
| Full color scale (50–900) | Add to tokens.json for primary, secondary, accent |
| Animation tokens | `--duration-*`, `--easing-*` |
| Component-level tokens | `--button-height-*`, `--input-height`, `--input-focus-ring` |
| Z-index token scale | `--z-sticky`, `--z-overlay`, `--z-modal`, `--z-toast` |
| Dark mode token layer | `@media (prefers-color-scheme: dark)` overrides |
| Font weight scale | `--font-weight-normal`, `--font-weight-medium`, `--font-weight-semibold`, `--font-weight-bold` |

---

### Phase 3 — Renderer Contract (Sprint 2–3, ~2 days)
**Target:** ARK defines the formal schema → DiSyL render mapping.

| Task | Output |
|---|---|
| Write `renderer-registry.json` | 20+ block type → template mappings |
| Write `docs/themes/ark-renderer-contract.md` | Formal contract spec document |
| Add render context declaration to all block templates | `{* Context: ... *}` headers |
| Extend `theme:validate` to check renderer registry coverage | CLI validation |
| Write integration test: schema → render output assertion | `tests/ark_renderer_contract_test.php` |

---

### Phase 4 — Block Definition Language (Sprint 3–4, ~3 days)
**Target:** ARK defines the builder schema contract.

| Task | Output |
|---|---|
| Create `block-registry.json` master list | 40+ block types categorized |
| Create `block-definitions/` directory | JSON definition per block type |
| Core layout blocks (8) | page, section, container, grid, hero, split, tabs, accordion |
| Core content blocks (8) | text, image, video, gallery, button, badge, divider, spacer |
| Core data blocks (5) | entity_list, entity_detail, stat_card, stat_row, table |
| Module blocks (20) | ecommerce, healthcare, bakeshop, lms, wms (4 each) |
| Write ARK Block Definition JSON Schema | `block-definition.schema.json` for validation |
| Extend `theme:validate` to validate block definitions | CLI check |

---

### Phase 5 — Capability Bridge (Sprint 4–5, ~2 days)
**Target:** ARK owns the cross-module entity presentation contract.

| Task | Output |
|---|---|
| Write `entity-view-map.json` | View contracts per entity type per module |
| Module-domain block definitions | `/block-definitions/{module}/` directories |
| Add EHR block templates to ARK | `blocks/ehr/patient-summary.block.disyl`, etc. |
| Add bakeshop block templates | `blocks/bakeshop/ledger-row.block.disyl` |
| Add LMS block templates | `blocks/lms/course-card.block.disyl`, etc. |
| Add WMS block templates | `blocks/wms/inventory-badge.block.disyl`, etc. |
| Integration tests: entity view map vs capability bus | New test file |
| Document entity view bridge pattern | `docs/themes/04b-capability-bridge.md` |

---

### Phase 6 — Safety & Policy Layer (Sprint 5, ~1 day)
**Target:** ARK enforces theme safety governance.

| Task | Output |
|---|---|
| Write `safety-policy.json` | Policy definition file |
| Extend `theme:validate` with policy checks | Pattern scan for blocked patterns |
| Extend `theme:validate` with raw output audit | Flag all `\|raw` usage with capability check |
| Add allowed-component check | Flag all `{ikb_*}` vs ComponentRegistry |
| Write security-focused theme doc | `docs/themes/ark-safety-policy.md` |
| Test: certified theme passes all safety checks | `tests/ark_safety_test.php` |

---

### Phase 7 — Theme Studio Wiring (Sprint 6, ~3 days)
**Target:** Theme Studio reads and writes ARK schema.

| Task | Output |
|---|---|
| Theme Studio reads `block-registry.json` | Block picker in editor |
| Theme Studio reads `block-definitions/` | Control panel per block |
| Theme Studio writes ARK-compatible JSON | Persistent storage format |
| Theme Studio previews via renderer-registry.json | Live preview mapped to template |
| Token editor uses extended token schema | Full color scale visible |
| Preset system uses `entity-view-map.json` | Module presets in theme studio |

---

## Key Files to Create

```
storage/cms-themes/ark/
├── renderer-registry.json          [Phase 3]
├── block-registry.json             [Phase 4]
├── entity-view-map.json            [Phase 5]
├── safety-policy.json              [Phase 6]
│
├── block-definitions/
│   ├── block-definition.schema.json
│   ├── layout/hero.json
│   ├── layout/section.json
│   ├── layout/grid.json
│   ├── content/text.json
│   ├── content/image.json
│   ├── content/button.json
│   ├── data/entity_list.json
│   ├── data/stat_card.json
│   ├── ecommerce/product-card.json
│   ├── healthcare/patient-summary.json
│   ├── bakeshop/ledger-row.json
│   ├── lms/course-card.json
│   └── wms/inventory-badge.json
│
└── blocks/                         [Phase 5 — module-domain templates]
    ├── ehr/
    │   ├── patient-summary.block.disyl
    │   ├── appointment-list.block.disyl
    │   └── vital-chart.block.disyl
    ├── bakeshop/
    │   ├── ledger-row.block.disyl
    │   └── production-summary.block.disyl
    ├── lms/
    │   ├── course-card.block.disyl
    │   ├── lesson-index.block.disyl
    │   └── progress-bar.block.disyl
    └── wms/
        ├── inventory-badge.block.disyl
        └── stock-level.block.disyl

docs/themes/
├── ark-authority-layer-plan.md     [this document]
├── ark-renderer-contract.md        [Phase 3]
└── ark-safety-policy.md           [Phase 6]
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
| Token system | 46 tokens, 3 coarse color shades | 100+ tokens, full 50–900 scales, dark mode |
| CSS completeness | ~70% (no forms, no mobile CSS) | 100% (all components, responsive, print, dark) |
| Customizer wiring | ~60% (orphaned controls) | 100% (all controls mapped) |
| Block definitions | 0 (informal via templates only) | 40+ JSON block definitions |
| Renderer contract | Implicit | Explicit registry with 20+ mappings |
| Capability bridge | 0 (entity views per module, not ARK-codified) | Full map for 5 modules |
| Safety policy | None | `safety-policy.json` + `theme:validate` enforcement |
| Builder readiness | 0% | ARK JSON schema published, builder can read/write it |
| Dark mode | None | Full dark mode token layer |
| a11y | Partial (skip link, semantic HTML) | Full (focus ring, ARIA, print) |

---

## Success Criterion

ARK v2.0 is complete when:

1. `php ikabud theme:validate ark` passes all checks including policy scan
2. `php ikabud theme:inspect ark` shows: 40+ blocks, renderer registry, capability bridge, safety policy
3. Any developer can build a complete new module page by picking block definitions from ARK — zero custom template code needed
4. Any future page builder can read `block-registry.json` and implement editing UI with zero new block schema invention
5. Theme Studio uses `entity-view-map.json` to offer module-aware layout presets
6. The ARK theme works on mobile with a functional hamburger menu, lightbox, and form styles
7. Customizer controls have 100% template wiring coverage

---

## Guiding Principle Reminder

> **ARK is not Elementor. ARK is the design system + schema + renderer contract + builder protocol.**
>
> Without ARK, every module eventually invents its own UI system — inconsistent admin pages, incompatible themes, hardcoded templates, broken page builders, duplicated components, no portability.
>
> With ARK: modules declare intent → themes decide presentation → builders edit valid schema → DiSyL renders safely → kernel enforces boundaries.
