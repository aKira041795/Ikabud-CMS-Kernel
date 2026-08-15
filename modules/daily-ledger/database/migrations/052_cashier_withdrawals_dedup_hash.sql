-- ============================================================
-- Migration 052: Server-side dedup guard for cashier withdrawals
--
-- Incident (2026-08-15, branch 8): 12 duplicate pullout rows created
-- ~1 min apart inflated variance flags. The client idempotency key is
-- cache-backed only — a modal reopen, second tab, cache eviction, or
-- concurrent double-fire all bypass it. Add a deterministic fingerprint
-- (dedup_hash) with a UNIQUE index so the DB rejects an identical
-- re-submission atomically: cache-independent, durable, race-proof.
--
-- Idempotent: guarded ADD COLUMN / ADD INDEX via the SET @sql=IF(...)+
-- PREPARE pattern. Backfill only fills rows still ''. Pre-existing exact
-- duplicates (same fingerprint) are reduced to their earliest row so the
-- unique index can build — same semantics as
-- scripts/cleanup-duplicate-withdrawals.php.
-- ============================================================

SET @cw_dedup_col = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_cashier_withdrawals' AND COLUMN_NAME = 'dedup_hash'),
  'SELECT 1',
  'ALTER TABLE dl_cashier_withdrawals ADD COLUMN dedup_hash CHAR(40) NOT NULL DEFAULT "" AFTER liable_user_id'
);
PREPARE cw_dedup_col_st FROM @cw_dedup_col;
EXECUTE cw_dedup_col_st;
DEALLOCATE PREPARE cw_dedup_col_st;

-- Backfill: deterministic SHA1 fingerprint matching the app helper
-- dl_withdrawalDedupHash(). COALESCE(NULL, '') aligns CONCAT_WS separators
-- with PHP implode('|', [...]) so the hashes match byte-for-byte.
UPDATE dl_cashier_withdrawals
   SET dedup_hash = SHA1(CONCAT_WS('|',
        branch_id, product_id, ledger_date, withdrawal_type,
        COALESCE(reason_code, ''), COALESCE(custom_reason, ''),
        COALESCE(dr_number, ''), COALESCE(target_branch_id, ''),
        quantity, COALESCE(liable_user_id, '')))
 WHERE dedup_hash = '';

-- Reduce pre-existing exact duplicates to their earliest row so the unique
-- index below can build. No-op when no duplicates exist (verified clean on
-- baronledger / applicationostest as of this migration).
SET @cw_dedup_del = IF(
  EXISTS(SELECT 1 FROM dl_cashier_withdrawals GROUP BY dedup_hash HAVING COUNT(*) > 1),
  'DELETE cw FROM dl_cashier_withdrawals cw
     INNER JOIN (SELECT dedup_hash, MIN(id) AS keep_id
                   FROM dl_cashier_withdrawals GROUP BY dedup_hash) k
       ON k.dedup_hash = cw.dedup_hash
    WHERE cw.id <> k.keep_id',
  'SELECT 1'
);
PREPARE cw_dedup_del_st FROM @cw_dedup_del;
EXECUTE cw_dedup_del_st;
DEALLOCATE PREPARE cw_dedup_del_st;

-- The atomic, race-proof guard: an identical re-submission violates this.
SET @cw_dedup_idx = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_cashier_withdrawals' AND INDEX_NAME = 'uq_dl_cw_dedup'),
  'SELECT 1',
  'ALTER TABLE dl_cashier_withdrawals ADD UNIQUE INDEX uq_dl_cw_dedup (dedup_hash)'
);
PREPARE cw_dedup_idx_st FROM @cw_dedup_idx;
EXECUTE cw_dedup_idx_st;
DEALLOCATE PREPARE cw_dedup_idx_st;
