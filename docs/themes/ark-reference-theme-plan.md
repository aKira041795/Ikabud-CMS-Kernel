# ARK Reference Theme — Build Plan

**Status**: Draft  
**Target**: Developer reference theme for Ikabud CMS module  
**Purpose**: Showcase DiSyL entity views, Kernel OS advances, and serve as the canonical how-to for CMS theme authors

---

## Philosophy

```
ARK is not "a theme."
ARK is the executable documentation for how themes work in Ikabud.
```

Every file, every block, every token in ARK exists to **demonstrate a Kernel OS concept** to a developer reading the source. If a developer can study ARK end-to-end, they should understand the entire CMS public rendering system — entity views, capability-driven blocks, customizer tokens, block variants, theme manifests, DiSyL macros, and multi-surface rendering.

---

## What ARK Must Showcase

| Concept | How ARK Shows It |
|---|---|
| **Entity view system** | Canonical `entity.view.disyl` + `entity.list.disyl` with capability gating, layout profiles, and context-aware rendering |
| **Capability-driven rendering** | Blocks that appear/disappear based on `capabilities.pricing`, `capabilities.inventory`, `capabilities.booking`, etc. |
| **DiSyL v4.7 features** | Macros, components (`<ikb_entity_view>`), filters, `{extends}`, `{block}`, `{include}`, `{set}`, `{for}`, script block interpolation |
| **Theme manifest system** | `theme.json`, `theme.manifest.json`, `tokens.json` — full annotated examples |
| **Design token architecture** | CSS custom properties driven by `tokens.json` — typography, spacing, color, radius, shadow scales |
| **Block variant system** | Multiple variants of same block (e.g., `pricing.block.default`, `pricing.block.compact`, `pricing.block.featured`) |
| **Theme customizer** | Shell controls (header, footer, colors, typography, layout) + entity presentation controls |
| **Component system** | Custom DiSyL components registered via `ComponentRegistry` |
| **Multi-surface support** | Separate output modes for `html`, `print`, `email`, `export` |
| **Frontend stack** | Alpine.js 3.x + Tailwind CSS v3 (CDN), progressive enhancement |
| **Theme activation lifecycle** | Install, activate, customize, public render — full documented flow |

---

## File Structure

