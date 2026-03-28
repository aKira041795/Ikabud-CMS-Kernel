-- ============================================================
-- Ecommerce Module — Customer Addresses
-- Table: ec_customer_addresses
-- Also adds 'customer' to cms_users.role ENUM if not present.
-- This migration is idempotent and safe to re-run.
-- ============================================================

-- Add 'customer' role to cms_users if the column exists and the value is not already in the ENUM.
-- We use ALTER TABLE ... MODIFY to extend the ENUM without breaking existing data.
-- Safe to run multiple times: MySQL silently ignores if value already in ENUM.
ALTER TABLE cms_users
    MODIFY COLUMN role ENUM(
        'superadmin',
        'administrator',
        'editor',
        'author',
        'contributor',
        'subscriber',
        'customer'
    ) NOT NULL DEFAULT 'subscriber';

CREATE TABLE IF NOT EXISTS ec_customer_addresses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL COMMENT 'cms_users.id',
    label           VARCHAR(100) NOT NULL DEFAULT 'Home' COMMENT 'Friendly label for this address',
    is_default      TINYINT(1)   NOT NULL DEFAULT 0,
    first_name      VARCHAR(100) NOT NULL DEFAULT '',
    last_name       VARCHAR(100) NOT NULL DEFAULT '',
    address_line1   VARCHAR(255) NOT NULL DEFAULT '',
    address_line2   VARCHAR(255) NULL DEFAULT NULL,
    city            VARCHAR(100) NOT NULL DEFAULT '',
    state           VARCHAR(100) NULL DEFAULT NULL,
    postal_code     VARCHAR(20)  NULL DEFAULT NULL,
    country         VARCHAR(2)   NOT NULL DEFAULT 'US' COMMENT 'ISO 3166-1 alpha-2',
    phone           VARCHAR(30)  NULL DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_addr_user    (user_id),
    INDEX idx_ec_addr_default (user_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
