# PAL + ZAP-ARTS Quotation System — Consolidation Plan

> **Source**: `system.xlsx` (Quotation / Sales Invoice form for ZAP-ARTS signage & printing)
> **Target**: PAL module (`modules/project-audit-ledger`)
> **Date**: 2026-07-11
> **Status**: ✅ All phases implemented

---

## 1. Excel Form Anatomy

The Excel workbook has 3 sheets:

| Sheet | Content |
|---|---|
| **FORM** | Main quotation/invoice template — rows 5–69, cols C–V |
| **Team lead** | "JULY cash advances" + "projec t1" |
| **Sheet2** | Empty |

### FORM Sections

| Section | Rows | Fields |
|---|---|---|
| Header | 5–8 | Customer Name, Company, Address, Contact, Date, Quotation No, Job Order No, Sales Invoice No |
| Scope of Work | 10–17 | ITEM, PRODUCT, DIMENSION, QTY; Scope dropdown (NEW/REFURBISH/WARRANTY CLAIM/LABOR ONLY/PRINT ONLY) |
| Pricing | 11–16 | Price/Unit, Price/SqFt, Installation Charge, Mobilization, Other Charges, Total, Down Payment (dropdown: DOWN/FULL) |
| Options | 19 | With Installation (YES/NO), Mode of Payment (CASH/CHECK/BANK TRANSFER/GCASH), Upload Button |
| Line Items | 31–66 | Items table with PARTICULARS, Dimension, QTY, TOTAL |
| Product Catalog | Rows 31–64, Cols R–T | 20+ signage materials with variants |
| Footer | 63–69 | Project Name, Total Price, Prepared By, Client Signature, BOM/BILL OF MATERIAL |

### Product Catalog (from Excel)

- **Tarpaulin**: Blackout 12oz, 15oz, 18oz, 20oz
- **Sticker**: Clear, White, Cut-out, Only
- **Panaflex**: Ecolsol, Panaflex (with Brand & Color)
- **Acrylic**: Plaque, Medals, Built-up Signage (lighted/non-lighted)
- **Photo Paper Printing**
- **Sticker on Sintra**
- **Frosted Sticker**: Cutout, Printed
- **Neon**
- **Stainless Signage**: Lighted, Non-lighted
- **ACP**: Only, With Lighted Acrylic Built-up, With Non-lighted Acrylic Built-up, With Acrylic Cut-out

### Formulas

| Cell | Formula | Meaning |
|---|---|---|
| `H38` | `=SUM(H36:H37)` | Line items total |
| `C64` | `=C32` | Project name → footer |
| `G64` | `=H38` | Total → footer |

### Cell Comments (ZAP-ARTS notes)

- `J6`, `J7`: "system generated numbers" (quotation no, job order no)
- `D13`: "drop down" (scope of work)
- `K16`: "drop down — DOWN PAYMENT / FULL PAYMENT"
- `L19`: "drop down" (mode of payment)

---

## 2. PAL Module Current State

**Module ID**: `project-audit-ledger` | **Auth-owned**: Yes | **Users**: `pal_users` (admin/supervisor/encoder)

### Existing Entities (32 tables)

| Entity | Table | CRUD |
|---|---|---|
| Projects | `pal_projects` | ✅ (has `job_order_number`, `contract_amount`) |
| Clients | `pal_clients` | ✅ |
| Suppliers | `pal_suppliers` | ✅ |
| Materials | `pal_materials` | ✅ |
| Inventory Movements | `pal_inventory_movements` | ✅ |
| Purchases | `pal_purchases` + `pal_purchase_items` | ✅ |
| Expenses | `pal_expenses` | ✅ |
| Sales | `pal_sales` (flat, **no line items**) | ✅ |
| Collections | `pal_collections` | ✅ |
| Material Issuance | `pal_material_issuances` + items | ✅ |
| Material Returns | `pal_material_returns` | ✅ |
| Fabrication | `pal_fabrication_allocations` + dues + payments | ✅ |
| Approvals | `pal_approvals` | ✅ |
| Audit Logs | `pal_audit_logs` | ✅ |
| Settings | `pal_settings` | ✅ |
| Attachments | Generic upload system | ✅ |

### Routes: ~50 GET + ~40 POST | Handlers: 17 files | Services: 10 files | Templates: 43 files | Entity Views: 11 configs | Migrations: 6

---

## 3. Gap Analysis

### What PAL Already Covers

| Excel Feature | PAL Entity | Match |
|---|---|---|
| Customer name, company, address, contact | `pal_clients` | ✅ Full |
| Job Order Number | `pal_projects.job_order_number` | ✅ Field exists, needs auto-gen |
| Sales Invoice Number | `pal_sales.invoice_number` (manual) | ✅ Full |
| Date | `pal_sales.sales_date` | ✅ Full |
| Gross/Discount/Tax/Net | `pal_sales` computed columns | ✅ Full |
| Mode of Payment | `pal_collections.payment_method` | ✅ Needs GCash option |
| Product catalog | `pal_materials` | ✅ Structure exists |
| Attachments/Uploads | Attachment system | ✅ Full |

### What PAL is Missing (Gaps)

