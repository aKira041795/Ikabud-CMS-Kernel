-- Academic Thesis Evaluation — Revision Requests
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_revision_requests (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               VARCHAR(64) NOT NULL,
    evaluation_case_id      INT UNSIGNED NOT NULL,
    source_stage_id         INT UNSIGNED DEFAULT NULL,
    manuscript_version_id   INT UNSIGNED DEFAULT NULL COMMENT 'Manuscript version the revision applies to',
    category                VARCHAR(100) NOT NULL DEFAULT 'other' COMMENT 'formatting, citation, attribution, methodology, analysis, literature_review, clarity, ethics, panel_comment, other',
    severity                ENUM('minor','major','critical') NOT NULL DEFAULT 'minor',
    instruction             TEXT NOT NULL,
    evidence_reference      JSON DEFAULT NULL COMMENT 'Linked evidence or rubric references',
    assigned_to             INT UNSIGNED DEFAULT NULL COMMENT 'User ID responsible for resolving',
    status                  ENUM('open','in_progress','resolved','cancelled') NOT NULL DEFAULT 'open',
    due_at                  DATETIME DEFAULT NULL,
    resolved_in_version_id  INT UNSIGNED DEFAULT NULL COMMENT 'Manuscript version that resolved this',
    created_by              INT UNSIGNED NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at             DATETIME DEFAULT NULL,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_revision_case (evaluation_case_id),
    KEY idx_revision_status (status),
    KEY idx_revision_category (category),
    CONSTRAINT fk_revision_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_revision_stage FOREIGN KEY (source_stage_id) REFERENCES ate_workflow_stages(id) ON DELETE SET NULL,
    CONSTRAINT fk_revision_manuscript FOREIGN KEY (manuscript_version_id) REFERENCES ate_manuscript_versions(id) ON DELETE SET NULL,
    CONSTRAINT fk_revision_resolved FOREIGN KEY (resolved_in_version_id) REFERENCES ate_manuscript_versions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
