ALTER TABLE contact_form_fields
    ADD COLUMN help_text TEXT DEFAULT NULL AFTER placeholder,
    ADD COLUMN options_text TEXT DEFAULT NULL AFTER help_text;

ALTER TABLE contact_form_submissions
        MODIFY COLUMN status ENUM('new','read','reviewed','archived','spam') NOT NULL DEFAULT 'new',
        ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER created_at,
        ADD COLUMN reviewed_by INT UNSIGNED DEFAULT NULL AFTER reviewed_at,
        ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER reviewed_by;

UPDATE contact_form_submissions
SET status = 'reviewed'
WHERE status = 'read';

ALTER TABLE contact_form_submissions
        MODIFY COLUMN status ENUM('new','reviewed','archived','spam') NOT NULL DEFAULT 'new';

UPDATE contact_form_submissions
SET reviewed_at = created_at
WHERE status IN ('reviewed', 'archived', 'spam')
    AND reviewed_at IS NULL;