| # | Gap | Priority | Impact |
|---|---|---|---|
| 1 | **Quotation entity** — no quote→invoice workflow | **HIGH** | Core workflow missing |
| 2 | **Sales line items** — `pal_sales` is flat, no itemization | **HIGH** | Can't capture per-product breakdown |
| 3 | **Scope of Work** — NEW/REFURBISH/WARRANTY/LABOR/PRINT | **HIGH** | Missing on both quotations and projects |
| 4 | **With Installation** toggle | **MEDIUM** | Affects pricing logic |
| 5 | **Price per Sq Foot** computation | **MEDIUM** | Core pricing model for signage |
| 6 | **Installation / Mobilization / Other Charges** | **MEDIUM** | Not on sales table |
| 7 | **Down Payment vs Full Payment** | **MEDIUM** | Partial in collections |
| 8 | **Product dimensions** (width, height, UOM) on line items | **MEDIUM** | Structured dimension fields missing |
| 9 | **Brand & Color** on materials | **LOW** | 2 fields on `pal_materials` |
| 10 | **Mockup upload** type | **LOW** | Attachments exist, needs type tag |
| 11 | **Cash advances per team lead** | **LOW** | Team lead sheet references |
| 12 | **BOM / Bill of Materials** standalone view | **LOW** | Can be composed from existing data |

---

## 4. Phase Plan

### Phase 1 — Quotation Entity & Workflow (HIGH)

**New table**: `pal_quotations`

```sql
CREATE TABLE pal_quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    quotation_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    quotation_date DATE NOT NULL,
    scope_of_work ENUM('new','refurbish','warranty_claim','labor_only','print_only') DEFAULT NULL,
    with_installation TINYINT(1) NOT NULL DEFAULT 0,
    mode_of_payment ENUM('cash','check','bank_transfer','gcash') DEFAULT NULL,
    installation_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    mobilization_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    down_payment DECIMAL(18,2) DEFAULT NULL,
    down_payment_type ENUM('down_payment','full_payment') DEFAULT NULL,
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','sent','approved','rejected','converted','expired') NOT NULL DEFAULT 'draft',
    converted_to_sale_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_quot_tenant (tenant_id),
    INDEX idx_pal_quot_project (project_id),
    INDEX idx_pal_quot_client (client_id),
    INDEX idx_pal_quot_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**New table**: `pal_quotation_items`

```sql
CREATE TABLE pal_quotation_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    quotation_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED DEFAULT NULL,
    particulars VARCHAR(255) NOT NULL,
    width DECIMAL(10,2) DEFAULT NULL,
    height DECIMAL(10,2) DEFAULT NULL,
    uom VARCHAR(20) DEFAULT NULL COMMENT 'e.g. ft, m, in',
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    price_per_unit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    price_per_sqft DECIMAL(18,2) DEFAULT NULL,
    line_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_pal_qi_quotation (quotation_id),
    INDEX idx_pal_qi_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**New routes**:

| Method | Path | Handler |
|---|---|---|
| GET | `/admin/project-audit-ledger/quotations` | List |
| GET | `/admin/project-audit-ledger/quotations/create` | Create form |
| GET | `/admin/project-audit-ledger/quotations/{id}` | Detail |
| GET | `/admin/project-audit-ledger/quotations/{id}/edit` | Edit form |
| POST | `/api/v1/project-audit-ledger/quotations` | Store |
| PUT | `/api/v1/project-audit-ledger/quotations/{id}` | Update |
| POST | `/api/v1/project-audit-ledger/quotations/{id}/convert` | Convert to invoice |

**Key workflow**: `draft → sent → approved → converted` (creates `pal_sales` record, sets `converted_to_sale_id`)

**New files**:
- `database/migrations/007_pal_quotations.sql`
- `services/QuotationService.php`
- `handlers/52-quotations.php`
- `templates/project-audit-ledger/pages/quotations-list.disyl`
- `templates/project-audit-ledger/pages/quotation-form.disyl`
- `templates/project-audit-ledger/pages/quotation-detail.disyl`
- `helpers/views/pal_quotation.disyl`
- `helpers/views/pal_quotation_item.disyl`

**Modified files**:
- `module.json` — add migration, capabilities, entity views
- `routes.php` — add quotation routes
- `handlers.php` — require new handler
- `helpers.php` — add `pal_cap_entity_list_pal_quotation` etc.
- `templates/project-audit-ledger/shell.disyl` — add Quotations nav item

---

### Phase 2 — Sales Line Items (HIGH)

**New table**: `pal_sale_items`

