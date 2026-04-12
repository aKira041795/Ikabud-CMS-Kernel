---
description: Actionable execution plan for ecommerce commerce maturity milestones
---

# Ecommerce Commerce Maturity — Execution Plan

**Companion to:** `ikabud-execution-plan.md` (kernel platform milestones)
**Governs:** Commerce depth, multi-store foundation, merchant intelligence

## Planning Assumptions

- Products stay on `cms_content`. This is non-negotiable.
- Each milestone is independently shippable and testable.
- Multi-store is structural work, not feature work. It runs parallel to depth features but the data layer ships before UI.
- No feature dumping. Each slice has explicit acceptance criteria and exit conditions.
- Kernel platform milestones (A–H) run on a parallel track. Commerce milestones do not block or depend on them unless noted.

---

## Milestone 1 — Operational Depth: Bookings, Memberships & Rewards

### Objective

Wire bookings, memberships, and loyalty/rewards into fully operational features — from product configuration through checkout, activation, customer account management, and admin visibility.

### Why First

All three features already have storage tables (`ec_bookings`, `ec_memberships`, `ec_loyalty_ledger`), helper functions, event listeners, customer-facing templates, and routes. But each has wiring gaps that prevent real merchant use. This milestone closes those gaps before adding new structural work.

---

### Architecture: How These Plug Into the Module

**These are not product types. They are product behaviors.**

A product in this system lives on `cms_content` (type=`product`). Its capabilities are defined by **meta flags** on `cms_content_meta`, not by a type enum column. Multiple behaviors can coexist on one product.

#### The behavior model

| Behavior | Meta flag | Storage table | Activation trigger |
|---|---|---|---|
| **Subscription** | `_is_subscription = 1` | `ec_subscriptions` | `ecommerce.order.paid` event → `ecSubscriptionCreateForPaidOrder()` |
| **Membership** | `_is_membership_product = 1` | `ec_memberships` | `ecommerce.order.paid` event → `ecMembershipCreateForPaidOrder()` |
| **Booking** | `_booking_enabled = 1` | `ec_bookings` | `ecommerce.order.paid` event → `ecBookingConfirmPaidOrder()` |
| **Loyalty** | No per-product flag (system-wide) | `ec_loyalty_ledger` | `ecommerce.order.paid` event → `ecLoyaltyRecordPaidOrder()` |
| **Digital/License** | `_is_digital = 1` | `ec_licenses` | `ecommerce.order.paid` event → license issuance |

#### The product_type derivation

`product_type` is a **computed label** resolved at read time in `ecProductGet()`, not a stored column. The chain (`helpers/30-products.php:382–388`):

```
bundle children exist? → 'bundle'
_is_membership_product? → 'membership'
_is_subscription?       → 'subscription'
_is_external_product?   → 'external'
default                 → 'physical'
```

Bookings do **not** override product_type — a subscription can also be bookable. A physical product can be bookable. The behaviors are orthogonal.

#### The wiring pipeline (per behavior)

1. **Admin product edit** — form fields write meta flags to `cms_content_meta`
2. **`ecProductGet()`** — reads meta, calls `ecProductBookingConfigFromMetaMap()` / `ecProductMembershipMetaFromMetaMap()` / `ecProductSubscriptionMetaFromMetaMap()` to hydrate the product array
3. **`ecBuildStorefrontCatalogItem()`** — passes hydrated data to templates (booking config, membership gate, subscription summary)
4. **Cart / Checkout** — validates behavior rules (subscription isolation, booking slot selection, membership gate)
5. **`ecOrderCreate()`** — creates order with `cart_items` that carry behavior metadata
6. **`ecommerce.order.paid` event** — listeners in each helper file create records in their respective tables
7. **Customer account pages** — handlers query behavior tables, templates render records

#### What "wiring" means

