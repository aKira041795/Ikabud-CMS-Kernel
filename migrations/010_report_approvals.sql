-- report_approvals depends on workflow_runs created by 009_kernel_workflow_runs.
-- In case 009 was skipped/missing, disable FK checks during CREATE.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS report_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    export_source VARCHAR(200) NOT NULL,
    export_format VARCHAR(10) NOT NULL DEFAULT 'csv',
    title VARCHAR(255) DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    requested_by INT UNSIGNED DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    rejected_by INT UNSIGNED DEFAULT NULL,
    reject_reason VARCHAR(500) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    workflow_run_id BIGINT UNSIGNED DEFAULT NULL,
    result_path VARCHAR(500) DEFAULT NULL,
    result_size BIGINT UNSIGNED DEFAULT NULL,
    result_mime VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    approved_at DATETIME NULL DEFAULT NULL,
    KEY idx_report_status (status),
    KEY idx_report_requested (requested_by),
    KEY idx_report_workflow (workflow_run_id),
    CONSTRAINT fk_report_workflow_run FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
