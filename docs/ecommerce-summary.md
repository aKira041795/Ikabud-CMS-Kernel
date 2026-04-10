# Ecommerce Summary

## Purpose

This document summarizes the current Ecommerce module, identifies the highest-value feature gaps against WooCommerce core plus key extensions, and turns that gap analysis into an execution plan that can be implemented incrementally without breaking the existing CMS, Integration Bridge, or WMS boundaries.

## Current Ecommerce Feature Set

### Catalog and Product Model

- Products are implemented as CMS content records with type `product`.
- Supported product behaviors today: simple products, variants, digital products, grouped products, bundle products, subscription products, and external or affiliate products.
- Catalog support already includes categories, tags, featured image, gallery media, SKU, pricing, sale pricing, stock tracking, product SEO metadata, attributes, and faceted filtering.
- Storefront rendering is aligned to the CMS entity-view and entity-list contracts rather than a standalone storefront renderer.

### Cart, Checkout, and Orders

- Cart supports guest sessions and registered customer persistence.
- Checkout supports guest checkout, shipping, tax calculation, coupon application, manual or gateway payment selection, and subscription-aware cart validation for recurring products.
- Order lifecycle supports pending, processing, shipped, delivered, cancelled, and refunded states.
- Order history, order detail, and guest confirmation flows already exist.

### Payments and Fulfillment

- Manual payments are supported, including pay-on-delivery style flows.
- PayMongo is implemented as the primary gateway for card and wallet methods.
- POS flows exist for in-store transactions.
- Digital product license issuance and download delivery are already implemented.

### Merchant Operations

- Admin flows already exist for products, orders, categories, coupons, customers, reports, email templates, and settings.
- Sales and inventory reporting already exist.
- Coupon management already exists, including gift cards backed by remaining-balance store credit.
- Customer address storage already exists.

### Integration and Warehouse Connectivity

- Integration Bridge support already exists for ecommerce to WMS order reservation, order creation, cancellation, release, status sync, and payment sync.
- Product authority modes already exist for WMS-authoritative or ecommerce-authoritative sync.
- WMS can already act as stock authority when the relevant bridges are active.

## Benchmark Gap Analysis

The module is already a solid commerce base. The highest-value gaps relative to WooCommerce core plus key extensions are below.

### High-Priority Gaps

- Product reviews and ratings
- Related products, upsells, and cross-sells
- Product attributes and faceted filtering
- Multi-region tax rules and tax classes
- Stripe gateway
- PayPal gateway
- Partial and full refund infrastructure with gateway reversal support

### Medium-Priority Gaps

- Table-rate shipping
- Shipment tracking numbers
- Refund gateway reversal flows

### Lower-Priority Gaps

- Memberships
- Loyalty points and rewards
- Product add-ons
- Product comparison
- Bookings and appointments
- Customer-facing order notes and timeline visibility

## Constraints and Design Rules

- Keep products on the CMS content model. Do not fork product ownership into a separate ecommerce-only product table.
- Keep route declarations in the ecommerce route map and business logic in helpers and handlers.
- Follow the existing payment gateway extension pattern already used for PayMongo.
- Do not force WMS integration into phase 1 features. Design the contracts early and wire the bridges only after the core ecommerce capability exists.
- Preserve module boundaries: Ecommerce owns catalog, checkout, and order intent; WMS owns warehouse execution and stock operations when configured as authority.

## Actionable Implementation Plan

## Phase 1: Core Commerce Hardening

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

## Phase 2: Payments and Shipping Expansion

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

## Phase 3: Integration Bridge and WMS Wiring

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

## Phase 4: Conversion and Operational Features

Objective: improve merchant retention, marketing, and interoperability.

- Abandoned cart recovery
- Outbound webhooks with signed payloads and delivery logs
- Product, order, and customer CSV import and export
- Customer-facing order notes and order timeline visibility

## Phase 5: Advanced Product Types and Revenue Models

Objective: expand the product model without collapsing the current catalog architecture.

- Product bundles
- Subscription products
- Multi-currency storefront pricing, checkout, and order snapshots

## Phase 6: Loyalty and Extended Commerce Features

Objective: add strategic extensions after the core stack is stable.

- Memberships and gated access
- Loyalty points and rewards
- Product add-ons
- Product comparison
- Bookings and appointments

## Recommended Build Order

Implement in this order:

1. Reviews and ratings
2. Product relations
3. Attributes and filtering
4. Tax engine replacement
5. Refund infrastructure
6. Stripe gateway
7. PayPal gateway
8. Table-rate shipping
9. Shipment tracking
10. Bridge and WMS wiring for refunds, tracking, and weight-driven shipping
11. Conversion features
12. Advanced product types and loyalty features

## First Execution Slice

The first build slice should be Reviews and Ratings because it is high-value, low-risk, and mostly isolated from payment and order flows.

Definition of done for the first slice:

- Migration created
- Review helper added to loader
- Review handler added to loader
- Routes added for submit, list, moderate, approve, reject
- Product detail flow renders approved reviews and aggregate rating
- Admin moderation path exists
- Integration-style test added
- Application and PHP error logs checked after test execution

## Success Criteria

- The ecommerce module closes the major WooCommerce parity gaps without abandoning the CMS entity-view model.
- New payment, shipping, and refund capabilities stay compatible with the existing Integration Bridge architecture.
- WMS integration remains staggered and explicit rather than embedded into early-phase catalog or checkout changes.
- Each phase is independently shippable and testable.