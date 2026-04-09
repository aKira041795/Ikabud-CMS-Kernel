CREATE TABLE IF NOT EXISTS ec_order_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    status ENUM('pending','processing','shipped','delivered','cancelled','refunded') NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'system',
    note TEXT NULL DEFAULT NULL,
    actor_user_id INT UNSIGNED NULL DEFAULT NULL,
    history_key VARCHAR(191) NULL DEFAULT NULL,
    meta JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ec_order_status_history_key (history_key),
    KEY idx_ec_order_status_history_order (order_id, created_at),
    CONSTRAINT fk_ec_order_status_history_order FOREIGN KEY (order_id)
        REFERENCES ec_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;