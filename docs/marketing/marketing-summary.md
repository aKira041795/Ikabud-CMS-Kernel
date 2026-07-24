# Ikabud — Marketing Summary

**For:** Marketing, Sales, and Business Development  
**Prepared from:** Full system and documentation review (June 2026)

---

## What Is Ikabud?

**Ikabud is an all-in-one business application platform** built on a kernel-governed, modular architecture. Think of it as the operating system for your entire business software stack — one platform where your website, online store, warehouse, content, workflows, and more all live together, governed by a shared secure core.

Unlike typical "plugin" platforms where any add-on can break anything else, Ikabud enforces strict contracts between its modules. Business features are isolated, predictable, and composable — so the platform stays stable as it grows.

> **In one sentence:** Ikabud is a governed modular business platform — one codebase, one login, one database per tenant, with every business feature operating through enforced contracts.

---

## Who Is It For?

| Audience | Why Ikabud Fits |
|----------|-----------------|
| **SMBs & growing businesses** | All-in-one: website, store, warehouse, CRM, and workflows — no patchwork of disconnected tools |
| **Enterprises with multiple brands/branches** | Multi-tenant: one platform installation serves multiple businesses with full data isolation |
| **Developers & digital agencies** | Module system with clean contracts, a CLI, and scaffolding tools — build new features without breaking existing ones |
| **Operations-heavy businesses** | WMS, daily ledger, workflow automation, and inventory intelligence baked in |

---

## Core Platform Highlights

### 🏗️ Kernel-Governed Architecture
Ikabud's core is a true **application kernel** — it owns routing, security, authentication, database access rules, and event wiring. Business modules plug in through declared contracts, not loose hooks. This means:
- No module can silently break another
- Security and auth are enforced at the platform level, not left to each module
- Adding new capabilities does not require changing the core

### 🏢 Multi-Tenant by Design
One Ikabud installation can serve **multiple independent businesses (tenants)** — each with their own isolated database, their own module configuration, and their own users. Perfect for agencies running multiple client sites or enterprises managing multiple branches.

### 🔒 Enterprise-Grade Security
- JWT-based authentication (HttpOnly, Secure, SameSite=Strict cookies)
- Role hierarchy: Superadmin → Admin → Manager → Viewer
- CSRF protection on all mutating routes
- CORS origin whitelisting
- Full audit logging for sensitive operations
- AES-encrypted database credentials per tenant

### ⚡ Runs on Standard Hosting
Ikabud deploys on **standard PHP/MySQL shared hosting** (including Bluehost) — no expensive infrastructure required. PHP 8.2+, MySQL 5.7+ (CI tests against MySQL 8.0 and MariaDB 10.6), Apache. One-click installer (`lock.php`) for fast onboarding.

---

## What's Included — Module Lineup

### 📝 CMS — Content Management System
A full-featured CMS with a **visual drag-and-drop page builder** (React-powered).

- Create pages, posts, and custom content types
- Visual page builder with sections, columns, and reusable blocks
- Media library with uploads, thumbnails, and usage tracking
- Categories, tags, menus, redirects, and SEO metadata
- Theme manager and customizer (colors, fonts, layouts, header/footer)
- Scheduled publishing, revisions, and content import/export
- WordPress XML importer for easy migration
- **AI Content Automation** — plan, generate, and publish content at scale using AI

### 🛒 E-Commerce — Commerce Layer
A full commerce layer that exceeds the WooCommerce baseline, fully integrated with the CMS and warehouse.

**Catalog & Products**
- Simple, variable, digital, grouped, bundle, and subscription products
- Faceted filtering, attributes, SEO metadata, product reviews and ratings
- Upsells, cross-sells, recently viewed, product comparison, and customer wishlists

**Cart & Checkout**
- Guest and registered customer checkout
- Tax classes and region-based rates
- Coupon codes, gift cards, and loyalty point redemption
- Fixed-price product add-ons and bookable appointment selections
- Subscription-aware cart validation

**Payments**
- PayMongo, Stripe, PayPal (hosted gateway flows, webhooks, refunds)
- Manual / pay-on-delivery support
- POS in-store transaction flows

**Order Management**
- Full order lifecycle: pending → processing → shipped → delivered → cancelled/refunded
- Partial and full refunds with gateway reversal
- Shipment tracking with carrier and tracking URL
- Customer return requests with admin approval
- Abandoned cart recovery, outbound webhooks, CSV import/export

**Advanced Commerce**
- Multi-currency storefront pricing
- Membership products and membership-gated catalog access
- Loyalty earning and redemption
- Booking and appointment products with calendar management
- Product comparison shortlists and customer wishlists

### 📦 WMS — Warehouse Management System
An **ERP-lite warehouse platform** for businesses with physical inventory.

