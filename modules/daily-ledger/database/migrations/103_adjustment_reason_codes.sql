-- ============================================================
-- Daily Ledger Module — branch adjustment reason codes
--
-- Owner direction 2026-11-16: retire "Encoder omission" from the
-- Open Stock Adjustment picker and replace it with three reasons
-- that say what actually happened:
--
--   wrong_entry          the entry was keyed wrongly (nothing lost)
--   missed_entry         stock existed but was never entered
--   unrecorded_delivery  an item arrived on a DR but was not written
--                        on the DR, while it is physically on hand
--
-- All three mean nothing was lost, so none of them charges a liable
-- person (see dl_adjustmentAddNeedsLiable()).
--
-- 'encoder_omission' is deliberately KEPT in the ENUM: rows already
-- recorded under it are real history and must stay editable and
-- reportable. It is removed from the picker, not from the ledger.
--
-- Plain ALTER (migration-058 style): re-running against the same
-- definition is a no-op and existing rows keep their values.
-- ============================================================

ALTER TABLE dl_cashier_withdrawals
    MODIFY COLUMN reason_code ENUM(
        'spoilage','staff_meal','sampling','testing','promo','donation','damage',
        'manual_adjustment','encoder_omission','self_production',
        'wrong_entry','missed_entry','unrecorded_delivery','other'
    ) NULL DEFAULT NULL;
