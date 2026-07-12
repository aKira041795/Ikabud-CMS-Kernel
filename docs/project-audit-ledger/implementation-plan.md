# Project Audit Ledger — Implementation Plan

> **Module**: `project-audit-ledger`  
> **Kernel OS**: Ikabud Kernel OS (DiSyL, PHP 8.3+, MySQL 8+)  
> **Domain**: Project Costing, Inventory, Fabrication Dues, Sales, and Audit Management  

---

## 1. Architecture Summary

### 1.1 Design Philosophy

The module is a **project-first costing and inventory system** designed for small fabrication/construction/service businesses. It treats every transaction as traceable to a project (or general operations). Financial records are **immutable after approval** — corrections use reversal/void patterns. Inventory is **movement-derived** — never mutate stock totals directly.

### 1.2 Kernel Integration Points

| Concern | Kernel Mechanism |
|---|---|
| Authentication | Kernel auth (`app()->authUser()`, `app()->requireRole()`) |
| Tenant isolation | `module()->db()` returns tenant-scoped PDO via `ModuleDB` |
| Capabilities | Module declares `capabilities.exposes` / `capabilities.depends` in `module.json` |
| Event bus | `EventBus::fire()` for domain events listed in `module.json` |
| Audit logging | `audit_logs` table via kernel's `write_log()` or direct INSERT |
| CSRF | Kernel CSRF token (`csrf_token()`, `csrf_meta()`, `csrf_input()`) |
| Routes | Declarative `routes.php` (GET/POST maps) resolved via `module-id:functionName` |
| Navigation | `nav[]` in `module.json` — renders in CMS admin shell |
| Settings | `settings_fields[]` in `module.json` — per-tenant via kernel settings |
| Rendering | DiSyL templates, CMS admin shell (`cmsRender`), HTMX partials |
| Attachments | Kernel file abstraction via `modules/media` |
| Reports | PDF via Dompdf/mPDF, Excel via PhpSpreadsheet, queued via kernel job queue |

### 1.3 Layered Architecture

```
┌──────────────────────────────────────────────┐
│                 DiSyL Templates               │
│         (disyl views + HTMX partials)         │
├──────────────────────────────────────────────┤
│           Page Handlers / Controllers          │
│    (handlers/ — auth check, render, redirect)  │
├──────────────────────────────────────────────┤
│              Domain Services                   │
│  (services/ — business logic, validation, tx)  │
├──────────────────────────────────────────────┤
│        Repository / Data Access Layer          │
│      (module()->db(), query builders)          │
├──────────────────────────────────────────────┤
│              Database (MySQL 8+)              │
│       (pal_* tables, tenant-scoped)           │
└──────────────────────────────────────────────┘
```

### 1.4 Module Type Decision

This module will be **auth-owned** (like bakeshop, guidance, wms) with its own users table and admin shell. The primary users are business owners/supervisors/encoders — not CMS content editors. The module needs its own login page, role management, and workspace, independent of the CMS admin.

**Why auth-owned**: The target users are construction/fabrication business staff who should not need CMS accounts. The module has role separation (admin, encoder, supervisor) that maps naturally to an auth-owned schema.

---

## 2. Module Directory Structure

```
modules/project-audit-ledger/
├── module.json                 # Manifest: capabilities, tables, routes, events, settings, nav
├── routes.php                  # Route declarations (GET/POST)
├── handlers.php                # Handler loader (requires sub-files)
├── helpers.php                 # Capability handlers, shared utilities
├── handlers/
│   ├── 00-bootstrap.php        # Session init, auth check, module context
│   ├── 05-auth.php             # Login, logout, password reset
│   ├── 10-dashboard.php        # Dashboard page + API
│   ├── 15-projects.php         # Project list, detail, CRUD
│   ├── 20-clients.php          # Client/supplier management
│   ├── 25-expenses.php         # Expense entry, list, detail
│   ├── 30-purchases.php        # Purchase orders, stock-in
│   ├── 35-inventory.php        # Materials, stock movements, adjustments
│   ├── 40-material-issuance.php # Issue materials to projects
│   ├── 45-fabrication.php      # Allocation, weekly dues, payments
│   ├── 50-sales.php            # Sales invoices, collections
│   ├── 55-approvals.php        # Approval queue, decision
│   ├── 60-reports.php          # Report generation (HTML, PDF, Excel)
│   ├── 65-audit.php            # Audit trail view/export
│   ├── 70-settings.php         # Module settings
│   └── 75-users.php            # User management
├── services/
│   ├── ProjectService.php
│   ├── ProjectCostService.php
│   ├── ExpenseService.php
│   ├── PurchaseService.php
│   ├── InventoryService.php
│   ├── InventoryMovementService.php
│   ├── MaterialIssuanceService.php
│   ├── MaterialReturnService.php
│   ├── FabricationAllocationService.php
│   ├── FabricationDueService.php
│   ├── FabricationPaymentService.php
│   ├── SalesService.php
│   ├── CollectionService.php
│   ├── ApprovalService.php
│   ├── ReportingService.php
│   └── AuditService.php
├── database/
│   └── migrations/
│       ├── 001_pal_core_schema.sql
│       ├── 002_pal_users.sql
│       ├── 003_pal_clients_suppliers.sql
│       ├── 004_pal_materials_inventory.sql
│       ├── 005_pal_purchases.sql
│       ├── 006_pal_material_issuance.sql
│       ├── 007_pal_expenses.sql
│       ├── 008_pal_sales_collections.sql
│       ├── 009_pal_fabrication.sql
│       ├── 010_pal_approvals_audit.sql
│       ├── 011_pal_report_exports.sql
│       └── 012_pal_settings.sql
├── templates/
│   └── project-audit-ledger/
│       ├── login.disyl
│       ├── forgot-password.disyl
│       ├── reset-password.disyl
│       ├── shell.disyl            # Admin shell layout
│       ├── dashboard.disyl
│       ├── projects/
│       │   ├── list.disyl
│       │   ├── form.disyl
│       │   └── detail.disyl
│       ├── clients/
│       │   ├── list.disyl
│       │   └── form.disyl
│       ├── expenses/
│       │   ├── list.disyl
│       │   ├── form.disyl
│       │   └── detail.disyl
│       ├── purchases/
│       │   ├── list.disyl
│       │   └── form.disyl
│       ├── inventory/
│       │   ├── list.disyl
│       │   ├── detail.disyl
│       │   └── movements.disyl
│       ├── material-issuance/
│       │   ├── list.disyl
│       │   └── form.disyl
│       ├── fabrication/
│       │   ├── allocation.disyl
│       │   ├── weekly-dues.disyl
│       │   └── payment-form.disyl
│       ├── sales/
│       │   ├── list.disyl
│       │   ├── form.disyl
│       │   └── collections.disyl
│       ├── approvals/
│       │   └── queue.disyl
│       ├── reports/
│       │   ├── center.disyl
│       │   ├── project-cost.disyl
│       │   ├── profit-loss.disyl
│       │   ├── inventory-stock.disyl
│       │   ├── sales-report.disyl
│       │   └── fabrication-report.disyl
│       ├── audit/
│       │   └── trail.disyl
│       └── settings/
│           ├── general.disyl
│           ├── users.disyl
│           └── user-form.disyl
└── tests/
    ├── Unit/
    │   ├── CostCalculationTest.php
    │   ├── FabricationAllocationTest.php
    │   ├── InventoryCostingTest.php
    │   └── PermissionRuleTest.php
    ├── Integration/
    │   ├── ExpenseApprovalFlowTest.php
    │   ├── PurchaseToStockInTest.php
    │   ├── MaterialIssuanceToProjectCostTest.php
    │   ├── FabricationPaymentFlowTest.php
    │   ├── SalesCollectionFlowTest.php
    │   └── AuditLoggingTest.php
    └── Security/
        ├── CrossTenantIsolationTest.php
        ├── UnauthorizedAccessTest.php
        └── ApprovedRecordModificationTest.php
```

---

## 3. Database Schema

### 3.1 Entity-Relationship Overview

```
pal_users ──┐
            ├── pal_projects ─────────────────┐
            │     ├── pal_project_types        │
            │     ├── pal_clients ─────────────┤
            │     ├── pal_expenses ────────────┤
            │     ├── pal_sales ───────────────┤
            │     │     └── pal_collections     │
            │     ├── pal_material_issuances ──┤
            │     │     └── pal_material_returns│
            │     ├── pal_fabrication_allocations
            │     │     └── pal_fabrication_weekly_dues
            │     │           └── pal_fabrication_payments
            │     └── pal_attachments
            │
pal_materials ──┐
    ├── pal_material_categories
    ├── pal_inventory_movements
    ├── pal_inventory_balances
    └── pal_purchases
          └── pal_purchase_items

pal_suppliers
pal_team_leads
pal_expense_categories
pal_units
pal_inventory_locations
pal_approvals
pal_audit_logs (module-owned)
pal_report_exports
pal_settings (module-owned)
```

### 3.2 Core Tables

#### `pal_projects`

```sql
CREATE TABLE pal_projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id VARCHAR(50) NOT NULL COMMENT 'Display ID',
    job_order_number VARCHAR(50) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    project_type_id INT UNSIGNED DEFAULT NULL,
    description TEXT DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    estimated_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    start_date DATE DEFAULT NULL,
    target_completion_date DATE DEFAULT NULL,
    actual_completion_date DATE DEFAULT NULL,
    project_manager VARCHAR(255) DEFAULT NULL,
    fabrication_team_lead_id INT UNSIGNED DEFAULT NULL,
    fabrication_alloc_pct DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentage override',
    fabrication_alloc_basis ENUM('expenses','labor_materials','contract','fixed','manual') DEFAULT 'expenses',
    fabrication_alloc_fixed DECIMAL(18,2) DEFAULT NULL,
    status ENUM('draft','approved','in_progress','on_hold','completed','cancelled','closed') NOT NULL DEFAULT 'draft',
    budget_warning_pct DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    notes TEXT DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_proj_tenant (tenant_id),
    INDEX idx_pal_proj_status (status),
    INDEX idx_pal_proj_client (client_id),
    INDEX idx_pal_proj_type (project_type_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES pal_clients(id) ON DELETE SET NULL,
    FOREIGN KEY (project_type_id) REFERENCES pal_project_types(id) ON DELETE SET NULL,
    FOREIGN KEY (fabrication_team_lead_id) REFERENCES pal_team_leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_project_types`

