SET @stmt := IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ehr_lab_results')
        AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ehr_lab_results' AND COLUMN_NAME = 'acknowledged_at'),
    'ALTER TABLE ehr_lab_results ADD COLUMN acknowledged_at DATETIME NULL AFTER released_at, ADD COLUMN acknowledged_by_user_id BIGINT UNSIGNED NULL AFTER acknowledged_at, ADD KEY idx_ehr_lab_results_ack (acknowledged_at)',
    'DO 0'
);
PREPARE add_ack_cols FROM @stmt;
EXECUTE add_ack_cols;
DEALLOCATE PREPARE add_ack_cols;