```
storage/cms-themes/ark/
├── theme.json                    # Legacy manifest (backward compat)
├── theme.manifest.json           # Canonical manifest (all metadata)
├── tokens.json                   # Design token definitions
├── style.css                     # Theme stylesheet
├── script.js                     # Theme JavaScript (Alpine components)
│
├── layouts/
│   ├── public.disyl              # Main public shell layout
│   ├── public-print.disyl        # Print-optimized layout
│   ├── public-email.disyl        # Email-safe layout
│   └── admin-preview.disyl       # Admin preview iframe layout
│
├── public/
│   ├── entity.view.disyl         # Canonical entity detail view
│   ├── entity.list.disyl         # Canonical entity list view
│   ├── archive.disyl             # Blog archive
│   ├── single.disyl              # Blog single post
│   ├── page.disyl                # Static page
│   ├── search.disyl              # Search results
│   ├── home.disyl                # Home page
│   ├── 404.disyl                 # 404 page
│   ├── full-width.disyl          # Full-width page template
│   ├── landing.disyl             # Landing page template
│   │
│   ├── blocks/
│   │   ├── meta.block.disyl           # Author, date, type badge
│   │   ├── media-gallery.block.disyl  # Featured image + gallery
│   │   │
│   │   ├── pricing/
│   │   │   ├── pricing.block.default.disyl    # Standard price display
│   │   │   ├── pricing.block.compact.disyl    # Compact (list card)
│   │   │   └── pricing.block.featured.disyl   # Featured/highlighted
│   │   │
│   │   ├── inventory/
│   │   │   ├── inventory.block.default.disyl  # Full stock status
│   │   │   └── inventory.block.compact.disyl  # Compact badge
│   │   │
│   │   ├── action/
│   │   │   ├── action.block.default.disyl     # Full action strip
│   │   │   └── action.block.inline.disyl      # Inline buttons
│   │   │
│   │   ├── progress/
│   │   │   ├── progress.block.default.disyl   # Full progress bar
│   │   │   └── progress.block.inline.disyl    # Compact progress
│   │   │
│   │   ├── lessons/
│   │   │   └── lessons.block.disyl            # Lesson/course index
│   │   │
│   │   └── list-card/
│   │       ├── list-card.block.default.disyl         # Default card
│   │       ├── list-card.pricing.block.disyl          # Card with pricing
│   │       ├── list-card.pricing.featured.block.disyl # Featured card
│   │       ├── list-card.inventory.block.disyl        # Card with stock
│   │       ├── list-card.inventory.compact.block.disyl# Compact stock card
│   │       └── list-card.progress.block.disyl         # Card with progress
│   │
│   └── partials/
│       ├── header.disyl              # Site header
│       ├── footer.disyl              # Site footer
│       ├── sidebar.disyl             # Sidebar
│       ├── breadcrumb.disyl          # Breadcrumb nav
│       ├── pagination.disyl          # Pagination controls
│       ├── search-form.disyl         # Search form
│       ├── canonical-entity-styles.disyl  # Shared entity CSS vars
│       └── storefront-styles.disyl   # Commerce-specific styles
│
├── admin/
│   ├── customizer-preview.disyl  # Live customizer preview frame
│   └── theme-info.disyl          # Theme info panel in admin
│
├── docs/
│   ├── 01-quickstart.md          # 5-minute setup
│   ├── 02-manifest.md            # theme.manifest.json annotated
│   ├── 03-tokens.md              # Design token system
│   ├── 04-entity-views.md        # Entity view templates guide
│   ├── 05-blocks.md              # Block library reference
│   ├── 06-variants.md            # Block variant system
│   ├── 07-customizer.md          # Customizer integration
│   ├── 08-components.md          # DiSyL component registry
│   ├── 09-layouts.md             # Layout system
│   ├── 10-multi-surface.md       # Print/email/export rendering
│   ├── 11-macros.md              # DiSyL macro library
│   └── 12-deployment.md          # Theme packaging and install
│
└── screenshots/
    ├── entity-view-product.png
    ├── entity-view-course.png
    ├── entity-list-shop.png
    ├── home-page.png
    └── customizer-panel.png
```

---

## Phase 1: Theme Foundation (Day 1)

### Deliverables

**1. `theme.manifest.json`** — Fully annotated canonical manifest

```json
{
    "name": "ark",
    "version": "1.0.0",
    "label": "ARK Reference Theme",
    "description": "Developer reference theme showcasing DiSyL entity views, capability-driven rendering, and Kernel OS 6.0+ public presentation system.",
    "author": "Ikabud Kernel Team",
    "license": "MIT",
    "customizer_scope": "native",
    "design_language": {
        "type_scale": "inter",
        "color_system": "oklch",
        "grid": "tailwind-v3",
        "icon_set": "fontawesome-6"
    },
    "supports": {
        "menus": ["primary", "footer"],
        "features": ["featured-images", "excerpts", "custom-templates", "entity-views"]
    },
    "assets": {
        "css": ["style.css"],
        "js": ["script.js"]
    }
}
```

Also include backward-compatible `theme.json`.

**2. `tokens.json`** — Design token system mapping to CSS custom properties

