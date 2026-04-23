CREATE TABLE IF NOT EXISTS cms_user_services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    service_key VARCHAR(100) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cms_user_service (user_id, service_key),
    KEY idx_cms_user_service_primary (user_id, is_primary),
    KEY idx_cms_user_service_key (service_key),
    CONSTRAINT fk_cms_user_service_user FOREIGN KEY (user_id) REFERENCES cms_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;