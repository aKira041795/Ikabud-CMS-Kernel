# DC Cafe — Standalone POS, Inventory & Sales System Analysis

**Date**: 2026-07-25  
**Source system**: `/var/www/bakeshopapp` (Julies Bakeshop — CodeIgniter 4)  
**Reference lines**: ~11,200 lines of DC-specific PHP across 15 files

---

## 1. Current State Summary

### 1.1 What DC Cafe Is Today

DC Cafe is a **cafe-in-a-bakery** — three physical locations operating inside/alongside Julies Bakeshop branches. Its POS runs as an integrated module within the Julies Bakeshop CodeIgniter 4 monolith, sharing a single `jbakeshop_live` database with bakery operations (baking production, deliveries, attendance, payroll).

### 1.2 Store Footprint

| Store | Type | Transactions | Revenue | Status |
|-------|------|-------------|---------|--------|
| **DC Blu** (store_id=39) | branch | 3,705 | ₱570,829.80 | Active — 96% of volume |
| DC Main (store_id=32) | branch | 78 | ₱22,656.00 | Dormant |
| DC City Mall (store_id=35) | outlet | 1 | ₱55.00 | Inactive |

**Effective reality**: DC Cafe = DC Blu. One active location generating ~₱4,900/day over 122 active days (May–Oct 2025).

### 1.3 Technology Stack

- **Framework**: CodeIgniter 4 (PHP 7.4+/8.x)
- **Database**: MySQL 5.7 (Bluehost shared hosting) — `jbakeshop_live`
- **Frontend**: Server-rendered Bootstrap 5 views, jQuery, vanilla JS
- **QR Codes**: phpqrcode library, PNG generation
- **No**: REST API, SPA, offline support, mobile app, WebSocket/push

### 1.4 Code Inventory

| Layer | Files | Lines | Notes |
|-------|-------|-------|-------|
| Controllers | 5 | ~8,800 | `DCMainPOSController` alone is 6,205 lines |
| Models | 10 | ~2,400 | Mirror bakery POS models with `dcmain_` prefix |
| Views | 18 | ~3,500 | Bootstrap 5 templates under `Views/dcmain/` |
| Routes | 1 | ~35 routes | Mixed auth/public, nested groups |
| Migrations | 3 | ~150 | Schema additions, not initial CREATE |
| **Total** | **~37** | **~14,900** | DC-specific only, excluding shared bakery code |

---

## 2. Database Schema — 10 DC Tables

### 2.1 Core Transaction Tables

```
dcmain_cashier_sessions (191 rows)
├── session_id, user_id, store_id
├── starting_cash, ending_cash
├── shift_type ENUM(morning,afternoon,night)
├── shift_start, shift_end
├── status ENUM(active,closed)
└── is_late_report, is_cash_confirmed

dcmain_transactions (3,784 rows)
├── transaction_id VARCHAR(50) PK — format: DCM{store}{YYYYMMDD}{seq}
├── session_id, store_id, cashier_id FK
├── total_amount, discount_amount, discount_reason, original_amount
├── payment_method VARCHAR(50) — string, not enum/normalized
└── transaction_date, created_at

dcmain_sales (6,756 rows)
├── sale_id PK
├── transaction_id FK, store_id FK, product_id FK
├── quantity, unit_price, total_price
├── sale_date, cashier_id FK, session_id
└── customization_data TEXT — JSON blob for soft-serve config
```

### 2.2 Inventory Table

```
dcmain_inventory (21,905 rows)
├── id PK, session_id FK, product_id
├── beginning_qty, production_qty, pullout_qty
├── ending_qty, sold_qty
├── unit_price, total_sales
└── component_usage TEXT — JSON blob
```

### 2.3 QR Ordering Tables (barely used — 3 orders)

```
dcmain_tables (2 rows)          — DC1, DC2
dcmain_qr_orders (3 rows)       — 7-status workflow
dcmain_qr_order_items (3 rows)  — line items
dcmain_qr_order_status_history (16 rows) — audit trail
```

