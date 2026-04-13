# Multi-Store Roadmap
**Objective:** Unified storefront that merges all store products — organized, categorized, filterable by store. Practical and intuitive for both merchants and customers.

**Reference model:** Aggregated marketplace (think Amazon / Lazada) — NOT isolated storefronts (Dokan). Products from all stores appear in one catalog. Store is a filter/facet, not a separate site.

---

## Current State (What Exists)

| Layer | Status |
|---|---|
| `ec_stores` table — name, code, slug, settings_json | ✅ Done |
| `ec_store_product_overrides` — per-store price/visibility | ✅ Done |
| `ec_store_inventory_sources` — maps WMS warehouse to Store | ✅ Done |
| `ecStoreResolveContext()` — `?store=slug` / header | ✅ Done |
| `ecStoreApplyProductOverrides()` — price/visibility at render | ✅ Done |
| Admin CRUD for stores | ✅ Done |
| WMS integration on Orders & Order Items | ✅ Done |
| `ecProductList()` — includes `store_id` filter routing | ✅ Done |
| Store facet filter on shop | ✅ Done |
| Dedicated `/store/{slug}` page (`ecPublicStorePage`) | ✅ Done |
| Store-owner role / scoped dashboard (`74-store-admin-access.php`) | ✅ Done |
| Order & Order item store attribution (`store_id` propagation) | ✅ Done |
| Per-store analytics (`ecStoreAdminReports`) | ✅ Done |
| Multi-store order return/refund routing | ✅ Done |
| Store badges on product cards | ❌ Gap |

---

## Actionable Execution Track — April 2026

This section turns the roadmap into an execution plan tied to the current implementation and the current review findings.

## Multi-Store UX Gaps & Marketplace Alignment

The multi-store system has a solid data layer already: stores, product overrides, user roles, WMS store routing, public store pages, and the initial store-admin area exist. The main problems are now UX correctness, inactive store-admin navigation, and missing merchant-facing capabilities that are baseline in Amazon, Lazada, Shopee, and Foodpanda-style seller portals.

### Current State Summary

**Working now**
- Store CRUD
- Per-store dashboards, orders, products, coupons, reviews
- Role-based store access (`owner`, `manager`, `supervisor`)
- WMS warehouse-to-store routing foundation
- Public store directory and per-store pages
- POS link in the store sidebar

**Broken / missing — six issue groups**

#### Issue 1 — Store Owner Login Redirect

**Root cause:** `cmsLoginBridge()` in `modules/cms/handlers/10-auth.php` historically only branched to `/cms/admin` or `/ecommerce/shop`, with no awareness of store assignments. Store-assigned CMS users could see the admin panel first instead of landing in the store portal.

**Current implementation note:** the redirect decision is now being centralized in the shared authenticated-home path so login, `/login`, and `/cms/login` use the same store-aware resolution.

#### Issue 2 — My Stores Used Admin Layout

**Root cause:** `templates/modules/ecommerce/admin/my-stores.disyl` extended the full ecommerce admin layout, so store users inherited the global admin shell.

**Current implementation note:** the `my-stores` entry page should live in a lightweight store-entry shell instead of the full admin navigation.

#### Issue 3 — Seven Disabled Sidebar Links

In `templates/modules/ecommerce/layouts/store-admin.disyl`, these links are currently non-functional placeholders rendered as disabled nav items.

| Link | Priority | Why |
|---|---|---|
| Reports | Critical | Present in all four reference platforms |
| Returns | High | Required for order lifecycle management |
| Customers | High | Standard merchant capability |
| Categories | High | Required for store catalog organization |
| Abandoned Carts | Medium | Revenue recovery |
| Import / Export | Medium | Bulk operations |
| Loyalty & Rewards | Low | Nice-to-have |

#### Issue 4 — No Supervisor vs Manager View Differentiation

At the moment, supervisors and managers see the same sidebar. The only practical difference is that supervisors are blocked from write actions by `can_edit = false`. The navigation itself is not role-specific.

#### Issue 5 — Missing Marketplace Must-Haves

Comparing the current store portal against Amazon Seller Central, Lazada Seller Center, Shopee Seller Centre, and Foodpanda merchant tooling:

| Feature | All 4 Platforms | Current |
|---|---|---|
| Dashboard with KPI cards | ✅ | Partial |
| Revenue / sales reports | ✅ | ✅ |
| Customer list (store buyers) | ✅ | ✅ |
| Notification center | ✅ | ✅ |
| Store profile / settings page | ✅ | ✅ |
| Return / refund management | ✅ | ✅ |
| Store-level shipping config | ✅ | ✅ |
| Buyer-seller messaging | ✅ | ✅ (order-scoped) |
| Seller performance score | ✅ | ❌ |
| Payout / settlement reports | ✅ | ❌ |

