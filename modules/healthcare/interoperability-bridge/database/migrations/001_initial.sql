CREATE TABLE IF NOT EXISTS ehr_interop_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    direction VARCHAR(16) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    message_type VARCHAR(64) NOT NULL,
    patient_id BIGINT UNSIGNED DEFAULT NULL,
    correlation_id VARCHAR(120) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'logged',
    payload_json LONGTEXT DEFAULT NULL,
    error_text TEXT DEFAULT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_interop_msg_patient (patient_id),
    KEY idx_ehr_interop_msg_protocol (protocol, direction),
    KEY idx_ehr_interop_msg_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_interop_identifier_map (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    local_entity VARCHAR(48) NOT NULL,
    local_id BIGINT UNSIGNED NOT NULL,
    external_system VARCHAR(120) NOT NULL,
    external_id VARCHAR(180) NOT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ehr_interop_idmap (local_entity, local_id, external_system),
    KEY idx_ehr_interop_idmap_external (external_system, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
