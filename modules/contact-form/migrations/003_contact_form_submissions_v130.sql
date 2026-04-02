ALTER TABLE contact_form_submissions
    ADD COLUMN form_id INT UNSIGNED DEFAULT NULL AFTER id,
    ADD COLUMN form_data JSON DEFAULT NULL AFTER message,
    ADD KEY idx_contact_form_submissions_form_id (form_id);