```sql
CREATE TABLE pal_sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    sale_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED DEFAULT NULL,
    particulars VARCHAR(255) NOT NULL,
    width DECIMAL(10,2) DEFAULT NULL,
    height DECIMAL(10,2) DEFAULT NULL,
    uom VARCHAR(20) DEFAULT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    price_per_unit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    price_per_sqft DECIMAL(18,2) DEFAULT NULL,
    line_total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_pal_si_sale (sale_id),
    INDEX idx_pal_si_material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**New columns on `pal_sales`** (migration `008_pal_sale_items.sql`):

```sql
ALTER TABLE pal_sales
    ADD COLUMN scope_of_work ENUM('new','refurbish','warranty_claim','labor_only','print_only') DEFAULT NULL AFTER status,
    ADD COLUMN with_installation TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_of_work,
    ADD COLUMN installation_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER tax_amount,
    ADD COLUMN mobilization_charge DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER installation_charge,
    ADD COLUMN other_charges DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER mobilization_charge,
    ADD COLUMN down_payment DECIMAL(18,2) DEFAULT NULL AFTER other_charges,
    ADD COLUMN down_payment_type ENUM('down_payment','full_payment') DEFAULT NULL AFTER down_payment,
    ADD COLUMN mode_of_payment ENUM('cash','check','bank_transfer','gcash') DEFAULT NULL AFTER down_payment_type,
    ADD COLUMN quotation_id INT UNSIGNED DEFAULT NULL AFTER client_id;
```

**Note**: `net_amount` remains a generated column. Recalculate as: `net_amount = gross_amount - discount_amount + tax_amount + installation_charge + mobilization_charge + other_charges`. The existing generated column may need to be dropped and re-added if MySQL 5.7 doesn't support altering generated columns in place — use the safe pattern: drop, alter, re-add.

**Modified files**:
- `services/SalesService.php` — line item CRUD, recalculate totals from items
- `handlers/50-sales.php` — accept line items array in create/update
- `templates/project-audit-ledger/pages/sales-form.disyl` — dynamic line items with Alpine.js
- `templates/project-audit-ledger/pages/sales-detail.disyl` — line items display
- `helpers/views/pal_sale.disyl` — add line items to detail view

---

### Phase 3 — Product Catalog & Material Enhancement (MEDIUM)

**New columns on `pal_materials`** (migration `009_pal_materials_enhance.sql`):

```sql
ALTER TABLE pal_materials
    ADD COLUMN brand VARCHAR(100) DEFAULT NULL AFTER name,
    ADD COLUMN color VARCHAR(50) DEFAULT NULL AFTER brand,
    ADD COLUMN default_width DECIMAL(10,2) DEFAULT NULL AFTER unit_id,
    ADD COLUMN default_height DECIMAL(10,2) DEFAULT NULL AFTER default_width,
    ADD COLUMN price_per_unit DECIMAL(18,2) DEFAULT NULL AFTER current_avg_cost,
    ADD COLUMN price_per_sqft DECIMAL(18,2) DEFAULT NULL AFTER price_per_unit;
```

**Seed data**: Insert the 20+ ZAP-ARTS product catalog entries into `pal_materials`:
- Tarpaulin: Blackout 12oz, 15oz, 18oz, 20oz
- Sticker: Clear, White, Cut-out
- Panaflex: Ecolsol, Panaflex
- Acrylic: Plaque, Medals, Built-up (Lighted), Built-up (Non-lighted)
- Photo Paper Printing
- Sticker on Sintra
- Frosted Sticker: Cutout, Printed
- Neon
- Stainless Signage: Lighted, Non-lighted
- ACP: Only, With Lighted Acrylic Built-up, With Non-lighted Acrylic Built-up, With Acrylic Cut-out

**New `pal_material_categories`**: Tarpaulin, Sticker, Panaflex, Acrylic, Photo Paper, Sintra, Frosted, Neon, Stainless, ACP

---

### Phase 4 — Printable Quotation/Invoice Template (MEDIUM)

Create print-optimized DiSyL template matching the ZAP-ARTS form layout:
- `templates/project-audit-ledger/prints/quotation-print.disyl` — A4 print layout
- `templates/project-audit-ledger/prints/invoice-print.disyl` — A4 print layout
- `@media print` CSS for header, line items table, charges breakdown, signature block
- Reuse the existing `window.print()` button pattern from `sales-detail.disyl` and `project-detail.disyl`

---

### Phase 5 — Team Lead Cash Advances (LOW)

**New table**: `pal_cash_advances`

```sql
CREATE TABLE pal_cash_advances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    team_lead_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    advance_date DATE NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','settled','voided') NOT NULL DEFAULT 'pending',
    settled_at DATETIME DEFAULT NULL,
    -- standard audit columns
    INDEX idx_pal_ca_tenant (tenant_id),
    INDEX idx_pal_ca_teamlead (team_lead_id),
    INDEX idx_pal_ca_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Links to `pal_team_leads` and optionally `pal_projects`. Settled via deduction from fabrication payments or direct repayment.

---

## 5. Entity Relationship Diagram (Post-Integration)

```
pal_clients ──┐
              ├── pal_quotations ──< pal_quotation_items ──< pal_materials
pal_projects ─┘        │
                       │ (convert)
                       ▼
pal_clients ──┐
              ├── pal_sales ──< pal_sale_items ──< pal_materials
pal_projects ─┘        │
pal_quotations ────────┘ (quotation_id FK)
                       │
                       ▼
              pal_collections

pal_materials ──< pal_inventory_movements
pal_materials ──< pal_purchase_items
pal_materials ──< pal_material_issuance_items

pal_team_leads ──< pal_cash_advances ──> pal_projects
pal_team_leads ──< pal_fabrication_allocations
```

