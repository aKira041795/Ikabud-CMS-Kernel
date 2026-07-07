# Theme Infrastructure Plan — Kernel OS Governance Layer

> **Date**: 2026-06-28  
> **Status**: ✅ Implemented (Kernel OS 6.1 / ARK V3)  
> **Purpose**: Add Kernel OS-governed theme infrastructure (slots, manifests, validation, Theme Studio) to support the Ikabud Foundation theme vision.
>
> **Updated 2026-07-07**: All 5 phases are now implemented in Kernel OS 6.1.0 with ARK V3. Theme Studio module (`modules/theme-studio/`) was also completed as an additional deliverable.

---

## Guiding Principles

```
Theme presents.    →  presentation only, no business logic
Modules provide.   →  entities, capabilities, forms, actions
DiSyL declares.    →  interface intent through governed components
Kernel OS governs. →  contracts, tenant rules, security, execution
```

A theme must never:
- Access the database or query module tables
- Call module services directly
- Perform authorization decisions
- Require a specific business module to function

---

## Current State Assessment

| Capability | Status | Notes |
|---|---|---|
| Kernel OS 6.1 + DiSyL 4.7 | ✅ Mature | 32 governed `ikb_*` components, EntityViewResolver, TemplateEngine |
| Entity-view pipeline | ✅ Proven | 13 CMS view contracts, capability-gated, full pipeline tested |
| Theme system | 🟡 CMS-scoped | Symlink-based activation, `theme.json`/`theme.manifest.json`, `tokens.json` |
| Design tokens | 🟡 Partial | entity-native has CSS custom properties + tokens.json |
| Component variants | 🟡 Partial | `ikb_panel` (tone/spacing/radius), `ikb_button` (variant/size), `ikb_badge` (variant) |
| Shell architecture | 🟡 Block-based | `{block header}`/`{block content}` — no slot system |
| Builder integration | 🟡 React-output | Builder emits raw HTML, not DiSyL contracts |

| Gap | Impact |
|---|---|
| `ikb_slot` governed component | No slot registration/contribution for modules |
| SlotRegistry in kernel | Modules can't declare slot contributions |
| Theme validation CLI | No `theme:validate` / `theme:inspect` |
| Theme Studio module | No token editor, layout controls, elements |
| Theme certification | No automated anti-pattern detection |
| Kernel-owned manifest schema | Current manifests are CMS-scoped |
| Shell as governed slots | Current shell uses `{block}`, not `<ikb_slot>` |
| Builder → DiSyL emission | Builder outputs raw HTML, not governed components |

---

## Phase 1 — Slot System Foundation

### 1a. Add `ikb_slot` to ComponentRegistry

Register `ikb_slot` as a structural component:

```php
self::register('ikb_slot', [
    'category' => self::CATEGORY_STRUCTURAL,
    'description' => 'Governed theme slot — modules may register content contributions',
    'attributes' => [
        'name' => [
            'type' => Grammar::TYPE_STRING,
            'required' => true,
            'description' => 'Slot identifier (e.g., "content.after", "header.main")'
        ]
    ],
    'leaf' => false
]);
```

### 1b. Create SlotRegistry in Kernel

New class at `kernel/Services/SlotRegistry.php`:

- `registerContribution(string $slot, array $config)` — called by modules at bootstrap
- Contribution config: `{slot, component, conditions, priority}`
- Conditions: entity_type, route, tenant, role, capabilities
- `resolveSlot(string $slot, array $context)` — returns ordered array of contributions matching context
- Module manifest support: `"slot_contributions": [...]` in `module.json`

### 1c. Integrate with TemplateEngine

When `{ikb_slot name="content.after"}` is encountered in a DiSyL template:
1. Resolve slot name
2. Query SlotRegistry for matching contributions
3. Render contributions in priority order
4. Pass entity/route/user context for condition evaluation

### 1d. Shell migration

- entity-native shell to add `{ikb_slot}` calls alongside existing `{block}` calls
- `{block content}` still works; `{ikb_slot name="content.before"}` / `{ikb_slot name="content.after"}` augment it

> **Status**: ✅ Implemented — ARK has `slots.json` and `supported_slots` in manifest.

---

## Phase 2 — Theme Manifest Standardization

### 2a. Kernel theme manifest schema

