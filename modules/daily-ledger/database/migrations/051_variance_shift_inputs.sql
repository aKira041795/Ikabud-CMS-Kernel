-- ============================================================
-- Migration 051: Variance flag shift-input snapshots
--
-- dl_variance_flags already snapshots the shift's beginning
-- (current_beg_bal), expected end and recorded end. Add the shift's
-- additional (addtl) and withdrawals (withdraw) so the variance view
-- can show the raw ledger inputs (shift beginning / additional /
-- withdrawals) instead of only the derived expected end — clearer for
-- reviewers to verify the variance from source numbers.
--
-- Idempotent: guarded ADD COLUMN via the SET @sql=IF(...)+PREPARE
-- pattern; backfill only fills rows that are still NULL.
-- ============================================================

SET @vf_addtl = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'addtl'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN addtl INT NULL AFTER current_beg_bal'
);
PREPARE vf_addtl_st FROM @vf_addtl;
EXECUTE vf_addtl_st;
DEALLOCATE PREPARE vf_addtl_st;

SET @vf_withdraw = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'withdraw'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN withdraw INT NULL AFTER addtl'
);
PREPARE vf_withdraw_st FROM @vf_withdraw;
EXECUTE vf_withdraw_st;
DEALLOCATE PREPARE vf_withdraw_st;

-- Backfill existing flags from their shift's ledger row (shift known).
-- Flags with unknown provenance (legacy shift NULL) are left NULL and are
-- regenerated with snapshots on the next recompute.
UPDATE dl_variance_flags vf
  LEFT JOIN dl_daily_ledger dl
    ON dl.branch_id = vf.branch_id
   AND dl.product_id = vf.product_id
   AND dl.ledger_date = vf.ledger_date
   AND dl.shift = vf.shift
   SET vf.addtl = COALESCE(vf.addtl, dl.addtl),
       vf.withdraw = COALESCE(vf.withdraw, dl.withdraw)
 WHERE vf.shift IS NOT NULL
   AND (vf.addtl IS NULL OR vf.withdraw IS NULL);
