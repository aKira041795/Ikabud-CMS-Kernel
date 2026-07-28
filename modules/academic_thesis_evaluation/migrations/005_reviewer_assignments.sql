-- Academic Thesis Evaluation — Reviewer Assignments
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_reviewer_assignments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    evaluation_case_id  INT UNSIGNED NOT NULL,
    stage_id            INT UNSIGNED DEFAULT NULL,
    reviewer_id         INT UNSIGNED NOT NULL,
    reviewer_role       VARCHAR(100) NOT NULL COMMENT 'adviser, panel_chair, panel_member, methodologist, statistician, integrity_reviewer, graduate_coordinator, external_examiner',
    assignment_type     VARCHAR(50) NOT NULL DEFAULT 'primary' COMMENT 'primary, secondary, observer',
    status              ENUM('pending','accepted','declined','completed','withdrawn') NOT NULL DEFAULT 'pending',
    assigned_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at         DATETIME DEFAULT NULL,
    completed_at        DATETIME DEFAULT NULL,
    conflict_declared   TINYINT(1) NOT NULL DEFAULT 0,
    conflict_note       TEXT,
    UNIQUE KEY uq_assignment_case_reviewer_role (evaluation_case_id, reviewer_id, reviewer_role),
    KEY idx_assignment_case (evaluation_case_id),
    KEY idx_assignment_reviewer (reviewer_id),
    KEY idx_assignment_status (status),
    CONSTRAINT fk_assignment_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_stage FOREIGN KEY (stage_id) REFERENCES ate_workflow_stages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
