CREATE TABLE IF NOT EXISTS ehr_access_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED DEFAULT NULL,
    policy_type VARCHAR(64) NOT NULL DEFAULT 'document',
    sensitivity_level VARCHAR(32) NOT NULL DEFAULT 'standard',
    department_scope_json JSON DEFAULT NULL,
    provider_scope_json JSON DEFAULT NULL,
    consent_required_flag TINYINT(1) NOT NULL DEFAULT 0,
    break_glass_only_flag TINYINT(1) NOT NULL DEFAULT 0,
    active_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_access_policies_patient (patient_id),
    KEY idx_ehr_access_policies_document (document_id),
    KEY idx_ehr_access_policies_sensitivity (sensitivity_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_uuid VARCHAR(64) NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    related_order_id BIGINT UNSIGNED DEFAULT NULL,
    related_result_id BIGINT UNSIGNED DEFAULT NULL,
    storage_key VARCHAR(255) NOT NULL,
    document_type VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED DEFAULT NULL,
    source VARCHAR(64) DEFAULT NULL,
    tag_json JSON DEFAULT NULL,
    sensitivity_level VARCHAR(32) NOT NULL DEFAULT 'standard',
    access_policy_id BIGINT UNSIGNED DEFAULT NULL,
    uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    uploaded_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_documents_uuid (document_uuid),
    KEY idx_ehr_documents_patient (patient_id),
    KEY idx_ehr_documents_encounter (encounter_id),
    KEY idx_ehr_documents_policy (access_policy_id),
    CONSTRAINT fk_ehr_documents_policy
        FOREIGN KEY (access_policy_id) REFERENCES ehr_access_policies(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;