### 2.4 Ingredient/BOM Tables (empty — 0 rows)

```
dcmain_ingredients              — ingredient master (name, unit, cost, stock, reorder)
dcmain_product_ingredients      — product-to-ingredient BOM (quantity per recipe)
```

### 2.5 Key Schema Issues

1. **Payment methods are strings**: "cash", "GCash", "g-cash", "g cash" matched with ad-hoc `LIKE` patterns in reporting queries — no referential integrity
2. **No customer table**: No customer accounts, no order history per customer, no loyalty
3. **No supplier/purchase order tables**: Ingredient stock has no inbound tracking
4. **Customization as JSON blob**: Soft-serve configs stored as `TEXT` JSON — not queryable
5. **All tables share bakery DB**: No data isolation, no separate backup/restore granularity

---

## 3. Feature Inventory — What Exists

### 3.1 POS Terminal (`DCMainPOSController` — 25 methods)

| Feature | Status | Notes |
|---------|--------|-------|
| Shift-based cashier sessions | ✅ Complete | Morning/afternoon/night, start/end cash |
| Product catalog per store | ✅ Complete | Via `getProductsForDCMain()` |
| Multi-payment transactions | ⚠️ Partial | String-based, no payment method table FK |
| Discount support | ✅ Complete | Amount + reason, original amount tracked |
| End-of-session reconciliation | ✅ Complete | Cash count vs system sales |
| Session inventory tracking | ✅ Complete | Beginning→production→sold→pullout→ending |
| Inventory progress save/resume | ✅ Complete | `saveInventoryProgress()` |
| Soft-serve customization | ✅ Complete | Bases, sauces, toppings, addons, variable pricing |
| Variable product components | ✅ Complete | Component usage deduction on sale |
| Voucher validation | ✅ Complete | Via `VoucherModel` |
| Sales reports | ✅ Complete | Date range, per-store, CSV export |
| Transaction details view | ✅ Complete | Line items per transaction |
| Today's sales data (AJAX) | ✅ Complete | Real-time dashboard refresh |

### 3.2 QR Ordering System (`DCMainQRController` — 20 methods)

| Feature | Status | Notes |
|---------|--------|-------|
| Table management (CRUD) | ✅ Complete | Add/edit/delete/activate/deactivate |
| QR code generation per table | ✅ Complete | phpqrcode, PNG output |
| QR code printing | ✅ Complete | Print-friendly layout |
| Customer ordering page (public) | ✅ Complete | No auth required, per-table URL |
| Order status workflow | ✅ Complete | pending→confirmed→preparing→serving→completed |
| Cashier order dashboard | ✅ Complete | 4-tab: Pending, Confirmed, Preparing, Serving |
| Kitchen display | ✅ Complete | Real-time order board |
| Transfer QR order → POS | ✅ Complete | `transferToPOS()` |
| Order status history | ✅ Complete | Full audit trail |

**Reality**: QR ordering was built but never operationally adopted — only 3 test orders exist.

### 3.3 Reporting (`DCReportingController`, `DCSessionController`)

| Feature | Status | Notes |
|---------|--------|-------|
| DC session listing | ✅ Complete | Separate from bakery sessions |
| Payment breakdown by method | ✅ Complete | Dynamic from active payment methods |
| Sales aggregation per session | ✅ Complete | Joins across sessions/transactions/sales |

---

## 4. Gap Analysis — What's Missing for Standalone

### 4.1 Critical (Blockers for Standalone Operation)

| Gap | Impact | Effort |
|-----|--------|--------|
| **No ingredient/inventory management** | Cannot track stock, reorder, or cost | High |
| **No purchase order / supplier system** | No inbound stock tracking | High |
| **No cost-of-goods-sold (COGS)** | Cannot calculate profitability per item | Medium |
| **No customer system** | No accounts, history, loyalty, CRM | High |
| **No API layer** | No mobile app, no 3rd-party integrations | High |
| **Shared bakery database** | No data isolation, backup, or migration path | Medium |
| **No offline support** | Internet-dependent; no fallback | High |

