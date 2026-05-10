CREATE TABLE IF NOT EXISTS ehr_wards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    ward_type VARCHAR(40) NOT NULL DEFAULT 'general',
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    active_flag TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ehr_wards_code (code),
    KEY idx_ehr_wards_active (active_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_beds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ward_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ehr_beds_ward_code (ward_id, code),
    KEY idx_ehr_beds_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_admissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admission_uuid CHAR(36) NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    ward_id BIGINT UNSIGNED NOT NULL,
    bed_id BIGINT UNSIGNED DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'admitted',
    admitted_at DATETIME NOT NULL,
    discharged_at DATETIME DEFAULT NULL,
    discharge_disposition VARCHAR(64) DEFAULT NULL,
    attending_user_id BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ehr_admissions_uuid (admission_uuid),
    KEY idx_ehr_admissions_patient (patient_id),
    KEY idx_ehr_admissions_status (status),
    KEY idx_ehr_admissions_bed (bed_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_adt_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admission_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    from_bed_id BIGINT UNSIGNED DEFAULT NULL,
    to_bed_id BIGINT UNSIGNED DEFAULT NULL,
    occurred_at DATETIME NOT NULL,
    performed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_adt_events_admission (admission_id, occurred_at),
    KEY idx_ehr_adt_events_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
