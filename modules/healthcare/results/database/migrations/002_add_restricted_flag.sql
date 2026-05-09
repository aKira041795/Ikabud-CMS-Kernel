ALTER TABLE ehr_lab_results
    ADD COLUMN restricted_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER released_at;
