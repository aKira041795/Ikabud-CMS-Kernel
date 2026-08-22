# ARK — Architectural Reference Kit

> **ARK is the production reference theme and executable theme specification for Kernel OS 6.1+.**

**Status**: Production certified / reference implementation — V3.0  
**Kernel OS**: `>=6.1.0`  
**DiSyL**: `>=4.7.0`  
**License**: MIT  

ARK is not merely a default theme. It is the **canonical reference implementation of an Ikabud theme** — every file demonstrates a governed Kernel OS concept. A developer can inspect ARK and learn:

- How a theme declares itself (manifest)
- How tokens are consumed (design tokens to CSS)
- How governed slots work (`{ikb_slot}` + SlotRegistry)
- How modules contribute content (slot contributions)
- How entity fallbacks render unknown types
- How capabilities affect presentation (capability-gated blocks)
- How Theme Studio extends a theme (optional companion)
- What themes are forbidden from doing (anti-patterns)

## Theme Doctrine

```
Theme presents.
Modules provide.
DiSyL declares.
Kernel OS governs.
```

ARK correctly avoids:
- Database access or SQL queries
- Module table reads
- Authorization logic or permission checks
- Business workflows or state transitions
- Mandatory JavaScript frameworks
- Module-specific hard dependencies
- Tenant resolution or context

## Activating

```bash
# Activate ARK as the active CMS theme
php ikabud theme:activate ark

# Optional: enable the companion module for advanced customization
php ikabud module:enable theme-studio
```

ARK is fully functional without Theme Studio. The companion module adds token editing, preset management, Theme Elements, and display conditions — but ARK itself is complete standalone.

## Non-Goals

ARK explicitly does not attempt to be:

