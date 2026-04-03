SET @contact_form_fields_has_conditional_logic_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_form_fields'
      AND column_name = 'conditional_logic'
);
SET @contact_form_fields_add_conditional_logic_col_sql := IF(
    @contact_form_fields_has_conditional_logic_col = 0,
    'ALTER TABLE contact_form_fields ADD COLUMN conditional_logic JSON DEFAULT NULL AFTER options_text',
    'SELECT 1'
);
PREPARE contact_form_fields_add_conditional_logic_col_stmt FROM @contact_form_fields_add_conditional_logic_col_sql;
EXECUTE contact_form_fields_add_conditional_logic_col_stmt;
DEALLOCATE PREPARE contact_form_fields_add_conditional_logic_col_stmt;