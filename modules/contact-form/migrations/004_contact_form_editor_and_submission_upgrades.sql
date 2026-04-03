SET @contact_form_fields_has_help_text_col := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'contact_form_fields'
          AND column_name = 'help_text'
);
SET @contact_form_fields_add_help_text_col_sql := IF(
        @contact_form_fields_has_help_text_col = 0,
        'ALTER TABLE contact_form_fields ADD COLUMN help_text TEXT DEFAULT NULL AFTER placeholder',
        'SELECT 1'
);
PREPARE contact_form_fields_add_help_text_col_stmt FROM @contact_form_fields_add_help_text_col_sql;
EXECUTE contact_form_fields_add_help_text_col_stmt;
DEALLOCATE PREPARE contact_form_fields_add_help_text_col_stmt;

SET @contact_form_fields_has_options_text_col := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'contact_form_fields'
          AND column_name = 'options_text'
);
SET @contact_form_fields_add_options_text_col_sql := IF(
        @contact_form_fields_has_options_text_col = 0,
        'ALTER TABLE contact_form_fields ADD COLUMN options_text TEXT DEFAULT NULL AFTER help_text',
        'SELECT 1'
);
PREPARE contact_form_fields_add_options_text_col_stmt FROM @contact_form_fields_add_options_text_col_sql;
EXECUTE contact_form_fields_add_options_text_col_stmt;
DEALLOCATE PREPARE contact_form_fields_add_options_text_col_stmt;

ALTER TABLE contact_form_submissions
        MODIFY COLUMN status ENUM('new','read','reviewed','archived','spam') NOT NULL DEFAULT 'new';

SET @contact_form_submissions_has_reviewed_at_col := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'contact_form_submissions'
          AND column_name = 'reviewed_at'
);
SET @contact_form_submissions_add_reviewed_at_col_sql := IF(
        @contact_form_submissions_has_reviewed_at_col = 0,
        'ALTER TABLE contact_form_submissions ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER created_at',
        'SELECT 1'
);
PREPARE contact_form_submissions_add_reviewed_at_col_stmt FROM @contact_form_submissions_add_reviewed_at_col_sql;
EXECUTE contact_form_submissions_add_reviewed_at_col_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_reviewed_at_col_stmt;

SET @contact_form_submissions_has_reviewed_by_col := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'contact_form_submissions'
          AND column_name = 'reviewed_by'
);
SET @contact_form_submissions_add_reviewed_by_col_sql := IF(
        @contact_form_submissions_has_reviewed_by_col = 0,
        'ALTER TABLE contact_form_submissions ADD COLUMN reviewed_by INT UNSIGNED DEFAULT NULL AFTER reviewed_at',
        'SELECT 1'
);
PREPARE contact_form_submissions_add_reviewed_by_col_stmt FROM @contact_form_submissions_add_reviewed_by_col_sql;
EXECUTE contact_form_submissions_add_reviewed_by_col_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_reviewed_by_col_stmt;

SET @contact_form_submissions_has_updated_at_col := (
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'contact_form_submissions'
          AND column_name = 'updated_at'
);
SET @contact_form_submissions_add_updated_at_col_sql := IF(
        @contact_form_submissions_has_updated_at_col = 0,
        'ALTER TABLE contact_form_submissions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER reviewed_by',
        'SELECT 1'
);
PREPARE contact_form_submissions_add_updated_at_col_stmt FROM @contact_form_submissions_add_updated_at_col_sql;
EXECUTE contact_form_submissions_add_updated_at_col_stmt;
DEALLOCATE PREPARE contact_form_submissions_add_updated_at_col_stmt;

UPDATE contact_form_submissions
SET status = 'reviewed'
WHERE status = 'read';

ALTER TABLE contact_form_submissions
        MODIFY COLUMN status ENUM('new','reviewed','archived','spam') NOT NULL DEFAULT 'new';

UPDATE contact_form_submissions
SET reviewed_at = created_at
WHERE status IN ('reviewed', 'archived', 'spam')
    AND reviewed_at IS NULL;