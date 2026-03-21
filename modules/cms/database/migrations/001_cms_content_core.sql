-- CMS Module — Content Core
-- Tables: cms_content, cms_content_meta

CREATE TABLE IF NOT EXISTS cms_content (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL,
    body LONGTEXT DEFAULT NULL,
    blocks_json LONGTEXT DEFAULT NULL,
    excerpt TEXT DEFAULT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'post',
    status ENUM('draft','published','scheduled','private','trash') NOT NULL DEFAULT 'draft',
    author_id INT UNSIGNED NOT NULL,
    featured_image_id INT UNSIGNED DEFAULT NULL,
    parent_id INT UNSIGNED DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    comment_status ENUM('open','closed') NOT NULL DEFAULT 'open',
    published_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_cms_type_slug (type, slug),
    KEY idx_cms_type_status (type, status),
    KEY idx_cms_author (author_id),
    KEY idx_cms_parent (parent_id),
    KEY idx_cms_published (published_at),
    KEY idx_cms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_meta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id INT UNSIGNED NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT DEFAULT NULL,
    UNIQUE KEY uk_cms_content_key (content_id, meta_key),
    KEY idx_cms_meta_key (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
