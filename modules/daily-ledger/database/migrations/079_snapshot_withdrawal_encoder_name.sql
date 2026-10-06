-- 079: Freeze the dispatching cashier's display name on cashier withdrawals.
--
-- Historical rows deliberately remain NULL. Incoming-transfer readers may use
-- the live user as a compatibility fallback, but every new online or replayed
-- withdrawal stores the name visible when it was encoded.
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
