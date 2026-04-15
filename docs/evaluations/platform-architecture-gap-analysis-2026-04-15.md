# Ikabud Platform — Senior Architecture Gap Analysis

**Repository:** `Ikabud-CMS-Kernel`  
**Evaluation date:** 2026-04-15  
**Scope:** Full platform audit — kernel, CMS, ecommerce, WMS, page builder, multi-tenant, test infrastructure  
**Method:** Exhaustive code audit of all module manifests, 14 ecommerce helper files (200+ functions), all kernel subsystems, CMS route/handler/helper/template coverage, builder node types, test matrix (119 files), checkout/tax/shipping/currency data flows, all existing roadmaps and execution plans.

---

## Executive Summary

The platform has crossed the threshold from "project" to "product." The kernel's capability-driven architecture, manifest-driven module system, event bus with integration bridge, and per-tenant DB isolation are genuinely well-engineered. The ecommerce module alone covers 38 helper files, 90+ routes, bookings, subscriptions, memberships, loyalty, abandoned cart recovery, multi-store, WMS bridging, 3 payment gateways, CSV import/export, POS, digital licenses, and outbound webhook delivery. The CMS has a real page builder with 48+ widget types, a DiSyL template engine at v4.0, and theme customization infrastructure. Test coverage spans 119 files across every major module.

**The gap is not in features. The platform has remarkable feature breadth.**

The gap is in the operational, reliability, and developer-experience seams that separate a capable system from an excellent one. This document catalogs every architectural gap found during an exhaustive audit, prioritized by production impact, and organized into an actionable plan.

---

## TABLE OF CONTENTS