```sql
CREATE TABLE pal_project_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_pt_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_clients`

```sql
CREATE TABLE pal_clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_cli_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_suppliers`

```sql
CREATE TABLE pal_suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    payment_terms VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_sup_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_materials`

```sql
CREATE TABLE pal_materials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    description TEXT DEFAULT NULL,
    unit_id INT UNSIGNED DEFAULT NULL,
    current_avg_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    reorder_level DECIMAL(18,2) DEFAULT NULL,
    preferred_supplier_id INT UNSIGNED DEFAULT NULL,
    storage_location VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_trackable TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_mat_tenant (tenant_id),
    INDEX idx_pal_mat_category (category_id),
    INDEX idx_pal_mat_supplier (preferred_supplier_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES pal_material_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES pal_units(id) ON DELETE SET NULL,
    FOREIGN KEY (preferred_supplier_id) REFERENCES pal_suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_material_categories`

```sql
CREATE TABLE pal_material_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_mcat_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_units`

```sql
CREATE TABLE pal_units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL COMMENT 'e.g., Piece, Roll, Meter',
    abbreviation VARCHAR(10) DEFAULT NULL,
    INDEX idx_pal_unit_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_inventory_locations`

```sql
CREATE TABLE pal_inventory_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_iloc_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_inventory_movements`

```sql
CREATE TABLE pal_inventory_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    movement_type ENUM(
        'stock_in','issuance','return','wastage','damage',
        'transfer_out','transfer_in','adjustment_up','adjustment_down',
        'initial_balance','reversal'
    ) NOT NULL,
    reference_type VARCHAR(50) DEFAULT NULL COMMENT 'e.g., purchase, issuance, return',
    reference_id INT UNSIGNED DEFAULT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    location_id INT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    batch_number VARCHAR(100) DEFAULT NULL,
    movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status ENUM('pending','approved','reversed') NOT NULL DEFAULT 'approved',
    reversal_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_im_mat (material_id),
    INDEX idx_pal_im_type (movement_type),
    INDEX idx_pal_im_project (project_id),
    INDEX idx_pal_im_date (movement_date),
    INDEX idx_pal_im_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES pal_materials(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES pal_inventory_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_inventory_balances` (snapshot/cache table)

```sql
CREATE TABLE pal_inventory_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    avg_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_ib_mat_loc (material_id, location_id),
    INDEX idx_pal_ib_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES pal_materials(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES pal_inventory_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_purchases`

```sql
CREATE TABLE pal_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    purchase_number VARCHAR(50) NOT NULL,
    supplier_id INT UNSIGNED DEFAULT NULL,
    purchase_date DATE NOT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    receipt_number VARCHAR(100) DEFAULT NULL,
    po_reference VARCHAR(100) DEFAULT NULL,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    freight_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    notes TEXT DEFAULT NULL,
    status ENUM('draft','submitted','approved','rejected','voided') NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_pur_tenant (tenant_id),
    INDEX idx_pal_pur_supplier (supplier_id),
    INDEX idx_pal_pur_status (status),
    INDEX idx_pal_pur_date (purchase_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES pal_suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_purchase_items`

```sql
CREATE TABLE pal_purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    quantity DECIMAL(18,4) NOT NULL,
    unit_id INT UNSIGNED DEFAULT NULL,
    unit_cost DECIMAL(18,2) NOT NULL,
    total_cost DECIMAL(18,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    batch_number VARCHAR(100) DEFAULT NULL,
    storage_location_id INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_pi_purchase (purchase_id),
    INDEX idx_pal_pi_material (material_id),
    FOREIGN KEY (purchase_id) REFERENCES pal_purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES pal_materials(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES pal_units(id) ON DELETE SET NULL,
    FOREIGN KEY (storage_location_id) REFERENCES pal_inventory_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_material_issuances`

```sql
CREATE TABLE pal_material_issuances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    issuance_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    issuance_date DATE NOT NULL,
    requested_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    released_by INT UNSIGNED DEFAULT NULL,
    received_by VARCHAR(255) DEFAULT NULL,
    purpose TEXT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','requested','approved','partially_issued','fully_issued','rejected','cancelled') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_mi_tenant (tenant_id),
    INDEX idx_pal_mi_project (project_id),
    INDEX idx_pal_mi_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_material_issuance_items`

```sql
CREATE TABLE pal_material_issuance_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issuance_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    requested_qty DECIMAL(18,4) NOT NULL,
    approved_qty DECIMAL(18,4) DEFAULT NULL,
    issued_qty DECIMAL(18,4) DEFAULT NULL,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(18,2) GENERATED ALWAYS AS (COALESCE(issued_qty, 0) * unit_cost) STORED,
    INDEX idx_pal_mii_issuance (issuance_id),
    INDEX idx_pal_mii_material (material_id),
    FOREIGN KEY (issuance_id) REFERENCES pal_material_issuances(id) ON DELETE CASCADE,
    FOREIGN KEY (material_id) REFERENCES pal_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_material_returns`

```sql
CREATE TABLE pal_material_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    issuance_id INT UNSIGNED DEFAULT NULL,
    material_id INT UNSIGNED NOT NULL,
    quantity_returned DECIMAL(18,4) NOT NULL,
    condition ENUM('reusable','damaged','wasted','scrap') NOT NULL DEFAULT 'reusable',
    reason VARCHAR(255) DEFAULT NULL,
    return_date DATE NOT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_mr_tenant (tenant_id),
    INDEX idx_pal_mr_project (project_id),
    INDEX idx_pal_mr_issuance (issuance_id),
    INDEX idx_pal_mr_material (material_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (issuance_id) REFERENCES pal_material_issuances(id) ON DELETE SET NULL,
    FOREIGN KEY (material_id) REFERENCES pal_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_expenses`

```sql
CREATE TABLE pal_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    expense_number VARCHAR(50) NOT NULL,
    expense_date DATE NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = general operating expense',
    category_id INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    payee VARCHAR(255) DEFAULT NULL,
    supplier_id INT UNSIGNED DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','submitted','pending_approval','approved','rejected','returned','voided','reversed') NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    reversal_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_exp_tenant (tenant_id),
    INDEX idx_pal_exp_project (project_id),
    INDEX idx_pal_exp_category (category_id),
    INDEX idx_pal_exp_status (status),
    INDEX idx_pal_exp_date (expense_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES pal_expense_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES pal_suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_expense_categories`

```sql
CREATE TABLE pal_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_project_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_pal_ec_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_sales`

```sql
CREATE TABLE pal_sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    sales_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    sales_date DATE NOT NULL,
    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(18,2) GENERATED ALWAYS AS (gross_amount - discount_amount + tax_amount) STORED,
    due_date DATE DEFAULT NULL,
    payment_terms VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft','issued','partially_paid','paid','overdue','cancelled','voided') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_sal_tenant (tenant_id),
    INDEX idx_pal_sal_project (project_id),
    INDEX idx_pal_sal_client (client_id),
    INDEX idx_pal_sal_status (status),
    INDEX idx_pal_sal_date (sales_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES pal_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_collections`

```sql
CREATE TABLE pal_collections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    collection_number VARCHAR(50) NOT NULL,
    sales_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    status ENUM('pending','approved','rejected','voided') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_coll_tenant (tenant_id),
    INDEX idx_pal_coll_sales (sales_id),
    INDEX idx_pal_coll_project (project_id),
    INDEX idx_pal_coll_client (client_id),
    INDEX idx_pal_coll_date (payment_date),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (sales_id) REFERENCES pal_sales(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE SET NULL,
    FOREIGN KEY (client_id) REFERENCES pal_clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_fabrication_allocations`

```sql
CREATE TABLE pal_fabrication_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    alloc_basis ENUM('expenses','labor_materials','contract','fixed','manual') NOT NULL DEFAULT 'expenses',
    alloc_percentage DECIMAL(5,2) DEFAULT NULL,
    base_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    calculated_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    approved_amount DECIMAL(18,2) DEFAULT NULL COMMENT 'May differ from calculated',
    approval_reason VARCHAR(255) DEFAULT NULL COMMENT 'Required if approved differs from calculated',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    status ENUM('draft','approved','adjusted') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_fa_project (project_id),
    INDEX idx_pal_fa_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_fabrication_weekly_dues`

```sql
CREATE TABLE pal_fabrication_weekly_dues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    allocation_id INT UNSIGNED NOT NULL,
    week_number INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    due_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(18,2) GENERATED ALWAYS AS (due_amount - paid_amount) STORED,
    due_date DATE DEFAULT NULL,
    status ENUM('not_due','pending','partial','paid','overdue','waived','adjusted') NOT NULL DEFAULT 'not_due',
    notes TEXT DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pal_fwd_tenant (tenant_id),
    INDEX idx_pal_fwd_project (project_id),
    INDEX idx_pal_fwd_alloc (allocation_id),
    INDEX idx_pal_fwd_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (allocation_id) REFERENCES pal_fabrication_allocations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_fabrication_payments`

```sql
CREATE TABLE pal_fabrication_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    payment_number VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    weekly_due_id INT UNSIGNED DEFAULT NULL,
    team_lead_id INT UNSIGNED DEFAULT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT NULL,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected','voided') NOT NULL DEFAULT 'pending',
    submitted_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_fp_tenant (tenant_id),
    INDEX idx_pal_fp_project (project_id),
    INDEX idx_pal_fp_due (weekly_due_id),
    INDEX idx_pal_fp_status (status),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES pal_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (weekly_due_id) REFERENCES pal_fabrication_weekly_dues(id) ON DELETE SET NULL,
    FOREIGN KEY (team_lead_id) REFERENCES pal_team_leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_team_leads`

```sql
CREATE TABLE pal_team_leads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    INDEX idx_pal_tl_tenant (tenant_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_approvals`

