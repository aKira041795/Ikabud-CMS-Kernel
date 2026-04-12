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
    ├─► Phase 2 (Storefront filter)
    │       └─► Phase 3 (Store pages)
    │               └─► Phase 6 (Branding)
    └─► Phase 4 (Roles)
            └─► Phase 5 (Attribution)

Phase 1 (Data) + Phase 4 (Store admin UI)
    └─► Phase 7A (per-store WMS inventory)
            └─► Phase 7B (warehouse on order)
                    └─► Phase 7C (admin UI in store edit)
                            └─► Phase 7E (return routing — free)
                                    └─► Phase 7F (product-store sync, wms_authoritative only)
```

**Recommended order:** 1 → 2 → 3 → 4 → 5 → 6 → 7

Phases 1–3 are customer-facing value and can be demoed quickly.
Phases 4–5 are merchant/operations value.
Phase 6 is polish.
Phase 7 is infrastructure — run 7A+7B+7C in parallel with Phase 4 (both touch the store edit page).

---

## Phase 7 — WMS × Multi-Store Integration
**Goal:** Each store draws inventory from its own WMS warehouse. Orders route to the right warehouse automatically. Stock levels shown on storefront are per-store-warehouse accurate.

---

### How the WMS Integration Works Today (Studied)

Before designing the multi-store WMS wiring, here is what the codebase already has:

#### Two Authoritative Modes (`kernel_integrations` table)

| Mode | Who owns products | Who owns stock |
|---|---|---|
| `wms_authoritative_products` | WMS pushes product records to ecommerce via bridge | WMS is the only stock source |
| `ecommerce_authoritative_products` | Ecommerce is master; syncs products to WMS | WMS still tracks physical movements |

Mode is read at runtime via `ecActiveIntegrationMode()` → `kernel_integrations WHERE is_active = 1`.

#### WMS Capabilities (already registered)

| Capability | Direction | What it does |
|---|---|---|
| `wms.stock.query@1` | EC → WMS | Fetch qty_on_hand / qty_available / qty_reserved for SKU list at a warehouse |
| `wms.stock.reserve@1` | EC → WMS | Reserve stock for an order (creates stock_movement record) |
| `wms.stock.release@1` | EC → WMS | Release reservation (cancel / refund) |
| `wms.order.create@1` | EC → WMS | Create a pick order in WMS |
| `wms.order.cancel@1` | EC → WMS | Cancel WMS pick order |
| `wms.return.create@1` | EC → WMS | Create inbound return in WMS |
| `wms.product.upsert@1` | EC → WMS | Sync product data to WMS catalog |
| `ecommerce.product.upsert@1` | WMS → EC | WMS pushes new/updated products to ecommerce catalog |
| `ecommerce.orders.status.sync@1` | WMS → EC | WMS pushes order status (processing / shipped / delivered) |
| `ecommerce.orders.tracking.sync@1` | WMS → EC | WMS pushes tracking number after dispatch |
| `ecommerce.orders.payment.sync@1` | WMS → EC | WMS marks COD order as paid after collection |

#### Integration Bridge Event Flows (already wired via `IntegrationBridge::upsertBridge`)

```
ecommerce.order.created   →  wms.stock.reserve@1      (reserve items)
ecommerce.order.created   →  wms.order.create@1       (create pick order)
ecommerce.order.cancelled →  wms.stock.release@1      (release reservation)
ecommerce.order.cancelled →  wms.order.cancel@1       (cancel pick order)
ecommerce.order.refunded  →  wms.stock.release@1      (release refunded items)
ecommerce.product.created →  wms.product.upsert@1     (sync product, ecommerce_authoritative mode)
ecommerce.product.updated →  wms.product.upsert@1

wms.order.picked          →  ecommerce.orders.status.sync@1    (→ processing)
wms.order.dispatched      →  ecommerce.orders.status.sync@1    (→ shipped)
wms.order.dispatched      →  ecommerce.orders.tracking.sync@1  (tracking number)
wms.order.delivered       →  ecommerce.orders.status.sync@1    (→ delivered)
wms.order.payment_collected → ecommerce.orders.payment.sync@1  (COD paid)
wms.product.created       →  ecommerce.product.upsert@1        (wms_authoritative mode)
wms.product.updated       →  ecommerce.product.upsert@1
```

#### The Critical Multi-Store Gap

`ecWmsInventorySnapshotMapForSkus()` (helpers/30-products.php) currently uses:
```php
$warehouseId = max(0, (int)ecSettings('default_wms_warehouse_id'));
```
**A single global warehouse.** All stores share the same stock level.

The multi-store infrastructure to fix this already exists but is unwired:
- `ec_store_inventory_sources` table: `store_id` → `source_type (local|wms)` → `warehouse_id`
- `ecStoreInventorySource(storeId)` helper: returns the active inventory source row for a store
- `ecStoreResolveContext()` helper: returns the current store

**The wire is missing** — the inventory snapshot function never calls these.

Similarly, `ecWmsFulfillmentBridgeDefinitions()` passes `warehouse_id: '{{order.warehouse_id}}'` to reserve/release calls, but `order.warehouse_id` is only populated if set at checkout. There is no code that resolves `store → warehouse` at checkout time.

---

### Phase 7 Work Items

#### 7A — Per-Store Inventory Snapshot (Stock Levels)

**What to change:** `ecWmsInventorySnapshotMapForSkus()` in `30-products.php`.

**Logic:**
```
1. If no store context → use global `default_wms_warehouse_id` (current behavior, preserved)
2. If store context → look up ec_store_inventory_sources for that store_id
   - source_type = 'wms'  → use warehouse_id from that row
   - source_type = 'local' → skip WMS, use ec_products.stock_qty (local mode)
   - no inventory source  → fall back to global default_wms_warehouse_id