1. [Reliability & Data Integrity](#1-reliability--data-integrity)
2. [Async & Background Processing](#2-async--background-processing)
3. [Observability & Diagnostics](#3-observability--diagnostics)  
4. [Security Hardening (Beyond CSP)](#4-security-hardening-beyond-csp)
5. [Developer Experience & Code Quality](#5-developer-experience--code-quality)
6. [Test Infrastructure Maturation](#6-test-infrastructure-maturation)
7. [Ecommerce Operational Gaps](#7-ecommerce-operational-gaps)
8. [CMS & Page Builder Gaps](#8-cms--page-builder-gaps)
9. [Kernel Infrastructure Gaps](#9-kernel-infrastructure-gaps)
10. [Multi-Tenant & Multi-Store Gaps](#10-multi-tenant--multi-store-gaps)
11. [Documentation Gaps](#11-documentation-gaps)
12. [Execution Plan](#12-execution-plan)

---

## 1. Reliability & Data Integrity

These are the things that cause data loss, silent corruption, or inconsistent state in production.

### 1.1 No Idempotency Keys for Payment Operations

**Current state:** `ecOrderMarkPaid()` checks if order is already paid and returns early. But there's no idempotency key system for the payment gateway layer itself. If a webhook is delivered twice by Stripe/PayPal/PayMongo (which happens regularly in production), the second delivery relies entirely on the `payment_status = 'paid'` guard in `ecOrderMarkPaid`.

**Risk:** If the first webhook sets status to paid but the license listener throws (e.g., OpenSSL failure), the retry webhook will see `paid` and skip — licenses never generated.

**Fix:** Add an `ec_webhook_events` table with `(gateway, event_id)` unique constraint. Record every webhook event ID before processing. Any duplicate silently returns 200. Separate from order status.

### 1.2 Stock Decrement Without Distributed Safety

**Current state:** `ecOrderCreate()` calls `ecProductDecrementStock()` during order creation. If the process crashes after stock decrement but before order insert completes, stock is permanently lost.

**Risk:** Under load (concurrent checkouts for last-in-stock item), overselling is possible because the SELECT→UPDATE sequence isn't atomic.

**Fix:** Use `UPDATE ec_products SET stock = stock - :qty WHERE id = :id AND stock >= :qty` with affected-row check. If 0 rows affected, reject the order. Wrap the entire order creation (stock + insert + items) in a single DB transaction (partially done, but the decrement call is outside the main transaction in some paths).

### 1.3 Refund Amount Not Validated Against Order Balance

**Current state:** `ecOrderCreateRefund()` accepts a `refund_amount` parameter. Neither the handler nor the function validates that the cumulative refunded amount doesn't exceed the order total.

**Risk:** Admin accidentally or maliciously issues refunds exceeding the order total. Gateway refund may succeed (some gateways allow it for chargebacks), creating accounting discrepancy.

**Fix:** In `ecOrderCreateRefund()`, query `SUM(amount) FROM ec_refunds WHERE order_id = ?` and reject if `existing + new > order.total`.

### 1.4 Coupon Restored on Abandoned Cart Recovery May Be Expired

**Current state:** `ecAbandonedCartRestoreSnapshot()` restores the full cart JSON, including `coupon_code`. No validation that the coupon is still valid, not expired, and hasn't exceeded `max_uses`.

**Risk:** Customer recovers cart weeks later and gets a discount from an expired promotional coupon.

**Fix:** After restoring snapshot, call `ecCouponValidate()` on the restored coupon. If invalid, strip it and flash a message.

### 1.5 No Foreign Key Constraints on Core Ecommerce Tables

**Current state:** Relationships between `ec_orders`, `ec_order_items`, `ec_order_licenses`, `ec_payment_transactions`, etc. are enforced only in application code.

**Risk:** Orphaned rows after partial failures. Delete cascade relies on manual cleanup functions in tests.

**Fix:** Add FK constraints with `ON DELETE CASCADE` for child tables. This is safe because the application already maintains referential integrity — the constraints just prevent violation.

---

## 2. Async & Background Processing

This is the single biggest architectural gap in the platform. There is no job queue, no background worker, no cron abstraction. Everything runs synchronously in the request lifecycle.

### 2.1 No Job Queue Infrastructure

**Current state:** The EventBus has `fireDeferred()` and `flushDeferred()` (shutdown hook), but these are still synchronous — they just delay to the end of the request, after `finish_response_if_possible()`. There is no actual background worker, no Redis/DB queue, no retry semantics.

**Impact areas:**
- Outbound webhook delivery blocks order creation if recipient is slow (5s timeout per webhook)
- Email sends are synchronous (mitigated by `finish_response_if_possible` but still blocks the PHP process)
- Subscription renewal has no cron job — `next_renewal_at` is set but never checked
- Membership expiry cleanup has no scheduled job
- Abandoned cart email campaigns process due reminders synchronously
- Report generation on large datasets can timeout

**Recommendation:** Implement a minimal DB-backed job queue:

```
Table: kernel_jobs
  id, queue, payload_json, attempts, max_attempts,
  available_at, reserved_at, created_at, failed_at, error

Worker: php ikabud work:queue --queue=default --sleep=3
```

Minimum viable implementation: A single `kernel_jobs` table, a `dispatch()` helper that inserts a row, and an `ikabud work:queue` CLI command that polls and processes. No Redis dependency. This unlocks:
- Async webhook delivery (move `ecOutboundWebhooksDispatchEvent` to queue)
- Async email sends
- Subscription renewal processing
- Membership expiry sweeps
- Report pre-computation

### 2.2 No Scheduled Task (Cron) Abstraction

**Current state:** No cron manifest or scheduler. Modules cannot declare "run this every hour" in `module.json`. The subscription renewal logic, abandoned cart reminder processing, and membership expiry all implicitly require external cron setup that isn't documented or enforced.

**Recommendation:** Add a `schedules` key to `module.json`:

```json
"schedules": [
  {"frequency": "hourly", "handler": "ecommerce:ecProcessDueSubscriptionRenewals"},
  {"frequency": "every_15_minutes", "handler": "ecommerce:ecAbandonedCartProcessDueReminders"},
  {"frequency": "daily", "handler": "ecommerce:ecMembershipExpiryCleanup"}
]
```

Plus a single cron entry: `* * * * * php ikabud schedule:run` that checks the manifest and dispatches due tasks.

---

## 3. Observability & Diagnostics

### 3.1 No Structured Logging

**Current state:** `write_log()` writes free-text lines to `app.log`. Context is passed as an array but serialized inline. No JSON-structured log format. No log levels routed to different destinations.

**Impact:** Log aggregation tools (CloudWatch, Datadog, ELK) can't parse the logs. Correlation requires manual grep with request ID.

**Recommendation:** Add a `LOG_FORMAT=json` env flag. When enabled, `write_log()` emits one JSON object per line: `{"timestamp": "...", "level": "...", "message": "...", "request_id": "...", "tenant_id": ..., "context": {...}}`. Keep plaintext as default for dev.

### 3.2 No Slow-Listener Detection

**Current state:** EventBus fires listeners synchronously. If a listener takes 500ms, there's no warning. If an integration bridge call takes 3s, no alert.

**Recommendation:** Wrap each listener call with `microtime(true)` measurement. If elapsed > configurable threshold (default 200ms), log a warning with event name, listener index, and duration.

### 3.3 No Health Check Endpoint

**Current state:** No `/health` or `/readiness` endpoint. Container orchestrators (Docker, K8s) and load balancers have no way to check if the app is alive.

**Recommendation:** Add `GET /health` in `public/index.php` (before module routing) that returns:
```json
{"status": "ok", "db": "connected", "cache": "available", "tenant": "resolved", "version": "..."}
```

### 3.4 No Request Timing Middleware

**Current state:** No per-request timing logged. No slow-request detection.

**Recommendation:** At the top of `public/index.php`, record `$_SERVER['REQUEST_TIME_FLOAT']`. At shutdown, calculate total duration. If > 1s, log a warning with route, method, and duration.

---

## 4. Security Hardening (Beyond CSP)

### 4.1 No Rate Limiting on Critical API Endpoints

**Current state:** Login has rate limiting (`module_login_rate_limit_test` confirms). Checkout has 3/5min limit. But other critical endpoints are unprotected:
- Password reset (`POST /cms/forgot-password`) — no rate limit
- Coupon validation API — no rate limit (brute-force coupon codes)
- Public booking form — rate limited by anti-spam, but anti-spam may be disabled
- License download (`GET /ecommerce/download/{token}`) — no rate limit

**Recommendation:** Add a generic rate-limit middleware that modules can declare per-route:
```php
'POST /cms/forgot-password' => ['handler' => '...', 'rate_limit' => '5/hour']
```

### 4.2 No CSRF Protection on Webhook Endpoints

**Current state:** Webhook endpoints (`/api/v1/ecommerce/webhooks/stripe`, `/paymongo`, `/paypal`) correctly skip CSRF (they use signature verification instead). However, the skip logic should be explicit and auditable, not implicit from the route being API-prefixed.

**Recommendation:** Document which routes intentionally skip CSRF and why. Add a `csrf: false` route-level flag for clarity.

### 4.3 No API Key Authentication for Public APIs

**Current state:** Public API endpoints (`/api/v1/ecommerce/products`, `/api/v1/cms/content`) have no authentication. They're intentionally public, but there's no mechanism for rate limiting by API consumer (only by IP).

**Recommendation:** Add optional API key authentication for headless consumers. Store keys in `kernel_api_keys` table with rate limits per key. This is prerequisite for any B2B/headless use case.

### 4.4 Encryption Key Rotation Not Supported

**Current state:** `kernel/Crypto.php` uses a single static AES-256-GCM key. No key rotation, no key versioning.

**Risk:** If the key is compromised, all encrypted data (tenant DB passwords) must be re-encrypted manually.

**Recommendation:** Add a `key_version` field to encrypted payloads. Support decrypting with previous keys during rotation window. Provide `ikabud crypto:rotate` CLI command.

---

## 5. Developer Experience & Code Quality

### 5.1 No Static Analysis (PHPStan/Psalm)

**Current state:** Zero static analysis tools configured. `composer.json` has no `phpstan` or `psalm` dependency. No `phpstan.neon` or `psalm.xml` config.

**Impact:** Type errors, undefined variables, dead code, and unreachable branches are invisible until runtime. In a 40,000+ line codebase with 300+ functions, this is significant.

**Recommendation:** Add PHPStan at level 5 (not max — too disruptive initially):
```json
"require-dev": { "phpstan/phpstan": "^1.0" },
"scripts": { "analyse": "phpstan analyse kernel/ src/ modules/ --level=5" }
```

Add to CI. Fix baseline. Incrementally raise level.

### 5.2 No Code Formatting Standard

**Current state:** No PHP-CS-Fixer, no CodeSniffer, no `.editorconfig` for PHP style. Code style is roughly consistent but not enforced.

**Recommendation:** Add PHP-CS-Fixer with PSR-12 ruleset. Generate baseline. Add `composer lint:fix` script. Run in CI as a check (not auto-fix).

### 5.3 No Pre-Commit Hooks

**Current state:** Tests and lint only run in CI. Developers can push broken code that fails CI.

**Recommendation:** Add a simple `scripts/pre-commit` hook that runs `composer analyse` on changed files. Install via `scripts/setup-hooks.sh`.

### 5.4 Module Scaffolding Missing

**Current state:** `example-notes` module exists as a reference, but there's no `ikabud make:module` scaffolding command.

**Recommendation:** Add `ikabud make:module {name}` that generates: `module.json`, `helpers.php`, `routes.php`, `handlers.php`, `database/migrations/`, `tests/{name}_module_test.php`. Low effort, high reuse.

---

## 6. Test Infrastructure Maturation

### 6.1 No Code Coverage Collection

**Current state:** Tests run via subprocess (`proc_open`) per file. No coverage instrumentation. Untested code paths are invisible.

**Recommendation:** Add pcov/xdebug coverage mode. In CI, run with `XDEBUG_MODE=coverage` and aggregate results. Report coverage % in CI output. Target: 60% minimum for kernel, 50% for modules.

### 6.2 Missing Security Test Suite

**Current state:** `kernel_hardening_test.php` covers session/CSRF/headers. No SQL injection tests, no XSS tests, no authorization bypass tests, no privilege escalation tests.

**Recommendation:** Create `tests/security_penetration_test.php` covering:
- SQL injection via all form inputs (billing address, coupon code, search, product title)
- XSS via stored content (product descriptions, user names, email templates)
- CSRF bypass attempts on state-changing endpoints
- Horizontal privilege escalation (tenant A accessing tenant B data)
- Vertical privilege escalation (customer accessing admin endpoints)

### 6.3 Missing Module Test Coverage

| Module | Test Files | Priority |
|--------|-----------|----------|
| Daily Ledger | 0 | Medium |
| GUI Settings | 0 | Low |
| Media | 0 | Medium |
| Security | 0 | **High** |
| Users | Minimal | **High** |
| SMS | Minimal | Medium |
| Workflow (advanced) | Indirect only | Medium |

### 6.4 No Performance Baseline

**Current state:** Only `benchmark:disyl` exists. No request latency benchmarks, no checkout throughput tests, no concurrent user simulation.

**Recommendation:** Add `scripts/benchmark-routes.sh` that hits critical paths with `ab` or `wrk`:
- `GET /ecommerce/shop` (storefront listing)
- `POST /api/v1/ecommerce/checkout` (checkout throughput)
- `GET /api/v1/ecommerce/products` (API response time)
- `GET /` (homepage public render)

Record baseline. Alert on >20% regression in CI.

### 6.5 No Database Migration Rollback Tests

**Current state:** Migrations run forward only. No rollback functionality. No test that verifies migrations can be applied to a fresh database, and no test that verifies partial migration failure is handled.

**Recommendation:** Add `tests/migration_integrity_test.php` that:
- Applies all migrations to an empty DB
- Verifies all expected tables exist
- Verifies FK constraints are valid
- Tests idempotency (running migrations twice doesn't fail)

---

## 7. Ecommerce Operational Gaps

### 7.1 Subscription Renewal Engine (Critical Missing Feature)

**Current state:** `ecSubscriptionCreateForPaidOrder()` creates subscription records with `next_renewal_at`, `interval_unit`, `interval_count`. But there is **no renewal processing logic anywhere**. No cron job, no CLI command, no scheduled task.

**Impact:** Subscriptions are created but never renewed. Customers pay once and retain access forever (unless manually expired).

**Required implementation:**
1. `ecProcessDueSubscriptionRenewals()` — query subscriptions where `next_renewal_at <= NOW() AND status = 'active'`
2. For each: attempt to charge via stored payment method (requires saved payment method infrastructure)
3. On success: advance `next_renewal_at` by interval, record payment
4. On failure: enter grace period → retry → eventually suspend
5. Events: `ecommerce.subscription.renewed`, `ecommerce.subscription.renewal_failed`, `ecommerce.subscription.suspended`

### 7.2 No Saved Payment Methods

**Current state:** Checkout creates one-off payment intents. No customer payment method storage. Stripe Checkout Sessions don't persist the card for reuse.

**Impact:** Blocks subscription auto-renewal, blocks one-click reordering, blocks saved-card checkout UX.

**Recommendation:** For Stripe: use `setup_mode` sessions or `Customer` objects with `PaymentMethod` attachment. Store reference in `ec_customer_payment_methods` table.

### 7.3 Membership Expiry Not Automated

**Current state:** `ecMembershipsForCustomer()` checks `ends_at` at query time. But memberships with `ends_at` in the past are never cleaned up or transition-evented.

**Impact:** Content gating still works (runtime check), but admin views show expired memberships as active, and no `ecommerce.membership.expired` event fires for downstream integrations.

**Recommendation:** Add `ecMembershipExpiryCleanup()` sweeper that marks expired memberships as `status = 'expired'` and fires events.

### 7.4 Webhook Delivery Must Be Async

**Current state:** `ecOutboundWebhookDeliver()` makes synchronous HTTP calls during event processing. 5-second timeout per webhook. Multiple webhooks = cumulative delay on checkout.

**Impact:** If a customer has 3 registered webhooks and one is slow, checkout takes 15+ seconds.

**Recommendation:** Move webhook delivery to the job queue (§2.1). Dispatch job immediately, process async. Add retry with exponential backoff (30s, 2m, 15m, 1h, 4h).

### 7.5 No Order Editing After Creation

**Current state:** Orders can only have their status changed. No ability to add/remove items, change quantities, adjust addresses, or modify pricing after creation.

**Impact:** Common support scenario — customer orders wrong item or enters wrong address. Admin must cancel and recreate.

**Recommendation:** Add `ecOrderEditItem()`, `ecOrderAddItem()`, `ecOrderRemoveItem()`, `ecOrderUpdateAddress()` with proper stock adjustments, total recalculation, and audit trail.

### 7.6 No Customer Address Book

**Current state:** `ecCustomerAddresses()` queries `ec_customer_addresses` table if it exists. But there's no `ecCustomerAddressSave()`, no address management UI, and no checkout pre-fill from saved addresses.

**Impact:** Returning customers must re-enter their address every time.

**Recommendation:** Add CRUD for saved addresses. Pre-fill billing/shipping on checkout for authenticated customers. Low effort, high UX value.

### 7.7 Reports Need Caching and Timezone Support

**Current state:** `ecReportSales()` runs aggregate SQL on every request. All dates in DB time (UTC). No user-facing timezone conversion. Top products hardcoded to LIMIT 10.

**Recommendation:**
- Cache report results for 5 minutes (tag-invalidated on new order)
- Accept `timezone` param, apply UTC offset in SQL GROUP BY
- Make top-N configurable

### 7.8 POS Is Skeletal

**Current state:** 2 functions: product search and quick sale. No cashier sessions, no till management, no receipt printing, no tender type tracking, no barcode scanning.

**Impact:** POS is enabled via feature flag but is barely functional beyond "quick web order."

**Recommendation:** Defer POS expansion to a dedicated phase. It needs hardware integration (receipt printer, barcode scanner, card reader) which is fundamentally different from web-first commerce.

### 7.9 Returns Don't Auto-Link to Refunds

**Current state:** Return requests and refunds are separate workflows. Approving a return does not create a refund. Admin must separately issue a refund.

**Recommendation:** Add option on return approval: "Issue refund of $X for returned items." Calls `ecOrderCreateRefund()` automatically. Optional — admin can still decline refund on approved return.

---

## 8. CMS & Page Builder Gaps

### 8.1 No Builder Schema Versioning

**Current state:** Builder documents are JSON blobs with widget nodes. Widget props evolve over time but there's no `schemaVersion` field. No migration path when a widget's prop structure changes.

**Risk:** Old documents with deprecated widget props may render incorrectly after a code update.

**Recommendation:** Add `"schemaVersion": 1` to root of builder documents. When rendering, check version and apply prop transformers (e.g., `v1→v2: rename "bgColor" to "backgroundColor"`).

### 8.2 No Builder E2E Tests

**Current state:** CMS has 15+ tests but none cover the full builder lifecycle: create document → add widgets → save → publish → public render → verify HTML output.

**Recommendation:** Add `tests/cms_builder_lifecycle_test.php` covering:
- Create builder document via API
- Insert heading + text + image nodes
- Save and publish
- Render public page
- Assert HTML contains expected content

### 8.3 Large Monolithic Files

| File | Lines | Concern |
|------|-------|---------|
| `handlers/90-public.php` | ~2000 | Public rendering, context, 50+ functions |
| `helpers/40-theme-settings.php` | ~1400 | Theme discovery, activation, resolution, sidebar |
| `helpers/78-public-context.php` | ~800 | Context assembly, customizer, entity exposure |

**Recommendation:** Split on natural boundaries:
- `90-public.php` → `90-public-render.php` + `91-public-entity.php` + `92-public-search.php`
- `40-theme-settings.php` → `40-theme-discovery.php` + `41-theme-activation.php` + `42-theme-resolution.php`

### 8.4 No Content Revision Diffing

**Current state:** Builder has `cms_builder_revisions` table with full document snapshots. But there's no diff view — admin can see revision list and restore, but not compare what changed.

**Recommendation:** Add a lightweight JSON-diff utility. Display changed nodes in the revision comparison UI.

### 8.5 No Multi-Language / i18n Support

**Current state:** No translation infrastructure. Content is single-language. Templates have no i18n string extraction. Admin UI is English-only.

**Impact:** Blocks international deployment. This is a significant product limitation.

**Recommendation:** This is a major feature, not a quick fix. Plan as a dedicated phase:
1. Add `locale` column to `cms_content`
2. Add translation relationship table
3. Add language selector in admin
4. Add template string extraction for admin UI

---

## 9. Kernel Infrastructure Gaps

### 9.1 No Circuit Breaker Pattern

**Current state:** `CapabilityBus` has circuit-breaker metrics storage but it's unclear if the breaker actually trips (state tracking is in JSON, not runtime enforcement). Integration Bridge has no circuit breaker.

**Recommendation:** Implement a proper 3-state circuit breaker (closed → open → half-open) with configurable thresholds: 5 failures in 60s = open for 30s, then half-open with 1 test call.

### 9.2 No Request-Scoped Transaction Manager

**Current state:** Individual functions manage their own transactions (e.g., `ecOrderCreate` wraps in try/begin/commit). There's no request-level transaction coordinator for multi-module operations.

**Impact:** If an order fires an event that triggers a capability that writes to another module's table and that fails, the order is committed but the capability side-effect is lost.

**Recommendation:** Add optional request-level transaction support: `app()->transaction(fn() => { ... })` that wraps the callback in a single transaction. Not mandatory for all routes — opt-in for critical flows.

### 9.3 Workflow Runtime Lacks Transition Guards

**Current state:** Workflow transitions check role permissions but have no custom guard callbacks (e.g., "can only publish if all required fields are filled").

**Recommendation:** Add `guards` key to transition config:
```json
"transitions": {
  "publish": {
    "from": "approved", "to": "published",
    "roles": ["editor", "admin"],
    "guards": ["cms:contentHasRequiredFields"]
  }
}
```

### 9.4 DiSyL Template Engine Not Fully Audited

**Current state:** The template engine is at v4.0 with advanced features (extends, blocks, includes, control structures, arithmetic, ternary, filters). The Compiler, Grammar, Component, and Hydration subsystems were not fully examined in this audit.

**Risk:** Template injection, infinite recursion in includes, or Compiler bugs could be lurking. DiSyL is the most complex single subsystem.

**Recommendation:** Dedicated DiSyL security audit: template injection vectors, recursion depth limits, memory limits on compilation, and fuzz testing with malformed templates.

### 9.5 No Module Dependency Graph CLI

**Current state:** Module dependencies are validated at load time but there's no visualization tool. The `ikabud-roadmap.md` lists "Module Graph and Dependency Intelligence" as a planned phase but it's not implemented.

**Recommendation:** Implement `ikabud module:graph` that outputs a DOT-format dependency graph. Useful for impact analysis before changes.

---

## 10. Multi-Tenant & Multi-Store Gaps

### 10.1 No Tenant Data Isolation Adversarial Tests

**Current state:** `tenant_chaos_test.php` exists but focuses on connection failures, not data leakage. There's no test that verifies tenant A cannot access tenant B's data through any code path.

**Recommendation:** Add `tests/tenant_isolation_adversarial_test.php`:
- Create order as tenant A, switch to tenant B, attempt to read it
- Create user in tenant A, attempt login from tenant B
- Upload media as tenant A, attempt access from tenant B
- Verify module settings isolation between tenants

### 10.2 Multi-Store Marketplace Phases Incomplete

**Current state per multistore-roadmap.md:** Phases A-G (sidebar, settings, role differentiation, notifications, messaging) are complete. Phases 1-7 (product-store assignment, unified storefront facets, dedicated store pages, store-owner scoped admin, order item attribution, store customization, WMS per-store routing) are still outstanding.

**Impact:** Multi-store is functional as admin infrastructure but the customer-facing marketplace experience doesn't exist yet.

**Priority:** Phase 1 (product-store assignment) and Phase 5 (order item attribution) are prerequisites for accurate store-level reporting. These should come before store customization (Phase 6).

### 10.3 No Tenant Provisioning API

**Current state:** Tenant creation requires direct DB insertion into the control plane. No self-service provisioning, no API endpoint for creating tenants programmatically.

**Recommendation:** Add `POST /api/v1/superadmin/tenants` with: domain, admin email, plan tier. Runs migrations, creates DB, registers in control plane. Prerequisite for SaaS self-service.

---

## 11. Documentation Gaps

### 11.1 No API Documentation (OpenAPI/Swagger)

**Current state:** `docs/kernel/api-reference.md` exists but is a prose document, not an OpenAPI spec. No Swagger UI. No machine-readable API contract.

**Recommendation:** Generate OpenAPI 3.0 spec from route maps. Serve Swagger UI at `/api/docs` for admin users.

### 11.2 No Widget Contract Documentation

**Current state:** 48 builder widget types exist. No formal documentation of each widget's:
- Accepted props and their types
- Default values
- Rendering behavior (server vs client)
- Structured data output

**Recommendation:** Generate `docs/page-builder/widget-contracts/` directory with one file per widget type, auto-generated from the widget definition code.

### 11.3 No Module Development Tutorial

**Current state:** `docs/kernel/module-development-guide.md` and `module-quickstart.md` exist. But they don't cover: how to test your module, how to add admin UI, how to integrate with the page builder, how to add scheduled tasks, or how to publish.

**Recommendation:** Write a comprehensive "Build Your First Module" tutorial covering the full lifecycle.

### 11.4 No Deployment Guide

**Current state:** `docs/kernel/installation.md` covers local setup. No production deployment guide covering: server requirements, database sizing, cache configuration, SSL setup, cron configuration, log rotation, backup strategy, or monitoring setup.

**Recommendation:** Write `docs/kernel/deployment-guide.md` immediately. This is table-stakes for anyone deploying in production.

---

## 12. Execution Plan

Organized by priority tier. Each item includes estimated complexity (S/M/L/XL) and the files/areas affected.

### Tier 0 — Production Safety (Do First)

| # | Item | Complexity | Area |
|---|------|-----------|------|
| 0.1 | Idempotency keys for webhook events | S | New table + gateway handlers |
| 0.2 | Atomic stock decrement (single UPDATE with check) | S | `20-orders.php` |
| 0.3 | Refund amount cap validation | S | `20-orders.php` |
| 0.4 | Coupon validation on cart recovery | S | `59-abandoned-carts.php` |
| 0.5 | Rate limiting on password reset + coupon validation | S | `public/index.php` route config |
| 0.6 | Health check endpoint | S | `public/index.php` |
| 0.7 | Request timing + slow-request logging | S | `public/index.php` |
| 0.8 | FK constraints on ecommerce tables | M | New migration |

### Tier 1 — Operational Foundation (This Sprint)

| # | Item | Complexity | Area |
|---|------|-----------|------|
| 1.1 | DB-backed job queue (kernel_jobs table + dispatch + worker) | L | New kernel subsystem |
| 1.2 | Async webhook delivery via job queue | M | `58-outbound-webhooks.php` |
| 1.3 | Scheduled task manifest + runner | M | Module system + CLI |
| 1.4 | Subscription renewal engine | L | `85-subscriptions.php` + new cron handler |
| 1.5 | Membership expiry sweep | S | `86-memberships-loyalty.php` + cron |
| 1.6 | Structured JSON logging (opt-in) | M | `bootstrap.php`, `write_log()` |
| 1.7 | Slow-listener detection in EventBus | S | `kernel/EventBus.php` |
| 1.8 | Deployment guide documentation | M | New doc |

### Tier 2 — Developer Experience (Next Sprint)

| # | Item | Complexity | Area |
|---|------|-----------|------|
| 2.1 | PHPStan level 5 + CI integration | M | `composer.json`, new config |
| 2.2 | PHP-CS-Fixer + PSR-12 baseline | M | `composer.json`, new config |
| 2.3 | Code coverage collection in CI | M | CI config + pcov |
| 2.4 | Security penetration test suite | L | New test file |
| 2.5 | Tenant data isolation adversarial test | M | New test file |
| 2.6 | Migration integrity test | M | New test file |
| 2.7 | Module scaffolding CLI (`ikabud make:module`) | M | CLI enhancement |
| 2.8 | Performance baseline benchmarks | M | New script |

### Tier 3 — Feature Completeness (Planned Sprints)

| # | Item | Complexity | Area |
|---|------|-----------|------|
| 3.1 | Saved payment methods (Stripe Customer) | L | New gateway + table |
| 3.2 | Customer address book + checkout pre-fill | M | New CRUD + checkout change |
| 3.3 | Order editing (add/remove items post-creation) | L | `20-orders.php` + handlers |
| 3.4 | Return → refund auto-link | M | `22-returns.php` |
| 3.5 | Builder schema versioning | M | Builder docs + render pipeline |
| 3.6 | Builder E2E lifecycle test | M | New test file |
| 3.7 | Report caching + timezone support | M | `50-reports.php` |
| 3.8 | API key authentication for headless | M | New kernel subsystem |
| 3.9 | Encryption key rotation support | M | `kernel/Crypto.php` + CLI |
| 3.10 | Circuit breaker (kernel capabilities) | M | `CapabilityBus.php` |

### Tier 4 — Strategic Evolution (Quarterly Plan)

| # | Item | Complexity | Area |
|---|------|-----------|------|
| 4.1 | Multi-store phase 1-5 (marketplace) | XL | Ecommerce module |
| 4.2 | Multi-language / i18n infrastructure | XL | CMS + kernel + templates |
| 4.3 | OpenAPI spec generation + Swagger UI | L | New tooling |
| 4.4 | Tenant self-service provisioning API | L | Control plane |
| 4.5 | Module dependency graph visualization | M | CLI + tooling |
| 4.6 | DiSyL security audit + fuzz testing | L | DiSyL subsystem |
| 4.7 | Workflow transition guards | M | `WorkflowRuntime.php` |
| 4.8 | Content revision diffing | M | Builder UI + backend |
| 4.9 | POS expansion (if needed) | XL | Ecommerce POS subsystem |

---

## Appendix A — Module Test Coverage Matrix

| Module | Test Files | Status |
|--------|-----------|--------|
| CMS | 15+ | ✅ Extensive |
| Ecommerce | 35+ | ✅ Extensive |
| Guidance | 8+ | ✅ Good |
| Contact Form | 3+ | ✅ Basic |
| WMS | 5+ | ✅ Basic |
| Kernel | 8+ | ✅ Solid |
| WordPress Bridge | 4+ | ✅ Good |
| Integration | 10+ | ✅ Good |
| Infrastructure | 8+ | ✅ Good |
| Anti-Spam | 1 | ⚠️ Minimal |
| AI | 1 | ⚠️ Minimal |
| Search | 1 | ⚠️ Minimal |
| Daily Ledger | 0 | ❌ Missing |
| GUI Settings | 0 | ❌ Missing |
| Media | 0 | ❌ Missing |
| Security | 0 | ❌ Missing |
| Users | Minimal | ⚠️ Needs expansion |

## Appendix B — Ecommerce Function Audit Summary

| Helper File | Functions | Lines | Status | Key Gap |
|------------|----------|-------|--------|---------|
| 20-orders.php | 64 | 2500 | ✅ | Stock atomicity, refund cap |
| 70-payment-gateways.php | 12 | 470 | ✅ | Webhook dedup |
| 72-gateway-stripe.php | 5 | 140 | ✅ | — |
| 73-gateway-paypal.php | 7 | 250 | ✅ | — |
| 71-gateway-paymongo.php | 6 | 190 | ✅ | — |
| 85-subscriptions.php | 23 | 400 | ✅ | **No renewal engine** |
| 86-memberships-loyalty.php | 47 | 650 | ✅ | No expiry sweep |
| 59-abandoned-carts.php | 31 | 550 | ✅ | Coupon expiry on restore |
| 65-customers.php | 6 | 200 | ✅ | No address book |
| 66-import-export.php | 28 | 850 | ✅ | Concurrent import race |
| 50-reports.php | 4 | 200 | ✅ | No cache, no timezone |
| 22-returns.php | 20 | 650 | ✅ | No auto-refund link |
| 58-outbound-webhooks.php | 15 | 380 | ✅ | **Sync delivery blocks checkout** |
| 60-pos.php | 2 | 110 | ⚠️ | Skeletal |

## Appendix C — Capability Registry Snapshot

**Kernel Capabilities (8):** `kernel.auth.user@1`, `kernel.auth.require@1`, `kernel.auth.authenticate@1`, `kernel.http.request_context@1`, `kernel.audit.record@1`, `kernel.render.context@1`, `workflow.state.get@1`, `workflow.transition@1`

**CMS Capabilities (18):** Content CRUD, media, builder, settings, themes, entity context (5 variants)

**Ecommerce Capabilities (8):** Cart, products, orders, membership content gate

**WMS Capabilities (10):** Stock, replenishment, product, order, return lifecycle

**Total registered capabilities:** ~44 across 21 modules

---

*End of evaluation. All findings are based on direct code inspection, not documentation claims.*
