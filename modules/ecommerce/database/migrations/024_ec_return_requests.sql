CREATE TABLE IF NOT EXISTS ec_return_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL DEFAULT NULL,
    request_number VARCHAR(40) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    reason VARCHAR(500) NOT NULL,
    condition_code VARCHAR(32) NOT NULL DEFAULT 'unknown',
    customer_note TEXT NULL DEFAULT NULL,
    admin_note TEXT NULL DEFAULT NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    wms_return_id BIGINT UNSIGNED NULL DEFAULT NULL,
    wms_reference_number VARCHAR(100) NULL DEFAULT NULL,
    meta LONGTEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ec_return_requests_number (request_number),
    KEY idx_ec_return_requests_order (order_id, created_at),
    KEY idx_ec_return_requests_customer (customer_id, created_at),
    KEY idx_ec_return_requests_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_return_request_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_title VARCHAR(255) NOT NULL,
    sku VARCHAR(120) NULL DEFAULT NULL,
    qty_requested INT NOT NULL DEFAULT 0,
    condition_code VARCHAR(32) NOT NULL DEFAULT 'unknown',
    notes TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ec_return_request_items_request (request_id),
    KEY idx_ec_return_request_items_order_item (order_item_id),
    KEY idx_ec_return_request_items_product (product_id),
    CONSTRAINT fk_ec_return_request_items_request
        FOREIGN KEY (request_id) REFERENCES ec_return_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;