#### Issue 6 — Comprehensive Delivery Plan Required

The roadmap needs a clear implementation sequence that separates critical-path UX fixes from store-admin feature expansion, then from deferred marketplace-depth work.

### Implementation Phases

### Phase A — Critical Path Fixes (Login + Layout)
**Status:** Complete

No new features. Fix the broken entry flow first.

**Work items**
1. Fix login redirect so non-admin CMS users with store assignments are routed to `/ecommerce/store-admin/{id}` when they have exactly one store and `/ecommerce/my-stores` when they have multiple stores.
2. Fix `my-stores` so it uses a minimal standalone store-entry layout rather than `modules/ecommerce/layouts/admin.disyl`.
3. Auto-redirect `/ecommerce/my-stores` to the direct store dashboard when exactly one store is assigned.

**Files**
- `modules/cms/handlers/10-auth.php`
- `bootstrap.php`
- `modules/cms/helpers/00-bootstrap.php`
- `modules/cms/helpers/80-customizer.php`
- `modules/ecommerce/helpers/38-stores.php`
- `modules/ecommerce/handlers/74-store-admin-access.php`
- `templates/modules/ecommerce/admin/my-stores.disyl`
- `templates/modules/ecommerce/layouts/store-entry.disyl`

**Verification**
- Store owner lands at the store dashboard, not `/cms/admin`
- CMS admin still lands at `/cms/admin`
- Customer still lands at `/ecommerce/my-orders` or public shop flows as appropriate

### Phase B — Activate Disabled Sidebar Links
**Status:** Complete

Implement the highest-priority store-admin placeholders.

**Work items**
1. **Reports** — `ecStoreAdminReports()` + store-scoped template using `store_id`-filtered reporting data from `helpers/50-reports.php`
2. **Returns** — `ecStoreAdminReturns()` + template using store-scoped return queries via order/item store attribution and `helpers/22-returns.php`
3. **Customers** — `ecStoreAdminCustomers()` + template listing customers who ordered from the store, including counts and spend
4. **Categories** — `ecStoreAdminCategories()` + template for store-relevant categories and product/category organization
5. **Abandoned Carts** — `ecStoreAdminAbandonedCarts()` + template scoped to carts containing store products

**Per-page implementation pattern**
1. Add handler function in `modules/ecommerce/handlers/74-store-admin-access.php`
2. Register route in `modules/ecommerce/routes.php`
3. Add template under `templates/modules/ecommerce/admin/`
4. Activate the sidebar link in `templates/modules/ecommerce/layouts/store-admin.disyl`

**Files**
- `modules/ecommerce/handlers/74-store-admin-access.php`
- `modules/ecommerce/routes.php`
- `templates/modules/ecommerce/admin/store-admin-reports.disyl`
- `templates/modules/ecommerce/admin/store-admin-returns.disyl`
- `templates/modules/ecommerce/admin/store-admin-customers.disyl`
- `templates/modules/ecommerce/admin/store-admin-categories.disyl`
- `templates/modules/ecommerce/admin/store-admin-abandoned-carts.disyl`
- `templates/modules/ecommerce/layouts/store-admin.disyl`
- `modules/ecommerce/helpers/50-reports.php`
- `modules/ecommerce/helpers/22-returns.php`

### Phase C — Store Profile & Settings
**Status:** Complete

Give owners a store-scoped control surface for identity and configuration.

**Work items**
1. Add `GET|POST /ecommerce/store-admin/{id}/settings`
2. Add `ecStoreAdminSettings()` with owner-only access
3. Allow editing name, description, announcement, banner, and logo
4. Show store team management for owner-only assignment workflows
5. Surface store-level shipping/config data from `settings_json`
6. Add a settings link to the sidebar for owners; keep it hidden or disabled for other roles

**Files**
- `modules/ecommerce/handlers/74-store-admin-access.php`
- `modules/ecommerce/routes.php`
- `templates/modules/ecommerce/admin/store-admin-settings.disyl`
- `templates/modules/ecommerce/layouts/store-admin.disyl`

### Phase D — Role-Differentiated Sidebar
**Status:** Complete

Make the store-admin UI reflect role boundaries instead of showing one generic sidebar.

**Target permission map**
- `supervisor`: dashboard, orders (read), products (read), reviews (read)
- `manager`: everything supervisor has plus coupons, categories, customers, returns, reports, POS, import/export
- `owner`: everything manager has plus settings and team management