---

## 6. Implementation Order & Dependencies

```
Phase 1 (Quotations)     ← No dependencies, can start immediately
    │
Phase 2 (Sale Items)     ← Depends on Phase 1 for quotation_id FK on pal_sales
    │
Phase 3 (Materials+Cat)   ← Independent, can run in parallel with Phase 2
    │
Phase 4 (Print Templates) ← Depends on Phase 1+2 for data structure
    │
Phase 5 (Cash Advances)   ← Independent, can run anytime
```

---

## 7. Files Checklist — Implementation Status: ✅ COMPLETED

### Phase 1 — Created

- [x] `database/migrations/007_pal_quotations.sql`
- [x] `services/QuotationService.php`
- [x] `handlers/52-quotations.php`
- [x] `templates/project-audit-ledger/pages/quotations-list.disyl`
- [x] `templates/project-audit-ledger/pages/quotation-form.disyl`
- [x] `templates/project-audit-ledger/pages/quotation-detail.disyl`
- [x] `helpers/views/pal_quotation.disyl`
- [x] `helpers/views/pal_quotation_item.disyl`

### Phase 1 — Modified

- [x] `module.json`
- [x] `routes.php`
- [x] `handlers.php`
- [x] `helpers.php`
- [x] `templates/project-audit-ledger/shell.disyl`

### Phase 2 — Created

- [x] `database/migrations/008_pal_sale_items.sql`

### Phase 2 — Modified

- [x] `services/SalesService.php`
- [x] `handlers/50-sales.php`
- [x] `templates/project-audit-ledger/pages/sales-form.disyl`
- [x] `templates/project-audit-ledger/pages/sales-detail.disyl`
- [x] `helpers/views/pal_sale.disyl` *(no change needed — entity view fields unchanged)*

### Phase 3 — Created

- [x] `database/migrations/009_pal_materials_enhance.sql`
- [x] `database/seeds/pal_product_catalog.sql`

### Phase 3 — Modified

- [x] `templates/project-audit-ledger/pages/settings-materials.disyl` *(no change needed — existing material settings handles new columns)*

### Phase 4 — Created

- [x] `templates/project-audit-ledger/prints/quotation-print.disyl`
- [x] `templates/project-audit-ledger/prints/invoice-print.disyl`

### Phase 5 — Created

- [x] `database/migrations/010_pal_cash_advances.sql`
- [x] `services/CashAdvanceService.php`
- [x] `handlers/57-cash-advances.php`
- [x] `templates/project-audit-ledger/pages/cash-advances-list.disyl`
- [x] `templates/project-audit-ledger/pages/cash-advance-form.disyl`
- [x] `helpers/views/pal_cash_advance.disyl`

---

## 8. Key Design Decisions

1. **Quotation is upstream of Sales Invoice**. A quotation is a proposal; converting it creates the invoice. This matches the Excel form's progression: Quotation No → Job Order Number → Sales Invoice Number.

2. **Line items live on both quotations and sales** as separate tables (`pal_quotation_items`, `pal_sale_items`), not shared. A quotation may be edited after conversion, and sale items may be adjusted independently during negotiation.

3. **Pricing is computed, not trusted blindly**. Unit price × QTY, or (Width × Height) × Price/SqFt. The stored `line_total` is verified against computed values on save.

4. **Scope of work lives on both quotations and sales**. When a quotation is approved, the scope carries to the linked sale. Projects inherit scope from their linked quotation/sale.

5. **Backward compatible**. Existing `pal_sales` flat amount fields (`gross_amount`, `discount_amount`, `tax_amount`) remain and are recomputed from line items. Old API endpoints continue to work.

6. **MySQL 5.7 safe**. No window functions, no CTEs, InnoDB everywhere. Generated column changes use drop-and-re-add pattern. All FKs match column types exactly.

---

## 9. Capability Map (New)

| Capability ID | Purpose | Role |
|---|---|---|
| `pal.quotation.list` | View quotations list | admin, supervisor, encoder |
| `pal.quotation.create` | Create new quotation | admin, supervisor |
| `pal.quotation.view` | View quotation detail | admin, supervisor, encoder |
| `pal.quotation.edit` | Edit quotation | admin, supervisor |
| `pal.quotation.convert` | Convert quotation to invoice | admin, supervisor |
| `pal.quotation.delete` | Delete quotation | admin |
| `pal.sale.items.manage` | Manage sale line items | admin, supervisor |
| `pal.cash_advance.list` | View cash advances | admin, supervisor |
| `pal.cash_advance.create` | Create cash advance | admin, supervisor |
| `pal.cash_advance.approve` | Approve cash advance | admin |
| `pal.cash_advance.settle` | Settle cash advance | admin |

### Entity View Capabilities (New)

| Capability ID | Source |
|---|---|
| `pal.entity.list.pal_quotation` | `pal_quotation` |
| `pal.entity.get.pal_quotation` | `pal_quotation` |
| `pal.entity.list.pal_quotation_item` | `pal_quotation_item` |
| `pal.entity.list.pal_sale_item` | `pal_sale_item` |
| `pal.entity.list.pal_cash_advance` | `pal_cash_advance` |

