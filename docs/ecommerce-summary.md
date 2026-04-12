# Ecommerce Summary

## Purpose

This document summarizes the Ecommerce module as a commerce layer inside the Ikabud business operating system. The six-phase WooCommerce benchmark that originally guided the build is now complete and the module has moved past that frame of reference. This document captures the shipped capability surface, the maturity assessment, and the structural decisions that govern what comes next.

## Current Ecommerce Feature Set

### Catalog and Product Model

- Products are implemented as CMS content records with type `product`.
- Supported product behaviors today: simple products, variants, digital products, grouped products, bundle products, subscription products, membership products, bookable service products, and external or affiliate products.
- Catalog support already includes categories, tags, featured image, gallery media, SKU, pricing, sale pricing, stock tracking, product SEO metadata, attributes, faceted filtering, related products, upsells, cross-sells, comparison, recently viewed products, fixed-price product add-ons, and customer wishlists.
- Membership-gated product access and membership activation products now live on the same CMS content model instead of a parallel product system.
- Storefront rendering is aligned to the CMS entity-view and entity-list contracts rather than a standalone storefront renderer.

### Cart, Checkout, and Orders

- Cart supports guest sessions and registered customer persistence.
- Checkout supports guest checkout, shipping, tax calculation, coupon and gift card application, loyalty point redemption, manual or gateway payment selection, subscription-aware cart validation for recurring products, fixed-price add-ons, and bookable appointment selections.
- Order lifecycle supports pending, processing, shipped, delivered, cancelled, and refunded states.
- Customer returns now support order-level return requests with admin approval and optional WMS return intake.
- Order history, order detail, shipment tracking, and guest confirmation flows already exist.
- Customers now have dedicated account surfaces for rewards, memberships, bookings, returns, and comparison-aware order history badges.
- Customers can build a short comparison shortlist and review products side by side from the storefront.

### Payments and Fulfillment

- Manual payments are supported, including pay-on-delivery style flows.
- PayMongo, Stripe, and PayPal are implemented for hosted gateway flows, webhook verification, and refund-aware payment handling.
- POS flows exist for in-store transactions.
- Digital product license issuance and download delivery are already implemented.
- Shipment tracking and refund-side gateway reversal support already exist.
- Order confirmation tokens expire after 90 days (`token_expires_at` on `ec_orders`); existing orders are back-filled on migration to limit token exposure window.
- Checkout submission and guest account auto-registration are protected by rate limiting via the kernel `rate_limits` table: checkout is capped at 3 attempts per 5 minutes per identifier; account creation during digital checkout is capped at 10 attempts per hour.

### Merchant Operations

- Admin flows already exist for products, orders, categories, coupons and gift cards, customers, reports, email templates, webhooks, abandoned carts, import/export, and settings.
- Admin pages for memberships and loyalty now exist: `/ecommerce/admin/memberships` (list with status/tier/date filters) and `/ecommerce/admin/loyalty` (balance summary, ledger activity, customer search).
- Product edit admin page has been refactored to a tabbed layout: core fields in a top two-column grid; secondary sections (Attributes, Relations, Subscription, Bookings, Digital, SEO) in AlpineJS tabs below, reducing the vertical scroll from ~14 stacked sidebar cards to a compact persistent sidebar plus six navigable tabs. Tab state persists via `?ptab=` URL param.
- Sales and inventory reporting already exist.
- Coupon management already exists, including gift cards backed by remaining-balance store credit.
- Customer address storage already exists.

### Integration and Warehouse Connectivity

- Integration Bridge support already exists for ecommerce to WMS order reservation, order creation, cancellation, release, refund release, status sync, shipment tracking sync, and payment sync.
- Product authority modes already exist for WMS-authoritative or ecommerce-authoritative sync.
- WMS can already act as stock authority when the relevant bridges are active.

## Roadmap Status Snapshot

