SET @contact_form_has_confirmation_rules_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_forms'
      AND column_name = 'confirmation_rules'
);
SET @contact_form_add_confirmation_rules_col_sql := IF(
    @contact_form_has_confirmation_rules_col = 0,
    'ALTER TABLE contact_forms ADD COLUMN confirmation_rules JSON DEFAULT NULL AFTER submit_label',
    'SELECT 1'
);
PREPARE contact_form_add_confirmation_rules_col_stmt FROM @contact_form_add_confirmation_rules_col_sql;
EXECUTE contact_form_add_confirmation_rules_col_stmt;
DEALLOCATE PREPARE contact_form_add_confirmation_rules_col_stmt;

SET @contact_form_has_notification_rules_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_forms'
      AND column_name = 'notification_rules'
);
SET @contact_form_add_notification_rules_col_sql := IF(
    @contact_form_has_notification_rules_col = 0,
    'ALTER TABLE contact_forms ADD COLUMN notification_rules JSON DEFAULT NULL AFTER confirmation_rules',
    'SELECT 1'
);
PREPARE contact_form_add_notification_rules_col_stmt FROM @contact_form_add_notification_rules_col_sql;
EXECUTE contact_form_add_notification_rules_col_stmt;
DEALLOCATE PREPARE contact_form_add_notification_rules_col_stmt;