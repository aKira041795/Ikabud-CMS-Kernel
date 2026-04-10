SET @ec_cart_items_options_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_cart_items'
      AND COLUMN_NAME = 'options_json'
);

SET @ec_cart_items_options_sql := IF(
    @ec_cart_items_options_exists = 0,
    'ALTER TABLE ec_cart_items ADD COLUMN options_json LONGTEXT NULL AFTER sku',
    'SELECT 1'
);

PREPARE ec_cart_items_options_stmt FROM @ec_cart_items_options_sql;
EXECUTE ec_cart_items_options_stmt;
DEALLOCATE PREPARE ec_cart_items_options_stmt;

SET @ec_order_items_snapshot_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_order_items'
      AND COLUMN_NAME = 'snapshot_json'
);

SET @ec_order_items_snapshot_sql := IF(
    @ec_order_items_snapshot_exists = 0,
    'ALTER TABLE ec_order_items ADD COLUMN snapshot_json LONGTEXT NULL AFTER variant_label',
    'SELECT 1'
);

PREPARE ec_order_items_snapshot_stmt FROM @ec_order_items_snapshot_sql;
EXECUTE ec_order_items_snapshot_stmt;
DEALLOCATE PREPARE ec_order_items_snapshot_stmt;

SET @ec_carts_loyalty_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ec_carts'
      AND COLUMN_NAME = 'loyalty_points'
);

SET @ec_carts_loyalty_sql := IF(
    @ec_carts_loyalty_exists = 0,
    'ALTER TABLE ec_carts ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER coupon_discount',
    'SELECT 1'
);

PREPARE ec_carts_loyalty_stmt FROM @ec_carts_loyalty_sql;
EXECUTE ec_carts_loyalty_stmt;
DEALLOCATE PREPARE ec_carts_loyalty_stmt;

CREATE TABLE IF NOT EXISTS ec_memberships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED DEFAULT NULL,
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_title VARCHAR(255) NOT NULL,
    membership_tier VARCHAR(100) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    duration_days INT NOT NULL DEFAULT 365,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ec_memberships_order_item_id (order_item_id),
    KEY idx_ec_memberships_customer_id (customer_id),
    KEY idx_ec_memberships_customer_email (customer_email),
    KEY idx_ec_memberships_tier_status (membership_tier, status),
    KEY idx_ec_memberships_ends_at (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_loyalty_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED DEFAULT NULL,
    entry_type VARCHAR(32) NOT NULL,
    points INT NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ec_loyalty_order_entry (order_id, entry_type, customer_id),
    KEY idx_ec_loyalty_customer_created (customer_id, created_at),
    KEY idx_ec_loyalty_entry_type (entry_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED DEFAULT NULL,
    customer_id BIGINT UNSIGNED DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    scheduled_for DATETIME NOT NULL,
    ends_at DATETIME DEFAULT NULL,
    duration_minutes INT NOT NULL DEFAULT 60,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ec_bookings_order_item_id (order_item_id),
    KEY idx_ec_bookings_customer_id (customer_id),
    KEY idx_ec_bookings_customer_email (customer_email),
    KEY idx_ec_bookings_status_scheduled (status, scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;