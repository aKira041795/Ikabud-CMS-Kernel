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

## Milestone 2 — Multi-Store Data Foundation

### Objective

Lay the structural data model for multi-store without building multi-store UI. After this milestone, the system can resolve store context and queries can be store-aware, but the storefront still operates as a single store.

### Why Second

Multi-store affects catalog projection, inventory authority, order ownership, and reporting scope. Every depth feature built after this point (segmentation, notifications, etc.) would need to be retrofitted if the store layer doesn't exist. Starting the data model now prevents that.

### Current State

- Zero `store_id` references in the ecommerce module
- Zero `ec_stores` or store override tables
- Products are global CMS entities (`cms_content`)
- Customers are global (`cms_users`)
- WMS has `wms_warehouses` (id, code, name, address, contact_info, is_active) and `wms_locations` (id, warehouse_id, code, name, type, capacity)
- Stock authority modes exist: `wms_authoritative_products` / `ecommerce_authoritative_products`
- Orders have no store attribution

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

### Acceptance Criteria

- `ec_stores`, `ec_store_product_overrides`, `ec_store_inventory_sources` tables exist and migrate cleanly
- `ec_orders.store_id` column exists
- `ecStoreResolveContext()` returns null when no stores configured (zero behavioral change)
- `ecStoreResolveContext()` returns the default store when one exists
- Product queries apply price and visibility overrides when store context is active
- Inventory queries route to correct stock source per store configuration
- Orders record `store_id` when store context is active
- All existing tests still pass (no regression)

### Exit Conditions

- Schema migrates and rolls forward cleanly
- Single-store deployments see zero behavioral difference
- Multi-store data model is ready for future UI and storefront routing

---

## Milestone 3 — Customer Segmentation and Tier Pricing

### Objective

Enable merchants to group customers into segments and apply tier-based pricing. This unlocks B2B, wholesale, and institutional deployment use cases.

### Current State

- No customer segmentation, tier, or group concept exists
- All customers see the same prices
- No wholesale or B2B pricing path

### Workstream 3.1 — Schema

Migration `029_ec_customer_segments.sql`:

- `ec_customer_segments` — id, code, name, description, discount_type (percent/fixed/price_list), discount_value, priority, is_active, created_at, updated_at
- `ec_customer_segment_members` — id, segment_id, user_id, added_at (UNIQUE on segment_id + user_id)
- `ec_segment_product_prices` — id, segment_id, product_id, price, sale_price, created_at, updated_at (UNIQUE on segment_id + product_id)

### Workstream 3.2 — Segment Resolution

- `ecCustomerActiveSegments(int $userId): array` — returns segments for a customer, ordered by priority
- `ecSegmentResolvePrice(int $productId, array $segments): ?array` — returns best price from segment price lists or discount rules

### Workstream 3.3 — Pricing Integration

- Extend `ecBuildStorefrontCatalogItem()` to apply segment pricing when customer is logged in
- Extend cart and checkout to validate segment pricing at order creation
- Segment pricing stacks below store overrides: global price → store override → segment override

### Workstream 3.4 — Admin UI

- Segment CRUD in ecommerce admin
- Customer assignment to segments (individual and bulk)
- Per-segment product price list editor

### Acceptance Criteria

- Customers in a segment see segment-specific prices on shop, product, and cart pages
- Segment pricing survives cart-to-order transition
- Non-segmented customers see standard prices (no regression)
- Admin can create, edit, and assign segments

### Exit Conditions

- Schema migrates cleanly
- Pricing resolution is deterministic: global → store → segment → sale (most specific wins)

---

## Milestone 4 — Back-in-Stock Notifications

### Objective

Allow customers to subscribe for notification when an out-of-stock product returns to stock.

### Workstream 4.1 — Schema

Migration `030_ec_stock_notifications.sql`:

- `ec_stock_notifications` — id, product_id, variant_id (nullable), customer_email, customer_id (nullable), status (waiting/sent/expired), notified_at, created_at

### Workstream 4.2 — Subscription Flow

- Storefront button on out-of-stock products: "Notify me when available"
- Guest: email input; logged-in: auto-fill
- POST handler with rate limiting

### Workstream 4.3 — Notification Trigger

- On stock increment (either ecommerce-side or WMS bridge), check for waiting notifications for that product/variant
- Send email, mark as `sent`
- Batch process to avoid sending during high-throughput stock updates

### Acceptance Criteria

- Customer can subscribe on out-of-stock product
- Notification fires when stock returns
- Duplicate subscriptions are prevented
- Notification is sent once per subscription

---

## Milestone 5 — Variant-Aware Merchandising

### Objective

Map product images to specific variants and enable richer merchandising media rules.

### Workstream 5.1 — Schema

- `ec_variant_media` — id, variant_id, media_id, sort_order (references CMS media library)

### Workstream 5.2 — Admin and Storefront

- Admin: assign gallery images to specific variants
- Storefront: swap displayed image when variant is selected
- Fallback: use parent product gallery when variant has no mapped media

### Acceptance Criteria

- Variant selection changes displayed product image
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
