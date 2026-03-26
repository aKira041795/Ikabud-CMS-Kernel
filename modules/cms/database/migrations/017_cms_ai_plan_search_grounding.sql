SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_ai_content_plans' AND COLUMN_NAME = 'search_grounding_enabled'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_ai_content_plans ADD COLUMN search_grounding_enabled TINYINT(1) NULL DEFAULT NULL AFTER seo_enabled',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
