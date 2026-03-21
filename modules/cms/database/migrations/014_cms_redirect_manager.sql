-- CMS: Add target_url column to slug redirects for custom redirect support
-- Also make content_id nullable so custom redirects can exist without a content reference

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cms_slug_redirects'
        AND COLUMN_NAME = 'target_url'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_slug_redirects ADD COLUMN target_url VARCHAR(500) DEFAULT NULL AFTER old_slug',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cms_slug_redirects'
        AND COLUMN_NAME = 'content_id'
    LIMIT 1
);
SET @sql := IF(@nullable = 'NO',
    'ALTER TABLE cms_slug_redirects MODIFY COLUMN content_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