---

## 10. Post-Implementation Gap Analysis

### Excel Form Coverage

| Excel Feature | Implemented | Notes |
|---|---|---|
| Customer Name / Company / Address / Contact | ✅ | Via `pal_clients` (existing), linked to quotations & sales |
| Quotation Number (auto-generated) | ✅ | `QTN-YYYYMMDD-NNNN` format via `QuotationService` |
| Job Order Number (auto-generated) | ⚠️ | Already existed on `pal_projects.job_order_number`, but auto-gen logic was not added — prospective feature |
| Sales Invoice Number (manual input) | ✅ | `pal_sales.invoice_number` (existing) |
| Date | ✅ | `quotation_date` / `sales_date` |
| Scope of Work (NEW/REFURBISH/WARRANTY/LABOR/PRINT) | ✅ | ENUM on both `pal_quotations` and `pal_sales` |
| With Installation (YES/NO) | ✅ | TINYINT toggle on both entities |
| Mode of Payment (CASH/CHECK/TRANSFER/GCASH) | ✅ | ENUM with all 4 options including GCash |
| Price per Unit | ✅ | `price_per_unit` on line items |
| Price per Sq Ft | ✅ | `price_per_sqft` with dimension-based computation |
| Installation Charge | ✅ | Separate DECIMAL field on both entities |
| Mobilization Charge | ✅ | Separate DECIMAL field |
| Other Charges | ✅ | Separate DECIMAL field |
| Down Payment / Full Payment | ✅ | `down_payment` amount + `down_payment_type` ENUM |
| Line Items (product, particulars, dims, qty, price, total) | ✅ | `pal_quotation_items` + `pal_sale_items` |
| Subtotal → Total calculation | ✅ | Computed automatically from line items + charges |
| Product Catalog (20+ materials) | ✅ | Seeded in `pal_product_catalog.sql` with categories |
| Brand & Color fields | ✅ | Columns added to `pal_materials` via migration 009 |
| Mockup Upload | ⚠️ | Attachments system exists generically — add a `mockup` attachment type in a future enhancement |
| Picture Upload | ✅ | Existing attachment system covers this |
| Client Signature | ✅ | In print templates |
| Prepared By | ✅ | In print templates + detail views |
| BOM / Bill of Materials | ⚠️ | Composable from existing data — no standalone BOM view |
| Quotation → Invoice workflow | ✅ | "Convert to Invoice" action creates `pal_sales` with items copied |
| Print-ready A4 Layout | ✅ | `prints/quotation-print.disyl` + `prints/invoice-print.disyl` |

### Team Lead Sheet Coverage

| Element | Implemented | Notes |
|---|---|---|
| Cash advances tracking | ✅ | `pal_cash_advances` table + CRUD + approval/settle/void workflow |
| Per-team-lead view | ✅ | Links to `pal_team_leads` with balance queries |
| "July" header | ⚠️ | No monthly grouping view — can be added via entity view filters |

### Coverage Summary

| Category | Total | Covered | Partial | Missing |
|---|---|---|---|---|
| Quotation workflow | 12 | 11 | 1 | 0 |
| Sales/invoice enhancements | 14 | 12 | 2 | 0 |
| Product catalog | 2 | 2 | 0 | 0 |
| Print templates | 2 | 2 | 0 | 0 |
| Cash advances | 3 | 3 | 0 | 0 |
| **Total** | **33** | **30** | **3** | **0** |

### Remaining Gaps (Low Priority) — Status: ✅ All Closed

The following low-priority gaps from the initial implementation have been closed:

1. **Job Order Number auto-generation** ✅ — On quotation convert, if the linked project has no `job_order_number`, it's auto-generated as `JO-YYYYMMDD-NNNN`.
2. **BOM standalone view** ✅ — New `/admin/project-audit-ledger/bom` page with project selector, material listing from quotations + sales, and CSV export.
3. **Monthly cash advance grouping** ✅ — Month/year filter added to cash advances list page and entity view handler.
4. **Mockup upload type tag** ✅ — Type selector (Mockup/Photo/Reference/Other) on quotation detail page attachments, stored in `description` field. New `/api/v1/project-audit-ledger/attachments/list` endpoint for entity-scoped queries.

### Audit Trail Coverage

All new entities and operations emit audit log entries via `palAudit()`:
- `pal.quotation.created` / `pal.quotation.updated` / `pal.quotation.converted`
- `pal.sale.created` / `pal.sale.updated` (enhanced with line items)
- `pal.cash_advance.created` / `pal.cash_advance.approved` / `pal.cash_advance.settled` / `pal.cash_advance.voided`
- `pal.attachment.uploaded` / `pal.attachment.deleted`

Domain events are also fired through the kernel event bus via `palFireEvent()` for all major state transitions.

---

## 11. Team Leader Views & Role Implementation Plan

> **Date**: 2026-07-11
> **Status**: 📋 Design Phase
> **Dependency**: Phase 1–5 completion (✅ done)

---

### 11.1 Current State Assessment

