-- 031_add_inventory_ending_count_metadata.sql
-- Distinguish a real ending count of zero from a legacy/unrecorded ending.
-- @mysql57-compat: ALTER TABLE via information_schema guard, InnoDB FKs.

SET @ending_counted_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_inventory_progress'
      AND column_name = 'ending_counted_at'
);

SET @sql := IF(@ending_counted_at_exists = 0,
    'ALTER TABLE `dc_inventory_progress`
     ADD COLUMN `ending_counted_at` DATETIME DEFAULT NULL AFTER `ending_qty`,
     ADD COLUMN `ending_counted_by` INT DEFAULT NULL AFTER `ending_counted_at`,
     ADD KEY `idx_dc_inv_progress_ending_counted_by` (`ending_counted_by`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ending_counted_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'dc_inventory_progress'
      AND constraint_name = 'fk_dc_inv_progress_ending_counted_by'
);

SET @sql2 := IF(@ending_counted_fk_exists = 0,
    'ALTER TABLE `dc_inventory_progress`
     ADD CONSTRAINT `fk_dc_inv_progress_ending_counted_by`
     FOREIGN KEY (`ending_counted_by`) REFERENCES `dc_users` (`user_id`)
     ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;