- Phase 1 is complete: reviews, product relations, attributes and filtering, tax classes, refund infrastructure, and product SEO are all shipped.
- Phase 2 is complete: Stripe, PayPal, table-rate shipping, shipment tracking, and gateway-aware refunds are shipped.
- Phase 3 is complete for the intended bridge seams: refund release, shipment tracking sync, and the related WMS order/payment lifecycle bridges are active.
- Phase 4 is complete for the planned scope: abandoned carts, outbound webhooks, import/export, gift cards, customer-facing order note visibility, and recently viewed merchandising are shipped.
- Phase 5 is complete: grouped products, bundles, subscriptions, and multi-currency are shipped.
- Phase 6 is complete: comparison, memberships, loyalty, add-ons, and bookings are shipped.

## Maturity Assessment

The ecommerce module has closed the WooCommerce parity benchmark and now exceeds it in several dimensions. The remaining work is no longer about missing feature families; it is about operational depth, merchant intelligence, and controlled structural flexibility.

### Architecture — Non-Negotiable Advantage

Products live inside the CMS content model, not a separate ecommerce silo. This keeps rendering unified, makes memberships, SEO, and content blending trivial, and avoids plugin sprawl and data fragmentation. This decision is permanent and must not be reversed.

### Shipped Capability Surface (Beyond WooCommerce Baseline)

The module now covers: simple, variant, digital, grouped, bundle, subscription, membership, bookable, and external products; faceted filtering and attributes; multi-currency; loyalty earning and redemption; membership-gated access; bookings and appointments; comparison shortlists; customer wishlists; CMS-native rendering with theme override support; and a native WMS bridge for stock authority, order reservation, and fulfillment sync.

This positions the system closer to Shopify (ecosystem breadth), Odoo (modular ERP behavior), and headless CMS patterns than to WooCommerce.

### Current Reality Check

- Core system: **complete**
- Booking depth: **complete** — reschedule, cancel, cutoff windows, and reminder engine are all wired end to end; the admin product edit form now exposes all 10 booking config fields and the handler correctly persists them
- Admin membership visibility: **partially wired** — list page exists; manual grant, extend, revoke, and expiry awareness are still pending
- Admin loyalty visibility: **partially wired** — loyalty dashboard page exists; configurable earn/redeem rates, manual point adjustments, and customer-detail integration are still pending
- Merchant intelligence: **thin** — no customer segmentation, tier pricing, or back-in-stock alerting
- Multi-store: **not formalized** — no store context layer, store-aware queries, or store product overrides exist in code

### Remaining Depth Gaps

**Operational depth:**
- Booking depth is now complete; capacity-aware calendar controls and resource/staff scheduling remain as future slices if needed
- Membership admin actions (manual grant, extend, revoke, expiry awareness) and customer-detail membership summary are still pending
- Loyalty configuration (earn/redeem rates as admin settings), manual point adjustments, and customer-detail loyalty panel are still pending
- Membership entitlement reach beyond catalog gating into CMS pages, posts, or tenant capabilities

**Merchant intelligence:**
- Customer segmentation and tier-based pricing (unlocks B2B, wholesale, institutional deployments)
- Back-in-stock notifications and richer inventory alerting
- Variant image mapping and richer merchandising media rules

**Adjacent tooling:**
- Referral and affiliate programs
- Accounting and finance export adapters
- Live carrier quote integrations beyond table-rate shipping

## Constraints and Design Rules

- Keep products on the CMS content model. Do not fork product ownership into a separate ecommerce-only product table.
- Keep route declarations in the ecommerce route map and business logic in helpers and handlers.
- Follow the existing payment gateway extension pattern already used for PayMongo.
- Do not force WMS integration into phase 1 features. Design the contracts early and wire the bridges only after the core ecommerce capability exists.
- Preserve module boundaries: Ecommerce owns catalog, checkout, and order intent; WMS owns warehouse execution and stock operations when configured as authority.