CSS variables generated from tokens:
```css
:root {
    --ark-color-primary: #6366f1;
    --ark-color-primary-light: #eef2ff;
    --ark-color-surface: #ffffff;
    --ark-color-surface-muted: #f8fafc;
    --ark-color-text: #0f172a;
    --ark-color-text-secondary: #64748b;
    --ark-color-border: #e2e8f0;
    --ark-color-success: #22c55e;
    --ark-color-warning: #f59e0b;
    --ark-color-danger: #ef4444;
    --ark-color-info: #3b82f6;
    --ark-font-family: 'Inter', system-ui, sans-serif;
    --ark-font-heading: 'Inter', system-ui, sans-serif;
    --ark-font-size-base: 16px;
    --ark-line-height: 1.6;
    --ark-radius-sm: 0.375rem;
    --ark-radius-md: 0.75rem;
    --ark-radius-lg: 1rem;
    --ark-radius-xl: 1.5rem;
    --ark-radius-full: 9999px;
    --ark-spacing-xs: 0.5rem;
    --ark-spacing-sm: 0.75rem;
    --ark-spacing-md: 1.25rem;
    --ark-spacing-lg: 2rem;
    --ark-spacing-xl: 3rem;
    --ark-shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --ark-shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
    --ark-shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
    --ark-container-max: 1280px;
    --ark-header-height: 64px;
    --ark-sidebar-width: 300px;
}
```

**3. `style.css`** — Minimal stylesheet using only CSS custom properties, no hardcoded values. Demonstrates the token-driven approach.

---

## Phase 2: Shell Layout (Day 1-2)

### Deliverables

**`layouts/public.disyl`** — The main shell template demonstrating:

- `{extends}` / `{block}` pattern for template inheritance
- `{block head}` for `<head>` injection
- `{block content}` as the content slot
- `{include}` for header, footer, sidebar partials
- Region-aware conditional rendering (`{if header_region.present}`, `{if show_sidebar}`)
- Alpine.js 3.x CDN include with `x-data` for interactive components
- Script block interpolation safety (DiSyL v4.7 feature)
- CSS custom property output from tokens
- ETag/Last-Modified cache metadata output
- Noscript fallback for animation gating
- Responsive grid layout with sidebar positioning

**Partials:**
- `public/partials/header.disyl` — Site header with menu, branding, mobile toggle, currency switcher
- `public/partials/footer.disyl` — Footer with widget columns, copyright bar
- `public/partials/sidebar.disyl` — Optional sidebar
- `public/partials/breadcrumb.disyl` — Breadcrumb navigation
- `public/partials/pagination.disyl` — Pagination chrome
- `public/partials/search-form.disyl` — Search form

---

## Phase 3: Entity View Templates (Day 2-3)

### Deliverables

**`public/entity.view.disyl`** — Canonical entity detail template.

Demonstrates:
- Capability-gated block rendering (`{if capabilities.pricing}`, `{if capabilities.inventory}`, etc.)
- Layout profile switching (commerce vs content vs education)
- Entity presentation overrides from customizer
- Media gallery with lightbox
- Progress tracking block
- Lessons/course index block
- Action strip (add-to-cart, book, inquire)
- Structured data output
- Builder content passthrough when builder-enabled
- Header-before-media vs media-before-header toggle
- Summary panel (sticky sidebar) for commerce layout
- Meta block (author, date, type badge)

**`public/entity.list.disyl`** — Canonical entity list template.

Demonstrates:
- Category navigation (chip row and dropdown modes)
- Search form with attribute filter preservation
- Filter summary pills
- List card grid with density control
- Pagination with page state
- Store hours panel (commerce-specific)
- Store banner hero
- Result count and active filter display
- Empty state handling
- List toolbar with search + category + sort controls

**Page templates:**
- `public/archive.disyl` — Blog archive extending entity list patterns
- `public/single.disyl` — Blog single extending entity view patterns
- `public/page.disyl` — Static page
- `public/search.disyl` — Search results
- `public/home.disyl` — Home page
- `public/404.disyl` — 404 page
- `public/full-width.disyl` — Full-width (no sidebar)
- `public/landing.disyl` — Landing page (minimal shell)

---

## Phase 4: Block Library (Day 3-4)

### Deliverables

Each block demonstrates specific DiSyL + entity view concepts.

