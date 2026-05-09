CREATE TABLE IF NOT EXISTS ehr_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note_uuid VARCHAR(64) NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    note_type VARCHAR(64) NOT NULL,
    current_version_id BIGINT UNSIGNED DEFAULT NULL,
    author_user_id BIGINT UNSIGNED DEFAULT NULL,
    authored_provider_id BIGINT UNSIGNED DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    signed_at DATETIME DEFAULT NULL,
    signed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    cosign_required TINYINT(1) NOT NULL DEFAULT 0,
    restricted_flag TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_notes_uuid (note_uuid),
    KEY idx_ehr_notes_patient (patient_id),
    KEY idx_ehr_notes_encounter (encounter_id),
    KEY idx_ehr_notes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_note_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    note_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    version_kind VARCHAR(32) NOT NULL,
    body_text LONGTEXT DEFAULT NULL,
    body_json JSON DEFAULT NULL,
    authored_at DATETIME NOT NULL,
    authored_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    sign_reason VARCHAR(255) DEFAULT NULL,
    amendment_reason VARCHAR(255) DEFAULT NULL,
    supersedes_version_id BIGINT UNSIGNED DEFAULT NULL,
    hash CHAR(64) NOT NULL,
    locked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_note_versions_note_version (note_id, version_no),
    KEY idx_ehr_note_versions_note (note_id),
    KEY idx_ehr_note_versions_supersedes (supersedes_version_id),
    CONSTRAINT fk_ehr_note_versions_note
        FOREIGN KEY (note_id) REFERENCES ehr_notes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_ehr_note_versions_supersedes
        FOREIGN KEY (supersedes_version_id) REFERENCES ehr_note_versions(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;