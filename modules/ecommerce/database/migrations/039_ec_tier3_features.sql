-- Tier 3 Feature Completeness: saved payment methods, address book CRUD,
-- order editing, report caching, API keys

-- 3.1 Saved payment methods (Stripe Customer)
CREATE TABLE IF NOT EXISTS ec_saved_payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    gateway VARCHAR(50) NOT NULL DEFAULT 'stripe',
    gateway_customer_id VARCHAR(255) DEFAULT NULL,
    gateway_payment_method_id VARCHAR(255) NOT NULL,
    card_brand VARCHAR(30) DEFAULT NULL,
    card_last4 VARCHAR(4) DEFAULT NULL,
    card_exp_month TINYINT UNSIGNED DEFAULT NULL,
    card_exp_year SMALLINT UNSIGNED DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    label VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spm_user (user_id),
    INDEX idx_spm_gateway_customer (gateway, gateway_customer_id),
    UNIQUE KEY uk_spm_gateway_method (gateway, gateway_payment_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.7 Report caching
CREATE TABLE IF NOT EXISTS ec_report_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    report_type VARCHAR(50) NOT NULL,
    params_hash VARCHAR(64) NOT NULL,
    data_json LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rc_type (report_type),
    INDEX idx_rc_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3.3 Order edit log
CREATE TABLE IF NOT EXISTS ec_order_edits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    edit_type ENUM('add_item', 'remove_item', 'update_qty', 'adjust_total') NOT NULL,
    edit_data JSON DEFAULT NULL,
    previous_total DECIMAL(12,2) DEFAULT NULL,
    new_total DECIMAL(12,2) DEFAULT NULL,
    edited_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_oe_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