### 4.2 High-Value (Differentiators)

| Gap | Impact | Effort |
|-----|--------|--------|
| Real-time analytics dashboard | Replace CSV exports with visual insights | Medium |
| Multi-store centralized management | Single menu/inventory across locations | High |
| Kitchen Display System (KDS) v2 | Replace browser-based KDS with dedicated view | Low |
| Digital menu board integration | Customer-facing display auto-updates | Low |
| Online ordering (pickup/delivery) | Expand beyond QR table ordering | High |
| Loyalty/rewards program | Points, stamps, member pricing | Medium |
| Employee scheduling (cafe-specific) | Shift management integrated with POS sessions | Medium |

### 4.3 Technical Debt (Must Address)

| Issue | Severity | Effort |
|-------|----------|--------|
| Payment method normalization | Medium | Low |
| `customization_data` JSON → structured columns | Medium | Medium |
| 6,205-line monolithic `DCMainPOSController` | High | Medium |
| Code duplication with bakery POS | Medium | Medium |
| No automated tests | High | Ongoing |
| No CI/CD pipeline | Medium | Low |
| MySQL 5.7 constraints (no window functions, no CTEs) | Low | N/A (upgrade path exists) |

---

## 5. Migration Path Considerations

### 5.1 Platform Options

| Option | Pros | Cons |
|--------|------|------|
| **A. Port to Ikabud/Kernel OS** | Shared infrastructure, entity views, capability bus, multi-tenant, existing modules (attendance, payroll, daily ledger) | Requires full rewrite; DiSyL template learning curve; mixing cafe + bakery concerns |
| **B. Refactor within CodeIgniter 4** | Fastest path, existing code works, team familiarity | CI4 is aging; no multi-tenant; no entity view system |
| **C. New Laravel/Vue SPA** | Modern stack, rich ecosystem, API-first | Full rewrite; new hosting requirements; team retraining |
| **D. Hybrid — API layer on CI4 + new frontend** | Preserves working backend; modern UI possible; incremental | CI4 API limitations; two codebases to maintain |

### 5.2 Recommendation: Option D (Hybrid — extract and enhance the existing CI4 codebase)

**Phase 1 — Standalone (Month 1)**: Extract DC schema and application code to an independent database and environment. Normalize payment methods. Zero disruption to cashier workflow.

**Phase 2 — Inventory & Costing (Month 2)**: Activate the dormant ingredient/BOM tables with full stock tracking, purchase orders, recipe costing, and margin reporting.

**Phase 3 — Customer Loyalty (Month 3)**: Customer profiles, purchase history, points-based rewards, and member pricing.

**Phase 4 — Dashboard (Month 4)**: Visual analytics — daily sales, product performance, inventory valuation, staff productivity, PDF/Excel exports.

**Phase 5 — Multi-Store + Launch (Month 5)**: Centralized menu management, per-store settings, consolidated reporting, testing, training, go-live.

**Optional Expansion — Online & QR Ordering**: Mobile-friendly customer ordering, table QR codes, kitchen display, GCash payment. This can be added during the main project (~3 extra weeks) or introduced later based on demand and budget.

**Long-term (future)**: Evaluate Kernel OS port once the system is stabilized and requirements are fully validated.

---

## 6. Data Volume & Growth

| Metric | Current | 12-month projection |
|--------|---------|---------------------|
| Daily transactions | ~31/day | ~50/day (with online ordering) |
| Monthly revenue | ~₱120K | ~₱200K |
| Active products | ~165 (per session) | ~200 |
| Database size (DC tables) | ~15MB | ~30MB |
| Peak concurrent users | 3–5 (cashiers) | 5–8 |

