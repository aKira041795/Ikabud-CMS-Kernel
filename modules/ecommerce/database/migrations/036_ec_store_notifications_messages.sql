CREATE TABLE IF NOT EXISTS ec_store_notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'info',
    title VARCHAR(191) NOT NULL,
    body TEXT NULL,
    action_url VARCHAR(255) NULL,
    related_order_id INT UNSIGNED NULL,
    related_return_request_id INT UNSIGNED NULL,
    related_product_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ec_store_notifications_store_user (store_id, user_id, is_read, created_at),
    KEY idx_ec_store_notifications_order (related_order_id),
    KEY idx_ec_store_notifications_return (related_return_request_id),
    KEY idx_ec_store_notifications_product (related_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_store_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    customer_user_id INT UNSIGNED NULL,
    sender_type VARCHAR(20) NOT NULL DEFAULT 'customer',
    sender_user_id INT UNSIGNED NULL,
    sender_name VARCHAR(191) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ec_store_messages_store_order (store_id, order_id, created_at),
    KEY idx_ec_store_messages_customer (customer_user_id),
    KEY idx_ec_store_messages_sender (sender_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;