- A business module (no database tables, no entity sources)
- A page builder (composes governed components, does not replace the builder)
- An ecommerce theme specifically (renders any module's entities generically)
- An admin framework (admin presentation is owned by CMS/entry modules)
- A JavaScript application shell (zero required JS by default)
- A replacement for Theme Studio (token/preset editing is the module's job)
- A source of authorization rules (permissions belong to Kernel OS)
- A place for tenant-specific modifications (use presets and token overrides)
- An exhaustive design showcase (ARK demonstrates conventions, not visual variety)

## Support Matrix

| Surface | Support | Notes |
|---|---|---|
| Public CMS pages | Required | Entity list/detail via governed components |
| Ecommerce storefront | Generic contracts | Works through entity-view fallbacks |
| Member portal | Supported | Via slot contributions and entity views |
| Admin interface | Not owned by ARK | CMS/entry modules own admin shell |
| Print | Supported | Through print layout + token overrides |
| Email | Separate output theme | Email-safe templates are theme-specific |
| PDF/DOCX | Not theme shell | Handled by reporting system |
| Theme Studio | Optional enhancement | ARK is complete without it |
| Alpine.js / HTMX bridges | Loaded on demand | Only when components require them |

## Slots — Canonical Vocabulary

ARK defines the governed slot vocabulary. These slot IDs are stable — modules register contributions against them.

| Slot | Purpose | Accepts | Multiple | Ordering | Since |
|---|---|---|---|---|---|
| `site.before` | Content before the document shell | component, badge, notification | yes | priority | 1.0 |
| `site.after` | Content after the document shell | component, badge, notification | yes | priority | 1.0 |
| `header.before` | Above the header bar | component, badge, notification | yes | priority | 1.0 |
| `header.main` | Inside the header, alongside branding | component | no | priority | 1.0 |
| `header.after` | Below the header bar | component, badge, notification | yes | priority | 1.0 |
| `hero` | Hero section below header | component, banner, slideshow | no | priority | 1.0 |
| `breadcrumbs` | Breadcrumb navigation row | component | no | priority | 1.0 |
| `content.before` | Above the main content block | component, badge, notification | yes | priority | 1.0 |
| `content` | The primary content area | component, entity_list, entity_detail | no | - | 1.0 |
| `content.after` | Below the main content block | component, badge, notification | yes | priority | 1.0 |
| `sidebar.primary` | Primary sidebar column | widget, component | yes | priority | 1.0 |
| `sidebar.secondary` | Secondary sidebar column | widget, component | yes | priority | 1.0 |
| `footer.before` | Above the footer grid | component, badge | yes | priority | 1.0 |
| `footer.main` | Inside the footer grid columns | widget, component | no | priority | 1.0 |
| `footer.after` | Below the footer grid | component, badge | yes | priority | 1.0 |
| `notifications` | Toast/alert overlay area | notification, toast | yes | priority | 1.0 |

**Slot stability & deprecation policy**: `slots.json` is the single source of
truth for the governed slot vocabulary (16 slots). Slot IDs are a stable public
API and will not be renamed or removed without a deprecation cycle:
(1) keep the ID for at least one minor Kernel OS release, (2) register a
deprecated alias resolving to the new ID, (3) remove the old ID only after the
deprecation window closes. Multiplicity and accepts-type changes follow the
same cycle. Modules must not rely on undocumented slots.

## Component Variant Semantics

ARK uses portable semantic variants, not theme-specific visual names:

| Variant | Meaning | Example Components |
|---|---|---|
| `default` | Standard presentation | panel, button, badge, card |
| `compact` | Dense, reduced spacing | pricing, inventory, list-card |
| `featured` | Highlighted, emphasized | pricing, card |
| `minimal` | Reduced visual weight | pricing, list-card |
| `inline` | Horizontal, no wrapping | action, progress |
| `outlined` | Border-only, no fill | button |
| `stacked` | Vertical arrangement | card, list-card |

A module requests semantic intent. ARK decides what `featured` looks like. Another theme may render it differently while preserving the same meaning.

## Safe Fallback Rendering Doctrine

> **Unknown entity types are supported — unknown fields are hidden.**

ARK fallbacks render only:
- Fields allowed by the entity-view contract
- Fields marked visible by the source schema
- Explicitly safe automatic fields (id, title, name, label, excerpt, description, url, image, status, price, published_at, created_at, author_name)

Fallbacks never blindly render `array_keys()` from an unknown entity, preventing accidental exposure of internal notes, tokens, tenant IDs, cost fields, or provider metadata.

## Production Test Matrix

Before certifying ARK as production-ready, verify under:

1. Kernel OS with no optional business modules installed
2. CMS module only (no ecommerce, guidance, wms)
3. CMS plus ecommerce module
4. Guidance public booking pages
5. Unknown third-party entity type (via generic fallbacks)
6. Theme Studio disabled (ARK complete without it)
7. Theme Studio enabled with token overrides
8. Invalid slot contribution (graceful degradation)
9. Missing optional capability (block hides, does not break)
10. JavaScript disabled (no required JS)
11. Mobile and keyboard navigation
12. High-contrast and reduced-motion modes
13. Long content, long names, and empty entities
14. Module removal after contribution was registered
15. Kernel upgrade with manifest/schema migration

## Validation

```bash
php ikabud theme:validate ark      # full certification
php ikabud theme:inspect ark       # theme summary
```

## Changelog

### v3.0.0 (2026-07-06) — Production Certified

- **Dark mode**: Full `@media (prefers-color-scheme: dark)` support with dark palette from `tokens.json`. All surface, text, border, and accent tokens remap automatically.
- **Form components**: Complete form styling system — `.ark-form-group`, `.ark-input`, `.ark-select`, `.ark-textarea`, `.ark-checkbox`, `.ark-label`, `.ark-label--required`, input error/disabled/focus states, inline and two-column layouts.
- **Component variants**: `.ark-panel` CSS class with tone (surface/muted/elevated/primary), spacing (none–xl), and radius (none–full) variants matching manifest declarations.
- **Ecommerce storefront**: `public/ecommerce/product-list.disyl` and `product-detail.disyl` — full product grid with category/sort filters, gallery with Alpine.js thumbnail switching, pricing/inventory/action capability-gated blocks, SKU/category meta, and related products.
- **script.js**: Optional Alpine.js/HTMX bridge with breadcrumb structured data injection, skip-link focus enhancement, gallery keyboard navigation, and customizer postMessage support. Zero mandatory JS.
- **Entity view map v3**: Extended with guidance_case, guidance_appointment, attendance_record, pal_project, pal_expense entity types.
- **Renderer registry v3**: Added accordion, tabs, hero, chart, cart_summary, checkout_cta, product_grid renderers.
- **a11y audit**: Updated to v3, all 69+ checks passing. Test validates responsive, form, dark-mode, print, accessibility, forced-colors, and reduced-motion patterns.
- **Performance**: CSS compressed ~10KB, well within 80KB budget.

### v2.0.0 — A11Y / Responsive / Form / Dark-Mode Certification

- Accessibility audit framework established
- Mobile responsive patterns (768px, 1024px breakpoints)
- Form component styles (inputs, selects, checkboxes, labels, errors)
- Dark mode token wiring
- Print stylesheet with full @page, orphans/widows, page-break-inside
- Forced-colors (high contrast) support
- Reduced motion media query

### v1.0.0 — Initial Release

- Production reference theme for Kernel OS 6.1+
- 16 governed slots, 4 entity fallback views, 40+ blocks
- Full design token system (tokens.json → CSS vars)
- Multi-surface support (public, print, email)
- Customizer integration with schema + PHP provider

## Files

```
storage/cms-themes/ark/
├── theme.manifest.json       # Canonical manifest (immutable contract)
├── tokens.json               # Design tokens (CSS custom properties)
├── style.css                 # Theme stylesheet (V3: dark mode, form, panel variants)
├── script.js                 # Optional Alpine/HTMX bridge (V3)
├── safety-policy.json        # Raw output / blocked patterns policy
├── slots.json                # 16 governed slot definitions
├── entity-view-map.json      # Cross-module presentation contracts
├── renderer-registry.json    # Block renderer contract map
├── block-registry.json       # Builder block registry
├── page-composition.schema.json # Document contract schema
├── customizer.schema.json    # Customizer schema v3
├── block-definitions/        # JSON block definitions (layout, content, data, forms, etc.)
├── layouts/
│   ├── public.disyl          # Shell with 16 governed slots
│   ├── public-print.disyl    # Print-optimized shell
│   ├── public-email.disyl    # Email-safe table-based shell
│   └── admin-preview.disyl   # Customizer iframe preview shell
├── public/
│   ├── entity.list.disyl     # Generic entity list
│   ├── entity.view.disyl     # Generic entity detail with capability blocks
│   ├── home.disyl            # Home page
│   ├── page.disyl            # Static page
│   ├── 404.disyl             # 404 page
│   ├── single.disyl          # Blog single post
│   ├── archive.disyl         # Blog archive
│   ├── search.disyl          # Search results
│   ├── full-width.disyl      # Full-width (no sidebar)
│   ├── landing.disyl         # Landing page (no header/footer)
│   ├── ecommerce/            # Storefront templates (V3)
│   ├── blocks/               # 40+ block library with semantic variants
│   └── partials/             # Header, footer, sidebar, macros, pagination, etc.
├── entity-views/             # Generic fallback templates (card, table, detail, compact)
├── admin/                    # Admin panel templates
├── src/ArkCustomizerProvider.php  # PHP customizer provider
├── templates/regions/        # Region templates (header, footer, sidebar)
└── docs/README.md            # This file
```
