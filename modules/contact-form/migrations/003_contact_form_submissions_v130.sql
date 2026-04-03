SET @contact_form_submissions_has_form_id_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_form_submissions'
      AND column_name = 'form_id'
);
SET @contact_form_submissions_add_form_id_col_sql := IF(
    @contact_form_submissions_has_form_id_col = 0,
    'ALTER TABLE contact_form_submissions ADD COLUMN form_id INT UNSIGNED DEFAULT NULL AFTER id',
    'SELECT 1'
);
PREPARE contact_form_submissions_add_form_id_col_stmt FROM @contact_form_submissions_add_form_id_col_sql;
EXECUTE contact_form_submissions_add_form_id_col_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_form_id_col_stmt;

SET @contact_form_submissions_has_form_data_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_form_submissions'
      AND column_name = 'form_data'
);
SET @contact_form_submissions_add_form_data_col_sql := IF(
    @contact_form_submissions_has_form_data_col = 0,
    'ALTER TABLE contact_form_submissions ADD COLUMN form_data JSON DEFAULT NULL AFTER message',
    'SELECT 1'
);
PREPARE contact_form_submissions_add_form_data_col_stmt FROM @contact_form_submissions_add_form_data_col_sql;
EXECUTE contact_form_submissions_add_form_data_col_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_form_data_col_stmt;

SET @contact_form_submissions_has_form_id_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'contact_form_submissions'
      AND index_name = 'idx_contact_form_submissions_form_id'
);
SET @contact_form_submissions_add_form_id_idx_sql := IF(
    @contact_form_submissions_has_form_id_idx = 0,
    'ALTER TABLE contact_form_submissions ADD KEY idx_contact_form_submissions_form_id (form_id)',
    'SELECT 1'
);
PREPARE contact_form_submissions_add_form_id_idx_stmt FROM @contact_form_submissions_add_form_id_idx_sql;
EXECUTE contact_form_submissions_add_form_id_idx_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_form_id_idx_stmt;