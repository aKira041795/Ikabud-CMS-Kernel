-- 081: Consignee destination identity and custody ledger.
--
-- MySQL 5.7 compatibility is INSPECTION-ONLY (no local 5.7 server). The two
-- material 5.7 risks are the ENUM rebuild and exact INT UNSIGNED FK matching.
-- Every ALTER is information_schema guarded and rerun-safe.

CREATE TABLE IF NOT EXISTS dl_consignees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    assigned_commissary_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_consignee_code (code),
    KEY idx_dl_consignee_commissary_active (assigned_commissary_id, is_active),
    CONSTRAINT fk_dl_consignee_commissary FOREIGN KEY (assigned_commissary_id) REFERENCES dl_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_consignee_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    consignee_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    shift ENUM('AM','PM') NOT NULL DEFAULT 'AM',
    price_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    beg_bal INT NOT NULL DEFAULT 0,
    addtl INT NOT NULL DEFAULT 0,
    withdraw INT NOT NULL DEFAULT 0,
    encoded_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_consignee_ledger_entry (consignee_id, product_id, ledger_date, shift),
    KEY idx_dl_consignee_ledger_date (ledger_date),
    KEY idx_dl_consignee_ledger_product (product_id),
    CONSTRAINT fk_dl_cl_consignee FOREIGN KEY (consignee_id) REFERENCES dl_consignees(id),
    CONSTRAINT fk_dl_cl_product FOREIGN KEY (product_id) REFERENCES dl_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'consignee_id'),
    'SELECT 1',
    'ALTER TABLE dl_deliveries ADD COLUMN consignee_id INT UNSIGNED NULL DEFAULT NULL AFTER destination_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'destination_type') LIKE '%''consignee''%',
    'SELECT 1',
    'ALTER TABLE dl_deliveries MODIFY COLUMN destination_type ENUM(''branch'',''own_account'',''reseller'',''customer'',''event'',''wastage'',''internal_use'',''adjustment'',''consignee'') NOT NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND index_name = 'idx_dl_deliveries_consignee'),
    'SELECT 1',
    'ALTER TABLE dl_deliveries ADD INDEX idx_dl_deliveries_consignee (consignee_id, status, delivery_date)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries' AND column_name = 'consignee_id' AND referenced_table_name = 'dl_consignees'),
    'SELECT 1',
    'ALTER TABLE dl_deliveries ADD CONSTRAINT fk_dl_delivery_consignee FOREIGN KEY (consignee_id) REFERENCES dl_consignees(id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Durable movement effect: the unique item key is the retry boundary. Reversal
-- changes status and posts an opposite movement; posted rows are never edited.
CREATE TABLE IF NOT EXISTS dl_consignee_ledger_effects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    delivery_item_id BIGINT UNSIGNED NOT NULL,
    consignee_id INT UNSIGNED NOT NULL,
    source_branch_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    ledger_date DATE NOT NULL,
    shift ENUM('AM','PM') NOT NULL,
    quantity INT NOT NULL,
    effect_status ENUM('applied','reversed') NOT NULL,
    applied_by INT UNSIGNED NULL,
    applied_at DATETIME NOT NULL,
    reversed_by INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    before_qty INT NOT NULL,
    after_qty INT NOT NULL,
    reverse_before_qty INT NULL,
    reverse_after_qty INT NULL,
    UNIQUE KEY uq_dl_consignee_effect_item (delivery_item_id),
    KEY idx_dl_consignee_effect_delivery (delivery_id, effect_status),
    CONSTRAINT fk_dl_cle_delivery FOREIGN KEY (delivery_id) REFERENCES dl_deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_cle_item FOREIGN KEY (delivery_item_id) REFERENCES dl_delivery_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_cle_consignee FOREIGN KEY (consignee_id) REFERENCES dl_consignees(id),
    CONSTRAINT fk_dl_cle_source FOREIGN KEY (source_branch_id) REFERENCES dl_branches(id),
    CONSTRAINT fk_dl_cle_product FOREIGN KEY (product_id) REFERENCES dl_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