```sql
CREATE TABLE pal_approvals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'e.g., expense, purchase, issuance, collection, fabrication_payment',
    entity_id INT UNSIGNED NOT NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewer_id INT UNSIGNED DEFAULT NULL,
    decision ENUM('pending','approved','rejected','returned','withdrawn','escalated') NOT NULL DEFAULT 'pending',
    decision_date DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    previous_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) DEFAULT NULL,
    escalation_level INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_app_tenant (tenant_id),
    INDEX idx_pal_app_entity (entity_type, entity_id),
    INDEX idx_pal_app_decision (decision),
    INDEX idx_pal_app_submitted (submitted_by),
    INDEX idx_pal_app_reviewer (reviewer_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_attachments`

```sql
CREATE TABLE pal_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(500) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    uploaded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_att_tenant (tenant_id),
    INDEX idx_pal_att_entity (entity_type, entity_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_audit_logs`

```sql
CREATE TABLE pal_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id VARCHAR(50) DEFAULT NULL,
    old_data JSON DEFAULT NULL,
    new_data JSON DEFAULT NULL,
    metadata_json JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_al_tenant (tenant_id),
    INDEX idx_pal_al_actor (actor_user_id),
    INDEX idx_pal_al_action (action),
    INDEX idx_pal_al_entity (entity_type, entity_id),
    INDEX idx_pal_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_report_exports`

```sql
CREATE TABLE pal_report_exports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    format ENUM('pdf','excel','html') NOT NULL,
    filters_json JSON DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    file_size INT UNSIGNED DEFAULT NULL,
    generated_by INT UNSIGNED DEFAULT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    INDEX idx_pal_re_tenant (tenant_id),
    INDEX idx_pal_re_type (report_type),
    INDEX idx_pal_re_generated (generated_at),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `pal_settings`

```sql
CREATE TABLE pal_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    UNIQUE KEY uq_pal_sett_tenant_key (tenant_id, setting_key),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 Users Table (auth-owned)

```sql
CREATE TABLE pal_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin','supervisor','encoder') NOT NULL DEFAULT 'encoder',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    token_version INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_user_username (tenant_id, username),
    INDEX idx_pal_user_tenant (tenant_id),
    INDEX idx_pal_user_role (role),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Entity Relationships

```
pal_clients ──1:N── pal_projects
pal_project_types ──1:N── pal_projects
pal_team_leads ──1:N── pal_projects

pal_projects ──1:N── pal_expenses
pal_projects ──1:N── pal_sales
pal_projects ──1:N── pal_material_issuances
pal_projects ──1:N── pal_material_returns
pal_projects ──1:1── pal_fabrication_allocations
pal_projects ──1:N── pal_fabrication_weekly_dues
pal_projects ──1:N── pal_fabrication_payments

pal_sales ──1:N── pal_collections

pal_materials ──1:N── pal_inventory_movements
pal_materials ──1:N── pal_inventory_balances
pal_materials ──1:N── pal_purchase_items
pal_materials ──1:N── pal_material_issuance_items
pal_materials ──1:N── pal_material_returns

pal_suppliers ──1:N── pal_purchases
pal_purchases ──1:N── pal_purchase_items

pal_expense_categories ──1:N── pal_expenses
pal_material_categories ──1:N── pal_materials
pal_units ──1:N── pal_materials / pal_purchase_items
pal_inventory_locations ──1:N── pal_inventory_movements / pal_inventory_balances

pal_fabrication_allocations ──1:N── pal_fabrication_weekly_dues
pal_fabrication_weekly_dues ──1:N── pal_fabrication_payments

