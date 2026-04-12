# Multi-Store Roadmap
**Objective:** Unified storefront that merges all store products — organized, categorized, filterable by store. Practical and intuitive for both merchants and customers.

**Reference model:** Aggregated marketplace (think Amazon / Lazada) — NOT isolated storefronts (Dokan). Products from all stores appear in one catalog. Store is a filter/facet, not a separate site.

---

## Current State (What Exists)

| Layer | Status |
|---|---|
| `ec_stores` table — name, code, slug, settings_json | ✅ Done |
| `ec_store_product_overrides` — per-store price/visibility | ✅ Done |
| `ec_store_inventory_sources` | ✅ Done |
| `ecStoreResolveContext()` — `?store=slug` / header | ✅ Done |
| `ecStoreApplyProductOverrides()` — price/visibility at render | ✅ Done |
| Admin CRUD for stores | ✅ Done |
| `ecProductList()` — ignores store context entirely | ❌ Gap |
| Store badge on product cards | ❌ Gap |
| Store facet filter on shop | ❌ Gap |
| Dedicated `/store/{slug}` page | ❌ Gap |
| Store-owner role / scoped dashboard | ❌ Gap |
| Order item store attribution | ❌ Gap |
| Per-store analytics | ❌ Gap |

---

## Phase 1 — Product-Store Assignment (Data Layer)
**Goal:** Define which products belong to which stores.

### Decision: Assignment Model
Use `ec_store_product_overrides` as the single source of truth:
- Product with **no overrides** = **global product** — visible in all stores.
- Product with `is_visible = 1` in store X = **assigned to store X**.
- Product with `is_visible = 0` in store X = **hidden in store X**.

This avoids adding new meta keys and reuses the existing table.

### Work Items
1. `ecProductList()` — add `store_id` filter: JOINs `ec_store_product_overrides`, returns products where either no override exists (global) OR `is_visible = 1` for that store.
2. `ecProductList()` — add `with_store` flag: attaches which store(s) each product belongs to (for store badges).
3. Admin product edit page — add "Store Assignment" section: checkboxes for each active store (sets `is_visible` in overrides).
4. Admin products list — add "Stores" column showing store badges per product.

### Acceptance Criteria
- `ecProductList(['store_id' => 2])` returns only products assigned to store 2 (or global products).
- `ecProductList()` with no store filter returns everything (current behavior preserved).
- Admin can assign/unassign a product to a store from the product edit page.

---

## Phase 2 — Unified Storefront with Store Facet
**Goal:** Main shop shows all products merged. Customer can filter by store.

### Work Items
1. **Store badge on product cards** — small tag showing the store name (e.g. "Akira" pill). For global products: no badge or "All Stores".
2. **Store filter in shop sidebar** — list of active stores with product counts. Clicking filters the catalog.
3. **URL pattern:** `/ecommerce/shop?store_filter=akira` — separate from `?store=akira` (context switch). `store_filter` = narrow the catalog. `store=` = full context switch.
4. **`ecPublicShop()`** — pass active store list + counts to the shop template.
5. **Shop template** — add store facet section to the filter sidebar.

### Acceptance Criteria
- Shop loads all products by default (merged from all stores).
- Clicking "Akira" in the filter shows only Akira's products + global products.
- Store badge visible on each product card.
- Store filter count shows live product totals.

---

## Phase 3 — Dedicated Store Pages
**Goal:** Each store gets a public-facing page at `/store/{slug}`.

### Work Items
1. Route: `GET /store/{slug}` → `ecPublicStorePage(array $params)`.
2. Handler: loads store row, resolves product grid (store-filtered `ecProductList`), renders store template.
3. Template `public/store-page.disyl`:
   - Store header: name, description, optional banner image.
   - Product grid: same card component as the shop.
   - Category filter: categories that have products in this store.
   - Breadcrumb: Home / Stores / Akira.
4. **"Visit Store" link** on product detail pages — links to `/store/{store_slug}` when a product belongs to a specific store.
5. **Store directory page:** `GET /ecommerce/stores` — lists all active stores with thumbnails and product counts. Entry point for customers browsing stores.

### Acceptance Criteria
- `/store/akira` renders Akira's products in a branded page.
- `/ecommerce/stores` lists all active stores.
- Product detail page links back to the store.
- SEO-friendly: store name in `<title>`, meta description from store description field.

