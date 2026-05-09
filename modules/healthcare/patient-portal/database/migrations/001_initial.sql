CREATE TABLE IF NOT EXISTS ehr_portal_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_uuid VARCHAR(64) NOT NULL UNIQUE,
    patient_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    last_login_at DATETIME DEFAULT NULL,
    token_version INT UNSIGNED NOT NULL DEFAULT 0,
    provisioned_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    deactivated_at DATETIME DEFAULT NULL,
    deactivation_reason VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ehr_portal_accounts_email (email),
    UNIQUE KEY uq_ehr_portal_accounts_patient (patient_id),
    KEY idx_ehr_portal_accounts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_portal_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    requester_ip VARCHAR(64) DEFAULT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ehr_portal_login_attempts_email_time (email, attempted_at),
    KEY idx_ehr_portal_login_attempts_ip_time (requester_ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
