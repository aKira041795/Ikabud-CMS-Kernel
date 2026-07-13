-- CMS Module — Phase 1 Foundation
-- Tables: cms_users, cms_content, cms_content_meta, cms_media

CREATE TABLE IF NOT EXISTS cms_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    role ENUM('superadmin','administrator','editor','author','contributor','subscriber') NOT NULL DEFAULT 'subscriber',
    avatar_url VARCHAR(500) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cms_username (username),
    UNIQUE KEY uk_cms_email (email),
    KEY idx_cms_role (role),
    KEY idx_cms_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(500) NOT NULL,
    slug VARCHAR(500) NOT NULL,
    body LONGTEXT DEFAULT NULL,
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
    KEY idx_cms_status (status),
    CONSTRAINT fk_cms_content_author FOREIGN KEY (author_id) REFERENCES cms_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_meta (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id INT UNSIGNED NOT NULL,
    meta_key VARCHAR(255) NOT NULL,
    meta_value LONGTEXT DEFAULT NULL,
    UNIQUE KEY uk_cms_content_key (content_id, meta_key),
    KEY idx_cms_meta_key (meta_key),
    CONSTRAINT fk_cms_meta_content FOREIGN KEY (content_id) REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    file_path VARCHAR(500) NOT NULL,
    alt_text VARCHAR(500) DEFAULT NULL,
    title VARCHAR(500) DEFAULT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cms_media_mime (mime_type),
    KEY idx_cms_media_uploader (uploaded_by),
    CONSTRAINT fk_cms_media_uploader FOREIGN KEY (uploaded_by) REFERENCES cms_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default administrator (password: admin123)
INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active)
VALUES ('cmsadmin', 'admin@cms.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'CMS Admin', 'administrator', 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();
