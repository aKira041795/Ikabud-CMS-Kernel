SET @cms_has_ai_auto_publish_policy_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_ai_content_plans'
      AND column_name = 'auto_publish_policy'
);
SET @cms_add_ai_auto_publish_policy_col_sql := IF(
    @cms_has_ai_auto_publish_policy_col = 0,
    'ALTER TABLE cms_ai_content_plans ADD COLUMN auto_publish_policy VARCHAR(48) NOT NULL DEFAULT ''off'' AFTER auto_refine_policy',
    'SELECT 1'
);
PREPARE cms_add_ai_auto_publish_policy_col_stmt FROM @cms_add_ai_auto_publish_policy_col_sql;
EXECUTE cms_add_ai_auto_publish_policy_col_stmt;
DEALLOCATE PREPARE cms_add_ai_auto_publish_policy_col_stmt;

SET @cms_has_ai_confidence_threshold_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_ai_content_plans'
      AND column_name = 'confidence_threshold'
);
SET @cms_add_ai_confidence_threshold_col_sql := IF(
    @cms_has_ai_confidence_threshold_col = 0,
    'ALTER TABLE cms_ai_content_plans ADD COLUMN confidence_threshold INT UNSIGNED NOT NULL DEFAULT 85 AFTER auto_publish_policy',
    'SELECT 1'
);
PREPARE cms_add_ai_confidence_threshold_col_stmt FROM @cms_add_ai_confidence_threshold_col_sql;
EXECUTE cms_add_ai_confidence_threshold_col_stmt;
DEALLOCATE PREPARE cms_add_ai_confidence_threshold_col_stmt;