```

**Impact:** Product cards, catalog list, product detail page all show per-store accurate stock. Store A shows Warehouse A's stock. Store B shows Warehouse B's stock.

#### 7B — Per-Store Warehouse on Order Creation

**What to change:** Checkout order creation (`ecCreateOrder` / checkout handler).

**Logic:**
```
1. At checkout, resolve ecStoreResolveContext()
2. Look up ecStoreInventorySource(store_id) → get warehouse_id
3. Stamp warehouse_id on the ec_order row
4. Bridge picks it up via {{order.warehouse_id}} → WMS routes pick to correct warehouse
```

Without this, all orders go to the global warehouse even if the customer shopped in a store-specific context.

#### 7C — Admin: Inventory Source Configuration per Store

**What to add:** On the Store edit page (`/ecommerce/admin/stores/{id}/edit`), add an "Inventory Source" section:
- Toggle: `local` (use ecommerce stock_qty) vs `wms` (pull from WMS warehouse)
- If `wms`: dropdown of available WMS warehouses (from `wms_warehouses` table)
- Save writes to `ec_store_inventory_sources`

This gives the admin a UI to wire Store A → Warehouse 1, Store B → Warehouse 2.

#### 7D — Multi-Warehouse Stock Query in `wms.stock.query@1`

**What to verify:** `wms_cap_wms_stock_query_1` already accepts `warehouse_id` as payload and queries `wmsStockSnapshot(warehouseId, filters)`. This is already correct — it queries per-warehouse. The fix needed is purely on the ecommerce side (7A) to pass the right `warehouse_id`.

**No changes needed in WMS module** for basic per-store inventory.

#### 7E — Return Routing

**What to change:** When a return is created (`ecWms return.create@1`), include `warehouse_id` from the original order. This ensures the return goes back to the correct warehouse.

Current state: `ecOrderRefundBridgeItems` already reads `$order['warehouse_id']`, so this works automatically once 7B stamps the warehouse on orders.

#### 7F — Product-Store WMS Sync (wms_authoritative mode only)

When WMS creates/updates a product and it syncs to ecommerce (`wms.product.created` → `ecommerce.product.upsert@1`), the ecommerce upsert should optionally set `is_visible = 1` in `ec_store_product_overrides` for the stores served by that warehouse.

**Logic:**
```
wms.product.created (warehouse_id = 2) 
→ ecommerce.product.upsert@1 
→ also upsert ec_store_product_overrides for stores where inventory_source.warehouse_id = 2
```

This makes WMS-originated products automatically appear in the right stores.

---

### Phase 7 Sequence

```
7A (inventory snapshot) — highest impact, customer-visible
7B (warehouse on order) — required for correct WMS routing
7C (admin UI)           — makes 7A/7B configurable without code
7D (verify only)        — no code change needed
7E (returns)            — free if 7B is done first
7F (product sync)       — wms_authoritative mode only, optional
```

**Prerequisite:** Phase 1 (product-store assignment) must be done before 7F.
**Prerequisite:** Phase 4 (store admin UI) sets up the store edit page that 7C extends.

---

### WMS × Multi-Store: Key Design Decisions

1. **One warehouse per store** (for now) — `ec_store_inventory_sources` supports multiple sources per store with priority ordering, but start with one-to-one. Multi-source can be enabled later by lowering the `priority` column logic.

2. **Local stock and WMS stock are mutually exclusive per store** — a store either uses local `stock_qty` or a WMS warehouse, not both. Mixed mode is confusing and unnecessary.

3. **Global fallback is preserved** — if a store has no inventory source configured, it falls back to `default_wms_warehouse_id` (global setting). Single-store deployments are unaffected.

4. **SKU is the join key** — WMS stock is keyed on SKU, not product ID. This is already how `ecWmsInventorySnapshotMapForSkus` works. As long as ecommerce products have a SKU set, the lookup works.

5. **Integration bridges are store-agnostic** — the bridge definitions do not need to change. They pass `warehouse_id` from the order, which is stamped at checkout (7B). The WMS module routes by warehouse internally.

---

## What This Is NOT (Scope Boundaries)

| Out of scope | Reason |
|---|---|
| Separate subdomain per store (akira.shop.com) | Adds hosting/SSL complexity — use `/store/akira` path instead |
| Isolated checkout per store (separate cart) | One unified cart, items from any store — simpler for customers |
| Vendor payouts / commission ledger | Future Phase 8 if marketplace revenue model is needed |
| Store-level shipping zones | Handled by existing shipping module per order, not per store |

---

## Key Design Principles

1. **One catalog, multiple views** — products are central; store is a dimension/filter, not a container.
2. **Global products are the default** — unassigned products appear everywhere. Stores opt-in to products, not the reverse.
3. **Unified checkout** — customer always checks out once, regardless of how many stores are in their cart.
4. **Store = facet** — in the catalog, store behaves exactly like a category filter. Familiar UX pattern.
5. **Progressive enhancement** — single-store deployments work unchanged. Multi-store activates when `ec_stores` has more than one active row.
