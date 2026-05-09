CREATE TABLE IF NOT EXISTS ehr_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_uuid VARCHAR(64) NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    encounter_id BIGINT UNSIGNED NOT NULL,
    order_type VARCHAR(64) NOT NULL,
    ordering_provider_id BIGINT UNSIGNED DEFAULT NULL,
    priority VARCHAR(32) NOT NULL DEFAULT 'routine',
    status VARCHAR(32) NOT NULL DEFAULT 'requested',
    ordered_at DATETIME NOT NULL,
    clinical_question VARCHAR(255) DEFAULT NULL,
    destination_module VARCHAR(64) DEFAULT NULL,
    billing_ref_status VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ehr_orders_uuid (order_uuid),
    KEY idx_ehr_orders_patient (patient_id),
    KEY idx_ehr_orders_encounter (encounter_id),
    KEY idx_ehr_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ehr_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    item_code VARCHAR(64) DEFAULT NULL,
    code_system VARCHAR(64) DEFAULT NULL,
    item_label VARCHAR(255) NOT NULL,
    specimen_type VARCHAR(64) DEFAULT NULL,
    body_site VARCHAR(64) DEFAULT NULL,
    laterality VARCHAR(32) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'requested',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ehr_order_items_order (order_id),
    CONSTRAINT fk_ehr_order_items_order
        FOREIGN KEY (order_id) REFERENCES ehr_orders(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;