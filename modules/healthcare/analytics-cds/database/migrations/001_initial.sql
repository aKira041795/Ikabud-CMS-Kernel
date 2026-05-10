CREATE TABLE IF NOT EXISTS ehr_cds_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_uuid CHAR(36) NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT DEFAULT NULL,
    domain VARCHAR(48) NOT NULL DEFAULT 'general',
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    expression_json LONGTEXT NOT NULL,
    active_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ehr_cds_rules_uuid (rule_uuid),
    UNIQUE KEY uniq_ehr_cds_rules_code (code),
    KEY idx_ehr_cds_rules_active (active_flag),
    KEY idx_ehr_cds_rules_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_cds_evaluations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED DEFAULT NULL,
    context_json LONGTEXT DEFAULT NULL,
    matched_flag TINYINT(1) NOT NULL DEFAULT 0,
    evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_cds_eval_rule (rule_id, evaluated_at),
    KEY idx_ehr_cds_eval_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_cds_alerts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED DEFAULT NULL,
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context_json LONGTEXT DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    acknowledged_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_cds_alerts_status (status, severity),
    KEY idx_ehr_cds_alerts_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
