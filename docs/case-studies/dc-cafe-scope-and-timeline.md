# DC Cafe Standalone POS & Inventory — Scope of Work & Timeline

**Based on**: [DC Cafe Standalone Analysis](./dc-cafe-standalone-analysis.md)  
**Date**: 2026-07-25  
**Audience**: Client-facing roadmap with technical detail for implementation planning

---

## Executive Summary

DC Cafe currently operates through Julies Bakeshop's system. While the existing POS handles daily sales effectively — one active location (DC Blu) generating ~₱120K/month — the cafe's data, reports, and operations remain tied to the bakery database.

This is **not a complete replacement of a working POS**. It is a controlled upgrade that keeps the familiar cashier experience while giving DC Cafe independence, inventory control, reporting, and growth capability.

**Total estimated effort**: ~5 months (1 developer) or 3–3.5 months (2 developers).

---

## Phase 1 — Standalone System (Month 1)

**Goal**: Move DC Cafe's sales, products, users, and reports into a separate database and application environment. Zero disruption to the cashier workflow.

**Result**: DC Cafe can operate independently without bakery-system issues affecting cafe operations.

### Week 1–2: Database Separation

| Task | Days | Deliverable |
|------|------|-------------|
| Provision standalone database | 0.5 | Isolated DB — no bakery tables |
| Extract DC schema into clean, versioned migrations | 2 | `001_create_dc_core_tables.sql` through `00N` |
| Write data migration script with checksum verification | 1.5 | CLI tool that copies DC data, verifies row counts |
| Dry-run migration against production copy | 1 | Migration report — all counts must match |
| Normalize payment methods (currently ad-hoc strings) | 0.5 | `payment_methods` table with proper foreign keys |

### Week 3–4: Application Separation

| Task | Days | Deliverable |
|------|------|-------------|
| Extract DC routes, controllers, models to standalone app | 2 | Independent codebase — no bakery dependencies |
| Point existing views at new database | 1 | Cashier sees no difference |
| Auth and session isolation | 1 | DC logins don't touch bakery user tables |
| End-to-end smoke test: start shift → sell → close shift | 1 | Every POS workflow verified |
| Deploy to staging environment | 0.5 | Accessible for UAT |

**Phase 1 total**: ~11 days

---

## Phase 2 — Inventory and Cost Control (Month 2)

**Goal**: Add ingredient tracking, stock receiving, purchase orders, low-stock alerts, recipe costing, and product margin monitoring.

**Result**: Management can see actual stock levels, product costs, wastage, and profit margins.

### Week 5–6: Ingredient Foundation

| Task | Days | Deliverable |
|------|------|-------------|
| Ingredient master (name, unit, cost, category) | 1.5 | Admin can add/edit ingredients |
| Map products to ingredients (recipe/BOM) | 2 | Each menu item linked to its ingredients + quantities |
| Real-time stock deduction on sale | 1 | Selling a Cuddly deducts soft serve base, sauces, toppings |
| Stock adjustment (count, waste, transfer) | 1.5 | Adjustments with reasons + audit trail |
| Low-stock alerts | 0.5 | Dashboard notification when ingredient falls below reorder level |

### Week 7–8: Purchasing & Costing

| Task | Days | Deliverable |
|------|------|-------------|
| Supplier management | 1 | Supplier directory with contact info |
| Purchase order system | 2.5 | Create PO → mark received → auto-update stock |
| Receive partial deliveries | 0.5 | PO can be received in multiple shipments |
| Cost-of-goods calculation (weighted average) | 1.5 | Accurate ingredient cost as prices change |
| Product margin report | 1 | Revenue minus COGS per menu item |
| Wastage tracking | 0.5 | Record and report discarded stock |

**Phase 2 total**: ~13 days

---

## Phase 3 — Customer Loyalty (Month 3)

**Goal**: Introduce customer profiles, purchase history, reward points, discounts, and member pricing.