## Historical Roadmap and Status

## Phase 1: Core Commerce Hardening (Complete)

Objective: close the most visible WooCommerce core gaps without destabilizing checkout.

### 1. Reviews and Ratings

Deliverables:

- Add review storage with approval status, rating, review text, verified purchase flag, and timestamps.
- Add storefront review read model for product detail pages.
- Add review submission endpoint and moderation endpoint.
- Add admin moderation view or order/product-adjacent moderation actions.
- Show aggregate rating and review count on product detail and product cards where practical.

Primary touchpoints:

- `modules/ecommerce/module.json`
- `modules/ecommerce/routes.php`
- `modules/ecommerce/helpers.php`
- `modules/ecommerce/handlers.php`
- `modules/ecommerce/handlers/10-public-shop.php`
- New helper and handler files for review logic and endpoints
- New migration after `012_ec_order_status_history.sql`

Acceptance criteria:

- A customer can submit a review.
- An admin can approve or reject it.
- Only approved reviews render publicly.
- Aggregate rating is computed correctly.

### 2. Related Products, Upsells, and Cross-Sells

Deliverables:

- Add relation storage between products.
- Add admin UI to assign related, upsell, and cross-sell items.
- Render upsells on product detail and cross-sells in cart.

Primary touchpoints:

- `modules/ecommerce/handlers/35-admin-products.php`
- `modules/ecommerce/handlers/10-public-shop.php`
- `modules/ecommerce/handlers/15-public-cart.php`
- New relation helper and migration

Acceptance criteria:

- Admin can assign relations.
- Product detail and cart pages consume the new relation data.

### 3. Product Attributes and Faceted Filtering

Deliverables:

- Add attribute definitions and product attribute values.
- Expose filter-aware product queries.
- Support attribute-based filtering on shop and category pages.

Primary touchpoints:

- `modules/ecommerce/helpers/30-products.php`
- `modules/ecommerce/handlers/10-public-shop.php`
- New attribute helper, migration, and API handler

Acceptance criteria:

- Products can store multiple attribute values.
- Shop queries can filter by selected attributes.

### 4. Tax Engine Replacement

Deliverables:

- Replace the single tax rate approach with tax classes and region-based rates.
- Support standard, reduced, and zero-rate classes at minimum.
- Calculate tax using checkout address plus product tax class.

Primary touchpoints:

- `modules/ecommerce/helpers/40-pricing.php`
- Checkout handlers
- Settings admin flow
- New tax migrations and helper

Acceptance criteria:

- Different products can belong to different tax classes.
- Tax changes correctly by destination rules.

### 5. Refund Infrastructure

Deliverables:

- Add refund records and partial refund support.
- Support line-item refunds and optional stock restoration.
- Emit a refund event for later bridge wiring.

Primary touchpoints:

- `modules/ecommerce/helpers/20-orders.php`
- `modules/ecommerce/handlers/40-admin-orders.php`
- New refund helper, API handler, and migration

Acceptance criteria:

- Admin can issue full or partial refunds.
- Refunded amounts are stored independently from the order header.

### 6. Product SEO Metadata

Deliverables:

- Add product-level SEO title, meta description, canonical override, and OG image support.
- Inject product SEO into the public entity-view rendering path.

Primary touchpoints:

- Product edit flow
- `modules/ecommerce/helpers/05-render-contracts.php`

Acceptance criteria:

- Product pages expose the configured metadata in page output.

## Phase 2: Payments and Shipping Expansion (Complete)

Objective: make the checkout stack commercially viable for a broader set of merchants.

### 1. Stripe Gateway

Deliverables:

- Add a Stripe gateway helper following the PayMongo pattern.
- Support payment intent creation, verification, webhook handling, and refund support.

Primary touchpoints:

- `modules/ecommerce/helpers/70-payment-gateways.php`
- New Stripe gateway helper
- `modules/ecommerce/module.json` settings