| Block | DiSyL Concepts Demonstrated |
|---|---|
| `meta.block.disyl` | `{if}` gating, `{include}`, filters (`|default`) |
| `media-gallery.block.disyl` | `{foreach}`, Alpine.js `x-data`, lightbox pattern |
| `pricing.block.default.disyl` | `{set}`, arithmetic, ternary, `{if}` chains, money filter |
| `pricing.block.compact.disyl` | Inline output, `|default`, minimal markup |
| `pricing.block.featured.disyl` | Featured ribbon, `{if}` cascade, sale badge |
| `inventory.block.default.disyl` | Nested `{if}`, enum display, status badges |
| `inventory.block.compact.disyl` | Dot indicator, single-line |
| `action.block.default.disyl` | POST forms, CSRF tokens, capability gating |
| `action.block.inline.disyl` | Inline buttons, no form wrapping |
| `progress.block.default.disyl` | SVG/CSS progress bar, percentage calc |
| `progress.block.inline.disyl` | Compact progress dot/text |
| `lessons.block.disyl` | Ordered list, `{foreach}`, completion badges |
| `list-card.block.default.disyl` | Card container with image + title + excerpt |
| `list-card.pricing.block.disyl` | Card with price display |
| `list-card.pricing.featured.block.disyl` | Featured/highlighted card |
| `list-card.inventory.block.disyl` | Card with stock badge |
| `list-card.inventory.compact.block.disyl` | Card with minimal stock indicator |
| `list-card.progress.block.disyl` | Card with progress bar |

### Block variant resolution pattern in `entity.view.disyl`:

```disyl
{* Resolve block variant from customizer, fall back to theme default, fall back to canonical *}
{set pricing_block = entity_presentation.block_variants.pricing|default:'default'}
{if pricing_block == 'compact'}
    {include "blocks/pricing/pricing.block.compact.disyl"}
{elseif pricing_block == 'featured'}
    {include "blocks/pricing/pricing.block.featured.disyl"}
{else}
    {include "blocks/pricing/pricing.block.default.disyl"}
{/if}
```

---

## Phase 5: DiSyL Macro Library (Day 4)

### Deliverables

**`public/partials/macros.disyl`** — Reusable macro library demonstrating DiSyL v4.7 `{macro}` feature.

```disyl
{* ── Button macro ─────────────────────────────────────── *}
{macro ark_button(label, url, variant = 'primary', size = 'md', icon = '')}
    <a href="{url}" class="ark-btn ark-btn--{variant} ark-btn--{size}"{if icon} data-icon="{icon}"{/if}>
        {label}
    </a>
{/macro}

{* ── Badge macro ──────────────────────────────────────── *}
{macro ark_badge(text, variant = 'default')}
    <span class="ark-badge ark-badge--{variant}">{text}</span>
{/macro}

{* ── Price display macro ──────────────────────────────── *}
{macro ark_price(amount, currency = '₱', sale_amount = '', suffix = '')}
    {if sale_amount}
        <span class="ark-price ark-price--sale">
            <span class="ark-price__original">{currency}{amount|number_format:2}</span>
            <span class="ark-price__sale">{currency}{sale_amount|number_format:2}</span>
        </span>
    {else}
        <span class="ark-price">
            <span class="ark-price__amount">{currency}{amount|number_format:2}</span>
            {if suffix}<span class="ark-price__suffix">{suffix}</span>{/if}
        </span>
    {/if}
{/macro}

{* ── Progress bar macro ───────────────────────────────── *}
{macro ark_progress(percent, label = '', color = 'primary')}
    <div class="ark-progress">
        {if label}<span class="ark-progress__label">{label}</span>{/if}
        <div class="ark-progress__track">
            <div class="ark-progress__fill ark-progress__fill--{color}" style="width:{percent}%"></div>
        </div>
        <span class="ark-progress__percent">{percent|number_format:0}%</span>
    </div>
{/macro}

{* ── Card macro ───────────────────────────────────────── *}
{macro ark_card(title, url, image = '', excerpt = '', badge = '', meta = '')}
    <article class="ark-card">
        {if image}
        <a href="{url}" class="ark-card__image-link">
            <img src="{image}" alt="{title}" class="ark-card__image" loading="lazy">
        </a>
        {/if}
        <div class="ark-card__body">
            {if badge}<span class="ark-card__badge">{badge}</span>{/if}
            <h3 class="ark-card__title"><a href="{url}">{title}</a></h3>
            {if excerpt}<p class="ark-card__excerpt">{excerpt}</p>{/if}
            {if meta}<div class="ark-card__meta">{meta}</div>{/if}
        </div>
    </article>
{/macro}
```

