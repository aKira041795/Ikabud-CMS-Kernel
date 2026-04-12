-- ============================================================
-- Ecommerce Module — Back-in-Stock Notifications
-- Creates ec_stock_notifications.
-- Safe to re-run (idempotent).
-- ============================================================

CREATE TABLE IF NOT EXISTS ec_stock_notifications (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id     INT UNSIGNED  NOT NULL              COMMENT 'cms_content.id of the parent product',
    variant_id     INT UNSIGNED  NULL DEFAULT NULL      COMMENT 'cms_content.id of a specific variant; NULL = any variant / parent product',
    customer_email VARCHAR(254)  NOT NULL,
    customer_id    INT UNSIGNED  NULL DEFAULT NULL      COMMENT 'cms_users.id — NULL for guest subscribers',
    status         ENUM('waiting','sent','expired') NOT NULL DEFAULT 'waiting',
    notified_at    DATETIME      NULL DEFAULT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Prevent duplicate waiting subscriptions for the same product+variant+email
    UNIQUE KEY uq_ec_sn_prod_variant_email (product_id, variant_id, customer_email, status),
    KEY idx_ec_sn_product_status (product_id, status),
    KEY idx_ec_sn_email (customer_email),
    KEY idx_ec_sn_customer (customer_id),
    KEY idx_ec_sn_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