**Work items**
1. Define a permissions map in `ecStoreAdminContext()` in `modules/ecommerce/handlers/00-bootstrap.php`
2. Pass permissions to the template context
3. Render store-admin navigation conditionally in `templates/modules/ecommerce/layouts/store-admin.disyl`
4. Keep supervisor write restrictions enforced on the server side
5. Add a persistent read-only banner for supervisors

### Phase E — Notifications & Alerts
**Status:** Complete

**Delivered work**
1. Add `ec_store_notifications`
2. Generate notifications on new orders, return requests, and low-stock conditions
3. Add a notification bell to the store-admin chrome
4. Add APIs for marking notifications as read

### Phase F — Messaging
**Status:** Complete for the current multi-location retail model

**Delivered work**
1. Add order-scoped customer-to-store message threads
2. Render merchant-side messaging in store-admin
3. Render customer-side messaging on order detail pages

**Still deferred for a future marketplace model**
- Cross-order inboxing
- File attachments and moderation workflows
- Seller SLA/performance integration

### Relevant Files

- `modules/cms/handlers/10-auth.php` — login redirect logic
- `modules/ecommerce/handlers/00-bootstrap.php` — `ecRequireStoreAccess()`, `ecStoreAdminContext()`
- `modules/ecommerce/handlers/74-store-admin-access.php` — all store-admin handlers
- `modules/ecommerce/helpers/38-stores.php` — store CRUD, `ecStoresForUser()`
- `modules/ecommerce/helpers/22-returns.php` — returns logic
- `modules/ecommerce/helpers/50-reports.php` — reporting logic
- `modules/ecommerce/routes.php` — store-admin routes
- `templates/modules/ecommerce/layouts/store-admin.disyl` — sidebar and store-admin shell
- `templates/modules/ecommerce/admin/my-stores.disyl` — store chooser entry page

### Verification Matrix

**Phase A**
- Store owner login redirects to the store dashboard
- Multi-store assignees land on `/ecommerce/my-stores`
- Admin bypass still works

**Phase B**
- All five high-priority links navigate to functional store-scoped views
- Supervisors cannot POST on those pages

**Phase C**
- Owners can edit store identity and branding
- Managers and supervisors cannot access owner-only settings actions

**Phase D**
- Supervisor sees only the read-only core sections
- Manager sees the operational sections but not owner-only settings/team management
- Owner sees the full store navigation

**Cross-cutting**
- Supervisors cannot POST
- Managers cannot access owner-only settings
- System admin bypass still works
- After each phase, inspect `storage/logs/app.log` and `storage/logs/error.log`

### Decisions and Scope

**In scope now**
- Phases A through F for the current multi-location retail scope

**Deferred**
- Seller performance scoring, payout settlement, and marketplace-vendor governance

**Explicitly out of scope for this roadmap segment**
- Commission / payout ledger
- Vendor onboarding and self-registration
- Seller reputation/rating systems

**Lower-priority placeholders**
- Import / Export is now available in the merchant portal
- Loyalty & Rewards now has store-level visibility, while deeper reward-program tooling remains low priority

### Further Considerations

1. **Marketplace vs multi-location retail**
    The current model is admin-assigned store staff, not self-registered third-party vendors. If this evolves toward Lazada/Shopee-style marketplace behavior, onboarding and seller governance need their own future phase.
2. **POS store scoping**
    The current sidebar points to `/ecommerce/pos` globally. The recommended direction is to scope POS to the current store's products and inventory.
3. **Import / Export timing**
    Bulk operations are now available in the merchant portal for products, orders, and customers. Future work should focus on bulk validation reporting and richer catalog templates instead of first-time wiring.

### Validation Plan
1. Keep shared authenticated-home redirect coverage for store-assigned CMS users.
2. Validate direct store-home resolution for single-store and multi-store assignees.
3. Verify no regressions for admin and non-store customer redirect paths.
4. After each implementation phase, check `storage/logs/app.log` and `storage/logs/error.log`.

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
2. **Migration:** `033_ec_store_users.sql` *(shifted from 032 — see Phase 7B for the new 032).*
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
1. **`ec_order_items.store_id` column** — migration `034_ec_order_item_store.sql` *(shifted from 033 — see Phase 7B for 032 and Phase 4 for 033)*. Populated at checkout from the product's store assignment.
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