---

## Phase 6: Customizer Integration (Day 4-5)

### Deliverables

**Customizer sections** registered via CMS helper hooks:

The theme does not **own** the customizer — it provides defaults. The customizer sections are CMS-owned, but ARK documents which controls affect which parts of the theme.

Documented in `docs/07-customizer.md`:

| Customizer Section | Controls | Theme Impact |
|---|---|---|
| `theme` → `Colors` | Primary, secondary, accent, surface, text, border | CSS custom properties |
| `theme` → `Typography` | Font family, heading weight, base size, line height | `--ark-font-*` vars |
| `theme` → `Layout` | Container width, header height, sidebar width, sidebar position | `--ark-container-max`, grid layout |
| `theme` → `Header` | Header style, sticky, transparent pages | `header.disyl` rendering |
| `theme` → `Footer` | Columns, colors, copyright, bar | `footer.disyl` rendering |
| `entity_presentation` → `Detail` | Layout profile, summary width, media ratio, spacing scale | `entity.view.disyl` layout |
| `entity_presentation` → `List` | Card density, show excerpt, excerpt length, filter summary | `entity.list.disyl` layout |
| `entity_presentation` → `Block Variants` | Per-block variant selection | Block include resolution |

**`admin/customizer-preview.disyl`** — Live preview iframe shell that receives customizer changes via `postMessage` and updates CSS variables in real-time.

---

## Phase 7: Component Registration (Day 5)

### Deliverables

**ARK custom DiSyL components** registered via PHP `ComponentRegistry`:

```php
// In modules/cms/helpers/ark-components.php or via theme hook
app()->templateEngine()->registerComponent('ark_card_grid', function(array $attrs, string $body): string {
    $items = $attrs['items'] ?? [];
    $columns = (int)($attrs['columns'] ?? 3);
    // renders a responsive card grid
});

app()->templateEngine()->registerComponent('ark_hero', function(array $attrs, string $body): string {
    // renders a hero section with optional background image
});

app()->templateEngine()->registerComponent('ark_stats', function(array $attrs, string $body): string {
    // renders a stats/metrics grid row
});
```

Demonstrates how modules/themes extend DiSyL with custom components while keeping template logic in PHP.

---

## Phase 8: Multi-Surface Support (Day 5-6)

### Deliverables

**`layouts/public-print.disyl`** — Print-optimized layout:
- Removes header, footer, sidebar
- Removes all interactive elements (forms, buttons, Alpine)
- Adds print-specific CSS (`@page`, `page-break-after`, etc.)
- Inlines all content for print fidelity

**`layouts/public-email.disyl`** — Email-safe layout:
- Table-based layout (no flexbox/grid)
- Inline styles (no `<style>` block)
- Removes JavaScript
- Replaces interactive CTAs with plain links
- Uses `|escape` on all dynamic output

**`public/partials/canonical-entity-styles.disyl`** — Output-target-aware style includes:
```disyl
{* Output-target-aware: 'html', 'print', 'email', 'export' *}
{if output_target == 'print'}
    <style>
        .ark-btn { display: none; }
        .ark-progress__fill { border: 1px solid #000; background: #000; }
    </style>
{elseif output_target == 'email'}
    {* Inline styles, no <style> block *}
{else}
    <link rel="stylesheet" href="{theme_style_url}">
{/if}
```

---

## Phase 9: Documentation (Day 6-7)

### Deliverables

