-- Academic Similarity Module v1.0.0 — Public Results Schema
-- Adds submitter identity tracking for front-facing user result history.

SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE ac_similarity_submissions
    ADD COLUMN submitter_user_id   INT UNSIGNED NOT NULL DEFAULT 0 AFTER author_identifier,
    ADD COLUMN submitter_source    VARCHAR(20) NOT NULL DEFAULT '' AFTER submitter_user_id,
    ADD INDEX idx_submissions_submitter (submitter_user_id, tenant_id);

SET FOREIGN_KEY_CHECKS = 1;
