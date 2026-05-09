CREATE TABLE IF NOT EXISTS ehr_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    consent_type VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'granted',
    scope_json JSON DEFAULT NULL,
    granted_at DATETIME NOT NULL,
    expires_at DATETIME DEFAULT NULL,
    captured_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    document_id BIGINT UNSIGNED DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    revoked_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_consents_patient (patient_id),
    KEY idx_ehr_consents_type_status (consent_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_break_glass_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(64) NOT NULL DEFAULT 'patient',
    object_id VARCHAR(64) DEFAULT NULL,
    requested_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    reason_text VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    granted_at DATETIME NOT NULL,
    granted_until DATETIME NOT NULL,
    request_context_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_break_glass_patient (patient_id),
    KEY idx_ehr_break_glass_status (status),
    KEY idx_ehr_break_glass_window (granted_at, granted_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;