**Scale is small** — a Raspberry Pi could run this. No sharding, no complex caching, no message queues needed.

---

## 7. Key Architectural Decisions Needed

1. **Single-tenant or multi-tenant?** Currently 3 stores share one DB. For standalone, do we want one DB per cafe franchisee or one multi-store DB?
2. **Menu management**: Centralized (one menu, pushed to stores) or per-store (each store has own menu)?
3. **Inventory method**: Perpetual (real-time deduction on sale) or periodic (count-based, like current session model)?
4. **Offline strategy**: PWA with Service Worker cache, or native Android app (Daily Ledger pattern), or both?
5. **Payment integration**: Keep cash + manual entry, or integrate with payment terminals (Maya, GCash API, card terminals)?
6. **Receipt printing**: Thermal printer support (ESC/POS), or email/SMS receipts, or both?

---

## 8. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Data migration corruption | Medium | High | Dry-run migrations, checksum verification |
| Bakery POS co-dependency breakage | High | High | Run both systems in parallel during cutover |
| Bluehost resource limits | Medium | Medium | Separate DB, query optimization, caching |
| QR ordering adoption failure (repeat) | Medium | Low | Validate with real customers before building |
| Scope creep into full ERP | High | Medium | Strict phase gating, MVP-first approach |
| Team unfamiliarity with new stack | Medium | Medium | Start with CI4 API layer (known stack) |

---

## Appendix A: File Reference

### Controllers
- `/var/www/bakeshopapp/app/Controllers/DCMainController.php` (150 lines)
- `/var/www/bakeshopapp/app/Controllers/DCMainPOSController.php` (6,205 lines)
- `/var/www/bakeshopapp/app/Controllers/DCMainQRController.php` (1,594 lines)
- `/var/www/bakeshopapp/app/Controllers/Admin/DCReportingController.php` (238 lines)
- `/var/www/bakeshopapp/app/Controllers/Admin/DCSessionController.php` (605 lines)

### Models
- `/var/www/bakeshopapp/app/Models/DCMainSaleModel.php` (614 lines)
- `/var/www/bakeshopapp/app/Models/DCMainTransactionModel.php` (454 lines)
- `/var/www/bakeshopapp/app/Models/DCMainSessionModel.php` (142 lines)
- `/var/www/bakeshopapp/app/Models/DCMainInventoryModel.php` (58 lines)
- `/var/www/bakeshopapp/app/Models/DCMainIngredientModel.php` (111 lines)
- `/var/www/bakeshopapp/app/Models/DCMainProductIngredientModel.php` (116 lines)
- `/var/www/bakeshopapp/app/Models/DCMainQROrderModel.php` (373 lines)
- `/var/www/bakeshopapp/app/Models/DCMainQROrderItemModel.php` (173 lines)
- `/var/www/bakeshopapp/app/Models/DCMainQROrderStatusHistoryModel.php` (62 lines)
- `/var/www/bakeshopapp/app/Models/DCMainTableModel.php` (281 lines)

### Views (18 files under `app/Views/dcmain/`)
- `dashboard.php`, `layouts/dcmain.php`
- `pos/`: `index.php`, `session_start.php`, `end_session.php`, `transactions.php`, `transaction_details.php`, `sales_report.php`, `inventory.php`
- `qr/`: `index.php`, `manage_tables.php`, `display_qr.php`, `print_qr_codes.php`, `order.php`, `cashier_orders.php`, `kitchen_display.php`, `view_order.php`, `notification_fix.js`, `search_fix.php`

### Documentation
- `/var/www/bakeshopapp/dc_main_qr_implementation_guide.md`
- `/var/www/bakeshopapp/dcmain_qr_improvements.md`

## Appendix B: Database Connection

```
Host: localhost (Bluehost shared)
Database: jbakeshop_live
User: bakeshop_admin
Engine: InnoDB, utf8mb4
MySQL: 5.7 (Compatibility profile)
```
