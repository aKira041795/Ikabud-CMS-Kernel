-- Academic Thesis Evaluation — Evaluation Profiles
-- Each profile defines an institution-specific evaluation process.
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_evaluation_profiles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           VARCHAR(64) NOT NULL,
    code                VARCHAR(100) NOT NULL,
    name                VARCHAR(255) NOT NULL,
    degree_level        VARCHAR(100) NOT NULL,
    version             VARCHAR(20) NOT NULL DEFAULT '1.0',
    description         TEXT,
    status              ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    workflow_definition JSON DEFAULT NULL,
    rubric_definition   JSON DEFAULT NULL,
    policy_reference    TEXT,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile_tenant_code (tenant_id, code, version),
    KEY idx_profiles_tenant (tenant_id),
    KEY idx_profiles_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
