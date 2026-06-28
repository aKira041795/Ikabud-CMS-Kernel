# ARK — Architectural Reference Kit

> **ARK is the production reference theme and executable theme specification for Kernel OS 6.1+.**

**Status**: Production pilot / reference implementation  
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
| `site.before` | Content before the document shell | component, pattern | yes | priority | 1.0 |
| `site.after` | Content after the document shell | component, pattern | yes | priority | 1.0 |
| `header.before` | Above the header bar | component, badge | yes | priority | 1.0 |
| `header.main` | Inside the header, alongside branding | component, nav | yes | priority | 1.0 |
| `header.after` | Below the header bar | component, hero | yes | priority | 1.0 |
| `hero` | Hero section below header | component, pattern | yes | priority | 1.0 |
| `breadcrumbs` | Breadcrumb navigation row | component, nav | no | priority | 1.0 |
| `content.before` | Above the main content block | component, alert | yes | priority | 1.0 |
| `content` | The primary content area | component, entity-view | no | - | 1.0 |
| `content.after` | Below the main content block | component, entity-list | yes | priority | 1.0 |
| `sidebar.primary` | Primary sidebar column | component, widget | yes | priority | 1.0 |
| `sidebar.secondary` | Secondary sidebar column | component, widget | yes | priority | 1.0 |
| `footer.before` | Above the footer grid | component, nav | yes | priority | 1.0 |
| `footer.main` | Inside the footer grid columns | component, nav | yes | priority | 1.0 |
| `footer.after` | Below the footer grid | component, legal | yes | priority | 1.0 |
| `notifications` | Toast/alert overlay area | component, alert | yes | priority | 1.0 |

**Stability guarantee**: These slot IDs will not be renamed or removed without a deprecation cycle across at least one minor Kernel OS version. Modules may rely on them as a stable public API.

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

## Files

```
storage/cms-themes/ark/
├── theme.manifest.json       # Canonical manifest (immutable contract)
├── tokens.json               # Design tokens (CSS custom properties)
├── style.css                 # Theme stylesheet (6KB)
├── layouts/
│   └── public.disyl          # Shell with 16 governed slots
├── public/
│   ├── entity.list.disyl     # Generic entity list
│   ├── entity.view.disyl     # Generic entity detail with capability blocks
│   ├── home.disyl            # Home page
│   ├── page.disyl            # Static page
│   ├── 404.disyl             # 404 page
│   └── blocks/               # Block library with semantic variants
├── entity-views/             # Generic fallback templates
└── docs/README.md
```