| Element | Current State | Gap |
|---|---|---|
| PAL user roles | `admin`, `supervisor`, `encoder` only | No `team_lead` role |
| `pal_team_leads` table | Standalone (name, contact, email, address) | No link to `pal_users` — team leads cannot log in |
| CA approval flow | Direct status update (`pending→approved→settled`) in service | No `pal_approvals` integration, no multi-step review |
| CA submission | Admin/supervisor only | Team leads cannot request their own CAs |
| Mobilization requests | **Does not exist** | No entity, no workflow |
| Team attendance view | PAL has no attendance data | `attendance-wage` module has it but separate DB/auth |
| Fabrication view per JO | Admin sees all projects | No team-lead-filtered view |
| CA request notifications | `palFireEvent()` fires events | No notification handler consuming them |

### Attendance-Wages Module (Reference Integration)

The `attendance-wage` module (`modules/attendance-wage/`) provides:
- `attendance_wage_users` — roles: `admin`, `supervisor`, `employee`
- `employee_profiles` — position, department, salary, gov IDs, `cash_advance_allowed`
- `attendance_records` — clock_in, clock_out, photo, location, status
- `cash_advances` — with repayment schedules (`full_next_payroll`, `installment`, `lumpsum_date`)
- `payroll_periods` + `salary_computation` — payroll engine
- Own auth cookie (`attendance_wage_token`), completely separate from PAL

**Integration challenge**: PAL and attendance-wages are separate modules with their own databases, auth, and user tables. A team lead in PAL needs to see their team's attendance data from the attendance-wages module.

---

### 11.2 Architecture Decision: Add `team_lead` Role to PAL

**Decision**: Add `team_lead` to `pal_users.role` ENUM, link `pal_team_leads.user_id` → `pal_users.id`.

**Why not keep team_leads standalone?**
- Team leads need to log in, view dashboards, submit requests — that requires authentication
- `pal_users` already has the auth infrastructure (password_hash, token_version, sessions)
- Adding a `user_id` FK to `pal_team_leads` creates a proper entity relationship without duplicating auth

**Migration**:
```sql
ALTER TABLE pal_users MODIFY COLUMN role ENUM('admin','supervisor','encoder','team_lead') NOT NULL DEFAULT 'encoder';
ALTER TABLE pal_team_leads ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER email;
ALTER TABLE pal_team_leads ADD INDEX idx_pal_tl_user (user_id);
```

**Backfill**: Existing `pal_team_leads` records with matching email to `pal_users` get linked. New team leads created via user management auto-create the `pal_team_leads` record.

---

### 11.3 Team Leader Shell & Views

Team leads get a **stripped-down shell** with only their relevant nav items:

```
📊 My Dashboard         — personal KPI cards
📁 My Projects          — projects assigned via fabrication_team_lead_id
🔧 Fabrication Dues     — fab allocations & dues for assigned JOs
💵 My Cash Advances     — requested + received CAs
🚛 Mobilization         — request mobilization funds
👥 Team Attendance      — team member hours (from attendance-wage)
⚙️ My Profile           — view/edit personal info
```

Capabilities:
- `pal.team_lead.dashboard@1` — View dashboard
- `pal.team_lead.fabrication@1` — View fab for assigned JOs
- `pal.team_lead.ca.request@1` — Submit CA requests
- `pal.team_lead.ca.view@1` — View own CA history
- `pal.team_lead.mobilization.request@1` — Submit mobilization requests
- `pal.team_lead.mobilization.view@1` — View own mobilization history
- `pal.team_lead.attendance@1` — View team attendance

---

### 11.4 View 1: Fabrications per JO Assigned

**Query**: Projects where `p.fabrication_team_lead_id` matches the team lead's `pal_team_leads.id`.

**Data shown**:
- Project title, job_order_number, contract_amount
- Fabrication budget (contract × alloc_pct%)
- CA dispensed so far (SUM of approved allocations)
- Remaining budget
- Weekly dues breakdown (due_amount, paid_amount, balance)

**Template**: `templates/.../pages/team-lead-fabrication.disyl`
**Route**: `GET /admin/project-audit-ledger/team-lead/fabrication`

---

### 11.5 View 2: Cash Advances — Requested & Received

**Current state**: CA created by admin, directly approved. No team lead self-service.

**Better flow**:

```
Team Lead submits CA request
         │
         ▼
   Status: pending
   pal_approvals record created (entity_type='cash_advance')
   Event: pal.ca.requested
         │
         ▼
   Supervisor notified (if supervisor role exists)
         │
         ▼
   Admin reviews in Approval Queue
         │
    ┌────┴────┐
    ▼         ▼
approved   rejected
    │         │
    ▼         ▼
Status:    Status:
approved   rejected
Event:     Event:
pal.ca.    pal.ca.
approved   rejected
    │
    ▼
Team lead sees
"Received: ₱X"
    │
    ▼
Admin marks settled
→ Status: settled
```

