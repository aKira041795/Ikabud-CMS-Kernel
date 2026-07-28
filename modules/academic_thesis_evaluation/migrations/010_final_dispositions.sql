-- Academic Thesis Evaluation — Final Dispositions
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_final_dispositions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    evaluation_case_id  INT UNSIGNED NOT NULL,
    status              VARCHAR(100) NOT NULL COMMENT 'approved, approved_with_minor_revisions, approved_with_major_revisions, resubmission_required, deferred, referred_for_formal_integrity_review, not_approved, withdrawn',
    decision_summary    TEXT,
    conditions          TEXT COMMENT 'Conditions attached to approval',
    effective_date      DATE NOT NULL,
    decided_by          INT UNSIGNED NOT NULL,
    authority_role      VARCHAR(100) NOT NULL COMMENT 'Role of the deciding authority',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_disposition_case (evaluation_case_id),
    KEY idx_disposition_status (status),
    CONSTRAINT fk_disposition_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