- **Movement-first ledger**: every stock change is an immutable record — 100% auditable, never corrupted
- Race-condition-safe stock reservations (row-level database locks)
- Idempotent API endpoints (safe for network retries, no double-deductions)
- **Mobile barcode scanner PWA** — warehouse staff pick orders on handheld devices
- Explicit task assignment: `pick`, `putaway`, `replenish`, `count`
- Optimized pick paths (shortest physical walk route)
- FIFO, FEFO (perishables), and LIFO picking strategies
- **Auto-replenishment engine** — monitors reorder points and safety stock, triggers restocking automatically
- **Forecasting** — 30-day run-rate analysis, "Days Remaining" stockout alerts
- **Slotting intelligence** — recommends moving fast movers to prime bin locations
- **Bill of Materials (BOM) / Production** — recipe-based assembly with automated raw material consumption
- Reverse logistics with quarantine isolation for returned goods
- Financial costing layer: FIFO and Moving Average Cost inventory valuation
- Purchase orders and expected delivery workflows
- Full diagnostics: Movement Trace Viewer, Reservation Inspector, ecommerce bridge tracing
- Native integration with E-Commerce (stock reserve on sale, release on cancel, shipment sync)

### ⚙️ Workflow Engine
Model and automate cross-module business processes without writing code.
- Event-triggered workflows
- Capability-driven steps (call any module function as a workflow step)
- Failure handling, retry semantics, and execution history

### 🤖 AI Module
- AI text generation integrated into content editing
- AI-powered content automation plans with run history
- Search-grounded content generation

### 📋 Other Built-In Modules
| Module | What It Does |
|--------|-------------|
| **Daily Ledger** | Financial and inventory tracking per day/period |
| **Ticketing** | Customer support ticket management |
| **SMS** | SMS sending integration for notifications |
| **Search** | Full-text content search across the platform |
| **Media** | Centralized asset management and media library |
| **Contact Form** | Form builder and submission handling |
| **Users** | User account management with role assignment |
| **Anti-Spam** | Spam detection for public-facing forms |
| **GUI Settings** | Theme and UI customization controls |

---

## Key Differentiators vs. Competitors

| Feature | Ikabud | Typical Plugin Platform (WordPress, etc.) |
|---------|--------|------------------------------------------|
| Module isolation | Enforced by kernel contracts | Convention-only, any plugin touches anything |
| Database ownership | Per-module, kernel-enforced | Shared tables, no enforced boundaries |
| Multi-tenancy | Built-in, isolated DB per tenant | Typically a paid add-on or complex setup |
| Security enforcement | Platform-level (auth, CSRF, CORS) | Depends on each plugin's implementation |
| Cross-module integration | Versioned capability contracts | Direct coupling or ad-hoc hooks |
| E-commerce ↔ WMS | Native bi-directional bridge | Requires third-party connectors |
| Self-hostable | Standard PHP/MySQL shared hosting | Same, but often heavier infrastructure |
| All-in-one | CMS + Store + WMS + Workflow + AI | Requires separate tools or plugins |

---

## Platform Reliability & Safety

- **Request ID tracing** — every request gets a unique ID, all logs are correlated
- **Admin view caching** — fast admin responses without repeated database reads
- **Tenant migration auto-sync** — database schema updates apply automatically per tenant on each request
- **OPcache-aware** — automatic code cache flushing on module changes and deployments
- **Hook error isolation** — a broken module hook cannot crash an unrelated request
- **Output buffering** — module handler exceptions are caught safely

---

## Deployment & Onboarding

1. **Upload** the deployment archive to any PHP host
2. **Run** the one-time web installer (`/lock.php`) — enter database credentials and create the admin account
3. **Enable modules** per tenant from the superadmin panel
4. **Go live** — the platform is running

Supported hosting: Bluehost, cPanel shared hosting, VPS, dedicated servers.

---

## Roadmap — What's Coming

### Commerce Maturity (Next)

| Priority | Focus |
|----------|-------|
| **1** | Booking depth — reschedule, cancel, reminders, capacity-aware calendar |
| **2** | Multi-store foundation — store context layer, product projection, store-aware inventory and orders |
| **3** | Customer segmentation and tier-based pricing — B2B, wholesale, institutional |
| **4** | Merchant intelligence — back-in-stock alerts, variant media, richer reporting |

### Platform Kernel (Parallel)

| Phase | Focus |
|-------|-------|
| **1** | Deterministic Capability Runtime — formal contract registry, schema validation |
| **2** | Event & Trigger Governance — safe automation with payload validation and tracing |
| **3** | Module Dependency Intelligence — visualize module relationships, impact analysis |
| **4** | Platform Observability — health dashboards, failure attribution without log-digging |
| **5** | Workflow Runtime Maturation — formal workflow definitions, auditable history |
| **6** | Developer Experience — scaffolding, manifest linting, faster module building |
| **7** | Productization — capability marketplace, visual automation builder, AI scaffolding |

---

## Positioning Statement

> **Ikabud is a governed modular business platform with a commerce layer inside a business operating system — not just a website builder, not just a store, not just a warehouse tool — but all of them, running on one secure, contract-driven kernel that keeps every piece in its place as the business grows.**

It gives businesses the power of a composed enterprise stack with the simplicity of a single deployment, and gives developers a platform with real architectural discipline instead of a plugin free-for-all.

The ecommerce capability is not an "ecommerce module" — it is a commerce layer that lives natively inside the CMS content model, bridges directly into warehouse execution, and scales structurally through store projection rather than catalog duplication.

---

*Document generated from full system and documentation review — April 2026*
