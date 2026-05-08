-- ============================================================
-- Daily Ledger Module — Withdrawal reason codes (Phase C)
--
-- Adds reason_code to dl_cashier_withdrawals so non-selling
-- removals (charge/pullout) carry an explicit reason from the
-- spec list: spoilage, staff_meal, sampling, testing, promo,
-- donation, damage, manual_adjustment, other.
--
-- Backfills existing rows: 'charge' and 'pullout' default to
-- 'manual_adjustment' so historical data stays valid.
-- 'delivery' rows are left NULL since their inventory effect now
-- flows through dl_deliveries/dl_branch_receivings.
-- ============================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_cashier_withdrawals' AND column_name = 'reason_code'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE dl_cashier_withdrawals ADD COLUMN reason_code ENUM('spoilage','staff_meal','sampling','testing','promo','donation','damage','manual_adjustment','other') NULL DEFAULT NULL AFTER withdrawal_type",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE dl_cashier_withdrawals
   SET reason_code = 'manual_adjustment'
 WHERE reason_code IS NULL
   AND withdrawal_type IN ('charge','pullout');

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dl_cashier_withdrawals' AND index_name = 'idx_dl_cw_reason'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE dl_cashier_withdrawals ADD INDEX idx_dl_cw_reason (reason_code)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
