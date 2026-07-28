-- Bakeshop — Inventory Ledger Foundation
--
-- Additive, MySQL 5.7 compatible migration. Does not alter or drop legacy tables.
--
-- 1. bakeshop_inventory_movements — immutable append-only ledger
-- 2. bakeshop_document_numbers — sequential document numbering per branch/type/year
-- 3. Version + status columns on legacy tables for optimistic concurrency + lifecycle
--
-- @mysql57-compat: ENGINE=InnoDB

-- ===================================================================
-- 1. Inventory Movements (immutable append-only ledger)
-- ===================================================================
-- Every posted operational event creates one or more movement rows.
-- Movements are never updated or deleted — corrections create compensating entries.
CREATE TABLE IF NOT EXISTS bakeshop_inventory_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Denormalized for tenant-scoped queries',
    branch_id INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    movement_type ENUM(
        'receipt',           -- ingredient delivery posted
        'production_issue',  -- ingredient consumed by production
        'production_output', -- finished product from production
        'transfer_out',      -- ingredient transferred to another branch
        'transfer_in',       -- ingredient received from another branch
        'adjustment',        -- manual stock adjustment (waste, spoilage, count)
        'void'               -- compensating entry for a voided document
    ) NOT NULL,
    reference_type VARCHAR(50) NOT NULL COMMENT 'Entity type that caused this movement (delivery, production, adjustment, transfer)',
    reference_id INT UNSIGNED NOT NULL COMMENT 'ID of the source document',
    qty DECIMAL(14,4) NOT NULL COMMENT 'Signed quantity: positive=in, negative=out',
    unit_id INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(14,4) DEFAULT NULL COMMENT 'Snapshot of cost per unit at time of posting',
    total_cost DECIMAL(14,4) DEFAULT NULL COMMENT 'qty * unit_cost, snapshotted at posting',
    description VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mvmnt_branch_ingredient (branch_id, ingredient_id),
    INDEX idx_mvmnt_tenant_date (tenant_id, created_at),
    INDEX idx_mvmnt_reference (reference_type, reference_id),
    INDEX idx_mvmnt_ingredient (ingredient_id),
    INDEX idx_mvmnt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 2. Document Numbers (sequential numbering per branch/type/year)
-- ===================================================================
CREATE TABLE IF NOT EXISTS bakeshop_document_numbers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(30) NOT NULL COMMENT 'receipt, production, transfer, adjustment, stocktake',
    year SMALLINT UNSIGNED NOT NULL,
    next_number INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_docnum_branch_type_year (branch_id, doc_type, year),
    INDEX idx_docnum_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 3. Version columns on legacy tables (optimistic concurrency)
-- ===================================================================

-- bakeshop_deliveries: add lifecycle status + version
ALTER TABLE bakeshop_deliveries
    ADD COLUMN status ENUM('draft','posted','voided') NOT NULL DEFAULT 'draft'
    AFTER notes,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER status,
    ADD COLUMN document_no VARCHAR(50) DEFAULT NULL
    AFTER version,
    ADD COLUMN voided_at DATETIME DEFAULT NULL
    AFTER document_no,
    ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL
    AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL
    AFTER voided_by,
    ADD INDEX idx_deliveries_status (status);

-- Backfill existing deliveries as 'posted' (current behavior treated them as immediately effective)
UPDATE bakeshop_deliveries SET status = 'posted' WHERE status = 'draft';

-- bakeshop_delivery_items: add version for line-level concurrency
ALTER TABLE bakeshop_delivery_items
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER unit_cost;

-- bakeshop_production_runs: add lifecycle status + version
ALTER TABLE bakeshop_production_runs
    ADD COLUMN status ENUM('draft','released','in_progress','completed','voided') NOT NULL DEFAULT 'draft'
    AFTER notes,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER status,
    ADD COLUMN document_no VARCHAR(50) DEFAULT NULL
    AFTER version,
    ADD INDEX idx_production_status (status);

-- Backfill existing non-voided runs as 'completed'
UPDATE bakeshop_production_runs SET status = 'completed' WHERE status = 'draft' AND voided_at IS NULL;
-- Backfill existing voided runs
UPDATE bakeshop_production_runs SET status = 'voided' WHERE voided_at IS NOT NULL AND status = 'draft';

-- bakeshop_production_items: add version
ALTER TABLE bakeshop_production_items
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER unit_id;

-- bakeshop_inventory_adjustments: add lifecycle status + version
ALTER TABLE bakeshop_inventory_adjustments
    ADD COLUMN status ENUM('draft','posted','voided') NOT NULL DEFAULT 'draft'
    AFTER notes,
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER status,
    ADD COLUMN document_no VARCHAR(50) DEFAULT NULL
    AFTER version,
    ADD COLUMN voided_at DATETIME DEFAULT NULL
    AFTER document_no,
    ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL
    AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL
    AFTER voided_by,
    ADD INDEX idx_adjustments_status (status);

-- Backfill existing adjustments as 'posted'
UPDATE bakeshop_inventory_adjustments SET status = 'posted' WHERE status = 'draft';

-- bakeshop_product_allocations: add version
ALTER TABLE bakeshop_product_allocations
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1
    AFTER allocated_date,
    ADD COLUMN voided_at DATETIME DEFAULT NULL
    AFTER version,
    ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL
    AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL
    AFTER voided_by;