---

## Phase 4 — Store-Owner Role & Scoped Admin
**Goal:** A store owner logs in and only sees their own store's data.

### Work Items
1. **`ec_store_users` table** — links `user_id` → `store_id` with a `role` column (`owner`, `staff`).
2. **Migration:** `032_ec_store_users.sql`.
3. **New CMS role:** `store_owner` — limited admin access.
4. **Admin dashboard scope:** if logged-in user has `store_owner` role, all admin pages (products, orders, reports) auto-filter to their store.
5. **Store owner can:**
   - Add/edit only their own products.
   - View only orders containing their products.
   - View their store's revenue report.
   - Edit their store profile (name, description, banner).
6. **Store owner cannot:**
   - See other stores' data.
   - Change global settings.
   - Access platform-level reports.
7. **Admin Stores page** — add "Assign Owner" button per store.

### Acceptance Criteria
- Store owner logs in → redirected to their store's admin dashboard.
- Products list only shows their store's products.
- Orders list only shows orders with their products.
- Platform admin still sees everything.

---

## Phase 5 — Order Item Attribution & Store Reports
**Goal:** Track which store generated which revenue.

### Work Items
1. **`ec_order_items.store_id` column** — migration `033_ec_order_item_store.sql`. Populated at checkout from the product's store assignment.
2. **Checkout logic** — when building order items, resolve each product's store and stamp `store_id`.
3. **Per-store revenue report** in admin reports page — filter by store, show: gross sales, order count, top products.
4. **Store owner report** — simplified revenue card on their dashboard.
5. **Admin orders list** — "Stores" column showing which stores are in each order.

### Acceptance Criteria
- Every order item has a `store_id` (null = global/unassigned).
- Reports page has store filter.
- Store owner can see their revenue trend.

---

## Phase 6 — Store Customization & Branding
**Goal:** Each store has its own visual identity within the unified storefront.

### Work Items
1. **Store banner image** — add `banner_image_id` (FK to `cms_media`) to `ec_stores`. Shown on `/store/{slug}` header.
2. **Store logo** — add `logo_image_id` to `ec_stores`. Shown in store badge on product cards and store directory.
3. **Store-level coupons** — existing `ec_coupons` table gets optional `store_id` — coupon valid only for products in that store.
4. **Featured products per store** — `ec_store_featured_products` table or a meta flag `_store_featured_{store_id}`.
5. **Store announcement/banner text** — shown at top of store page and optionally on checkout for that store's items.

### Acceptance Criteria
- Store page shows logo + banner.
- Store coupon only applies to that store's cart items.
- Featured products appear first on the store page.

---

## Delivery Sequence & Dependencies

```
Phase 1 (Data)
    └─► Phase 2 (Storefront filter)
            └─► Phase 3 (Store pages)
                    └─► Phase 6 (Branding) — can run in parallel with Phase 4

Phase 1 (Data)
    └─► Phase 4 (Roles)
            └─► Phase 5 (Attribution)
```

**Recommended order:** 1 → 2 → 3 → 4 → 5 → 6

Phases 1–3 are customer-facing value and can be demoed quickly.
Phases 4–5 are merchant/operations value.
Phase 6 is polish.

---

## What This Is NOT (Scope Boundaries)

| Out of scope | Reason |
|---|---|
| Separate subdomain per store (akira.shop.com) | Adds hosting/SSL complexity — use `/store/akira` path instead |
| Isolated checkout per store (separate cart) | One unified cart, items from any store — simpler for customers |
| Vendor payouts / commission ledger | Future Phase 7 if marketplace revenue model is needed |
| Store-level shipping zones | Handled by existing shipping module per order, not per store |

---

## Key Design Principles

1. **One catalog, multiple views** — products are central; store is a dimension/filter, not a container.
2. **Global products are the default** — unassigned products appear everywhere. Stores opt-in to products, not the reverse.
3. **Unified checkout** — customer always checks out once, regardless of how many stores are in their cart.
4. **Store = facet** — in the catalog, store behaves exactly like a category filter. Familiar UX pattern.
5. **Progressive enhancement** — single-store deployments work unchanged. Multi-store activates when `ec_stores` has more than one active row.