**⚠️ Integration-mode guard — must be restructured:** `ecWmsInventorySnapshotMapForSkus()` currently exits early (returns `[]`) when the global integration mode is not `wms_authoritative_products`. This guard must not run before the store-context branch: a store can declare `source_type = 'wms'` even when the global mode is `ecommerce_authoritative_products`. The guard must only apply to the global fallback path (step 1). Concretely, the function must attempt the store-source lookup before checking the global mode.

**⚠️ Snapshot static cache — key must include warehouse ID:** The existing `static $cache` is keyed by `$warehouseKey`. After the per-store branch is added, different stores will resolve different warehouse IDs. The cache bucket `$cache[$warehouseKey]` correctly segregates by warehouse, so a store without a WMS source (falls back to global warehouse) will share a cache bucket with any other caller using the same warehouse. This is safe — but the cache key must **not** be derived from state computed before the per-store lookup, or the wrong warehouse's cached rows will be served. Keep the key as `(string)$warehouseId` where `$warehouseId` is the resolved value after the store-source lookup.

**⚠️ `ecStoreResolveContext()` static cache — test isolation:** The function uses `static $resolved` which locks on the first call per PHP process. In integration tests that change store context across test cases (e.g., [`tests/ecommerce_multistore_membership_loyalty_test.php`](../tests/ecommerce_multistore_membership_loyalty_test.php)), the cached value will bleed. If tests call `ecWmsInventorySnapshotMapForSkus()` with different store contexts, wrap in a mechanism that resets the static (e.g., pass a reset flag or use a testable wrapper).

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

**⚠️ Missing migration:** Migration `028_ec_multi_store_foundation.sql` added `store_id` to `ec_orders` but did **not** add `warehouse_id`. A new migration is required before 7B can stamp the column:

```sql
-- 032_ec_orders_warehouse_id.sql (idempotent)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ec_orders'
      AND COLUMN_NAME  = 'warehouse_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE ec_orders ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL AFTER store_id, ADD KEY idx_ec_orders_warehouse_id (warehouse_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

Also update the `INSERT INTO ec_orders` path in `ecCreateOrder` (`helpers/20-orders.php`) following the same pattern already used for `store_id` (idempotent column-existence check → conditional column inclusion). Without this column, `ecOrderRefundBridgeItems()` will always resolve `warehouse_id = 0` from the loaded order row, making 7E a no-op despite the bridge already reading the field.

**Note on migration numbering:** Adding `032_ec_orders_warehouse_id.sql` shifts Phase 4's `ec_store_users` migration to `033` and Phase 5's `ec_order_item_store` migration to `034` — see updated numbers below.

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

**⚠️ Missing helper — reverse warehouse→stores lookup:** `ecStoreInventorySource(int $storeId)` in `helpers/38-stores.php` goes store → inventory source. The 7F logic needs the reverse: given a `warehouse_id`, find all stores whose active inventory source points to that warehouse. A new helper is required:

```php
/**
 * Returns store IDs whose active WMS inventory source maps to the given warehouse.
 * Used by the ecommerce.product.upsert@1 bridge extension (7F).
 */
function ecStoresByWarehouseId(int $warehouseId): array
{
    if (!ecStoreStorageAvailable() || $warehouseId <= 0) {
        return [];
    }
    try {
        $rows = ecDb()->query(
            'SELECT store_id FROM ec_store_inventory_sources WHERE warehouse_id = ? AND source_type = ? AND is_active = 1',
            [$warehouseId, 'wms']
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    } catch (\Throwable $e) {
        return [];
    }
}
```

Add this helper to `helpers/38-stores.php` before implementing the upsert bridge extension.

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

---

## Stability Hardening — Completed Items

| Item | Status | Notes |
|---|---|---|
| `ecStoreResolveContext()` singleton made test-resetable | ✅ Done | Switched from `static $resolved` to `$GLOBALS['_ec_store_resolved_cache']`; `ecStoreClearResolvedContext()` added |
| `store_owned_only` INNER JOIN regression test | ✅ Done | `tests/ecommerce_store_catalog_filter_test.php` — 8 assertions covering global catalog, empty store (INNER JOIN), assigned store, and singleton reset |
| WMS capability `consumes` declarations | ✅ Done | `module.json` now lists `wms.stock.reserve@1`, `wms.stock.release@1`, `wms.order.create@1`, `wms.order.cancel@1`, `wms.return.create@1` as optional consumes |
| Split `helpers/30-products.php` into domain files | ✅ Done | `30-catalog.php` (69 fns), `31-inventory.php` (10 fns), `36-storefront.php` (18 fns); `helpers.php` and `cms_theme_test.php` updated; `30-products.php` deleted |
