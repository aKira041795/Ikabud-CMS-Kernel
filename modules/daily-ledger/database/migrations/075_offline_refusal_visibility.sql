-- ============================================================
-- Migration 075: Offline refusal visibility for the admin surface
--
-- Resolves the admin-visibility gap left open by lane cashier-truth-3-states
-- (HARPP decision #144, option 1). apiOfflineReconcile()/apiOfflineStatus()
-- now record the client-reported pending marker BEFORE the enrollment verdict,
-- and record the enrollment-validation refusal reason when they refuse, so a
-- stranded device is visible to the admin even when its grant is expired,
-- revoked, account-mismatched, schema-mismatched or inactive.
--
--   last_refusal_reason  precise diagnostic (expired / revoked /
--                        account-mismatch / schema-mismatch / inactive /
--                        branch-mismatch / scope-mismatch / not-found)
--   last_refusal_at      when that diagnostic was last observed
--
-- Both are ADMIN DIAGNOSTIC ONLY. They are never read as a gate and never
-- change whether a write is allowed; the cashier keeps plain language and
-- never sees these words. The server DB stays the single source of truth.
--
-- Bluehost MySQL 5.7 compatible: guarded ADD COLUMN / ADD INDEX via the
-- SET @sql=IF(...)+PREPARE pattern already used by migration 053, no window
-- functions, no CTEs, no JSON_TABLE, InnoDB. The added index lets the admin
-- device list (branch-scoped, status-agnostic) find reported unsynced work
-- without scanning the enrollment table.
-- ============================================================

SET @dl_oe_refusal_reason = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'last_refusal_reason'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN last_refusal_reason VARCHAR(40) NULL AFTER pending_fields'
);
PREPARE dl_oe_refusal_reason_st FROM @dl_oe_refusal_reason;
EXECUTE dl_oe_refusal_reason_st;
DEALLOCATE PREPARE dl_oe_refusal_reason_st;

SET @dl_oe_refusal_at = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'last_refusal_at'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN last_refusal_at DATETIME NULL AFTER last_refusal_reason'
);
PREPARE dl_oe_refusal_at_st FROM @dl_oe_refusal_at;
EXECUTE dl_oe_refusal_at_st;
DEALLOCATE PREPARE dl_oe_refusal_at_st;

-- The admin list is branch-scoped and no longer gated on status = 'active', so
-- the existing (status, last_reported_pending_count) index no longer leads with
-- a column the query constrains. Lead with branch_id instead.
SET @dl_oe_branch_pending_idx = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND INDEX_NAME = 'idx_dl_oe_branch_pending'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD INDEX idx_dl_oe_branch_pending (branch_id, last_reported_pending_count)'
);
PREPARE dl_oe_branch_pending_idx_st FROM @dl_oe_branch_pending_idx;
EXECUTE dl_oe_branch_pending_idx_st;
DEALLOCATE PREPARE dl_oe_branch_pending_idx_st;
