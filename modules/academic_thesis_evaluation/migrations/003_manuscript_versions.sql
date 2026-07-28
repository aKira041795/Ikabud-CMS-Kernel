-- Academic Thesis Evaluation — Manuscript Versions
-- Immutable: every submission and revision creates a new row.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_manuscript_versions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    evaluation_case_id  INT UNSIGNED NOT NULL,
    version_number      INT UNSIGNED NOT NULL,
    file_reference      VARCHAR(500) NOT NULL COMMENT 'Path or storage key',
    file_hash           VARCHAR(128) NOT NULL COMMENT 'SHA-256 hash',
    word_count          INT UNSIGNED DEFAULT NULL,
    submitted_by        INT UNSIGNED NOT NULL,
    submission_note     TEXT,
    is_revision         TINYINT(1) NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manuscript_case_version (evaluation_case_id, version_number),
    KEY idx_manuscript_case (evaluation_case_id),
    KEY idx_manuscript_tenant (tenant_id),
    KEY idx_manuscript_hash (file_hash),
    CONSTRAINT fk_manuscript_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
