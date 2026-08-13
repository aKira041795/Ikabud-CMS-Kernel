-- ============================================================
-- Migration 047: Shift tag on POS sales (AM / PM attribution)
--
-- Business need: with the shift-period model, each POS sale is made by a
-- specific cashier during a specific shift. Tagging dl_pos_sales with the
-- shift lets the admin POS report split AM vs PM sales and keeps POS
-- attribution aligned with the manual ledger shift model.
--
-- The day-mode totals (branch-day close, variance) are unchanged: POS net
-- totals stay branch-day scoped. This column is informational/attribution
-- for reporting and filtering.
--
-- NULL = pre-shift / legacy rows or unresolved (default).
--
-- Bluehost MySQL 5.7 compatible (plain ALTER TABLE ADD COLUMN).
-- Rerun-safe via the migration runner (applied once per tenant by name).
-- ============================================================

ALTER TABLE dl_pos_sales
  ADD COLUMN shift ENUM('AM','PM') DEFAULT NULL AFTER cashier_id;
