-- ============================================================
-- Migration 042: POS sales, payments, receipt lifecycle, and
-- branch-day sales mode state.
--
-- Adds the optional Point of Sale workflow to Daily Ledger:
--   dl_sales_day_modes               — server-authoritative branch/business-date
--                                      sales mode (manual | pos | fallback) with
--                                      optimistic versioning and fallback metadata.
--   dl_pos_sales                     — sale/receipt headers (append-only evidence;
--                                      completed rows are never edited or deleted,
--                                      corrections are void/refund documents).
--   dl_pos_sale_items                — immutable product/price/tax/discount snapshots.
--   dl_pos_payments                  — tender rows (method, tendered/applied/change).
--   dl_pos_sale_events               — append-only lifecycle + authorization evidence.
--   dl_pos_fallback_checkpoints      — supervisor-authorized POS→manual switch record.
--   dl_pos_fallback_checkpoint_items — per-product physical count + addtl/withdraw
--                                      snapshots at the checkpoint instant.
--
-- Money is stored as integer cents (BIGINT) — never binary floats.
-- Idempotency: uq_dl_pos_client_op (branch_id, client_operation_key) makes client
-- retries safe; uq_dl_pos_receipt (branch_id, receipt_no) guarantees one receipt
-- per branch sequence. uq_dl_pos_checkpoint makes a fallback checkpoint final.
--
-- Bluehost MySQL 5.7 compatible: no window functions, no CTEs, no JSON_TABLE,
-- InnoDB + utf8mb4 everywhere, FK column types match referenced columns exactly.
-- Idempotent: CREATE TABLE IF NOT EXISTS throughout.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS dl_sales_day_modes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    mode ENUM('manual','pos','fallback') NOT NULL DEFAULT 'manual',
    status ENUM('active','locked') NOT NULL DEFAULT 'active',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    selected_by INT UNSIGNED DEFAULT NULL,
    selected_at DATETIME DEFAULT NULL,
    fallback_at DATETIME DEFAULT NULL,
    fallback_by INT UNSIGNED DEFAULT NULL,
    fallback_reason VARCHAR(255) DEFAULT NULL,
    closed_by INT UNSIGNED DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_sdm_branch_date (branch_id, ledger_date),
    CONSTRAINT fk_dl_sdm_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    INDEX idx_dl_sdm_date (ledger_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_uuid CHAR(36) NOT NULL,
    client_operation_key VARCHAR(80) NOT NULL,
    request_hash CHAR(64) NOT NULL DEFAULT '',
    sale_kind ENUM('sale','refund') NOT NULL DEFAULT 'sale',
    refund_of_sale_id BIGINT UNSIGNED DEFAULT NULL,
    branch_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    cashier_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(40) NOT NULL,
    status ENUM('draft','completed','voided','partially_refunded','refunded') NOT NULL DEFAULT 'draft',
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    subtotal_cents BIGINT NOT NULL DEFAULT 0,
    discount_cents BIGINT NOT NULL DEFAULT 0,
    tax_cents BIGINT NOT NULL DEFAULT 0,
    total_cents BIGINT NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    completed_at DATETIME DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    voided_by INT UNSIGNED DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    refund_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pos_sale_uuid (sale_uuid),
    UNIQUE KEY uq_dl_pos_client_op (branch_id, client_operation_key),
    UNIQUE KEY uq_dl_pos_receipt (branch_id, receipt_no),
    CONSTRAINT fk_dl_pos_sale_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_pos_sale_cashier FOREIGN KEY (cashier_id) REFERENCES dl_users(id),
    CONSTRAINT fk_dl_pos_sale_refund_of FOREIGN KEY (refund_of_sale_id) REFERENCES dl_pos_sales(id),
    INDEX idx_dl_pos_sales_branch_date (branch_id, ledger_date),
    INDEX idx_dl_pos_sales_status (status),
    INDEX idx_dl_pos_sales_completed (branch_id, ledger_date, status, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(30) NOT NULL DEFAULT '',
    price_group_id INT UNSIGNED DEFAULT NULL,
    unit_price_cents BIGINT NOT NULL DEFAULT 0,
    quantity INT NOT NULL,
    line_discount_cents BIGINT NOT NULL DEFAULT 0,
    tax_cents BIGINT NOT NULL DEFAULT 0,
    line_total_cents BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pos_item (sale_id, product_id),
    CONSTRAINT fk_dl_pos_item_sale FOREIGN KEY (sale_id) REFERENCES dl_pos_sales(id),
    CONSTRAINT fk_dl_pos_item_product FOREIGN KEY (product_id) REFERENCES dl_products(id),
    INDEX idx_dl_pos_item_product_date (product_id, sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    tender_method VARCHAR(30) NOT NULL DEFAULT 'cash',
    amount_tendered_cents BIGINT NOT NULL DEFAULT 0,
    amount_applied_cents BIGINT NOT NULL DEFAULT 0,
    change_cents BIGINT NOT NULL DEFAULT 0,
    reference VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_pos_payment_sale FOREIGN KEY (sale_id) REFERENCES dl_pos_sales(id),
    INDEX idx_dl_pos_payment_sale (sale_id),
    INDEX idx_dl_pos_payment_method (tender_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_sale_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    actor_id INT UNSIGNED DEFAULT NULL,
    actor_role VARCHAR(40) NOT NULL DEFAULT '',
    reason VARCHAR(255) DEFAULT NULL,
    payload TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_pos_event_sale FOREIGN KEY (sale_id) REFERENCES dl_pos_sales(id),
    INDEX idx_dl_pos_event_sale (sale_id),
    INDEX idx_dl_pos_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_fallback_checkpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pos_checkpoint (branch_id, ledger_date),
    CONSTRAINT fk_dl_pos_cp_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_pos_cp_user FOREIGN KEY (recorded_by) REFERENCES dl_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_pos_fallback_checkpoint_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkpoint_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    physical_count INT NOT NULL DEFAULT 0,
    addtl_snapshot INT NOT NULL DEFAULT 0,
    withdraw_snapshot INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_pos_cp_item (checkpoint_id, product_id),
    CONSTRAINT fk_dl_pos_cpi_checkpoint FOREIGN KEY (checkpoint_id) REFERENCES dl_pos_fallback_checkpoints(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_pos_cpi_product FOREIGN KEY (product_id) REFERENCES dl_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
