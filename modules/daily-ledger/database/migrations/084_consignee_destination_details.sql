-- 084: Consignee delivery details and independent price group.
--
-- MySQL 5.7 compatibility is INSPECTION-ONLY (no local 5.7 server). Column
-- widths and INT UNSIGNED signedness mirror dl_branches exactly.

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_consignees' AND column_name = 'area'),
    'SELECT 1',
    'ALTER TABLE dl_consignees ADD COLUMN area VARCHAR(100) NULL DEFAULT NULL AFTER name'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_consignees' AND column_name = 'address'),
    'SELECT 1',
    'ALTER TABLE dl_consignees ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL AFTER area'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_consignees' AND column_name = 'price_group_id'),
    'SELECT 1',
    'ALTER TABLE dl_consignees ADD COLUMN price_group_id INT UNSIGNED NULL DEFAULT NULL AFTER assigned_commissary_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'dl_consignees' AND index_name = 'idx_dl_consignees_price_group'),
    'SELECT 1',
    'ALTER TABLE dl_consignees ADD INDEX idx_dl_consignees_price_group (price_group_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'dl_consignees' AND constraint_name = 'fk_dl_consignees_price_group'),
    'SELECT 1',
    'ALTER TABLE dl_consignees ADD CONSTRAINT fk_dl_consignees_price_group FOREIGN KEY (price_group_id) REFERENCES dl_price_groups(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
