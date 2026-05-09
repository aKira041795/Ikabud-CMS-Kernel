CREATE TABLE IF NOT EXISTS ehr_patients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_uuid VARCHAR(64) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    middle_name VARCHAR(120) DEFAULT NULL,
    sex VARCHAR(32) NOT NULL DEFAULT 'unknown',
    birth_date DATE NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    primary_phone VARCHAR(64) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    address_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_patients_uuid (patient_uuid),
    KEY idx_ehr_patients_name_birth (last_name, first_name, birth_date),
    KEY idx_ehr_patients_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_patient_identifiers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    identifier_type VARCHAR(64) NOT NULL,
    identifier_value VARCHAR(190) NOT NULL,
    issuing_authority VARCHAR(120) DEFAULT NULL,
    valid_from DATE DEFAULT NULL,
    valid_to DATE DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_patient_identifier (identifier_type, identifier_value),
    KEY idx_ehr_patient_identifiers_patient (patient_id),
    CONSTRAINT fk_ehr_patient_identifiers_patient
        FOREIGN KEY (patient_id) REFERENCES ehr_patients(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;