**Changes needed**:
| File | Change |
|---|---|
| `pal_users` migration | Add `role='team_lead'` to ENUM |
| `pal_team_leads` migration | Add `user_id` FK |
| `CashAdvanceService.php` | Add `submitForApproval()` method, integrate with `palApprovalService` |
| `ApprovalService.php` | Add `cash_advance` to `TABLES` constant, add post-approval handler |
| `handlers/57-cash-advances.php` | Add `palApiCashAdvanceSubmit()` and team lead views |
| `templates/.../team-lead-ca-list.disyl` | Team lead CA list (filtered by team_lead_id) |
| `templates/.../team-lead-ca-form.disyl` | Team lead CA request form |
| `shell.disyl` | Add team lead shell variant |

**Immediate CA list for team leads** (before full approval integration):
Pass `team_lead_id` filter from the authenticated user's linked `pal_team_leads` record. Show status with badges.

---

### 11.6 View 3: Mobilization Request (NEW Entity)

**New table**: `pal_mobilization_requests`

```sql
CREATE TABLE pal_mobilization_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    team_lead_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    request_date DATE NOT NULL,
    purpose VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected','disbursed','voided') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    disbursed_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT DEFAULT NULL,
    approval_id INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_mob_tenant (tenant_id),
    INDEX idx_pal_mob_teamlead (team_lead_id),
    INDEX idx_pal_mob_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Flow**:
```
Team Lead submits mobilization request
         │
         ▼
   pal_mobilization_requests (status: pending)
   pal_approvals (entity_type='mobilization')
   Event: pal.mobilization.requested
         │
         ▼
   Admin reviews → approve/reject
         │
    ┌────┴────┐
    ▼         ▼
approved   rejected
    │
    ▼
   Status: disbursed (when funds released)
```

**New files**:
| File | Purpose |
|---|---|
| `database/migrations/011_pal_mobilization.sql` | New table |
| `services/MobilizationService.php` | CRUD + approval |
| `handlers/58-mobilization.php` | Team lead + admin handlers |
| `templates/.../team-lead-mobilization-list.disyl` | Team lead view |
| `templates/.../team-lead-mobilization-form.disyl` | Request form |

**New routes**:
| Method | Path | Handler |
|---|---|---|
| GET | `/admin/project-audit-ledger/team-lead/mobilization` | Team lead list |
| GET | `/admin/project-audit-ledger/team-lead/mobilization/create` | Request form |
| POST | `/api/v1/project-audit-ledger/mobilization` | Submit request |
| POST | `/api/v1/project-audit-ledger/mobilization/{id}/approve` | Admin approve |
| POST | `/api/v1/project-audit-ledger/mobilization/{id}/reject` | Admin reject |

---

### 11.7 View 4: Team Member Attendance (Cross-Module Integration)

**Challenge**: PAL and `attendance-wage` are separate modules with separate:
- Databases (tenant DBs may be the same, but user tables differ)
- Auth cookies (`pal_token` vs `attendance_wage_token`)
- User tables (`pal_users` vs `attendance_wage_users`)

**Recommended approach**: **Direct DB read bridge**

Since both modules run under the same tenant and share the same MySQL database, PAL can read `attendance_records` and `employee_profiles` directly via the kernel's tenant-scoped DB connection.

**Implementation**:
1. Add a `team_lead_email` column to `pal_team_leads` (already has `email`)
2. In the `attendance-wage` module, add `team_lead_email` to `employee_profiles`
3. PAL's team lead attendance handler queries:
```sql
SELECT ar.*, ep.full_name, ep.position
FROM attendance_records ar
JOIN employee_profiles ep ON ar.user_id = ep.user_id
WHERE ep.team_lead_email = :team_lead_email
  AND ar.clock_in >= :date_from AND ar.clock_in <= :date_to