**Result**: DC Cafe can encourage repeat purchases and build a reliable customer database.

### Week 9–10: Customer Profiles

| Task | Days | Deliverable |
|------|------|-------------|
| Customer table + API | 1 | Name, phone (primary lookup), email, join date |
| Quick customer lookup on POS | 1 | Cashier searches by phone number during checkout |
| Purchase history per customer | 1 | Viewable at POS and in admin |
| Customer-facing order status lookup | 1 | Customer checks their order by phone/order number |

### Week 11–12: Loyalty Program

| Task | Days | Deliverable |
|------|------|-------------|
| Points system (earn per peso spent) | 2 | Configurable earn rate (e.g., 1 point per ₱20) |
| Rewards redemption at POS | 1.5 | Cashier applies rewards during checkout |
| Member pricing / discount tiers | 1 | VIP customers get automatic discounts |
| Basic customer reports | 1 | Top customers, visit frequency, average spend |
| Export customer list | 0.5 | CSV export for marketing |

**Phase 3 total**: ~10 days

---

## Phase 4 — Management Dashboard (Month 4)

**Goal**: Provide clear reporting for daily sales, top-selling products, inventory movement, product margins, and staff performance.

**Result**: The owner can monitor business performance without relying on manual spreadsheets.

### Week 13–14: Core Dashboard

| Task | Days | Deliverable |
|------|------|-------------|
| Daily sales overview | 1.5 | Today's revenue, transaction count, average ticket — at a glance |
| Sales trends (daily/weekly/monthly) | 1.5 | Line and bar charts |
| Top-selling products (Pareto/80-20) | 1 | Which items drive revenue |
| Product margin dashboard | 1 | Most and least profitable items |
| Inventory valuation | 1 | Current stock value at cost |
| Low-stock and reorder summary | 0.5 | What needs ordering now |

### Week 15–16: Staff & Export Reports

| Task | Days | Deliverable |
|------|------|-------------|
| Staff performance (sales per shift) | 1 | Compare cashier productivity |
| Shift summary report | 1 | Per-session: sales, cash count, variances |
| Export to PDF | 1 | Formatted daily/weekly/monthly reports |
| Export to Excel/CSV | 1 | Raw data for accounting |
| Scheduled email reports | 1 | Auto-send daily summary to owner |

**Phase 4 total**: ~11.5 days

---

## Phase 5 — Multi-Store Readiness + Launch (Month 5)

**Goal**: Prepare the system for additional branches, centralized menu management, branch-level inventory, and consolidated reporting. Final testing, training, and go-live.

**Result**: Future locations can be added without rebuilding the system.

### Week 17–18: Multi-Store Foundation

| Task | Days | Deliverable |
|------|------|-------------|
| Centralized menu management | 2 | Define menu once, push to selected stores |
| Per-store settings | 1.5 | Tax rate, operating hours, receipt header per location |
| Branch-level inventory | 1.5 | Each store tracks its own stock |
| Cross-store inventory transfers | 1.5 | Transfer stock between branches with approval |
| Consolidated multi-store reporting | 1.5 | Owner sees all stores aggregated or filtered |
| User roles per store | 1 | Staff assigned to specific locations |

### Week 19–20: Testing, Training & Launch

| Task | Days | Deliverable |
|------|------|-------------|
| Full system test suite | 2 | Automated tests on critical paths (auth, sales, inventory) |
| Security review | 1 | Rate limiting, input validation, access control check |
| Data migration final dry-run | 1 | Production-copy migration with verification |
| Staff training session | 1 | Cashier quick guide + admin walkthrough |
| Go-live cutover | 0.5 | Switch to standalone system |
| Hypercare (1 week standby) | 1 | On-call support for any issues |

**Phase 5 total**: ~14 days

---

## Summary Timeline

