SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_ai_content_plans' AND COLUMN_NAME = 'content_mode'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE cms_ai_content_plans ADD COLUMN content_mode VARCHAR(20) NOT NULL DEFAULT ''standard'' AFTER content_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
