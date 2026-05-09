CREATE TABLE IF NOT EXISTS ehr_encounters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    encounter_uuid VARCHAR(64) NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_type VARCHAR(64) NOT NULL,
    service_line VARCHAR(64) NOT NULL DEFAULT 'ambulatory',
    facility_id BIGINT UNSIGNED DEFAULT NULL,
    department_id BIGINT UNSIGNED DEFAULT NULL,
    location_id BIGINT UNSIGNED DEFAULT NULL,
    attending_provider_id BIGINT UNSIGNED DEFAULT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    reason_for_visit VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_encounters_uuid (encounter_uuid),
    KEY idx_ehr_encounters_patient (patient_id),
    KEY idx_ehr_encounters_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_vitals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    captured_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    captured_at DATETIME NOT NULL,
    height_cm DECIMAL(6,2) DEFAULT NULL,
    weight_kg DECIMAL(6,2) DEFAULT NULL,
    bmi DECIMAL(6,2) DEFAULT NULL,
    temperature_c DECIMAL(4,1) DEFAULT NULL,
    systolic_bp SMALLINT UNSIGNED DEFAULT NULL,
    diastolic_bp SMALLINT UNSIGNED DEFAULT NULL,
    pulse_bpm SMALLINT UNSIGNED DEFAULT NULL,
    respiratory_rate SMALLINT UNSIGNED DEFAULT NULL,
    spo2 DECIMAL(5,2) DEFAULT NULL,
    pain_score TINYINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_vitals_encounter (encounter_id),
    KEY idx_ehr_vitals_patient (patient_id),
    CONSTRAINT fk_ehr_vitals_encounter
        FOREIGN KEY (encounter_id) REFERENCES ehr_encounters(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;