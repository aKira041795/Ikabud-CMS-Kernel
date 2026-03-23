SET @cms_has_ai_auto_refine_policy_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_ai_content_plans'
      AND column_name = 'auto_refine_policy'
);
SET @cms_add_ai_auto_refine_policy_col_sql := IF(
    @cms_has_ai_auto_refine_policy_col = 0,
    'ALTER TABLE cms_ai_content_plans ADD COLUMN auto_refine_policy VARCHAR(32) NOT NULL DEFAULT ''high_severity_once'' AFTER search_grounding_enabled',
    'SELECT 1'
);
PREPARE cms_add_ai_auto_refine_policy_col_stmt FROM @cms_add_ai_auto_refine_policy_col_sql;
EXECUTE cms_add_ai_auto_refine_policy_col_stmt;
DEALLOCATE PREPARE cms_add_ai_auto_refine_policy_col_stmt;