ORDER BY ar.clock_in DESC
```

**Or simpler** (Phase 1):
- Team lead manages their team via the `attendance-wage` module's own interface
- PAL provides a deep-link to `/admin/attendance` or an iframe embed
- No cross-module schema changes needed

**Recommended for MVP**: The simpler approach — deep-link to attendance-wage module. Cross-module DB reads can be Phase 2.

---

### 11.8 Better Flow: Summary

#### Current Flow (Admin-only)
```
Admin → Creates CA → Directly approves → Settles
Admin → Creates mobilization (doesn't exist yet)
Admin → Views all fabrication
No team lead login
```

#### Proposed Flow (Team Lead + Admin)
```
┌─────────────────────────────────────────────────────────┐
│ TEAM LEAD                                              │
│                                                         │
│ Login → Dashboard                                       │
│   ├─ View fabrications per assigned JO                  │
│   ├─ Submit CA request ──────────┐                      │
│   ├─ Submit mobilization request ─┤                     │
│   ├─ View CA history (received)   │                     │
│   └─ View team attendance ────────┤                     │
│                                    ▼                     │
│                          pal_approvals queue            │
│                                    │                     │
├────────────────────────────────────┼─────────────────────┤
│ ADMIN                              │                     │
│                                    ▼                     │
│ Login → Approval Queue             │                     │
│   ├─ Review pending CA requests ◄──┘                     │
│   ├─ Review mobilization requests                        │
│   ├─ Approve/Reject → status updated                    │
│   └─ Mark CA as settled, mobilization as disbursed      │
│                                                         │
│ Login → Fabrication Management                          │
│   ├─ All projects (admin view)                          │
│   └─ Per-team-lead breakdown                            │
└─────────────────────────────────────────────────────────┘
```

---

### 11.9 Implementation Phases

#### Phase 6 — Team Lead Role & Auth

| Step | Description |
|---|---|
| 6.1 | Migration: Add `team_lead` to `pal_users.role` ENUM |
| 6.2 | Migration: Add `user_id` FK to `pal_team_leads` |
| 6.3 | Update `handlers/75-users.php` — allow creating team_lead users |
| 6.4 | Update `handlers/00-bootstrap.php` — team_lead role in `palRequireRole()` |
| 6.5 | Add new capabilities to `module.json` |
| 6.6 | Create team lead shell template variant |
| 6.7 | Update `handlers/70-settings.php` — team lead management links to user |

#### Phase 7 — Team Lead Fabrication View

| Step | Description |
|---|---|
| 7.1 | Handler: `palPageTeamLeadFabrication()` — filter projects by team_lead_id |
| 7.2 | Template: `team-lead-fabrication.disyl` — fabrication table per JO |
| 7.3 | Route + nav item |

#### Phase 8 — CA Request Flow (Team Lead → Admin)

| Step | Description |
|---|---|
| 8.1 | Update `CashAdvanceService` — add `submitForApproval()` |
| 8.2 | Update `ApprovalService` — add `cash_advance` entity support |
| 8.3 | Handler: team lead CA request form & submission API |
| 8.4 | Handler: team lead CA list (own records only) |
| 8.5 | Template: team lead CA request form + list with status badges |
| 8.6 | Admin approval queue: show CA requests, link to decide |

#### Phase 9 — Mobilization Request (NEW)

| Step | Description |
|---|---|
| 9.1 | Migration: `011_pal_mobilization.sql` |
| 9.2 | Service: `MobilizationService.php` |
| 9.3 | Handler: `58-mobilization.php` |
| 9.4 | Templates: list + form |
| 9.5 | Approval integration |
| 9.6 | Routes + nav |

#### Phase 10 — Team Attendance View

| Step | Description |
|---|---|
| 10.1 | Deep-link or iframe embed to attendance-wage module |
| 10.2 | (Phase 2) Cross-module DB query bridge |

---

### 11.10 Files Checklist

#### Phase 6 — Create
- [ ] `database/migrations/011_pal_team_lead_role.sql`
- [ ] `templates/project-audit-ledger/team-lead-shell.disyl`

#### Phase 6 — Modify
- [ ] `module.json` — add team_lead capabilities
- [ ] `handlers/00-bootstrap.php` — team_lead in role checks
- [ ] `handlers/75-users.php` — team_lead role in create/update
- [ ] `handlers/70-settings.php` — link team leads to users

#### Phase 7 — Create
- [ ] `templates/project-audit-ledger/pages/team-lead-fabrication.disyl`

#### Phase 7 — Modify
- [ ] `routes.php`
- [ ] `handlers.php`
- [ ] `handlers/45-fabrication.php` — add team lead handler

#### Phase 8 — Create
- [ ] `templates/project-audit-ledger/pages/team-lead-ca-list.disyl`
- [ ] `templates/project-audit-ledger/pages/team-lead-ca-form.disyl`

#### Phase 8 — Modify
- [ ] `services/CashAdvanceService.php`
- [ ] `services/ApprovalService.php`
- [ ] `handlers/57-cash-advances.php`

#### Phase 9 — Create
- [ ] `database/migrations/012_pal_mobilization.sql`
- [ ] `services/MobilizationService.php`
- [ ] `handlers/58-mobilization.php`
- [ ] `templates/project-audit-ledger/pages/team-lead-mobilization-list.disyl`
- [ ] `templates/project-audit-ledger/pages/team-lead-mobilization-form.disyl`
- [ ] `helpers/views/pal_mobilization.disyl`

#### Phase 9 — Modify
- [ ] `module.json`
- [ ] `routes.php`
- [ ] `handlers.php`
- [ ] `helpers.php`
- [ ] `services/ApprovalService.php`

#### Phase 10 — Create
- [ ] `templates/project-audit-ledger/pages/team-lead-attendance.disyl`

#### Phase 10 — Modify
- [ ] `routes.php`

---

### 11.11 Suggested Better Flow

The original flow was purely admin-driven. The proposed flow distributes responsibility:

| Action | Who | Checkpoint |
|---|---|---|
| Submit CA request | Team Lead | Supervisor notified |
| Submit mobilization request | Team Lead | Supervisor notified |
| Review CA/mobilization | Admin (via Approval Queue) | Approve or reject |
| View fabrication per JO | Team Lead | Read-only, filtered |
| View own CA history | Team Lead | Status + amounts |
| View team attendance | Team Lead | Deep-link to attendance-wage |
| Settle approved CA | Admin | Marks CA as settled |
| Disburse mobilization | Admin | Marks as disbursed |

**Benefits**:
- Team leads have autonomy to request funds without admin manually creating everything
- Audit trail is clear: who requested, who approved
- Approval queue centralizes all pending decisions in one place
- Integration with existing `pal_approvals` ensures consistency
- Fabrication dues are visible per JO, not just globally
