SET @contact_form_has_submit_label_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_forms'
      AND column_name = 'submit_label'
);
SET @contact_form_add_submit_label_col_sql := IF(
    @contact_form_has_submit_label_col = 0,
    'ALTER TABLE contact_forms ADD COLUMN submit_label VARCHAR(100) NOT NULL DEFAULT '''' AFTER success_message',
    'SELECT 1'
);
PREPARE contact_form_add_submit_label_col_stmt FROM @contact_form_add_submit_label_col_sql;
EXECUTE contact_form_add_submit_label_col_stmt;
DEALLOCATE PREPARE contact_form_add_submit_label_col_stmt;
