-- ============================================================
-- Migration 059: Refresh dedup_hash to include the ledger shift
--
-- The ledger is shift-scoped (AM/PM are separate dl_daily_ledger
-- rows), but the withdrawal fingerprint never included the shift.
-- The unique index uq_dl_cw_dedup therefore treated a PM line as an
-- exact duplicate of the same AM line and rejected it with
-- "Duplicate ignored — identical adjustment already recorded", so an
-- operator could not record the same adjustment on the other shift.
--
-- The fingerprint now carries the shift as its final component
-- (NULL/'' for legacy rows whose originating shift is unknown, which
-- matches the PHP helper's `?? ''`).
--
-- Idempotent: only rows whose stored hash differs are rewritten, and
-- a more specific fingerprint cannot merge two previously distinct
-- hashes, so uq_dl_cw_dedup stays satisfiable.
-- ============================================================

UPDATE dl_cashier_withdrawals
   SET dedup_hash = SHA1(CONCAT_WS('|',
        branch_id, product_id, ledger_date, withdrawal_type,
        COALESCE(reason_code, ''), COALESCE(custom_reason, ''),
        COALESCE(dr_number, ''), COALESCE(target_branch_id, ''),
        quantity, COALESCE(liable_user_id, ''),
        COALESCE(NULLIF(unit, ''), 'pcs'),
        COALESCE(shift, '')))
 WHERE dedup_hash <> SHA1(CONCAT_WS('|',
        branch_id, product_id, ledger_date, withdrawal_type,
        COALESCE(reason_code, ''), COALESCE(custom_reason, ''),
        COALESCE(dr_number, ''), COALESCE(target_branch_id, ''),
        quantity, COALESCE(liable_user_id, ''),
        COALESCE(NULLIF(unit, ''), 'pcs'),
        COALESCE(shift, '')));
