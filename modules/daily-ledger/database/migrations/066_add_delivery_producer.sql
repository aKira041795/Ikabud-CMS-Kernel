-- 066: delayed entry of "who produced" on a paper DR
--
-- Paper reporting and digital encoding are not real time: the cashier encodes a
-- paper DR later. Until now dl_deliveries recorded only the encoder (created_by /
-- posted_by) and the encoding moment (posted_at), so the Daily Sheet evidence log
-- (the SENT rows) named the encoder as if they were the producer. This adds the
-- two optional columns the encoder can fill in from the paper sheet.
--
-- Additive only, guarded per column through information_schema, so re-running is
-- a no-op. The 118 existing rows keep both columns NULL and the SENT log falls
-- back to the encoder exactly as it did before.
--
-- @mysql57-compat: no window functions, no CTEs, no JSON_TABLE. `produced_by` is
-- INT UNSIGNED to match dl_users.id exactly (int unsigned NOT NULL AUTO_INCREMENT).
-- No FOREIGN KEY is added, so the migration cannot fail on an engine/collation
-- mismatch and a user deletion is never blocked by history.

SET @produced_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_deliveries'
       AND column_name = 'produced_by'
);
SET @sql := IF(@produced_by_exists = 0,
    'ALTER TABLE dl_deliveries ADD COLUMN produced_by INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @produced_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_deliveries'
       AND column_name = 'produced_at'
);
SET @sql := IF(@produced_at_exists = 0,
    'ALTER TABLE dl_deliveries ADD COLUMN produced_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