### 2. PayPal Gateway

Deliverables:

- Add PayPal order creation, verification, webhook handling, and refund support.

Primary touchpoints:

- `modules/ecommerce/helpers/70-payment-gateways.php`
- New PayPal gateway helper
- `modules/ecommerce/module.json` settings

### 3. Table-Rate Shipping

Deliverables:

- Add shipping rules based on order amount, item count, or weight.
- Integrate rule resolution into checkout shipping calculation.

Primary touchpoints:

- Shipping helper area
- Checkout handlers
- Admin settings or shipping management UI

### 4. Shipment Tracking

Deliverables:

- Add shipment records with carrier, tracking number, tracking URL, and shipped timestamp.
- Show tracking in customer order detail and email flows.

Primary touchpoints:

- Order admin flow
- Public order detail flow
- Notification helpers and templates

### 5. Refund Gateway Reversal

Deliverables:

- Extend the refund infrastructure so Stripe and PayPal refunds can execute against the remote gateway when supported.
- Store gateway refund identifiers and remote refund state on the ecommerce side.

Primary touchpoints:

- `modules/ecommerce/helpers/70-payment-gateways.php`
- Refund helper and order admin refund flow

## Phase 3: Integration Bridge and WMS Wiring (Complete)

Objective: wire the new ecommerce features into warehouse and integration flows after the ecommerce-side contracts are stable.

### 1. Refund to WMS Restock Flow

Deliverables:

- Emit an ecommerce refund event with enough line-item detail for warehouse restock or release decisions.
- Add a bridge definition from ecommerce refund events to the relevant WMS stock capability.

Primary touchpoints:

- `modules/ecommerce/helpers/20-orders.php`
- Relevant WMS capability payload contracts

### 2. Shipment Tracking Sync

Deliverables:

- Add an ecommerce tracking sync capability.
- Map WMS dispatch events to ecommerce shipment records.

Primary touchpoints:

- `modules/ecommerce/module.json`
- `modules/ecommerce/helpers.php`
- `modules/ecommerce/helpers/20-orders.php`

### 3. Product Attribute and Weight Sync

Deliverables:

- Extend authoritative product sync payloads to include attributes relevant to storefront filtering and shipping.
- Use WMS-originated weight data when WMS is active as stock and fulfillment authority.

## Phase 4: Conversion and Operational Features (Complete)

Objective: improve merchant retention, marketing, and interoperability.

- Abandoned cart recovery
- Outbound webhooks with signed payloads and delivery logs
- Product, order, and customer CSV import and export
- Recently viewed products
- Customer-facing order notes and order timeline visibility

## Phase 5: Advanced Product Types and Revenue Models (Complete)

Objective: expand the product model without collapsing the current catalog architecture.

- Product bundles
- Subscription products
- Multi-currency storefront pricing, checkout, and order snapshots

## Phase 6: Loyalty and Extended Commerce Features (Complete)

Objective: add strategic extensions after the core stack is stable.

Implemented in this phase:

- Product comparison
- Membership products and membership-gated catalog access
- Loyalty earning and redemption with cart, checkout, order, and account visibility
- Fixed-price product add-ons persisted through cart and order snapshots
- Bookings and appointments with product configuration, cart validation, pending order records, and paid-order confirmation
- Shared and native theme account surfaces for memberships, bookings, and rewards

Residual follow-on gaps after phase 6 closeout:

- Booking depth is still intentionally lightweight; reminder emails, staff or resource scheduling, and richer calendar management are not part of the current implementation.
- Membership breadth is currently ecommerce-focused; extending the same entitlement model into general CMS content gating would be a separate follow-on slice.
- Validation for this closeout now includes `tests/ecommerce_phase6_features_test.php` plus regression coverage for subscriptions and comparison flows.

## Multi-Store Foundation

