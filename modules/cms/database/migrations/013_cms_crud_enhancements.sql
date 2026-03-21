-- ═══════════════════════════════════════════════════════════════
-- 013 CMS CRUD Enhancements
-- MySQL-compatible, idempotent version using information_schema checks.
-- ═══════════════════════════════════════════════════════════════

-- ── cms_content: columns ─────────────────────────────────────────────
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'is_sticky'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN is_sticky TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Pin to top of listing''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'is_featured'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Promoted / featured content''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'password'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN password VARCHAR(255) DEFAULT NULL COMMENT ''SHA-256 hashed password for protected content''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'post_format'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN post_format VARCHAR(30) NOT NULL DEFAULT ''standard'' COMMENT ''Content presentation format''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'word_count'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN word_count INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'reading_time'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN reading_time TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Estimated reading time in minutes''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND COLUMN_NAME = 'comment_count'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_content ADD COLUMN comment_count INT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── cms_content: indexes ─────────────────────────────────────────────
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_sticky'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_sticky (is_sticky)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_featured'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_featured (is_featured)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_format'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_format (post_format)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_word_count'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_word_count (word_count)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_type_status_sticky'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_type_status_sticky (type, status, is_sticky)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'idx_type_status_featured'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD INDEX idx_type_status_featured (type, status, is_featured)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content' AND INDEX_NAME = 'ft_content_search'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_content ADD FULLTEXT INDEX ft_content_search (title, excerpt)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── cms_media: columns ───────────────────────────────────────────────
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'alt_text'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_media ADD COLUMN alt_text VARCHAR(500) DEFAULT NULL COMMENT ''Default alt text for this media item''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'caption'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_media ADD COLUMN caption TEXT DEFAULT NULL COMMENT ''Default caption for this media item''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'image_width'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD COLUMN image_width SMALLINT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'image_height'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD COLUMN image_height SMALLINT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'focus_point'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_media ADD COLUMN focus_point VARCHAR(20) DEFAULT NULL COMMENT ''CSS background-position value''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD COLUMN deleted_at DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Prefer avoiding duplicate single-column indexes when one already exists under another name.
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND INDEX_NAME = 'idx_media_deleted'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD INDEX idx_media_deleted (deleted_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'mime_type' AND INDEX_NAME <> 'PRIMARY'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD INDEX idx_media_mime (mime_type)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_media' AND COLUMN_NAME = 'uploaded_by' AND INDEX_NAME <> 'PRIMARY'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE cms_media ADD INDEX idx_media_uploader (uploaded_by)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── cms_media_usage table ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cms_media_usage (
        id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
        media_id    INT UNSIGNED  NOT NULL,
        content_id  INT UNSIGNED  NOT NULL,
        usage_type  VARCHAR(30)   NOT NULL DEFAULT 'embedded'
                COMMENT 'featured_image | embedded | gallery | og_image',
        created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_media_content_type (media_id, content_id, usage_type),
        KEY idx_media_usage_media   (media_id),
        KEY idx_media_usage_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── cms_slug_redirects baseline table ────────────────────────────────
CREATE TABLE IF NOT EXISTS cms_slug_redirects (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        content_id INT UNSIGNED NOT NULL,
        old_slug   VARCHAR(500) NOT NULL,
        old_type   VARCHAR(50)  NOT NULL DEFAULT 'post',
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_slug_redirect_content (content_id),
        KEY idx_slug_redirect_slug    (old_slug(100)),
        KEY idx_slug_redirect_type    (old_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
