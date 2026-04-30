ALTER TABLE gm_counselor_notes
    ADD COLUMN appointment_id INT DEFAULT NULL AFTER case_id;