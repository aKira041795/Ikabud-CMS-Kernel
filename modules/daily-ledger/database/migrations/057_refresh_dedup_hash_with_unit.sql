-- ============================================================
-- Migration 057: Refresh dedup_hash to include withdrawal unit
--
-- The box/unit feature (migration 054) extended the deterministic
-- fingerprint dl_withdrawalDedupHash() with the resolved unit
-- ('pcs'|'box'). Migration 052 backfilled dedup_hash BEFORE unit
-- existed, so legacy rows carry a 10-field hash while new inserts
-- (online, offline, and cashier-withdrawal edit paths) carry an
-- 11-field hash that includes the unit. That asymmetry silently
-- bypasses the dedup guard when an identical re-submission arrives
-- from the unit-aware code path for a legacy row.
--
-- This idempotent refresh recomputes every row with the canonical
-- unit component (NULL/'' normalize to 'pcs', matching the PHP
-- helper default) so the stored fingerprint equals the app-side
-- contract byte-for-byte. Safe to re-run; the uq_dl_cw_dedup unique
-- index stays intact because exact-duplicate groups were already
-- reduced in migration 052, and a box line never hashes equal to a
-- pcs line of a different quantity.
-- ============================================================

UPDATE dl_cashier_withdrawals
   SET dedup_hash = SHA1(CONCAT_WS('|',
        branch_id, product_id, ledger_date, withdrawal_type,
        COALESCE(reason_code, ''), COALESCE(custom_reason, ''),
        COALESCE(dr_number, ''), COALESCE(target_branch_id, ''),
        quantity, COALESCE(liable_user_id, ''),
        COALESCE(NULLIF(unit, ''), 'pcs')))
 WHERE dedup_hash <> SHA1(CONCAT_WS('|',
        branch_id, product_id, ledger_date, withdrawal_type,
        COALESCE(reason_code, ''), COALESCE(custom_reason, ''),
        COALESCE(dr_number, ''), COALESCE(target_branch_id, ''),
        quantity, COALESCE(liable_user_id, ''),
        COALESCE(NULLIF(unit, ''), 'pcs')));