12-document series inside `docs/`, each serving as a standalone developer reference:

| Doc | Audience | Content |
|---|---|---|
| `01-quickstart.md` | New devs | "Copy ARK, rename, customize in 5 minutes" |
| `02-manifest.md` | Theme authors | Annotated `theme.manifest.json` with every field explained |
| `03-tokens.md` | Designers | How token system maps to CSS vars, how to extend |
| `04-entity-views.md` | Template devs | `entity.view.disyl` and `entity.list.disyl` architecture |
| `05-blocks.md` | Template devs | Block library reference, include paths, context requirements |
| `06-variants.md` | Template devs | Block variant resolution pattern, customizer wiring |
| `07-customizer.md` | Theme authors | Customizer sections, defaults, preview bridge |
| `08-components.md` | PHP devs | Component registration, PHP handler pattern |
| `09-layouts.md` | Template devs | `{extends}`, `{block}`, `{include}` patterns, inheritance rules |
| `10-multi-surface.md` | Template devs | Print/email/export output-target patterns |
| `11-macros.md` | Template devs | Macro library reference, parameter conventions |
| `12-deployment.md` | DevOps | ZIP packaging, installer validation, asset copying |

---

## Phase 10: Theme Activation & Testing (Day 7)

### Deliverables

- **ZIP packaging** — `ark-theme-v1.0.0.zip` with correct structure
- **Installer validation** — Verify `validateModuleManifest()` passes, assets copy to `public/assets/cms/themes/ark/`
- **Activation flow** — Document the sequence: upload → extract → copy assets → set `active_theme` → update symlink
- **Test page types**: product detail, product list, blog post, blog archive, static page, search results, 404, landing page
- **Test customizer**: colors, typography, layout, header, footer, entity presentation controls
- **Test capability gating**: entity with pricing, with inventory, with booking, with inquiry, with media gallery, with progress tracking, with no capabilities
- **Test block variants**: switch each block through all variants
- **Test multi-surface**: HTML render, print render, email render
- **Test responsiveness**: mobile, tablet, desktop breakpoints

---

## Total Build Estimate

| Phase | Effort | Dependencies |
|---|---|---|
| 1. Theme Foundation | 1 day | None |
| 2. Shell Layout | 1 day | Phase 1 |
| 3. Entity View Templates | 2 days | Phase 2 |
| 4. Block Library | 2 days | Phase 3 |
| 5. Macro Library | 0.5 day | Phase 3 |
| 6. Customizer Integration | 1 day | Phase 2 |
| 7. Component Registration | 0.5 day | Phase 3 |
| 8. Multi-Surface Support | 1 day | Phase 2, 3 |
| 9. Documentation | 2 days | All above |
| 10. Activation & Testing | 1 day | All above |
| **Total** | **~12 days** | |

A minimal ARK (Foundation + Shell + Entity Views + Core Blocks + Docs) could ship in **~5 days**.

---

## Key Architectural Decisions

1. **ARK does NOT fork entity views** — It overrides `entity.view.disyl` and `entity.list.disyl` to demonstrate the override path, but documents that the canonical CMS versions work without overrides.

2. **Block variants live in subdirectories** — `blocks/pricing/pricing.block.default.disyl` (not `blocks/pricing.block.disyl`). The extra directory enables variant grouping without filename collisions.

3. **All CSS values go through custom properties** — No hardcoded colors, spacing, or radius values. Every visual property references `--ark-*` vars so the customizer can override them.

4. **DiSyL macros are the primary abstraction** — Instead of repeating markup patterns, ARK defines macros for buttons, badges, prices, progress bars, and cards. This demonstrates DiSyL's macro system as the template-level equivalent of PHP helper functions.

5. **Documentation is in the theme itself** — The `docs/` folder ships with the theme ZIP so developers have reference material at hand without searching the repo.

6. **Block variant resolution goes through entity_presentation** — The customizer controls which variant renders, not the template. This enforces the principle that themes provide options, customizer makes choices.
