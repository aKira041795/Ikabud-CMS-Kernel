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
SET @_m010_ev := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integrations' AND column_name = 'event_source');
SET @_m010_alt1 := IF(@_m010_ev = 0, 'ALTER TABLE kernel_integrations ADD COLUMN event_source VARCHAR(30) NOT NULL DEFAULT ''eventbus'' AFTER is_active', 'DO 0');
PREPARE _m010_stmt1 FROM @_m010_alt1;
EXECUTE _m010_stmt1;
DEALLOCATE PREPARE _m010_stmt1;

SET @_m010_vl := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integrations' AND column_name = 'version_lock');
SET @_m010_alt2 := IF(@_m010_vl = 0, 'ALTER TABLE kernel_integrations ADD COLUMN version_lock VARCHAR(255) DEFAULT NULL AFTER event_source', 'DO 0');
PREPARE _m010_stmt2 FROM @_m010_alt2;
EXECUTE _m010_stmt2;
DEALLOCATE PREPARE _m010_stmt2;
