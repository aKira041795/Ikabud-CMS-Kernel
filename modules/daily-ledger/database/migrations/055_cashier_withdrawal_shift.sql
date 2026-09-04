-- ============================================================
-- Migration 055: Record ledger shift on cashier withdrawals
--
-- dl_daily_ledger is shift-scoped (AM/PM, migration 044/045), but
-- dl_cashier_withdrawals never recorded which shift a row contributed
-- to. That makes the audited cashier edit (withdrawals/edit) unable to
-- recompute the correct shift-scoped ledger row: an AM row edited from
-- the PM view would adjust the PM ledger instead of the AM ledger.
--
-- Additive: shift VARCHAR(2) NULL (values 'AM'|'PM'). NULL = legacy
-- rows (pre-055) whose originating shift is unknown — the edit path
-- falls back to the actor's resolved shift for those, best-effort.
--
-- Idempotent: guarded ADD COLUMN (MySQL 5.7-safe).
-- ============================================================

ALTER TABLE dl_cashier_withdrawals
    ADD COLUMN shift VARCHAR(2) NULL DEFAULT NULL AFTER ledger_date;

ALTER TABLE dl_cashier_withdrawals
    ADD INDEX idx_dl_cw_branch_date_shift (branch_id, ledger_date, shift);
