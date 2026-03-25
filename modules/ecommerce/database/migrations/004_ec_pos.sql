-- ============================================================
-- Ecommerce Module — POS Sessions
-- Table: ec_pos_sessions
-- This migration is idempotent and safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_pos_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cashier_user_id INT UNSIGNED NOT NULL COMMENT 'cms_users.id',
    opened_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at       DATETIME     NULL DEFAULT NULL,
    opening_cash    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    closing_cash    DECIMAL(10,2) NULL DEFAULT NULL,
    expected_cash   DECIMAL(10,2) NULL DEFAULT NULL,
    notes           TEXT         NULL DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_pos_cashier (cashier_user_id),
    INDEX idx_ec_pos_opened  (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
