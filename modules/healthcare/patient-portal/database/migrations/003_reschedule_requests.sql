CREATE TABLE IF NOT EXISTS ehr_portal_reschedule_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    appointment_uuid VARCHAR(64) NOT NULL,
    appointment_type VARCHAR(128) DEFAULT NULL,
    scheduled_start DATETIME DEFAULT NULL,
    preferred_window VARCHAR(64) DEFAULT NULL,
    contact_method VARCHAR(32) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    requester_ip VARCHAR(64) DEFAULT NULL,
    handled_at DATETIME DEFAULT NULL,
    handled_by VARCHAR(128) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ehr_portal_reschedule_patient (patient_id, created_at),
    KEY idx_ehr_portal_reschedule_appt (appointment_uuid),
    KEY idx_ehr_portal_reschedule_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
