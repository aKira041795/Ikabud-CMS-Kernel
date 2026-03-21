-- Search Module — Cross-module Search Index

CREATE TABLE IF NOT EXISTS kernel_search_index (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(50) NOT NULL,
    title VARCHAR(500) DEFAULT NULL,
    excerpt VARCHAR(1000) DEFAULT NULL,
    search_text LONGTEXT DEFAULT NULL,
    json_metadata JSON DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_search_entity (module, entity_type, entity_id),
    KEY idx_search_module (module),
    KEY idx_search_entity_type (entity_type),
    KEY idx_search_updated (updated_at),
    FULLTEXT KEY ft_search_text (title, excerpt, search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