pal_approvals ── polymorphic ── any entity (entity_type + entity_id)
pal_attachments ── polymorphic ── any entity
pal_audit_logs ── standalone ── all entities
```

---

## 5. Capability Matrix

### 5.1 Exposed Capabilities

| Capability ID | Description | Priority | Mode |
|---|---|---|---|
| `kernel.auth.authenticate@1` | Auth login (pipeline with kernel) | 550 | pipeline |
| `pal.read@1` | Basic read access (dashboard, list views) | 50 | first |
| `pal.manage@1` | Full management access | 50 | first |
| `pal.projects.read@1` | View project details | 50 | first |
| `pal.projects.write@1` | Create/update projects | 50 | first |
| `pal.expenses.read@1` | View expenses | 50 | first |
| `pal.expenses.write@1` | Create/update expenses | 50 | first |
| `pal.inventory.read@1` | View inventory | 50 | first |
| `pal.inventory.write@1` | Create inventory movements | 50 | first |
| `pal.purchases.read@1` | View purchases | 50 | first |
| `pal.purchases.write@1` | Create/approve purchases | 50 | first |
| `pal.sales.read@1` | View sales | 50 | first |
| `pal.sales.write@1` | Create sales records | 50 | first |
| `pal.collections.read@1` | View collections | 50 | first |
| `pal.collections.write@1` | Record collections | 50 | first |
| `pal.fabrication.read@1` | View fabrication data | 50 | first |
| `pal.fabrication.write@1` | Manage fabrication allocations/payments | 50 | first |
| `pal.approvals.read@1` | View approval queue | 50 | first |
| `pal.approvals.write@1` | Approve/reject entries | 50 | first |
| `pal.reports.read@1` | View and export reports | 50 | first |
| `pal.audit.read@1` | View audit trail | 50 | first |
| `pal.settings.read@1` | View settings | 50 | first |
| `pal.settings.write@1` | Manage settings | 50 | first |
| `pal.users.manage@1` | Manage module users | 50 | first |
| `entity.list.pal_project@1` | List projects (entity view contract) | 50 | first |
| `entity.get.pal_project@1` | Get single project (entity view contract) | 50 | first |
| `entity.list.pal_material@1` | List materials (entity view contract) | 50 | first |
| `entity.get.pal_material@1` | Get single material (entity view contract) | 50 | first |

### 5.2 Dependencies

| Capability ID | Source |
|---|---|
| `kernel.audit.record@1` | Kernel |
| `kernel.auth.user@1` | Kernel |
| `media.file.store@1` | Media module |
| `media.file.retrieve@1` | Media module |

### 5.3 Role-to-Capability Mapping

| Role | Capabilities |
|---|---|
| admin | All `pal.*` capabilities |
| supervisor | `pal.read`, `pal.projects.read`, `pal.expenses.read`, `pal.inventory.read`, `pal.purchases.read`, `pal.sales.read`, `pal.collections.read`, `pal.fabrication.read`, `pal.approvals.*`, `pal.reports.read`, `pal.audit.read` |
| encoder | `pal.projects.read`, `pal.projects.write` (create only), `pal.expenses.*`, `pal.inventory.read`, `pal.purchases.*`, `pal.sales.*`, `pal.collections.*`, `pal.fabrication.read` |

---

## 6. Route List

### 6.1 Auth Routes (Public)

| Method | Path | Handler | Description |
|---|---|---|---|
| GET | `/project-audit-ledger/login` | `pal:pageLogin` | Login page |
| POST | `/project-audit-ledger/auth/login` | `pal:authLogin` | Login action |
| POST | `/project-audit-ledger/logout` | `pal:authLogout` | Logout |
| GET | `/project-audit-ledger/forgot-password` | `pal:pageForgotPassword` | Forgot password |
| POST | `/project-audit-ledger/auth/forgot-password` | `pal:authForgotPassword` | Send reset email |
| GET | `/project-audit-ledger/reset-password` | `pal:pageResetPassword` | Reset password form |
| POST | `/project-audit-ledger/auth/reset-password` | `pal:authResetPassword` | Reset password action |

### 6.2 Admin Pages (Require Auth)

| Method | Path | Handler | Description |
|---|---|---|---|
| GET | `/admin/project-audit-ledger` | `pal:pageDashboard` | Dashboard |
| GET | `/admin/project-audit-ledger/projects` | `pal:pageProjectList` | Project list |
| GET | `/admin/project-audit-ledger/projects/create` | `pal:pageProjectForm` | Create project |
| GET | `/admin/project-audit-ledger/projects/{id}/edit` | `pal:pageProjectForm` | Edit project |
| GET | `/admin/project-audit-ledger/projects/{id}` | `pal:pageProjectDetail` | Project detail/cost ledger |
| GET | `/admin/project-audit-ledger/clients` | `pal:pageClientList` | Client list |
| GET | `/admin/project-audit-ledger/clients/create` | `pal:pageClientForm` | Create client |
| GET | `/admin/project-audit-ledger/clients/{id}/edit` | `pal:pageClientForm` | Edit client |
| GET | `/admin/project-audit-ledger/suppliers` | `pal:pageSupplierList` | Supplier list |
| GET | `/admin/project-audit-ledger/suppliers/create` | `pal:pageSupplierForm` | Create supplier |
| GET | `/admin/project-audit-ledger/expenses` | `pal:pageExpenseList` | Expense list |
| GET | `/admin/project-audit-ledger/expenses/create` | `pal:pageExpenseForm` | Create expense |
| GET | `/admin/project-audit-ledger/expenses/{id}` | `pal:pageExpenseDetail` | Expense detail |
| GET | `/admin/project-audit-ledger/purchases` | `pal:pagePurchaseList` | Purchase list |
| GET | `/admin/project-audit-ledger/purchases/create` | `pal:pagePurchaseForm` | Create purchase |
| GET | `/admin/project-audit-ledger/inventory` | `pal:pageInventoryList` | Material list |
| GET | `/admin/project-audit-ledger/inventory/{id}` | `pal:pageInventoryDetail` | Material detail & movements |
| GET | `/admin/project-audit-ledger/inventory/movements` | `pal:pageMovementList` | All inventory movements |
| GET | `/admin/project-audit-ledger/material-issuance` | `pal:pageIssuanceList` | Material issuance list |
| GET | `/admin/project-audit-ledger/material-issuance/create` | `pal:pageIssuanceForm` | Create issuance |
| GET | `/admin/project-audit-ledger/fabrication` | `pal:pageFabricationAllocation` | Fabrication allocations |
| GET | `/admin/project-audit-ledger/fabrication/{projectId}/dues` | `pal:pageFabricationDues` | Weekly dues |
| GET | `/admin/project-audit-ledger/fabrication/payments/create` | `pal:pageFabricationPaymentForm` | Record payment |
| GET | `/admin/project-audit-ledger/sales` | `pal:pageSalesList` | Sales list |
| GET | `/admin/project-audit-ledger/sales/create` | `pal:pageSalesForm` | Create sale |
| GET | `/admin/project-audit-ledger/collections` | `pal:pageCollectionList` | Collection list |
| GET | `/admin/project-audit-ledger/collections/create` | `pal:pageCollectionForm` | Create collection |
| GET | `/admin/project-audit-ledger/approvals` | `pal:pageApprovalQueue` | Approval queue |
| GET | `/admin/project-audit-ledger/reports` | `pal:pageReportsCenter` | Reports center |
| GET | `/admin/project-audit-ledger/audit` | `pal:pageAuditTrail` | Audit trail |
| GET | `/admin/project-audit-ledger/settings` | `pal:pageSettings` | Settings |
| GET | `/admin/project-audit-ledger/users` | `pal:pageUserList` | User management |

### 6.3 API Routes

| Method | Path | Handler | Description |
|---|---|---|---|
| GET | `/api/v1/project-audit-ledger/dashboard` | `pal:apiDashboard` | Dashboard data |
| GET | `/api/v1/project-audit-ledger/projects` | `pal:apiProjectList` | Project list data |
| POST | `/api/v1/project-audit-ledger/projects` | `pal:apiProjectStore` | Create project |
| POST | `/api/v1/project-audit-ledger/projects/{id}` | `pal:apiProjectUpdate` | Update project |
| POST | `/api/v1/project-audit-ledger/projects/{id}/status` | `pal:apiProjectStatus` | Change project status |
| GET | `/api/v1/project-audit-ledger/projects/{id}/cost` | `pal:apiProjectCost` | Project cost breakdown |
| GET | `/api/v1/project-audit-ledger/clients` | `pal:apiClientList` | Client list |
| POST | `/api/v1/project-audit-ledger/clients` | `pal:apiClientStore` | Create client |
| POST | `/api/v1/project-audit-ledger/clients/{id}` | `pal:apiClientUpdate` | Update client |
| GET | `/api/v1/project-audit-ledger/suppliers` | `pal:apiSupplierList` | Supplier list |
| POST | `/api/v1/project-audit-ledger/suppliers` | `pal:apiSupplierStore` | Create supplier |
| POST | `/api/v1/project-audit-ledger/expenses` | `pal:apiExpenseStore` | Create expense |
| GET | `/api/v1/project-audit-ledger/expenses` | `pal:apiExpenseList` | Expense list |
| POST | `/api/v1/project-audit-ledger/expenses/{id}/submit` | `pal:apiExpenseSubmit` | Submit for approval |
| GET | `/api/v1/project-audit-ledger/purchases` | `pal:apiPurchaseList` | Purchase list |
| POST | `/api/v1/project-audit-ledger/purchases` | `pal:apiPurchaseStore` | Create purchase |
| GET | `/api/v1/project-audit-ledger/materials` | `pal:apiMaterialList` | Material list |
| POST | `/api/v1/project-audit-ledger/materials` | `pal:apiMaterialStore` | Create material |
| GET | `/api/v1/project-audit-ledger/materials/{id}/movements` | `pal:apiMaterialMovements` | Material movements |
| POST | `/api/v1/project-audit-ledger/inventory/adjust` | `pal:apiInventoryAdjust` | Adjust inventory |
| GET | `/api/v1/project-audit-ledger/issuances` | `pal:apiIssuanceList` | Issuance list |
| POST | `/api/v1/project-audit-ledger/issuances` | `pal:apiIssuanceStore` | Create issuance |
| POST | `/api/v1/project-audit-ledger/issuances/{id}/approve` | `pal:apiIssuanceApprove` | Approve issuance |
| POST | `/api/v1/project-audit-ledger/materials/return` | `pal:apiMaterialReturn` | Return material |
| GET | `/api/v1/project-audit-ledger/fabrication/allocations` | `pal:apiFabricationAllocations` | List allocations |
| POST | `/api/v1/project-audit-ledger/fabrication/allocations` | `pal:apiFabricationAllocationStore` | Create/update allocation |
| GET | `/api/v1/project-audit-ledger/fabrication/dues/{projectId}` | `pal:apiFabricationDues` | Weekly dues for project |
| POST | `/api/v1/project-audit-ledger/fabrication/dues` | `pal:apiFabricationDueStore` | Create/update dues schedule |
| POST | `/api/v1/project-audit-ledger/fabrication/payments` | `pal:apiFabricationPaymentStore` | Record payment |
| GET | `/api/v1/project-audit-ledger/sales` | `pal:apiSalesList` | Sales list |
| POST | `/api/v1/project-audit-ledger/sales` | `pal:apiSalesStore` | Create sale |
| GET | `/api/v1/project-audit-ledger/sales/{id}/collections` | `pal:apiCollectionList` | Collections for sale |
| POST | `/api/v1/project-audit-ledger/collections` | `pal:apiCollectionStore` | Create collection |
| GET | `/api/v1/project-audit-ledger/approvals` | `pal:apiApprovalQueue` | Pending approvals |
| POST | `/api/v1/project-audit-ledger/approvals/{id}/decide` | `pal:apiApprovalDecide` | Approve/reject |
| GET | `/api/v1/project-audit-ledger/reports` | `pal:apiReportList` | Available reports |
| POST | `/api/v1/project-audit-ledger/reports/generate` | `pal:apiReportGenerate` | Generate report |
| GET | `/api/v1/project-audit-ledger/reports/{id}/download` | `pal:apiReportDownload` | Download report |
| GET | `/api/v1/project-audit-ledger/audit` | `pal:apiAuditList` | Audit log list |
| GET | `/api/v1/project-audit-ledger/settings` | `pal:apiSettingsGet` | Get settings |
| POST | `/api/v1/project-audit-ledger/settings` | `pal:apiSettingsSave` | Save settings |
| GET | `/api/v1/project-audit-ledger/users` | `pal:apiUserList` | User list |
| POST | `/api/v1/project-audit-ledger/users` | `pal:apiUserStore` | Create user |
| POST | `/api/v1/project-audit-ledger/users/{id}` | `pal:apiUserUpdate` | Update user |
| POST | `/api/v1/project-audit-ledger/users/{id}/delete` | `pal:apiUserDelete` | Delete user |
| POST | `/api/v1/project-audit-ledger/attachments/upload` | `pal:apiAttachmentUpload` | Upload attachment |
| GET | `/api/v1/project-audit-ledger/attachments/{id}/download` | `pal:apiAttachmentDownload` | Download attachment |

---

## 7. Page/View List

| # | Page | Description | Roles |
|---|---|---|---|
| 1 | Dashboard | Business status summary, KPIs, alerts | admin, supervisor, encoder* |
| 2 | Project List | Searchable/filterable list of projects | all |
| 3 | Create/Edit Project | Project form with all fields | admin, encoder |
| 4 | Project Detail | Cost ledger, expenses, dues, attached items | all |
| 5 | Client List | Searchable client list | admin, encoder |
| 6 | Client Form | Create/edit client | admin, encoder |
| 7 | Supplier List | Supplier directory | admin, encoder |
| 8 | Supplier Form | Create/edit supplier | admin, encoder |
| 9 | Expense List | Filterable expense list by project/status | all |
| 10 | Expense Form | Create/edit expense with attachment upload | admin, encoder |
| 11 | Expense Detail | Full expense view with approval chain | all |
| 12 | Purchase List | Purchase order list | all |
| 13 | Purchase Form | Create purchase with line items | admin, encoder |
| 14 | Inventory List | Material master list with stock levels | all |
| 15 | Material Detail | Material info with movement history | all |
| 16 | Movement List | All inventory movements filterable | admin, supervisor |
| 17 | Issuance List | Material issuance requests | all |
| 18 | Issuance Form | Create issuance request | encoder |
| 19 | Fabrication Allocations | All projects with fabrication allocation status | admin, supervisor |
| 20 | Weekly Dues | Weekly due schedule for a project | admin, supervisor |
| 21 | Fabrication Payment Form | Record payment to team lead | admin, encoder |
| 22 | Sales List | Invoice list | all |
| 23 | Sales Form | Create sales invoice | admin, encoder |
| 24 | Collection List | Payment collections list | all |
| 25 | Collection Form | Record payment received | admin, encoder |
| 26 | Approval Queue | Pending approvals with actions | admin, supervisor |
| 27 | Reports Center | Report selection, filters, generation | admin, supervisor |
| 28 | Audit Trail | Append-only action log | admin |
| 29 | User Management | Module user CRUD, role assignment | admin |
| 30 | Settings | Module configuration | admin |

*Encoder sees limited dashboard (assigned tasks, drafts, pending submissions)

---

## 8. Rendering Architecture & Entity View Strategy

This module uses the **Kernel OS Entity View system** (v1.1.0+, shipped in DiSyL 4.7 / Kernel OS 6.0) as the primary rendering engine. The pattern is **composite templates with embedded entity views** — not a split between entity views and custom templates.

### 8.1 Core Pattern

```
┌──────────────────────────────────────────────────┐
│              Composite DiSyL Template             │
│  (custom .disyl — page layout, sections, KPI     │
│   cards, tabs, filter forms, computed values)     │
├──────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌───────────────────────┐  │
│  │  {ikb_entity_list}  │  │  {ikb_entity_detail}   │  │
│  │  source="pal_..."   │  │  source="pal_..." id=X │  │
│  │  view="table"       │  │  view="detailed"       │  │
│  └─────────────────┘  └───────────────────────┘  │
│  Delegates to: EntityViewResolver → CapabilityBus │
├──────────────────────────────────────────────────┤
│        Handler fetches aggregate/KPI data         │
│        (totals, counts, computed metrics)          │
└──────────────────────────────────────────────────┘
```

### 8.2 Page Rendering Classification

Every page falls into one of three tiers:

| Tier | Approach | Pages |
|---|---|---|
| **Entity list** | `{ikb_entity_list}` alone — source + view declares everything | Projects list, clients list, suppliers list, expenses list, purchases list, materials list, issuances list, sales list, collections list, fabrication dues list, audit trail |
| **Entity detail** | `{ikb_entity_detail}` alone | Single expense detail, single material detail, single project summary |
| **Composite page** | Custom template with handler-fetched KPIs + embedded `{ikb_entity_list}`/`{ikb_entity_detail}` per section | Dashboard, project cost ledger, approval queue, reports center, settings |

### 8.3 Entity View Contracts to Register

Each business entity gets a `entity.list.{name}@1` and `entity.get.{name}@1` capability handler, view contracts via PHP `registerView()` or DiSyL `{ikb_entity_view}`, and builtinDefaults fallback.

| Entity | Source | Views | Renderers Needed |
|---|---|---|---|
| `pal_project` | `pal_projects` + `pal_clients` | `table`, `compact`, `detailed` | `status`→badge, `contract_amount`→money, `start_date`→datetime |
| `pal_expense` | `pal_expenses` + `pal_expense_categories` | `table`, `compact` | `amount`→money, `status`→badge, `expense_date`→datetime |
| `pal_purchase` | `pal_purchases` + `pal_suppliers` | `table` | `total_amount`→money, `status`→badge |
| `pal_material` | `pal_materials` + `pal_inventory_balances` | `table`, `card_grid` | `current_avg_cost`→money, `quantity`→number, `is_active`→boolean |
| `pal_issuance` | `pal_material_issuances` + `pal_projects` | `table` | `status`→badge, `issuance_date`→datetime |
| `pal_sale` | `pal_sales` + `pal_clients` | `table` | `net_amount`→money, `status`→badge |
| `pal_collection` | `pal_collections` + `pal_sales` | `table` | `amount`→money, `payment_date`→datetime |
| `pal_fabrication_due` | `pal_fabrication_weekly_dues` + `pal_projects` | `table` | `due_amount`→money, `paid_amount`→money, `balance`→money, `status`→badge |
| `pal_audit_log` | `pal_audit_logs` | `table` | `created_at`→datetime |

### 8.4 Composite Pages — Pattern Reference

These pages use a custom DiSyL template (the composite) with entity views embedded inside. The **only** reference pattern needed is the `attendance-wage` dashboard:

```disyl
{# Dashboard — composite template #}
<div class="grid grid-cols-4 gap-5 mb-10">
    {call statCard("Active Projects", active_count)}
    {call statCard("Total Contract Value", "₱{total_contract}")}
    {call statCard("Pending Approvals", pending_count)}
    {call statCard("Est. Profit", "₱{est_profit}")}
</div>

<div class="mb-10">
    <h2>Project Cost Summary</h2>
    {ikb_entity_list source="pal_project.active" view="compact" limit="5" /}
</div>

<div class="mb-10">
    <h2>Recent Expenses</h2>
    {ikb_entity_list source="pal_expense.recent" view="table" limit="10" /}
</div>
```

**No duplication of list/detail rendering.** Entity views handle the data-fetch-and-render loop. The composite template handles layout, KPI cards, and section arrangement.

### 8.5 Composite Pages and Their Data Sources

| Composite Page | Handler Fetches | Entity Views Embedded |
|---|---|---|
| **Dashboard** | KPI counts (active projects, total contract value, pending approvals, est. profit, low-stock count) | `pal_project.active` (compact), `pal_expense.recent` (table), `pal_fabrication_due.overdue` (table) |
| **Project Cost Ledger** | Project header + computed totals (total cost, remaining budget, profit, margin) | `pal_expense.by_project` (table), `pal_issuance.by_project` (table), `pal_fabrication_due.by_project` (table), `pal_sale.by_project` (table), `pal_collection.by_project` (table) |
| **Approval Queue** | Pending entity summary, approval history | `pal_expense.pending` (table), `pal_purchase.pending` (table), `pal_issuance.pending` (table) + approval form |
| **Reports Center** | Report generation status, available formats | Filter form + `{ikb_export_button}` + download list |

### 8.6 Entity View Limitations — Why Composite Templates Are Needed

Entity views are designed for **single-source data display**: one capability call → one set of rows → one view contract. They cannot handle:

| Gap | Why Entity Views Can't Do It | Workaround |
|---|---|---|
| **Multi-source aggregation** | Entity views call ONE capability (`entity.list.X`). A dashboard needs data from 5+ tables. | Handler fetches aggregate KPIs; template arranges multiple `{ikb_entity_list}` calls, one per source |
| **Computed/cross-entity metrics** | Entity views return raw row data. Profit margin = (sales − costs) / sales requires joining project + expenses + sales data at calculation time, not display time. | `ProjectCostService` computes metrics; handler passes to template as variables |
| **Tabbed/tabular page layout** | No `{ikb_tabs}` component in the registry yet. Tabs require Alpine.js state management. | Alpine.js `x-data="{ tab: 'expenses' }"` with `x-show` wrapping entity views |
| **Forms with validation + multi-field filters** | Entity views support basic search, pagination, sort. Report filters (date range + project + client + status) need a form with validation before data fetch. | Custom form template POSTing to an API that returns entity-view-compatible data, or passing `filters` param to entity view |
| **Charts / visualizations** | No chart component in the registry. Chart.js or similar would need Alpine.js integration. | Alpine.js loading Chart.js in a dedicated section; data passed from handler as JSON |
| **Approval decision embedded in context** | Approval needs to show: the entity being approved + approval history + decision form (approve/reject/return fields). Entity views show one list or one detail — not a composite of three data sources with different layouts. | Custom template with `{ikb_entity_detail}` for the target entity + separate approval history section + inline form |
| **Row-click → related detail panel** | Entity views have `row-click` URL navigation, but no side-panel/drawer drill-down without a page load. | `{ikb_entity_list row-click="/detail/{id}"}` navigates to a new page; `{ikb_drawer}` could be used via Alpine.js |

**None of these gaps block implementation.** Every gap has a proven workaround — the `attendance-wage` dashboard already uses composite templates with embedded entity views. The gaps represent features that could be added to the entity view system in future kernel releases (e.g., an `{ikb_composite_page}` component, chart components, tab components). For now, the composite template pattern is the correct approach.

### 8.7 Entity View Contract Registration

View contracts are registered in the module's `helpers/views/` directory via `{ikb_entity_view}` DiSyL config files, loaded by `TemplateEngine::loadViewConfigs()`. See §17 (Phase 1) for the bootstrap call.

Example — `helpers/views/pal_project.disyl`:

```disyl
{ikb_entity_view name="pal_project" view="table"}
    {field name="project_id" type="string"}
    {field name="title" type="string"}
    {field name="client_name" type="string"}
    {field name="contract_amount" type="number" renderer="money:2"}
    {field name="status" type="enum" renderer="badge:{draft|gray|approved|blue|in_progress|green|completed|green|cancelled|red}"}
    {field name="start_date" type="date" renderer="datetime:date"}
    {action name="view" url="/admin/project-audit-ledger/projects/{id}" label="View"}
    {action name="edit" url="/admin/project-audit-ledger/projects/{id}/edit" label="Edit"}
{/ikb_entity_view}
```

---

## 9. Service Responsibilities

### 9.1 `ProjectService`
- CRUD projects
- Status transitions (draft → approved → in_progress → completed → closed)
- Client and type assignment
- Validate date ranges
- Handle project attachments

### 9.2 `ProjectCostService`
- Calculate total project cost (sum of approved expenses + material issuance costs)
- Calculate remaining budget (`contract_amount - total_cost`)
- Calculate estimated profit (`net_sales - total_cost`)
- Calculate profit margin (`profit / net_sales * 100`, safe division by zero)
- Budget threshold checks (normal/near/over)
- Cost breakdown by category

### 9.3 `ExpenseService`
- CRUD expenses (tenants/users/status aware)
- Submit for approval
- Status transitions (draft → submitted → pending_approval → approved/rejected/returned)
- Void/reverse approved expenses
- Validate project assignment
- Enforce immutability after approval

### 9.4 `PurchaseService`
- Create purchases with line items
- Status transitions (draft → submitted → approved → voided)
- On approval: trigger stock-in movements + update average cost
- Validate supplier and materials

### 9.5 `InventoryService`
- Material master CRUD
- Stock inquiry (current balance by material/location)
- Low-stock detection (reorder level)
- Average cost calculation

### 9.6 `InventoryMovementService`
- Record all movement types (stock_in, issuance, return, wastage, damage, transfer, adjustment, reversal)
- Update inventory balances cache
- Validate movement quantity constraints
- Handle reversals (create inverse movement, link via `reversal_id`)

### 9.7 `MaterialIssuanceService`
- Create issuance requests
- Status transitions (draft → requested → approved → partially_issued → fully_issued)
- On final issuance: create stock-out movement, assign cost to project
- Validate stock availability before issuance

### 9.8 `MaterialReturnService`
- Record returns against issuances
- Categorize by condition (reusable → back to stock, damaged/wasted → project cost)
- Create stock-in movement for reusable items
- Update project material costs

### 9.9 `FabricationAllocationService`
- Calculate allocation based on configurable basis and percentage
- Support per-project override
- Track calculated vs approved amounts
- Require reason+approval when override differs from calculation

### 9.10 `FabricationDueService`
- Generate weekly due schedule from allocation
- Distribute allocation across project duration (equal or uneven)
- Track paid amounts, carry forward balances
- Support manual adjustments with approval

### 9.11 `FabricationPaymentService`
- Record payments against weekly dues
- Validate payment does not exceed allocation without adjustment
- Status transitions (pending → approved → voided)
- Track cumulative paid vs allocation

### 9.12 `SalesService`
- Create sales records linked to projects
- Status transitions (draft → issued → partially_paid → paid → overdue → voided)
- Calculate outstanding balance
- Validate collection totals against net amount

### 9.13 `CollectionService`
- Record collections against sales
- Validate amount doesn't exceed outstanding receivable
- Status transitions (pending → approved → voided)
- Auto-update sales status (partially_paid / paid)

### 9.14 `ApprovalService`
- Submit entities for approval
- Record approval decisions with remarks
- Enforce no self-approval (configurable by admin)
- Update entity status on decision
- Escalation support
- Return for correction workflow

### 9.15 `ReportingService`
- Generate HTML, PDF, Excel reports
- Apply filters (date range, project, client, status, etc.)
- Stream large exports
- Track report generation in `pal_report_exports`
- Enforce permission checks

### 9.16 `AuditService`
- Record all auditable actions
- Provide queryable audit log
- Append-only (no delete capability in interface)

---

## 10. Approval State Diagrams

### 10.1 Expense Approval Flow

```
                    ┌──────────┐
                    │  DRAFT   │
                    └─────┬────┘
                          │ submit
                    ┌─────▼──────┐
              ┌─────│ SUBMITTED  │
              │     └──────┬─────┘
              │            │ reviewer action
              │     ┌──────┴──────────┐
              │     │                 │
        ┌─────▼──┐ ┌─▼───────┐  ┌────▼──────┐
        │RETURNED│ │APPROVED │  │ REJECTED  │
        └───┬────┘ └────┬────┘  └─────┬─────┘
            │           │             │
            │ edit+     │             │
            │ resubmit  │             │
            └───────────┘             │
                                      │
                  ┌───────┐     ┌─────▼─────┐
                  │VOIDED │     │ REVERSED  │
                  └───────┘     └───────────┘
```

### 10.2 Purchase Approval Flow

```
   DRAFT ──submit──► SUBMITTED ──approve──► APPROVED ──► (stock-in created)
                     │                          │
                     │ reject                   │ void
                     ▼                          ▼
                  REJECTED                   VOIDED
```

### 10.3 Material Issuance Flow

```
   DRAFT ──submit──► REQUESTED ──approve──► APPROVED ──► PARTIALLY_ISSUED ──► FULLY_ISSUED
                     │                          │
                     │ reject                   │
                     ▼                          ▼
                  REJECTED                  CANCELLED
```

### 10.4 Fabrication Payment Flow

```
   PENDING ──approve──► APPROVED ──► (updates weekly_due.paid_amount)
     │
     │ reject
     ▼
   REJECTED
```

### 10.5 Collection Flow

```
   PENDING ──approve──► APPROVED ──► (updates sale status)
     │
     │ reject
     ▼
   REJECTED
```

### 10.6 Self-Approval Rule

```
   IF submitter == reviewer AND allow_self_approval == false:
       → BLOCK with "You cannot approve your own submission"
   ELSE:
       → Allow decision
```

---

## 11. Inventory Movement Rules

### 11.1 Movement Types

| Type | Direction | Effect on Qty | Requires Approval | Triggers Cost Calc |
|---|---|---|---|---|
| `stock_in` | In | + | Yes (via purchase approval) | Yes — average cost recalculated |
| `issuance` | Out | - | Yes | Yes — cost assigned to project |
| `return` (reusable) | In | + | Yes | No — uses current average cost |
| `return` (damaged) | None | 0 | Yes | No — stays as project cost |
| `wastage` | Out | - | Yes | No |
| `damage` | Out | - | Yes | No |
| `transfer_out` | Out | - | Yes | No |
| `transfer_in` | In | + | Yes | No |
| `adjustment_up` | In | + | Yes | No |
| `adjustment_down` | Out | - | Yes | No |
| `initial_balance` | In | + | Admin only | Yes — sets initial cost |
| `reversal` | Inverse | ± | Admin only | Yes — reverses original cost |

### 11.2 Weighted Average Cost Formula

```
New Avg Cost = (Current Qty × Current Avg Cost + New Qty × New Unit Cost) ÷ (Current Qty + New Qty)
```

- Only applies on `stock_in` movements (purchases)
- Stored in `pal_materials.current_avg_cost`
- Snapshot at time of issuance is stored in `pal_material_issuance_items.unit_cost`
- Future price changes do NOT retroactively affect issued costs

### 11.3 Stock Availability Check

```
Available = SUM(movements WHERE movement_type IN (stock_in, return, transfer_in, adjustment_up, initial_balance))
          - SUM(movements WHERE movement_type IN (issuance, wastage, damage, transfer_out, adjustment_down))
```

Queried from `pal_inventory_balances` cache table, recalculated on each movement.

### 11.4 Reversal Rules

- A reversal creates a new movement with opposite quantity
- Links to original via `reversal_id`
- Requires admin role
- Records reason in movement description
- Updates inventory balances cache

---

## 12. Fabrication Calculation Rules

### 12.1 Allocation Basis Options

| Basis | Formula |
|---|---|
| `expenses` | `SUM(approved project expenses) × percentage` |
| `labor_materials` | `SUM(labor expenses + material costs) × percentage` |
| `contract` | `contract_amount × percentage` |
| `fixed` | `fixed_amount` (no percentage needed) |
| `manual` | `approved_amount` (fully manual) |

### 12.2 Default Configuration

```
Global default percentage: 25%
Global default basis: expenses
```

### 12.3 Override Priority

```
Project-level setting > Project-type setting > Global default
```

### 12.4 Weekly Due Calculation

```
Default: Total allocation ÷ number of weeks in project duration
Uneven: Manual entry with approval
```

### 12.5 Payment Constraint

```
Total approved payments ≤ Approved fabrication allocation
(Exception: authorized adjustment with reason + approval)
```

### 12.6 Fabrication Balance

```
Fabrication Balance = Approved Allocation Amount - Total Paid Amount
```

---

## 13. Reporting Matrix

| Report # | Name | Source Tables | Key Columns | Filters |
|---|---|---|---|---|
| R1 | Project Cost Report | `pal_projects`, `pal_expenses`, `pal_material_issuances` | Project, contract, total cost, budget remaining, cost by category | Date range, project, status |
| R2 | Project P&L Report | `pal_projects`, `pal_sales`, `pal_expenses`, `pal_material_issuances` | Revenue, costs, profit, margin | Date range, project, client |
| R3 | Expense Summary by Category | `pal_expenses`, `pal_expense_categories` | Category, total amount, % of total | Date range, project, status |
| R4 | Material Usage Report | `pal_material_issuance_items`, `pal_materials`, `pal_projects` | Material, project, qty issued, total cost | Date range, project, material |
| R5 | Inventory Stock Report | `pal_inventory_balances`, `pal_materials` | Material, qty on hand, avg cost, inventory value | Category, location |
| R6 | Stock Movement Report | `pal_inventory_movements`, `pal_materials` | Date, material, type, qty, unit cost, reference | Date range, material, type |
| R7 | Low-Stock Report | `pal_materials`, `pal_inventory_balances` | Material, qty on hand, reorder level, suggested qty | Category, supplier |
| R8 | Purchases Report | `pal_purchases`, `pal_purchase_items`, `pal_suppliers` | Purchase #, supplier, date, total amount, items | Date range, supplier, status |
| R9 | Sales Report | `pal_sales`, `pal_clients`, `pal_projects` | Invoice #, client, project, gross, net | Date range, client, status |
| R10 | Collection Report | `pal_collections`, `pal_sales`, `pal_clients` | Collection #, sale, client, amount, method, date | Date range, client, method |
| R11 | Outstanding Receivables | `pal_sales`, `pal_collections`, `pal_clients` | Sale, client, net amount, collections, balance | Client, project, aging |
| R12 | Fabrication Allocation Report | `pal_fabrication_allocations`, `pal_projects`, `pal_team_leads` | Project, basis, %, base, calculated, approved | Project, status |
| R13 | Weekly Fabrication Dues | `pal_fabrication_weekly_dues`, `pal_projects`, `pal_team_leads` | Week, due amount, paid, balance, status | Project, date range |
| R14 | Fabrication Payment History | `pal_fabrication_payments`, `pal_projects`, `pal_team_leads` | Payment #, project, team lead, amount, date, method | Project, date range |
| R15 | Audit Trail Report | `pal_audit_logs` | Date, user, action, entity, old/new values | Date range, user, action, entity |
| R16 | User Activity Report | `pal_audit_logs` | User, action count, last activity, entities touched | Date range, user, role |

### Report Formats

| Report | HTML | PDF | Excel |
|---|---|---|---|
| R1–R16 | Always | Yes | Yes (R3, R4, R5, R6, R7, R8, R9, R10, R11) |
| Large exports | Streamed | Queued | Queued |

### Report Header Template (all formats)

```
[Company Name]
[Report Title]
Date Range: [start] – [end]
Generated: [datetime] by [user]
Filters: [applied filters]
Page [n] of [N]
```

---

## 14. Security Checklist

### 14.1 Authentication & Session
- [x] Kernel auth integration (not custom auth)
- [x] Session-based login with token version
- [x] Password hashing with `password_hash()` / `password_verify()`
- [x] HttpOnly, Secure, SameSite cookies
- [x] Rate limiting on login attempts
- [x] Forgot/reset password flow
- [x] Session timeout / idle logout

### 14.2 Authorization
- [x] Role-based access (admin / supervisor / encoder)
- [x] Capability-based permission checks
- [x] Server-side permission enforcement (never only in UI/DiSyL)
- [x] No self-approval by default
- [x] Tenant-scoped every query (`WHERE tenant_id = ?`)
- [x] User ownership checks (encoders see own records)

### 14.3 Input Validation & Output
- [x] SQL parameter binding (prepared statements)
- [x] CSRF token on all POST/DELETE
- [x] Output escaping in DiSyL templates
- [x] Amount validation (no negative unless reversal)
- [x] Quantity validation (issue ≤ available stock)
- [x] Date range validation
- [x] File upload type/size validation

### 14.4 Data Protection
- [x] Tenant isolation on all queries
- [x] Immutable approved records (no direct edit)
- [x] Void/reverse pattern for corrections (no hard delete of financial records)
- [x] Audit logging of all sensitive actions
- [x] No direct file path exposure in download URLs
- [x] Attachment access requires permission + tenant scope

### 14.5 Audit & Integrity
- [x] Append-only audit logs (no delete in UI)
- [x] Version column on mutable entities
- [x] Record actor on every change (created_by, updated_by)
- [x] Approval chain recorded in `pal_approvals`
- [x] Reversal links via `reversal_id`

### 14.6 Deployment
- [x] Environment-based configuration (no hard-coded credentials)
- [x] Error handling without stack trace exposure
- [x] HTTPS enforcement (cloud)
- [x] VPN/tunnel requirement (local NAS)
- [x] Encrypted database connections where supported

---

## 15. Test Plan

### 15.1 Unit Tests

| Test | What It Verifies |
|---|---|
| `testProjectCostCalculation` | Approved expenses + material costs sum correctly |
| `testProfitCalculation` | `net_sales - total_cost` with zero-handling |
| `testProfitMargin` | `(profit / net_sales) * 100` with zero-handling |
| `testFabricationAllocation_expenses` | 25% of eligible expenses |
| `testFabricationAllocation_contract` | Percentage × contract amount |
| `testFabricationAllocation_fixed` | Fixed amount returned as-is |
| `testFabricationAllocation_manual` | Manual override with reason required |
| `testWeeklyDueCalculation` | Total allocation ÷ weeks (equal) |
| `testWeeklyDueUneven` | Manual uneven entry |
| `testPartialPaymentCalculation` | Partial + full = total |
| `testInventoryAverageCost` | Weighted avg formula |
| `testStockAvailability` | Balance query correct |
| `testCollectionBalance` | Outstanding = net - collections |
| `testPermissionAdmin` | Admin can access all |
| `testPermissionEncoder` | Encoder cannot approve own records |
| `testPermissionSupervisor` | Supervisor can approve |
| `testSelfApprovalBlock` | Self-approval blocked by default |

### 15.2 Integration Tests

| Test | Flow |
|---|---|
| `testPurchaseToStockIn` | Create purchase → approve → verify stock-in movement + avg cost update |
| `testMaterialIssuanceToProjectCost` | Create issuance → approve → issue → verify project cost update |
| `testMaterialReturn` | Issue → return reusable → verify stock restored → project cost unchanged |
| `testMaterialReturnDamaged` | Issue → return damaged → verify stock NOT restored → project cost unchanged |
| `testExpenseApprovalFlow` | Create → submit → approve → verify project cost updated |
| `testExpenseRejection` | Create → submit → reject → verify no cost impact |
| `testFabricationPaymentWithinAllocation` | Create allocation → pay 50% → verify balance |
| `testFabricationPaymentExceedsAllocation` | Attempt overpayment → verify blocked |
| `testSalesAndCollectionFull` | Create sale → collect full amount → verify status = paid |
| `testSalesAndCollectionPartial` | Create sale → collect partial → verify status = partially_paid, balance correct |
| `testReportGeneration` | Generate PDF/Excel → verify file created |
| `testAuditLogging` | Perform actions → verify audit records created |

### 15.3 Security Tests

| Test | Vector |
|---|---|
| `testCrossTenantProjectAccess` | Tenant A user accesses Tenant B project ID |
| `testCrossTenantAttachmentAccess` | Tenant A user downloads Tenant B attachment |
| `testEncoderApprovesOwnExpense` | Self-approval blocked |
| `testEncoderAccessesSettings` | Settings page blocked |
| `testDirectUrlAccessWithoutAuth` | Protected route returns 401/redirect |
| `testCsrfTokenRequired` | POST without CSRF fails |
| `testSqlInjectionAttempt` | Malicious input sanitized |
| `testInvalidFileUpload` | Non-allowed file type rejected |
| `testApprovedRecordModification` | Edit approved expense → blocked |
| `testSupervisorAccessesUserManagement` | User management page blocked for supervisor |

### 15.4 Acceptance Tests

| Scenario | Roles | Steps |
|---|---|---|
| Admin creates project, assigns client, adds expenses, approves | admin | Full lifecycle |
| Encoder creates expense, submits, supervisor approves | encoder → supervisor | Approval flow |
| Encoder creates purchase, admin approves, stock increases | encoder → admin | Stock-in flow |
| Encoder requests material, supervisor approves, admin issues | encoder → supervisor → admin | Issuance flow |
| Admin configures fabrication, generates weekly dues, records payment | admin | Fabrication flow |
| Admin creates sale, records collections, views P&L | admin | Sales flow |
| User generates PDF report, downloads | admin | Report export |
| Auditor views audit trail, filters by date/user/action | admin | Audit trail |

---

## 17. Implementation Sequence

### Phase 1 — Foundation (Week 1-2)

**Files to create:**
1. `modules/project-audit-ledger/module.json` — manifest, capabilities, settings, nav
2. `modules/project-audit-ledger/routes.php` — route declarations
3. `modules/project-audit-ledger/handlers.php` — handler loader
4. `modules/project-audit-ledger/helpers.php` — capability handlers, utilities
5. `modules/project-audit-ledger/database/migrations/001_pal_core_schema.sql` — core tables
6. `modules/project-audit-ledger/database/migrations/002_pal_users.sql` — users table + seed
7. `modules/project-audit-ledger/handlers/00-bootstrap.php` — auth middleware, context
8. `modules/project-audit-ledger/handlers/05-auth.php` — login/logout/reset
9. `modules/project-audit-ledger/handlers/75-users.php` — user CRUD
10. `modules/project-audit-ledger/templates/project-audit-ledger/login.disyl`
11. `modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl`

**Architectural decisions:**
- Auth-owned module with own users table, cookie, and admin shell
- Uses kernel's `app()->registerAuthTable()` for auth integration
- Capability handlers registered in `helpers.php` via `pal_capability_handlers()`
- Entity view configs loaded in `handlers.php` via `TemplateEngine::loadViewConfigs(__DIR__ . '/helpers/views')` — registers `{ikb_entity_view}` contracts
- Entity capability handlers (`entity.list.pal_*`, `entity.get.pal_*`) follow the pattern established by attendance-wage/guidance/wms — handler functions in `helpers.php`, registered in `pal_capability_handlers()` map
- Settings stored in `pal_settings` table (module-owned) rather than kernel module settings, for performance and schema control
- Audit uses `pal_audit_logs` table (module-owned) rather than kernel `audit_logs`, to keep tenant-scoped data in tenant DB

### Phase 2 — Projects and Expenses (Week 3-4)

**Files to create:**
1. `services/ProjectService.php`
2. `services/ProjectCostService.php`
3. `services/ExpenseService.php`
4. `handlers/10-dashboard.php`
5. `handlers/15-projects.php`
6. `handlers/20-clients.php`
7. `handlers/25-expenses.php`
8. `database/migrations/003_pal_clients_suppliers.sql`
9. `database/migrations/007_pal_expenses.sql`
10. Templates: dashboard, projects/*, clients/*, expenses/*

**Architectural decisions:**
- Project cost is computed on read from movements + expenses (not stored redundantly)
- Budget threshold warnings configurable per project (`budget_warning_pct`)
- Expenses can be project-specific or general operating

### Phase 3 — Inventory (Week 5-7)

**Files to create:**
1. `services/PurchaseService.php`
2. `services/InventoryService.php`
3. `services/InventoryMovementService.php`
4. `services/MaterialIssuanceService.php`
5. `services/MaterialReturnService.php`
6. `handlers/30-purchases.php`
7. `handlers/35-inventory.php`
8. `handlers/40-material-issuance.php`
9. `database/migrations/004_pal_materials_inventory.sql`
10. `database/migrations/005_pal_purchases.sql`
11. `database/migrations/006_pal_material_issuance.sql`
12. Templates: purchases/*, inventory/*, material-issuance/*

**Architectural decisions:**
- `pal_inventory_balances` is a cache/reference table updated on each movement
- Weighted average cost calculated at stock-in time, stored in movement record
- Issuance cost uses the current average cost at issuance time (snapshot stored)
- No retroactive cost recalculation

### Phase 4 — Fabrication (Week 8-9)

**Files to create:**
1. `services/FabricationAllocationService.php`
2. `services/FabricationDueService.php`
3. `services/FabricationPaymentService.php`
4. `handlers/45-fabrication.php`
5. `database/migrations/009_pal_fabrication.sql`
6. Templates: fabrication/*

**Architectural decisions:**
- Allocation requires explicit approval before weekly dues can be generated
- Weekly due generation: user-driven (not automatic cron), with even split default
- Manual adjustment with approval reason required when override differs from calculated
- Payment total capped at allocation (hard constraint in service)

### Phase 5 — Sales and Collections (Week 10-11)

**Files to create:**
1. `services/SalesService.php`
2. `services/CollectionService.php`
3. `services/ApprovalService.php`
4. `handlers/50-sales.php`
5. `handlers/55-approvals.php`
6. `database/migrations/008_pal_sales_collections.sql`
7. `database/migrations/010_pal_approvals_audit.sql`
8. Templates: sales/*, approvals/*

**Architectural decisions:**
- Approval service is polymorphic (accepts entity_type + entity_id)
- Sales status auto-updates on collection approval
- Outstanding balance = sum of approved collections subtracted from net amount

### Phase 6 — Reports and Stabilization (Week 12-14)

**Files to create:**
1. `services/ReportingService.php`
2. `services/AuditService.php`
3. `handlers/60-reports.php`
4. `handlers/65-audit.php`
5. `handlers/70-settings.php`
6. `database/migrations/011_pal_report_exports.sql`
7. `database/migrations/012_pal_settings.sql`
8. All report templates (16 reports × HTML + partials)
9. All tests (unit, integration, security)
10. Documentation updates

**Architectural decisions:**
- PDF generation via Dompdf (bundled dependency)
- Excel generation via PhpSpreadsheet (bundled dependency)
- Large exports queued via kernel job queue
- Report filters as serialized JSON in `pal_report_exports`

### Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Inventory balance drift | Recalculation trigger on every movement; periodic reconciliation workflow |
| Fabrication overpayment | Hard constraint in `FabricationPaymentService`; approval required for adjustments |
| Cross-tenant leakage | All queries scoped to `tenant_id`; service layer validates tenant on every operation |
| Performance with large datasets | Indexes on all foreign keys and filter columns; movement table paginated; balances cached |
| Approval workflow complexity | Simple state machine with explicit transitions; no BPMN-level workflow engine |
| User adoption | Mobile-responsive templates; workflow-aligned UI; minimal accounting jargon |
| **Entity view gaps for composite pages** | Use composite DiSyL templates with embedded `{ikb_entity_list}` calls + handler-fetched aggregates. See §8.6 for full gap analysis. This is the established pattern from `attendance-wage` dashboard. |

---

*End of Implementation Plan*

---

## Appendix A: 2026-07-11 Implementation Review — Gaps & Fixes

### A.1 Critical Fix: `convertToProject()` was incomplete

**Gap**: `QuotationService::convertToProject()` only created a project shell — it did NOT copy quotation line items to `pal_project_items` nor transfer the financial detail fields (`scope_of_work`, `with_installation`, `installation_charge`, `mobilization_charge`, `other_charges`, `mode_of_payment`, `down_payment`, `down_payment_type`).

**Fix**: `convertToProject()` now:
1. Fetches all `pal_quotation_items` for the quotation
2. Inserts the full set of JO-merge fields into `pal_projects`
3. Copies each line item into `pal_project_items` with all dimension/pricing fields preserved
4. Recalculates `contract_amount` as `items_total + charges` (consistent with `ProjectService::create()`)

### A.2 Fix: Missing `reads_tables` declaration

**Gap**: PAL queries `attendance_groups`, `attendance_group_members`, `attendance_wage_users`, `employee_profiles`, and `attendance_records` (all owned by the `attendance-wage` module) but only declared `["audit_logs"]` in `module.json`.

**Fix**: Added the 5 AW tables to `reads_tables`:
```json
"reads_tables": ["audit_logs", "attendance_groups", "attendance_group_members", "attendance_wage_users", "employee_profiles", "attendance_records"]
```

### A.3 Fix: Project status values updated to post-migration-014 ENUM

**Gap**: After migration `014_pal_jo_merge.sql` changed `pal_projects.status` to `ENUM('draft','pending','approved','started','ongoing','completed','cancelled','closed')`, several handlers and templates still used the old statuses `'in_progress'` and `'on_hold'`.

**Fix**: Updated all status references across:
- `handlers/06-team-lead-auth.php` — team lead OTP login project count  
- `handlers/10-dashboard.php` — active project count
- `handlers/40-issuance.php` — issuance and return form project dropdowns
- `handlers/50-sales.php` — sales and collection form project dropdowns
- `handlers/53-team-lead.php` — all 4 team lead queries (dashboard, fabrication, CA form, mobilization form)
- `templates/.../dashboard.disyl` — project status badges
- `templates/.../team-lead-dashboard.disyl` — project status badges

### A.4 Fix: Dead code removed from attendance query

**Gap**: `palPageTeamLeadAttendance()` in `53-team-lead.php` built a query with `?` placeholders, then immediately rebuilt the same query with named parameters. The first query was never executed.

**Fix**: Removed the dead code path; the handler now directly uses named parameters.

### A.5 Fix: `session_regenerate_id` warning

**Gap**: `05-auth.php` called `session_regenerate_id(true)` unconditionally, causing a PHP warning when no session was active.

**Fix**: Added `session_status() === PHP_SESSION_ACTIVE` guard before calling `session_regenerate_id()`.

### A.6 Gap: Team lead ↔ AW group bridge (documented, not automated)

The cross-module bridge uses `attendance_groups.pal_team_lead_email` ↔ `pal_team_leads.email`. This is a manual admin setup — PAL team leads must be manually linked to AW attendance groups by entering the team lead's email in the AW group form. No automated sync exists.

### A.7 Excel form coverage

The ZAP-ARTS quotation form (Excel) maps to PAL fields as follows:
- ✅ Customer name, company, address → `pal_clients` / `pal_settings`
- ✅ Quotation number → `pal_quotations.quotation_number`
- ✅ Job Order Number → `pal_projects.job_order_number`
- ✅ Scope of work, with installation, installation charge, mobilization charge, other charges → `pal_projects` (via 014 migration)
- ✅ Mode of payment, down payment → `pal_projects` (via 014 migration)
- ✅ Product/particulars with dimensions (width×height) → `pal_quotation_items` / `pal_project_items`
- ✅ Price per sq ft → `price_per_sqft` column on all line-item tables
- ✅ Upload button → `pal_attachments`
- ⚠️ SALES INVOICE NUMBER (manual input) — `pal_sales.invoice_number` exists but is not populated from the form; entered manually by admin

### A.8 Fix: Missing domain events & dead code (2026-07-11 review)

**Gap**: 8 domain events declared in `module.json` were never fired. 1 handler had no route (dead code). 1 duplicate table entry in `module.json`. 1 duplicate route.

| # | Issue | File | Fix |
|---|---|---|---|
| 1 | `pal.inventory.stocked_in` never fired | `ApprovalService.php` | Added `palFireEvent` after stock-in movement loop |
| 2 | `pal.inventory.material_issued` never fired | `ApprovalService.php` | Added `palFireEvent` after issuance approval side effects |
| 3 | `pal.inventory.material_returned` never fired | `MaterialReturnService.php` | Added `palFireEvent` after return + restock |
| 4 | `pal.inventory.adjusted` never fired | `handlers/35-inventory.php` | Added `palFireEvent` after adjustment audit |
| 5 | `pal.fabrication.allocation_created` never fired | `handlers/45-fabrication.php` | Added `palFireEvent` after audit call |
| 6 | `pal.fabrication.payment_recorded` never fired | `handlers/45-fabrication.php` | Added `palFireEvent` after audit call |
| 7 | `pal.fabrication.payment_approved` — wrong event name fired | `ApprovalService.php` | Added explicit `palFireEvent('pal.fabrication.payment_approved')` in process method |
| 8 | `pal.quotation.converted` — wrong event name fired | `QuotationService.php` | Changed `pal.quotation.converted_to_project` → `pal.quotation.converted` |
| 9 | `palApiProjectCost()` — no route (dead code) | `routes.php` | Added `GET /api/v1/project-audit-ledger/projects/{id}/cost` route |
| 10 | `pal_material_categories` listed twice in `owns_tables` | `module.json` | Removed duplicate entry |
| 11 | Duplicate quotation convert route | `routes.php` | Removed duplicate line |

### A.9 PAL domain hardening — completion safety & client snapshots (2026-07-12)

**Scope**: P0 contract correctness + P1 domain hardening from the July 2026 repository review.

| # | Issue | File | Fix |
|---|---|---|---|
| 1 | Dead-code `elseif (isset($data['items']))` branch (unreachable) | `ProjectService.php` | Removed duplicate branch |
| 2 | Concurrent completion can double-create invoices | `ProjectService.php` | Added `SELECT ... FOR UPDATE` row lock + idempotent early-return for already-completed projects |
| 3 | No unique constraints on business numbers | `016_pal_unique_business_numbers.sql` | Added `uq_pal_proj_jo_number`, `uq_pal_sales_number`, `uq_pal_invoice_number`, `uq_pal_collection_number` (all per-tenant) |
| 4 | `pal.sale.created` domain event not emitted on auto-created invoices | `ProjectService.php` | Added `palFireEvent('pal.sale.created', ...)` after audit log |
| 5 | Client master changes mutate historical invoice data | `017_pal_client_snapshot.sql`, `SalesService.php`, `ProjectService.php` | Added `client_name`, `client_contact`, `client_email`, `client_phone`, `client_address` columns to `pal_sales`; populated at creation time via `loadClientSnapshot()` |
| 6 | Invoice `client_name` must fall back to snapshot before live join | `SalesService.php` | Changed SELECT queries to `COALESCE(s.client_name, c.name)` |
| 7 | No formal AttachmentService | `AttachmentService.php`, `handlers.php` | Created `palAttachmentService` with `upload()`, `get()`, `listForEntity()`, `delete()`, `reassign()`, `getFilePath()` |
| 8 | `palDb()` crashes in test/CLI context when `module()` is undefined | `helpers.php` | Added `catch (Throwable)` fallback with explicit `owns_tables`/`reads_tables` lists |
| 9 | No regression coverage for completion flow | `PalProjectCompletionTest.php` | 26 integration tests: idempotency, invoice creation, client snapshots + immutability, no-client guard, duplicate prevention, unique constraint verification |

**Migration files added**: `016_pal_unique_business_numbers.sql`, `017_pal_client_snapshot.sql`

**Test**: `php tests/PalProjectCompletionTest.php` — 26 passed, 0 failed, clean logs.

### A.10 Service decomposition — state machine, coordinator, receivables (2026-07-12)

**Scope**: P1 architectural refactoring — formal Job Order state machine, completion coordinator, receivable/payment separation.

| # | Change | Files | Description |
|---|---|---|---|
| 1 | Formal Job Order state machine | `JobOrderWorkflow.php` | `palJobOrderWorkflow` defines all allowed transitions (`draft→pending→approved→started→ongoing→completed→closed`), guards (client required for completion, paid invoice protection), and side-effect hooks. Replaces scattered status logic. |
| 2 | Completion coordinator | `ProjectCompletionCoordinator.php` | Extracts completion orchestration from `ProjectService` into a focused coordinator: validate → lock FOR UPDATE → update status → create invoice → copy items → create receivable → audit → emit events. All in one transaction. |
| 3 | ProjectService delegation | `ProjectService.php` | `completeProject()` now delegates to `ProjectCompletionCoordinator`. Backward compatible — all existing handlers and tests unchanged. |
| 4 | ReceivableService | `ReceivableService.php` | First-class receivable management: `createFromInvoice()`, `allocatePayment()`, `listOutstanding()`, `markOverdue()`, `void()`, `clientOutstanding()`. Separates "money expected" from "money received". |
| 5 | PaymentService | `PaymentService.php` | Payment recording with auto-allocation to earliest-due receivables: `record()`, `approve()`, `reject()`. Updates sale status (paid/partial/overdue) after payment allocation. |
| 6 | Receivables table | `018_pal_receivables.sql` | `pal_receivables` with type (full/installment/down_payment/progress_billing), amount_paid, outstanding (generated), status. `pal_receivable_payments` junction table for payment allocation. |
| 7 | SalesService public method | `SalesService.php` | Added `saveItemsForSale()` public entry point for coordinator. |
| 8 | Module manifest | `module.json` | Added `pal_receivables`, `pal_receivable_payments` to `owns_tables`. Added migration 018. |

**New service files**: `JobOrderWorkflow.php`, `ProjectCompletionCoordinator.php`, `ReceivableService.php`, `PaymentService.php`

**Migration files added**: `018_pal_receivables.sql`

**Test**: `php tests/PalProjectCompletionTest.php` — 26 passed, 0 failed, clean logs.
