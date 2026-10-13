-- 093: the 'self_production' withdrawal reason — own production captured as Add Stock
--
-- Some branches produce stock themselves: it enters the branch with NO delivery
-- receipt and NO commissary dispatch. The owner's capture point (2026-10-13) is
-- the cashier ledger's Addt'l cell through the withdrawal modal, with the
-- dedicated type 'own_production' (VARCHAR column — no type migration needed)
-- and this reason code. The reason distinguishes own production from variance
-- corrections and encoder omissions in labels, filters and the addtl
-- reconciliation.
--
-- reason_code is an ENUM, so the new value needs the column re-declared.
-- Pattern: migration 058 (plain idempotent MODIFY COLUMN, MySQL 5.7-safe);
-- existing rows keep their values, no backfill required.
--
-- @mysql57-compat: single ALTER ... MODIFY, InnoDB table unchanged,
-- utf8mb4_unicode_ci respected, no index touched.

ALTER TABLE dl_cashier_withdrawals
    MODIFY COLUMN reason_code ENUM('spoilage','staff_meal','sampling','testing','promo','donation','damage','manual_adjustment','encoder_omission','self_production','other') NULL DEFAULT NULL;
