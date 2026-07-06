# ARK Theme — Ecommerce Expansion & Renderer Registry Audit (2026-07-06)

## Summary
ARK V3.0 now includes full ecommerce storefront templates (product list + product detail) alongside a mature renderer registry (27 renderers), entity-view map (11 entity types), and comprehensive block library. Theme validation passes cleanly: 88 templates, 4 layouts, 16 slots, 27 component variants, 4 entity fallbacks. Two DiSyL lint errors are pre-existing in admin templates (not ARK-specific). Ecommerce page performance shows slow response times (~4s) that warrant investigation.

## What was reviewed
- `storage/cms-themes/ark/public/ecommerce/product-detail.disyl` — full ecommerce product detail
- `storage/cms-themes/ark/public/ecommerce/product-list.disyl` — storefront product listing
- `storage/cms-themes/ark/renderer-registry.json` — 27 renderer declarations
- `storage/cms-themes/ark/entity-view-map.json` — 11 entity type mappings
- `storage/cms-themes/ark/block-registry.json` — block definitions
- `storage/cms-themes/ark/page-composition.schema.json` — schema constraints
- `storage/cms-themes/ark/safety-policy.json` — rendering restrictions
- `storage/cms-themes/ark/customizer.schema.json` — customizer settings
- `storage/cms-themes/ark/theme.manifest.json` — theme manifest
- `php ikabud theme:validate ark` output
- `php ikabud theme:inspect ark` output
- `storage/logs/app.log` — slow request traces
- `php _lint_disyl.php` — template lint results

## Findings

### ✅ Theme Validation — All Checks Pass
```
Theme Validate: ark
  ✓ Loaded theme.manifest.json
  ✓ Schema valid
  ✓ 88 template(s) found in 4 directories
  ✓ No anti-patterns detected
  ✓ Core CSS: 69KB compressed
  ✓ All .disyl templates pass lint
  ✓ All checks passed
```

### ✅ Theme Inspection — Complete Profile
```
Theme: ARK ref Theme
  Layouts: 4 (public, public-print, public-email, admin-preview)
  Slots: 16
  Component variants: 27
  Entity fallbacks: 4 (card, table, detail, compact)
  Surfaces: 3 (public, print, email)
  Required CSS: 1 file
  Kernel OS: 6.1.0
  DiSyL: 4.7.0
  Version: 3.0.0
```

### ✅ Product Detail Template — Comprehensive
- Extends `_cms_active_theme/layouts/public.disyl` ✓
- Sets: `is_ecommerce_public`, `is_ecommerce_entity_route`, `entity_view_context`, `entity_presentation`, `builder_enabled` ✓
- Breadcrumbs with active-state detection
- Product gallery with Alpine.js image switching (main + thumbnails + featured_image_url/entity.image fallback)
- Rating/stars display with review count
- **Capability-gated blocks**: pricing, inventory, action strips — each checks `capability_data.X` before rendering
  - Pricing: `pricing.block.featured.disyl` or `.default.disyl` based on `entity_presentation.block_variants.pricing`
  - Inventory: `inventory.block.compact.disyl` or `.default.disyl`
  - Action (add-to-cart/CTA): `action.block.inline.disyl` or `.default.disyl`
- Full description below fold with HTML safety
- Related products via `{ikb_entity_list source="ecommerce_product.related" view="card_grid" limit="4"}`
- Empty state with back-to-shop link

### ✅ Product List Template — Clean Entity-List Integration
- Store hero section (logo + title + description)
- Toolbar with Alpine.js `@change` for category filter + sort
- Product grid via `{ikb_entity_list}` with `ecommerce_product.recent` fallback, `card_grid` view, limit 12
- Pagination with prev/next and page status
- Empty state with icon + message

### ✅ Renderer Registry — 27 Declarations, All Resolve
All 27 renderer template paths verified to exist on disk by `theme:validate`. Key renderers:
- Core: `entity_list`, `entity_detail`
- Blocks: `meta`, `media_gallery`, `pricing_*` (3 variants), `inventory_*` (2), `action_*` (2), `progress_*` (2), `lessons`, `accordion`, `tabs`, `hero`, `chart`
- List cards: 6 variants (default, pricing, pricing.featured, inventory, inventory.compact, progress)
- Ecommerce-specific: `cart_summary`, `checkout_cta`, `product_grid`

### ✅ Entity-View Map — 11 Entity Types
| Entity Type | Views | Block |
|---|---|---|
| `cms_post` | card_grid, detail | — |
| `ecommerce_product` | compact, detail | product_card |
| `ehr_patient` | compact, detail | patient_summary |
| `ehr_appointment` | compact, detail | appointment_list |
| `bakeshop_product` | compact | ledger_row |
| `wms_stock` | compact, table | inventory_badge |
| `guidance_case` | compact, detail | patient_summary |
| `guidance_appointment` | compact, detail | appointment_list |
| `attendance_record` | compact, table | ledger_row |
| `pal_project` | compact, detail | course_card |
| `pal_expense` | compact, table | ledger_row |

### 🟡 Performance — Slow Ecommerce Requests
`app.log` shows:
```
[warning] slow_request {"duration_ms":3864,"uri":"/ecommerce/cart",...}
[warning] slow_request {"duration_ms":4072,"uri":"/ecommerce/shop",...}
[info] cms.public_context.total {"theme":"ark","duration_ms":2552.18}
```
- `/ecommerce/cart`: ~3.9s — concerning for a cart page
- `/ecommerce/shop`: ~4.1s — storefront listing
- CMS public context rendering alone takes ~2.5s
- **Recommendation**: Profile whether the bottleneck is template compilation (DiSyL), DB queries, or capability resolution. Consider adding page caching for public ecommerce pages.

### 🟡 CSS Bundle Size — 69KB Compressed
- Core CSS is 69KB (compressed, likely gzipped)
- This exceeds the original 50KB budget noted in `theme:validate` anti-pattern scan
- However, `theme:validate` reports it as a passing check (not a warning) — the budget may have been relaxed
- Verify whether the 50KB budget in `ThemeManifestValidator` is still enforced or was adjusted

### ✅ Block Registry — Coverage Complete
- `block-registry.json` includes definitions for all 27 renderers
- Block constraints defined in `page-composition.schema.json`
- ARK safety policy restricts PHP execution, direct DB access, and file inclusion in templates

## Issues

| # | Severity | Description | File |
|---|---|---|---|
| 1 | 🟡 | Slow ecommerce page loads (3.9–4.1s) — needs profiling | `app.log` |
| 2 | 🟡 | CSS bundle 69KB may exceed original 50KB budget | `style.css` |
| 3 | 🟢 | 6 empty block variant directories still present (B1 from earlier gap analysis) | `storage/cms-themes/ark/public/blocks/` |

## Recommendations
1. **Profile ecommerce performance**: Check if the ~4s load time is from DiSyL compilation (first-request penalty), DB queries, or capability resolution. Add a warm-up or page cache strategy.
2. **Verify CSS budget**: Confirm whether the 50KB limit was intentionally relaxed or if 69KB is a regression. If budget stands, tree-shake unused styles.
3. **Clean up empty variant directories**: 6 empty block variant dirs remain from earlier gap analysis (B1) — fill or remove them.
4. **Add ecommerce-specific smoke test**: ARK has a11y audit and theme regression tests, but no dedicated ecommerce rendering test that exercises both product-list and product-detail templates with real data.