```
          Month 1    Month 2    Month 3    Month 4    Month 5
Phase 1   ██████████
Phase 2              ██████████
Phase 3                         ██████████
Phase 4                                    ██████████
Phase 5                                               ██████████
          Standalone  Inventory  Loyalty   Dashboard  Multi-Store
          System      & Costing                      + Launch
```

### Month-by-Month Deliverables

| Month | Main Deliverable |
|-------|-----------------|
| **1** | Standalone DC Cafe system — independent from bakery |
| **2** | Inventory, purchasing, and product costing |
| **3** | Customer loyalty and management reports |
| **4** | Dashboard and multi-store readiness |
| **5** | Testing, staff training, and launch |

---

## Optional Expansion — Online and QR Ordering

Online pickup ordering, table QR ordering, kitchen display screens, automatic receipt printing, and GCash integration may be added as an optional expansion.

This feature can be implemented during the main project or introduced later, depending on budget, customer demand, and operational readiness.

### Scope if Included

| Feature | Days | Notes |
|---------|------|-------|
| Mobile-friendly customer ordering page | 4 | Product catalog, cart, checkout flow |
| QR code table ordering | 1 | Scan → menu → order from phone |
| Order status tracking for customers | 1 | "Received → Preparing → Ready" |
| Online payment (GCash) | 2 | Redirect to GCash, webhook confirmation |
| Kitchen Display System (KDS) | 2 | Dedicated screen: order queue, timers |
| Thermal printer integration | 1.5 | Auto-print kitchen tickets on new orders |
| Cashier order dashboard enhancements | 1.5 | Sound alerts, auto-refresh, order age coloring |
| **Total (if added to main project)** | **~13 days** | Adds ~3 weeks to timeline |

### When to Consider This Expansion

- Customer demand for table or online ordering is confirmed
- Staff is comfortable with the base system
- Budget allows for the additional ~3 weeks of development
- A thermal printer and kitchen display screen are available

---

## Resource Estimates

| Option | Duration | Notes |
|--------|----------|-------|
| **1 Developer** | ~5 months | Sequential delivery; lower cost, longer timeline |
| **2 Developers** | ~3–3.5 months | Phases 2+3 can partially overlap; Phase 4 frontend/backend can run parallel |
| **1 Developer + Contract UI** | ~4 months | Backend dev full-time; hire UI help for dashboard in Month 4 |

---

## What's NOT Changing

- The cashier POS workflow stays the same — start shift, ring up sales, end shift
- Soft-serve customization (bases, sauces, toppings, addons) continues to work
- Existing sales history is preserved and migrated
- User logins and permissions remain intact

---

## What's NOT in Scope (Current Project)

- Native mobile app (iOS/Android) — the system will be mobile-browser friendly
- Third-party delivery integration (GrabFood, FoodPanda)
- Table reservation or waitlist system
- Full accounting software integration (export to CSV/Excel instead)
- Franchise management portal
- AI-powered demand forecasting
- Self-service kiosk mode

---

## Key Decisions Required Before Starting

1. **Hosting**: Stay on Bluehost shared, or move to a VPS?
2. **Multi-store timing**: Are additional locations planned within 12 months? (Affects Phase 5 scope.)
3. **Online ordering**: Include as part of initial build, or defer as optional expansion?
4. **Online payments**: Integrate GCash from day one, or start with cash/card only?
5. **Owner mobile access**: Is a simple mobile dashboard for the owner valuable enough to prioritize?

---

## References

- [DC Cafe Standalone Technical Analysis](./dc-cafe-standalone-analysis.md) — Full technical assessment (database schema, code inventory, gap analysis, risk register)
- [Julies Bakeshop Daily Ledger Case Study](./julies-bakeshop-daily-ledger.md) — Reference for a similar extraction project
- Source system: `/var/www/bakeshopapp` — Julies Bakeshop CodeIgniter 4 monolith
- Database: `jbakeshop_live` on Bluehost MySQL 5.7
