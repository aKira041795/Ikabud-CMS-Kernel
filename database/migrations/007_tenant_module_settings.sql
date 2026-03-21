CREATE TABLE IF NOT EXISTS tenant_module_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    module_id VARCHAR(100) NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_module_setting (tenant_id, module_id, setting_key),
    KEY idx_tenant_module (tenant_id, module_id),
    KEY idx_module_key (module_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
