-- ============================================================
-- Migration 043: Custom adjustment reason
--
-- dl_cashier_withdrawals gains an optional free-text `custom_reason`
-- column for branch-side stock adjustments. When the cashier picks the
-- "other" reason code, the free-text reason is required and persisted
-- here so the Adjustment Log can show exactly why stock was corrected.
--
-- Bluehost MySQL 5.7 compatible (plain ALTER TABLE ADD COLUMN).
-- Rerun-safe: the migration runner tracks applied migrations by name,
-- so this runs exactly once per tenant database.
-- ============================================================

ALTER TABLE dl_cashier_withdrawals
  ADD COLUMN custom_reason VARCHAR(255) DEFAULT NULL AFTER reason_code;
