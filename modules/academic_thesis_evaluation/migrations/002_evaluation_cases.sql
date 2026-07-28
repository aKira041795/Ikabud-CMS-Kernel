-- Academic Thesis Evaluation — Evaluation Cases
-- One case per thesis/dissertation submission.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_evaluation_cases (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                   VARCHAR(64) NOT NULL,
    profile_id                  INT UNSIGNED NOT NULL,
    submission_owner_id         INT UNSIGNED NOT NULL COMMENT 'User ID of the student/author',
    student_number              VARCHAR(100) DEFAULT NULL,
    program_id                  INT UNSIGNED DEFAULT NULL,
    title                       VARCHAR(500) NOT NULL,
    research_category           VARCHAR(255) DEFAULT NULL,
    thesis_type                 VARCHAR(100) DEFAULT NULL COMMENT 'e.g. masters_thesis, doctoral_dissertation',
    current_stage               VARCHAR(100) NOT NULL DEFAULT 'submission',
    status                      ENUM('submitted','in_review','revision','completed','withdrawn','deferred') NOT NULL DEFAULT 'submitted',
    active_manuscript_version_id INT UNSIGNED DEFAULT NULL,
    adviser_id                  INT UNSIGNED DEFAULT NULL,
    panel_chair_id              INT UNSIGNED DEFAULT NULL,
    ethics_approval_ref         VARCHAR(255) DEFAULT NULL,
    submitted_at                DATETIME DEFAULT NULL,
    completed_at                DATETIME DEFAULT NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cases_tenant (tenant_id),
    KEY idx_cases_profile (profile_id),
    KEY idx_cases_owner (submission_owner_id),
    KEY idx_cases_stage (current_stage),
    KEY idx_cases_status (status),
    CONSTRAINT fk_case_profile FOREIGN KEY (profile_id) REFERENCES ate_evaluation_profiles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
