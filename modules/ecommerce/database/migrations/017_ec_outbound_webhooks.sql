CREATE TABLE IF NOT EXISTS ec_outbound_webhooks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    signing_secret VARCHAR(255) NOT NULL,
    event_patterns JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_delivery_status VARCHAR(40) DEFAULT NULL,
    last_delivery_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ec_outbound_webhooks_active (is_active, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ec_webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_id INT UNSIGNED NULL DEFAULT NULL,
    event_name VARCHAR(190) NOT NULL,
    delivery_id VARCHAR(190) NOT NULL,
    request_body LONGTEXT NOT NULL,
    signature VARCHAR(255) NOT NULL,
    response_body TEXT NULL,
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('delivered', 'failed') NOT NULL DEFAULT 'failed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ec_webhook_delivery_id (delivery_id),
    KEY idx_ec_webhook_deliveries_lookup (webhook_id, created_at),
    KEY idx_ec_webhook_deliveries_event (event_name, created_at),
    CONSTRAINT fk_ec_webhook_deliveries_webhook FOREIGN KEY (webhook_id)
        REFERENCES ec_outbound_webhooks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;