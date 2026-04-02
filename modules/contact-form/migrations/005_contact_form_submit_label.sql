ALTER TABLE contact_forms
    ADD COLUMN submit_label VARCHAR(100) NOT NULL DEFAULT '' AFTER success_message;
