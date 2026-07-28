-- Academic Thesis Evaluation — Rubric Templates and Criteria
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS ate_rubric_templates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       VARCHAR(64) NOT NULL,
    code            VARCHAR(100) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    version         VARCHAR(20) NOT NULL DEFAULT '1.0',
    degree_level    VARCHAR(100) DEFAULT NULL,
    status          ENUM('active','draft','archived') NOT NULL DEFAULT 'draft',
    total_weight    DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rubric_tenant_code_version (tenant_id, code, version),
    KEY idx_rubric_tenant (tenant_id),
    KEY idx_rubric_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ate_rubric_criteria (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rubric_template_id      INT UNSIGNED NOT NULL,
    parent_id               INT UNSIGNED DEFAULT NULL,
    code                    VARCHAR(100) NOT NULL,
    label                   VARCHAR(255) NOT NULL,
    description             TEXT,
    weight                  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    score_min               DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    score_max               DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    required_comment_below  DECIMAL(5,2) DEFAULT NULL COMMENT 'Require comment when score is below this threshold',
    sort_order              INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_criteria_rubric (rubric_template_id),
    KEY idx_criteria_parent (parent_id),
    CONSTRAINT fk_criteria_rubric FOREIGN KEY (rubric_template_id) REFERENCES ate_rubric_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ate_rubric_responses (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id               VARCHAR(64) NOT NULL,
    evaluation_case_id      INT UNSIGNED NOT NULL,
    manuscript_version_id   INT UNSIGNED DEFAULT NULL,
    reviewer_assignment_id  INT UNSIGNED NOT NULL,
    criterion_id            INT UNSIGNED NOT NULL,
    score                   DECIMAL(5,2) DEFAULT NULL,
    comment                 TEXT,
    evidence_reference      JSON DEFAULT NULL COMMENT 'Linked AISS evidence or manuscript citations',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_response_assignment_criterion (reviewer_assignment_id, criterion_id, manuscript_version_id),
    KEY idx_response_case (evaluation_case_id),
    KEY idx_response_criterion (criterion_id),
    CONSTRAINT fk_response_case FOREIGN KEY (evaluation_case_id) REFERENCES ate_evaluation_cases(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_manuscript FOREIGN KEY (manuscript_version_id) REFERENCES ate_manuscript_versions(id) ON DELETE SET NULL,
    CONSTRAINT fk_response_assignment FOREIGN KEY (reviewer_assignment_id) REFERENCES ate_reviewer_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_criterion FOREIGN KEY (criterion_id) REFERENCES ate_rubric_criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
