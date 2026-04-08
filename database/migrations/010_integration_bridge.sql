CREATE TABLE IF NOT EXISTS kernel_integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    trigger_event VARCHAR(255) NOT NULL,
    target_capability VARCHAR(255) NOT NULL,
    mapping_json JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    KEY idx_trigger_event (trigger_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kernel_integration_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    integration_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL,
    payload_in JSON DEFAULT NULL,
    payload_out JSON DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_integration_id (integration_id),
    CONSTRAINT fk_integration_log FOREIGN KEY (integration_id) REFERENCES kernel_integrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v2 additions: origin tracking and capability version guard
ALTER TABLE kernel_integrations
    ADD COLUMN event_source VARCHAR(30) NOT NULL DEFAULT 'eventbus' AFTER is_active;

ALTER TABLE kernel_integrations
    ADD COLUMN version_lock VARCHAR(255) DEFAULT NULL AFTER event_source;
