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

## Milestone 1 — Booking Depth

### Objective

Make bookings operationally complete for real merchant use: reschedule, cancel, and remind.

### Why First

Bookings already exist end to end (product config → cart → order → confirmation → account listing). But they are operationally shallow — once created, a booking cannot be changed or cancelled by the customer, and there are no automated reminders. This is the most visible gap in the shipped storefront.

### Current State

- `ec_bookings` table exists with: `id`, `order_id`, `order_item_id`, `customer_id`, `customer_email`, `product_id`, `product_title`, `status`, `scheduled_for`, `ends_at`, `duration_minutes`, `notes`, `created_at`, `updated_at`
- Statuses today: `pending`, `confirmed` (set on paid order)
- Helpers: `ecBookingCreatePendingRecordsForOrder()`, `ecBookingConfirmPaidOrder()`, `ecBookingsForOrder()`, `ecBookingsForCustomer()`
- No reschedule, cancel, or reminder logic exists
- No admin booking management beyond order-level viewing

### Workstream 1.1 — Schema Changes

Migration `027_ec_booking_operations.sql`:

- Add `cancelled_at DATETIME NULL` to `ec_bookings`
- Add `cancel_reason VARCHAR(255) NULL` to `ec_bookings`
- Add `rescheduled_from_id BIGINT UNSIGNED NULL` to `ec_bookings` (self-referencing, links to original booking)
- Add `reminder_sent_at DATETIME NULL` to `ec_bookings`
- Add index `idx_ec_bookings_reminder (reminder_sent_at, status, scheduled_for)`

Register migration in `module.json`.

### Workstream 1.2 — Booking Settings (Product-Level)

Extend `ecProductBookingDefaults()` and `ecProductBookingConfigFromMetaMap()`:

- `allow_reschedule` (bool, default false)
- `reschedule_cutoff_hours` (int, default 24) — how many hours before scheduled_for the customer can reschedule
- `allow_cancel` (bool, default false)
- `cancel_cutoff_hours` (int, default 24) — how many hours before scheduled_for the customer can cancel
- `reminder_hours_before` (int, default 24) — when to send reminder (0 = disabled)

Save/load via existing `ecProductSaveBookingMeta()` path.

### Workstream 1.3 — Reschedule Logic

New helper functions in `helpers/87-product-options-bookings.php`:

- `ecBookingCanReschedule(array $booking): bool` — checks status is `confirmed`, cutoff not passed, product allows reschedule
- `ecBookingReschedule(int $bookingId, string $newScheduledFor, ?string $newEndsAt): array` — creates new booking record linked to original via `rescheduled_from_id`, marks original as `rescheduled`, validates against product time slots and capacity

### Workstream 1.4 — Cancel Logic

- `ecBookingCanCancel(array $booking): bool` — checks status is `confirmed`, cutoff not passed, product allows cancel
- `ecBookingCancel(int $bookingId, string $reason): array` — sets status to `cancelled`, records `cancelled_at` and `cancel_reason`

No automatic refund. Cancellation creates an admin-visible event. Refund is a separate merchant decision.

### Workstream 1.5 — Reminder Engine

- `ecBookingsDueForReminder(): array` — selects confirmed bookings where `scheduled_for` is within `reminder_hours_before`, `reminder_sent_at IS NULL`, and status is `confirmed`
- `ecBookingSendReminder(int $bookingId): bool` — sends email notification, sets `reminder_sent_at`
- CLI command or cron-callable helper: `ecBookingProcessReminders()` — iterates due bookings, calls send, logs results

Cron integration: register `ecBookingProcessReminders` on the existing `kbc_every_5_minutes`-equivalent schedule or the ecommerce module's own interval.

### Workstream 1.6 — Customer Account UI

Extend existing booking account surfaces:

- Add "Reschedule" button on confirmed bookings (visible only if `ecBookingCanReschedule()` returns true)
- Add "Cancel" button on confirmed bookings (visible only if `ecBookingCanCancel()` returns true)
- Reschedule flow: date/time picker respecting product slot config, POST to reschedule handler
- Cancel flow: confirmation prompt with optional reason, POST to cancel handler

Templates affected:
- `templates/modules/ecommerce/public/my-bookings.disyl`
- `storage/cms-themes/native-default/public/ecommerce/my-bookings.disyl` (if override exists)

### Workstream 1.7 — Admin Visibility

- Booking list in order detail shows status badges: `confirmed`, `cancelled`, `rescheduled`
- Cancelled bookings show reason and timestamp
- Rescheduled bookings link to the replacement booking

### Workstream 1.8 — Handlers and Routes

New routes:
- `POST /ecommerce/my-bookings/reschedule` — CSRF-protected, login-required
- `POST /ecommerce/my-bookings/cancel` — CSRF-protected, login-required

New handler file: `handlers/19-public-bookings.php` (or extend existing booking handler if present)

### Acceptance Criteria

- A customer can reschedule a confirmed booking within the cutoff window
- A customer can cancel a confirmed booking within the cutoff window
- Reminders are sent automatically for upcoming confirmed bookings
- Rescheduled bookings create a new record linked to the original
- Cancelled bookings do not auto-refund
- All actions are CSRF-protected and login-gated
- Shared and native theme output stay in parity

### Exit Conditions

- Integration test covers reschedule, cancel, and reminder flows
- No PHP errors or warnings in app.log after test execution
- Product-level booking settings round-trip correctly

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