Multi-store is not a feature. It is a structural decision that affects catalog projection, inventory authority, order ownership, and reporting scope. Building it late means refactoring everything. The correct time to lay the data model is now, before depth features create more surface area to migrate.

### Correct Model: Overlay, Not Duplication

The wrong approach is adding `store_id` everywhere blindly or duplicating catalogs per store. The correct model is projection: products remain global CMS entities, and stores define overrides.

**Layer 1 — Global Catalog (Single Source of Truth)**
- Products (CMS entities) remain global
- Attributes, SEO, media remain global
- Base price remains global with optional store-level override

**Layer 2 — Store Context Layer (Overlay)**
Each store defines:
- Price overrides
- Stock source (WMS location or local ecommerce stock)
- Product availability (enabled or disabled per store)
- Shipping rules (optional store override)
- Tax behavior (optional store override)

**Layer 3 — Inventory Authority (Already Aligned)**
The existing `wms_authoritative_products` and `ecommerce_authoritative_products` modes already support per-context stock authority. Extending to per-store stock source means mapping each store to a WMS warehouse or location.

**Layer 4 — Order Ownership**
Each order belongs to a store and a fulfillment path. This affects reporting, payouts, logistics, and taxation.

**Layer 5 — Customer Scope**
Customers are shared across all stores. One account, unified loyalty, memberships, and order history. This is already the case because `cms_users` is global.

### Minimal Data Model

The multi-store foundation requires only:
- `ec_stores` — store definitions
- `ec_store_product_overrides` — price, availability, and visibility overrides per store per product
- `ec_store_inventory_sources` — maps each store to a WMS warehouse or local stock mode
- `ec_store_settings` — store-level shipping, tax, and checkout configuration

Products stay in `cms_content`. Always.

### Where Multi-Store Connects to Existing Features

- **Bookings:** store equals location; capacity becomes per-store
- **Memberships:** global or store-specific access
- **Loyalty:** global points with store attribution
- **WMS:** already location-aware by design (381 location references in WMS module); the bridge already supports authority modes

## Next Recommended Build Order

With the main roadmap complete, the next work is structural stability and controlled depth — not feature dumping.

1. **Booking depth** — reschedule and cancel windows, reminder notifications, capacity-aware calendar controls (operational depth; affects real merchant operations and customer UX immediately)
2. **Multi-store data foundation** — `ec_stores`, `ec_store_product_overrides`, `ec_store_inventory_sources`, `ec_store_settings`, context resolution, and store-aware queries (structural; start now before depth features create more migration surface)
3. **Customer segmentation and tier pricing** — unlocks B2B, wholesale, and institutional deployment use cases
4. **Back-in-stock notifications** — merchant intelligence
5. **Variant-aware merchandising** — variant image mapping and richer media rules
6. **CMS-wide membership gating** — entitlement checks beyond the storefront, if needed

## Next Execution Slice

The next build slice should be booking depth (reschedule, cancel, reminders) because bookings exist end to end and operational depth is the most meaningful remaining gap in the shipped storefront experience.

Definition of done for that slice:

- Booking records support merchant-controlled reminder timing and customer-visible reschedule or cancel rules
- Account and order-detail surfaces expose the new booking actions without breaking existing purchase flows
- Shared and native theme output stay in parity
- Integration-style test added
- Application and PHP error logs checked after test execution

The slice immediately after should be the multi-store data foundation — data model, context resolution, and store-aware queries only (no multi-store UI yet). This prevents structural debt from accumulating under the depth features.

## Success Criteria

- The ecommerce module has closed the WooCommerce parity benchmark without abandoning the CMS entity-view model.
- New payment, shipping, and refund capabilities stay compatible with the Integration Bridge architecture.
- WMS integration remains staggered and explicit.
- Multi-store is introduced as a structural layer, not bolted on after the fact.
- Remaining depth and intelligence slices stay independently shippable and testable.
- The system is positioned as a commerce layer inside a business operating system, not as an ecommerce plugin.