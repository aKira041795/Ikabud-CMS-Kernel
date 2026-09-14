-- ============================================================
-- Daily Ledger Module — Encoder-omission reason code
--
-- Add Stock (adjustment_add) normally resolves a shortage, so the
-- stock has to be charged to a liable person. That is wrong for the
-- common admin case: the cashier simply forgot to record stock that
-- was never lost. Nothing is chargeable, so no liable person is
-- recorded (liable_user_id stays NULL).
--
-- That case gets its own reason code, and the reason_code ENUM has
-- to accept it.
--
-- Plain ALTER (migration-056 style). Re-running against the same
-- definition is a no-op, and existing rows keep their values.
-- ============================================================

ALTER TABLE dl_cashier_withdrawals
    MODIFY COLUMN reason_code ENUM('spoilage','staff_meal','sampling','testing','promo','donation','damage','manual_adjustment','encoder_omission','other') NULL DEFAULT NULL;