Required keys:
- `kernel_os_compat` — min Kernel OS version
- `disyl_compat` — min DiSyL version  
- `supported_surfaces` — `public`, `admin`, `print`, `email`, `export`
- `supported_slots` — slots the theme renders
- `tokens` — path to tokens.json
- `component_variants` — mapping of ikb_* component variants
- `entity_view_variants` — mapping of entity view → theme presentation variants
- `fallback_views` — `card`, `table`, `detail`, `compact` — for unknown entity types
- `required_assets` / `optional_assets` — CSS/JS/font declarations
- `accessibility` — guarantees (landmarks, skip-to-content, focus states)
- `browser_support` — targeted browsers

### 2b. Manifest validation at theme load

Add validation step in `cmsThemeManifestForSlug()`:
- Required keys present
- Token file exists and parses
- All `supported_slots` are valid
- Fallback entity view files exist
- No anti-pattern indicators

---

## Phase 3 — Theme CLI Tools

### 3a. `php ikabud theme:validate <slug>`

Checks:
- Manifest validity (schema, required keys)
- Token file exists, valid JSON, complete color/spacing/radius scales
- All templates exist (shell, layouts, entity views, blocks)
- No unknown slots referenced
- No undeclared assets
- Inaccessible color combinations
- Missing fallback entity views
- Template lint errors (via `_lint_disyl.php`)
- Direct module/database access (pattern scan)
- Performance budget (CSS < 50KB, JS < 20KB, zero required JS for static pages)
- Unsafe raw output patterns

### 3b. `php ikabud theme:inspect <slug>`

Output summary:
```
Theme: Ikabud Foundation
Surfaces: public, portal, print
Layouts: 4
Slots: 18
Component variants: 42
Entity fallbacks: card, table, detail, compact
Required JS: none
Optional bridges: Alpine, HTMX
```

---

## Phase 4 — Entity-View Fallback Hardening

### 4a. Theme fallback contract

Each theme must declare fallback views in manifest:
```json
{
  "fallback_views": {
    "card": "entity-views/default-card.disyl",
    "table": "entity-views/default-table.disyl",
    "detail": "entity-views/default-detail.disyl",
    "compact": "entity-views/default-compact.disyl"
  }
}
```

### 4b. Fallback resolution in EntityViewResolver

When `resolve()` is called for a source with no registered view contract:
1. Check theme manifest for fallback views
2. Render using generic fallback template with available fields
3. Log diagnostic for developer awareness

---

## Phase 5 — Theme Studio Module

### 5a. Module scaffold

New `modules/theme-studio/` with:
- `module.json` — declares capabilities, slot contributions, settings
- Capabilities: `theme.customize@1`, `theme.tokens@1`, `theme.presets@1`

### 5b. Token editor

- Visual editor for color, spacing, typography, radius, shadow tokens
- Real-time preview via customizer
- Preset save/load (corporate, school, store, portfolio)

### 5c. Layout controls

- Container width, content/sidebar widths, header/footer width
- Sidebar assignment per template
- Header controls (logo placement, nav alignment, sticky, transparent)

### 5d. Theme Elements

- Hook: governed content → slot injection
- Hero: context-aware page/entity hero
- Header: alternate section header
- Layout: container/sidebar/content width overrides
- Block: reusable DiSyL composition
- Conditions: entity_type, view, tenant, role, capability, taxonomy

### 5e. Preset import/export

- JSON export of all token overrides + layout config
- Import validates against current manifest schema

---

## Phase 6 — Builder DiSyL Contract Emission

### 6a. Builder palette → governed components

Builder component palette maps to `ikb_*` components:
- Section → `ikb_section`
- Container → `ikb_container`
- Grid → `ikb_grid`
- Entity List → `ikb_entity_list`
- Entity Detail → `ikb_entity_detail`
- Panel/Card → `ikb_panel`

### 6b. Persistence format shift

Builder output changes from raw HTML to DiSyL attribute JSON:
```json
{
  "component": "ikb_section",
  "attrs": { "tone": "muted", "spacing": "xl" },
  "children": [
    {
      "component": "ikb_entity_list",
      "attrs": { "source": "ecommerce.product.featured", "view": "card" }
    }
  ]
}
```

### 6c. Server-side render path

New renderer in CMS helpers converts DiSyL JSON → rendered output via existing ComponentRegistry + TemplateEngine.

---

## Implementation Order

```
Phase 1: Slot system      ← START HERE (this session)
Phase 2: Manifest schema
Phase 3: CLI tools
Phase 4: Fallback hardening
Phase 5: Theme Studio module
Phase 6: Builder DiSyL emission
```

The foundation theme (ARK) builds on top of this infrastructure, not before it. The existing entity-native theme serves as the proving ground.
