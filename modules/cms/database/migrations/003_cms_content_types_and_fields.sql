ALTER TABLE cms_content
    MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'post';

CREATE TABLE IF NOT EXISTS cms_content_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'file-text',
    supports JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cms_content_types_slug (slug),
    KEY idx_cms_content_types_active (is_active),
    KEY idx_cms_content_types_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_field_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type_id INT UNSIGNED NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    field_type ENUM('text','textarea','number','select','boolean','date','url') NOT NULL,
    label VARCHAR(200) NOT NULL,
    placeholder VARCHAR(200) DEFAULT NULL,
    options_json JSON DEFAULT NULL,
    validation_json JSON DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cms_field_def (content_type_id, field_key),
    KEY idx_cms_field_def_type (field_type),
    KEY idx_cms_field_def_sort (sort_order),
    CONSTRAINT fk_cms_field_def_content_type FOREIGN KEY (content_type_id) REFERENCES cms_content_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed records removed for installer packaging. Create content types via app setup if needed.
