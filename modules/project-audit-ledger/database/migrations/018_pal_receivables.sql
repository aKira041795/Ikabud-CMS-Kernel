SET FOREIGN_KEY_CHECKS = 0;

-- Create pal_receivables table to separate the concept of "expected payment"
-- (receivable) from "money received" (collection/payment).
--
-- Previously, the system created a "collection" record with status 'pending'
-- at invoice time, conflating receivables with payments.
--
-- New model:
--   Invoice (pal_sales) creates one or more Receivables (pal_receivables)
--   Payments (pal_collections) settle Receivables (via pal_receivable_payments)
--
-- This enables:
--   - Partial payments across multiple due dates
--   - Down payment tracking separate from installment schedules
--   - Clear overdue balance reporting
--   - Payment allocation to specific receivable lines

CREATE TABLE IF NOT EXISTS pal_receivables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    receivable_number VARCHAR(50) NOT NULL COMMENT 'Display ID',
    sales_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED DEFAULT NULL,
    client_id INT UNSIGNED DEFAULT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    amount_paid DECIMAL(18,2) NOT NULL DEFAULT 0.00 COMMENT 'Total payments allocated to this receivable',
    outstanding DECIMAL(18,2) GENERATED ALWAYS AS (amount - amount_paid) STORED,
    receivable_type ENUM('full','installment','down_payment','progress_billing') NOT NULL DEFAULT 'full',
    installment_number INT UNSIGNED DEFAULT NULL COMMENT 'For installment schedules',
    notes TEXT DEFAULT NULL,
    status ENUM('pending','partial','settled','overdue','cancelled','voided') NOT NULL DEFAULT 'pending',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    voided_by INT UNSIGNED DEFAULT NULL,
    voided_at DATETIME DEFAULT NULL,
    void_reason VARCHAR(255) DEFAULT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    INDEX idx_pal_recv_tenant (tenant_id),
    INDEX idx_pal_recv_sales (sales_id),
    INDEX idx_pal_recv_project (project_id),
    INDEX idx_pal_recv_client (client_id),
    INDEX idx_pal_recv_status (status),
    INDEX idx_pal_recv_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table: allocates payments (collections) to receivables
-- Enables partial payments across multiple receivables
CREATE TABLE IF NOT EXISTS pal_receivable_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    receivable_id INT UNSIGNED NOT NULL,
    collection_id INT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_rp_receivable (receivable_id),
    INDEX idx_pal_rp_collection (collection_id),
    FOREIGN KEY (receivable_id) REFERENCES pal_receivables(id) ON DELETE CASCADE,
    FOREIGN KEY (collection_id) REFERENCES pal_collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add receivable_number unique constraint
ALTER TABLE pal_receivables
    ADD UNIQUE KEY uq_pal_recv_number (tenant_id, receivable_number);

SET FOREIGN_KEY_CHECKS = 1;
