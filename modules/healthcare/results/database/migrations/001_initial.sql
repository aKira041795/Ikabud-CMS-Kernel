CREATE TABLE IF NOT EXISTS ehr_lab_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    result_status VARCHAR(32) NOT NULL DEFAULT 'entered',
    observed_at DATETIME NOT NULL,
    value_text TEXT DEFAULT NULL,
    value_numeric DECIMAL(12,4) DEFAULT NULL,
    unit VARCHAR(32) DEFAULT NULL,
    reference_range_text VARCHAR(255) DEFAULT NULL,
    abnormal_flag VARCHAR(16) DEFAULT NULL,
    entered_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    verified_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    released_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_lab_results_order_item (order_item_id),
    KEY idx_ehr_lab_results_status (result_status),
    CONSTRAINT fk_ehr_lab_results_order_item
        FOREIGN KEY (order_item_id) REFERENCES ehr_order_items(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;