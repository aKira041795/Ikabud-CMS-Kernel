-- Academic Thesis Evaluation — Workflow Stage Instances
-- Records each stage transition in a case's lifecycle.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_workflow_stages (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    evaluation_case_id  INT UNSIGNED NOT NULL,
    stage_code          VARCHAR(100) NOT NULL,
    stage_order         INT UNSIGNED NOT NULL DEFAULT 0,
    status              ENUM('pending','active','completed','skipped') NOT NULL DEFAULT 'pending',
    assigned_role       VARCHAR(100) DEFAULT NULL,
    opened_at           DATETIME DEFAULT NULL,
    due_at              DATETIME DEFAULT NULL,
    completed_at        DATETIME DEFAULT NULL,
    completed_by        INT UNSIGNED DEFAULT NULL,
    outcome             VARCHAR(100) DEFAULT NULL,
    notes               TEXT,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stage_case (evaluation_case_id),
    KEY idx_stage_tenant (tenant_id),
    KEY idx_stage_code (stage_code),
    KEY idx_stage_status (status),
    CONSTRAINT fk_stage_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
