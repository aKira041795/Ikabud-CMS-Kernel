-- 079: Freeze the creator/dispatcher display name on incoming-transfer rows.
--
-- Historical rows deliberately remain NULL. Incoming-transfer readers may use
-- the live user as a compatibility fallback, but every new online or replayed
-- withdrawal and the active delivery creation paths store the name visible when
-- the row was created.
-- Additive, guarded, rerun-safe, and MySQL 5.7-compatible (inspection only).

SET @sql := IF(
    EXISTS(
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'dl_cashier_withdrawals'
           AND column_name = 'encoded_by_name_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE dl_cashier_withdrawals ADD COLUMN encoded_by_name_snapshot VARCHAR(150) NULL DEFAULT NULL AFTER encoded_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'dl_deliveries'
           AND column_name = 'created_by_name_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE dl_deliveries ADD COLUMN created_by_name_snapshot VARCHAR(150) NULL DEFAULT NULL AFTER created_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