A behavior is **fully wired** when all 7 steps work end to end. A behavior is **partially wired** when some steps exist but gaps prevent real use (e.g., table exists but no admin management, or records are created but customer can't act on them).

---

### Current State

#### Bookings — partially wired ✅ → now fully wired (completed)

- **Table:** `ec_bookings` with columns for cancel, reschedule, reminder (migration 027 applied)
- **Helpers:** Full lifecycle — create, confirm, reschedule, cancel, reminder, hydrate for display
- **Product config:** `_booking_enabled`, duration, notice hours, weekdays, time slots, allow_reschedule, allow_cancel, cutoff hours, reminder hours
- **Customer UI:** `my-bookings.disyl` with reschedule/cancel forms, status badges, flash messages
- **Admin UI:** Booking panel in order detail with status badges, cancel reason, reschedule links
- **Routes:** GET `/ecommerce/my-bookings`, POST reschedule + cancel
- **Event listener:** `ecommerce.order.paid` → `ecBookingConfirmPaidOrder()`
- **Gap closed:** Reschedule, cancel, reminder engine, admin product booking operations settings

#### Memberships — partially wired

- **Table:** `ec_memberships` exists (order_id, order_item_id, customer_id, customer_email, product_id, membership_tier, status, duration_days, starts_at, ends_at)
- **Helpers:** `ecMembershipCreateForPaidOrder()`, `ecMembershipsForCustomer()`, `ecCustomerActiveMembershipTiers()`, `ecMembershipGateForProduct()`
- **Product config:** `_is_membership_product`, `_membership_tier`, `_membership_duration_days`, `_required_membership_tiers`
- **Customer UI:** `my-memberships.disyl` — lists memberships with tier, duration, status, originating order link
- **Routes:** GET `/ecommerce/my-memberships` → `ecPublicMemberships()`; GET `/ecommerce/admin/memberships` → `ecAdminMemberships()` ✅
- **Event listener:** `ecommerce.order.paid` → `ecMembershipCreateForPaidOrder()`
- **Admin list page:** `memberships.disyl` exists with status/tier/date filters and customer search ✅
- **Remaining gaps:**
  - No admin ability to manually grant, extend, or revoke a membership
  - No membership expiry warning email to customer
  - Customer cannot see remaining days or renewal options
  - No membership status in admin customer detail

#### Loyalty/Rewards — partially wired

- **Table:** `ec_loyalty_ledger` exists (customer_id, order_id, entry_type, points, description, created_at)
- **Helpers:** `ecLoyaltyRecordPaidOrder()` (earn + redeem), `ecCustomerLoyaltyPointsBalance()`, `ecLoyaltyEntriesForCustomer()`, `ecCartApplyLoyalty()`, `ecLoyaltyCurrencyDiscount()`
- **Product config:** None (system-wide, not per-product)
- **Customer UI:** `rewards.disyl` — balance display, transaction history
- **Cart integration:** Loyalty points can be selected and applied as discount at checkout
- **Routes:** GET `/ecommerce/rewards` → `ecPublicRewards()`; GET `/ecommerce/admin/loyalty` → `ecAdminLoyalty()` ✅
- **Event listener:** `ecommerce.order.paid` → `ecLoyaltyRecordPaidOrder()`
- **Admin loyalty page:** `loyalty.disyl` exists with balance summary cards, ledger activity, and customer search ✅
- **Remaining gaps:**
  - Earn rate is hardcoded (`ecLoyaltyEarnRatePerCurrencyUnit()` returns 1, `ecLoyaltyPointsPerCurrencyUnit()` returns 100, `ecLoyaltyMinimumRedeemPoints()` returns 100) — no admin settings
  - No admin ability to manually credit/debit points
  - No expiry policy for earned points
  - No loyalty tier levels (bronze, silver, gold) based on lifetime spend
  - Points-per-currency and minimum redemption are not configurable via admin settings

---

### Workstream 1.A — Booking Depth (COMPLETED ✅)

All booking workstreams from the original plan have been implemented:

- **1.A.1** Schema migration `027_ec_booking_operations.sql` — applied
- **1.A.2** Product-level booking settings — allow_reschedule, allow_cancel, cutoff hours, reminder hours
- **1.A.3** Reschedule logic — `ecBookingCanReschedule()`, `ecBookingReschedule()`
- **1.A.4** Cancel logic — `ecBookingCanCancel()`, `ecBookingCancel()`
- **1.A.5** Reminder engine — `ecBookingsDueForReminder()`, `ecBookingSendReminder()`, `ecBookingProcessReminders()`
- **1.A.6** Customer UI — reschedule/cancel forms in `my-bookings.disyl` (module + native theme)
- **1.A.7** Admin visibility — booking panel in order detail with status badges
- **1.A.8** Routes and handlers — `handlers/26-public-bookings.php`, POST routes registered

### Workstream 1.B — Membership Operational Wiring

#### 1.B.1 — Admin Memberships List Page ✅ COMPLETED

- Route: `GET /ecommerce/admin/memberships` → `ecAdminMemberships()`
- Handler: `handlers/71-admin-memberships-loyalty.php`
- Template: `templates/modules/ecommerce/admin/memberships.disyl`
- Features: customer name/email search, status/tier filter, start/end dates, order link

#### 1.B.2 — Admin Membership Actions

- **Manual grant:** Admin can create a membership for a customer without an order (gift, comp, migration)
- **Extend:** Admin can extend `ends_at` by N days
- **Revoke:** Admin can set status to `cancelled` with a reason
- API or form-based (POST route on admin memberships page)

#### 1.B.3 — Membership Expiry Awareness

- Customer `my-memberships.disyl`: show "Expires in X days" badge when `ends_at` is within 30 days
- Helper: `ecMembershipExpiringForCustomer(int $customerId, int $withinDays = 30): array`
- Optional: cron-driven expiry warning email (same pattern as booking reminders)

#### 1.B.4 — Admin Customer Detail: Membership Info

Extend admin customer edit/detail page to show active memberships, tiers, and expiry dates. Read-only summary — full management via the memberships admin page.

### Workstream 1.C — Loyalty/Rewards Operational Wiring

#### 1.C.1 — Configurable Loyalty Settings

Move hardcoded values to admin settings (stored in `ec_settings`):

- `loyalty_earn_rate` — points earned per currency unit spent (default: 1)
- `loyalty_points_per_currency_unit` — points needed for 1 currency unit discount (default: 100)
- `loyalty_minimum_redeem_points` — minimum points to redeem (default: 100)
- `loyalty_enabled` — master toggle (default: true)

Update `ecLoyaltyEarnRatePerCurrencyUnit()`, `ecLoyaltyPointsPerCurrencyUnit()`, `ecLoyaltyMinimumRedeemPoints()` to read from settings with fallback to current hardcoded values.

Admin UI: add Loyalty section to ecommerce admin settings page.

#### 1.C.2 — Admin Loyalty Dashboard ✅ COMPLETED

- Route: `GET /ecommerce/admin/loyalty` → `ecAdminLoyalty()`
- Handler: `handlers/71-admin-memberships-loyalty.php`
- Template: `templates/modules/ecommerce/admin/loyalty.disyl`
- Features: summary cards (in circulation, earned all-time, redeemed), activity log, customer search

#### 1.C.3 — Admin Manual Points Adjustment

- From loyalty dashboard or customer detail: admin can credit or debit points with a description
- Helper: `ecLoyaltyAdminAdjust(int $customerId, int $points, string $description, int $adminUserId): array`
- Uses existing `ecLoyaltyRecordEntry()` with entry_type `admin_credit` or `admin_debit`

#### 1.C.4 — Admin Customer Detail: Loyalty Balance

Extend admin customer edit/detail page to show current loyalty balance and recent transactions.

---

### Acceptance Criteria

**Bookings (completed):**
- ✅ Customer can reschedule/cancel confirmed bookings within cutoff window
- ✅ Reminders engine exists for upcoming bookings
- ✅ Rescheduled bookings create linked records
- ✅ Admin sees booking status in order detail
- ✅ All 10 booking config fields (including allow_reschedule, reschedule_cutoff_hours, allow_cancel, cancel_cutoff_hours, reminder_hours_before) now correctly persisted through handler → `ecProductSaveBookingMeta`
- ✅ Product edit admin UI refactored to tabbed layout — booking fields exposed in dedicated Bookings tab

**Memberships:**
- ✅ Admin can list, search, and filter all memberships
- Admin can manually grant, extend, or revoke a membership
- Customer sees expiry countdown on active memberships
- Admin customer detail shows membership summary

**Rewards:**
- Earn rate, redemption rate, and minimum are configurable in admin settings
- ✅ Admin has a loyalty dashboard with circulation stats and activity log
- Admin can manually credit/debit loyalty points
- Admin customer detail shows loyalty balance

**Cross-cutting:**
- All admin pages follow existing admin layout and permission patterns
- All customer-facing changes maintain shared + native theme parity
- No PHP errors or warnings in app.log

### Exit Conditions

- Each behavior's 7-step wiring pipeline is complete end to end
- Admin can manage all three features without database access
- Customer account pages surface all relevant status and action information
- Settings changes take effect without code deployment

---

## Milestone 2 — Multi-Store Data Foundation ✅ COMPLETE

### Objective

Lay the structural data model for multi-store without building multi-store UI. After this milestone, the system can resolve store context and queries can be store-aware, but the storefront still operates as a single store.

### Why Second

Multi-store affects catalog projection, inventory authority, order ownership, and reporting scope. Every depth feature built after this point (segmentation, notifications, etc.) would need to be retrofitted if the store layer doesn't exist. Starting the data model now prevents that.

### Completed

- `ec_stores`, `ec_store_product_overrides`, `ec_store_inventory_sources` tables created via `028_ec_multi_store_foundation.sql` (idempotent, default store seeded)
- `ec_orders.store_id` column added with index
- `helpers/38-stores.php` — full context resolution: `ecStoreStorageAvailable`, `ecStoreIsMultiStoreActive`, `ecStoreDefault`, `ecStoreBySlug`, `ecStoreById`, `ecStoreResolveContext`, `ecStoreProductOverride`, `ecStoreApplyProductOverrides`, `ecStoreInventorySource`
- Resolution order: `?store=` query param → `X-Store-Slug` header → default store → null
- `ecBuildStorefrontCatalogItem()` applies price/sale price overrides and visibility (hidden products return `is_visible: false` stub) when context is active
- `ecOrderCreate()` writes `store_id` when context is active; NULL otherwise (backward compatible)
- Helper registered in `helpers.php`; tables + migration registered in `module.json`
- Single-store deployments: zero behavioral difference (`ecStoreResolveContext()` returns null = no overrides applied)

### Workstream 2.1 — Schema

Migration `028_ec_multi_store_foundation.sql`:

```sql
CREATE TABLE IF NOT EXISTS ec_stores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(100) NOT NULL,
    description TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    settings_json JSON       NULL COMMENT 'store-level overrides: shipping, tax, checkout',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_stores_code (code),
    UNIQUE KEY uq_ec_stores_slug (slug),
    KEY idx_ec_stores_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_store_product_overrides (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL COMMENT 'cms_content.id',
    is_visible  TINYINT(1)   NOT NULL DEFAULT 1,
    price_override    DECIMAL(12,2) NULL DEFAULT NULL,
    sale_price_override DECIMAL(12,2) NULL DEFAULT NULL,
    sort_override     INT NULL DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_spo_store_product (store_id, product_id),
    KEY idx_ec_spo_product (product_id),
    CONSTRAINT fk_ec_spo_store FOREIGN KEY (store_id) REFERENCES ec_stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_store_inventory_sources (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id      INT UNSIGNED NOT NULL,
    source_type   VARCHAR(20)  NOT NULL DEFAULT 'local' COMMENT 'local or wms',
    warehouse_id  INT UNSIGNED NULL COMMENT 'wms_warehouses.id when source_type=wms',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    priority      INT          NOT NULL DEFAULT 0 COMMENT 'lower = preferred',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ec_sis_store_warehouse (store_id, warehouse_id),
    CONSTRAINT fk_ec_sis_store FOREIGN KEY (store_id) REFERENCES ec_stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Add `store_id INT UNSIGNED NULL DEFAULT NULL` and `KEY idx_ec_orders_store (store_id)` to `ec_orders` via ALTER in the same migration.

Register all new tables in `module.json` `owns_tables`. Register migration in `module.json` `migrations`.

### Workstream 2.2 — Store Context Resolution

New helper file: `helpers/38-stores.php`

Core functions:
- `ecStoreStorageAvailable(): bool` — direct table probe (same pattern as wishlist/bookings)
- `ecStoreDefault(): ?array` — returns the row where `is_default = 1`, cached per request
- `ecStoreBySlug(string $slug): ?array`
- `ecStoreById(int $id): ?array`
- `ecStoreResolveContext(): ?array` — resolves active store from request (subdomain, path prefix, cookie, or default); returns null when multi-store is not active (no rows in `ec_stores`)
- `ecStoreIsMultiStoreActive(): bool` — returns true if at least one active store exists

Design rule: when `ecStoreResolveContext()` returns null, all existing queries behave exactly as they do today. Multi-store is purely additive.

### Workstream 2.3 — Store-Aware Product Queries

Extend `ecBuildStorefrontCatalogItem()` and shop query helpers:

- When store context is active, JOIN `ec_store_product_overrides` to filter `is_visible = 1` and apply price/sale price overrides
- When store context is null, skip the join entirely (backward compatible)

### Workstream 2.4 — Store-Aware Inventory

Extend stock query helpers:

- When store has `source_type = 'wms'`, resolve stock from `wms_stocks` filtered by `warehouse_id`
- When store has `source_type = 'local'`, use existing ecommerce stock fields
- When no store context, use existing behavior unchanged

### Workstream 2.5 — Order Store Attribution

- When store context is active, `ecOrderCreate()` writes `store_id` on the order
- Reporting queries gain optional `store_id` filter
- When no store context, `store_id` remains NULL (backward compatible)

### Workstream 2.6 — Default Store Seed

On first migration run, if `ec_stores` is empty after table creation, insert one default store:
```sql
INSERT INTO ec_stores (code, name, slug, is_active, is_default)
VALUES ('default', 'Default Store', 'default', 1, 1);
```

This ensures existing single-store deployments continue working without manual setup.

### Remaining Gap — Workstream 2.4 (Inventory Routing)

`ecStoreInventorySource()` is implemented and returns the preferred source for a store. Actual stock query routing (WMS warehouse filter vs local) was not wired in this pass — stock reads still use the existing WMS/local authority flags. This becomes relevant only when a store is configured with `source_type = 'wms'` and a specific `warehouse_id`; it is safe to defer to Milestone 3 prep.

### Acceptance Criteria ✅

- `ec_stores`, `ec_store_product_overrides`, `ec_store_inventory_sources` tables exist and migrate cleanly
- `ec_orders.store_id` column exists
- `ecStoreResolveContext()` returns null with zero behavioral change in single-store mode
- Product queries apply price and visibility overrides when store context is active
- Orders record `store_id` when store context is active
- All existing helpers load without error (tested via helper loader)

### Exit Conditions ✅

- Schema migrates and rolls forward cleanly
- Single-store deployments see zero behavioral difference
- Multi-store data model is ready for future UI and storefront routing

---

## Milestone 3 — Customer Segmentation and Tier Pricing ✅ COMPLETE (Data Foundation)

### Objective

Enable merchants to group customers into segments and apply tier-based pricing. This unlocks B2B, wholesale, and institutional deployment use cases.

### Completed

- `ec_customer_segments`, `ec_customer_segment_members`, `ec_segment_product_prices` tables created via `029_ec_customer_segments.sql` (idempotent)
- `helpers/39-pricing-tiers.php` — `ecSegmentStorageAvailable`, `ecSegmentCurrentUserId`, `ecCustomerActiveSegments`, `ecSegmentResolvePrice`, `ecSegmentApplyProductPrice`, `ecSegmentAddMember`, `ecSegmentRemoveMember`, `ecSegmentProductPriceList`, `ecSegmentUpsertProductPrice`
- Pricing stack: `global price → store override → segment override` — applied in `ecBuildStorefrontCatalogItem()` after store overrides, before `ecStorefrontNormalizePricing`
- Segment types: `percent` (% off list), `fixed` (flat amount off), `price_list` (per-product rows in `ec_segment_product_prices`)
- Resolution: highest-priority segment evaluated first; `price_list` falls through to next segment if no row for the product
- `_segment_priced: true` flag set on product when segment pricing applies
- Helper registered in `helpers.php`; tables + migration registered in `module.json`
- Non-segmented customers: zero behavioral change (`ecSegmentCurrentUserId()` returns 0 = no segments fetched)

### Workstream 3.1 — Schema

Migration `029_ec_customer_segments.sql`:

- `ec_customer_segments` — id, code, name, description, discount_type (percent/fixed/price_list), discount_value, priority, is_active, created_at, updated_at
- `ec_customer_segment_members` — id, segment_id, user_id, added_at (UNIQUE on segment_id + user_id)
- `ec_segment_product_prices` — id, segment_id, product_id, price, sale_price, created_at, updated_at (UNIQUE on segment_id + product_id)

### Remaining Gap — Workstream 3.4 (Admin UI)

Segment CRUD, customer assignment UI, and per-segment price list editor are deferred. The data layer and resolution engine are in place; the admin surface is the next step.

### Acceptance Criteria ✅

- Customers in a segment see segment-specific prices in `ecBuildStorefrontCatalogItem`
- Non-segmented customers see standard prices (no regression)
- Pricing resolution is deterministic: global → store → segment (most specific wins)
- Schema migrates cleanly

### Exit Conditions ✅

- Schema migrates cleanly
- Pricing resolution is deterministic: global → store → segment → sale (most specific wins)

---

## Milestone 4 — Back-in-Stock Notifications ✅ COMPLETE (Data Foundation)

### Objective

Allow customers to subscribe for notification when an out-of-stock product returns to stock.

### Completed

- `ec_stock_notifications` created via `030_ec_stock_notifications.sql` (idempotent) — tracks product_id, variant_id, customer_email, customer_id, status (waiting/sent/expired), notified_at
- `helpers/41-stock-notifications.php` — `ecStockNotificationStorageAvailable`, `ecStockNotificationSubscribe`, `ecStockNotificationCheckAndTrigger`, `ecStockNotificationProcessProduct`, `ecStockNotificationSend`, `ecStockNotificationExpire`, `ecStockNotificationWaiters`, `ecStockNotificationWaitersCount`, `ecPublicStockNotifySubscribe` (POST handler)
- Trigger wired into `ecProductIncrementStock` and `ecProductUpdateInventory` — fires when qty transitions from ≤0 to >0
- Duplicate prevention via UNIQUE(product_id, variant_id, customer_email, status=waiting)
- `ecStockNotificationExpire(90)` for cron-based cleanup of stale subscriptions
- `ecommerce.product.back_in_stock` event fired on trigger for extensibility
- Backward compatible: silently no-ops when `sendEmail()` unavailable or storage absent

### Workstream 4.1 — Schema

Migration `030_ec_stock_notifications.sql`:

- `ec_stock_notifications` — id, product_id, variant_id (nullable), customer_email, customer_id (nullable), status (waiting/sent/expired), notified_at, created_at

### Remaining Gap — Storefront UI

The "Notify me when available" button and email-input widget on the product detail page template is deferred. The POST handler `ecPublicStockNotifySubscribe()` is ready; only the template wiring remains.

### Acceptance Criteria ✅

- Customer can subscribe (duplicate-safe)
- Notification fires when stock returns (via trigger in ecProductIncrementStock / ecProductUpdateInventory)
- Duplicate subscriptions are prevented
- Notification is sent once per subscription (marked `sent` immediately after email dispatch)

---

## Milestone 5 — Variant-Aware Merchandising ✅ COMPLETE (Data Foundation)

### Objective

Map product images to specific variants and enable richer merchandising media rules.

### Completed

- `ec_variant_media` created via `031_ec_variant_media.sql` (idempotent) — UNIQUE(variant_id, media_id), sorted by sort_order
- `helpers/42-variant-media.php` — `ecVariantMediaStorageAvailable`, `ecVariantMediaForVariant`, `ecVariantMediaForProduct`, `ecVariantMediaForProducts` (batch), `ecVariantMediaFallbackGallery`, `ecVariantMediaAttach`, `ecVariantMediaDetach`, `ecVariantMediaDetachAll`, `ecVariantMediaReorder`, `ecVariantMediaNormalizeRow/Rows`
- `variant_media_map` added to `ecBuildStorefrontCatalogItem` return value — `{variantId: [{url, thumb, caption, sort_order, media_id}]}` keyed by variant_id; empty `{}` when no variant media assigned or storage unavailable
- Fallback rule: `ecVariantMediaFallbackGallery()` returns variant-specific images when assigned, otherwise parent product gallery
- Batch loader `ecVariantMediaForProducts()` available for collection pages (avoids N+1 per product)
- Registered in `helpers.php` + `module.json`
- Simple products with no variants: `variant_media_map = {}` — zero behavioral change

### Workstream 5.1 — Schema

- `ec_variant_media` — id, variant_id, media_id, sort_order (references CMS media library)

### Remaining Gap — Workstream 5.2 (Admin UI + JS swap)

- Admin UI for assigning gallery images to variants (product-edit template)
- Storefront JS image-swap on variant selection (reads `variant_media_map` from page data)

### Acceptance Criteria ✅

- Variant selection can change displayed product image (data layer ready; JS swap deferred)
- Variants without mapped media fall back to parent gallery
- No regression on simple or non-variant products

---

## Milestone 6 — CMS-Wide Membership Gating

### Objective

Extend the existing ecommerce membership entitlement model to gate CMS pages and posts, not just storefront products.

### Workstream 6.1 — Entitlement Check Extension

- `ecMembershipUserHasAccess(int $userId, string $requiredMembership): bool` — already exists for products; extend to accept a content ID or content type check
- CMS entity-view render path checks membership entitlement before rendering gated content

### Workstream 6.2 — Content Gating UI

- Admin content editor: "Require membership" dropdown on pages and posts
- Storefront: gated content shows teaser with membership purchase CTA

### Acceptance Criteria

- Non-member sees teaser, member sees full content
- Membership check uses the same entitlement logic as product gating

---

## Delivery Sequence

| Wave | Milestones | Focus |
|------|-----------|-------|
| **Wave 1** | Milestone 1 (Booking Depth) | Operational depth |
| **Wave 2** | Milestone 2 (Multi-Store Foundation) | Structural |
| **Wave 3** | Milestone 3 (Segmentation + Tier Pricing) | Revenue model |
| **Wave 4** | Milestones 4 + 5 (Stock Notifications + Variant Media) | Merchant intelligence |
| **Wave 5** | Milestone 6 (CMS Membership Gating) | Platform reach |

Waves 1 and 2 are the critical path. Waves 3–5 are independently shippable after Wave 2.

## Governance Rules

- No milestone should bypass the CMS content model for product ownership
- No milestone should introduce store-specific product duplication
- Every schema change ships as an idempotent migration registered in `module.json`
- Every customer-facing action is CSRF-protected and login-gated where applicable
- Shared and native theme template parity is maintained for all storefront changes
- Integration tests are added per milestone; existing tests must not regress

## Relationship to Kernel Platform Milestones

This commerce execution plan runs parallel to the kernel milestones (A–H) in `ikabud-execution-plan.md`. Dependencies:

- **Milestone 2 (Multi-Store)** benefits from kernel Milestone A (Deterministic Capabilities) for store-aware capability resolution, but does not block on it
- **Milestone 3 (Segmentation)** benefits from kernel Milestone B (Runtime Contracts) for pricing contract enforcement, but does not block on it
- **Milestone 6 (CMS Gating)** may consume kernel event/trigger infrastructure if available, but can ship with direct entitlement checks

No commerce milestone is gated by a kernel milestone. Both tracks advance independently.
