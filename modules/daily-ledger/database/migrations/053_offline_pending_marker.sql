-- ============================================================
-- Migration 053: Offline pending-work marker on device enrollments
--
-- Purpose (2026-08-19, baronledger incident): a cashier-entered ending
-- (bal_end) queued in the encrypted vault never synced after the vault
-- locked, so the admin never saw the value. The client cannot report
-- pending work while the vault is locked, but the plaintext op records
-- (client_op_id / type / state / created_at — envelope is the only
-- encrypted part) let it report a NON-decrypting pending summary on the
-- next contact (offline/status, offline/reconcile).
--
-- These columns store that client-reported visibility signal ONLY —
-- never ledger values, PINs, or keys. The server DB stays the single
-- source of truth; this marker is for admin visibility / integrity, never
-- a gate.
--
--   last_reported_pending_count  how many pending ops the device last reported
--   pending_since                earliest pending op timestamp (client-reported)
--   pending_fields               distinct ledger fields pending (e.g. 'bal_end')
--   sync_requested_at            admin-requested "sync now" reminder (future use)
--   sync_requested_by_user_id    admin who requested the reminder (future use)
--
-- Bluehost MySQL 5.7 compatible. Rerun-safe guarded ADD COLUMN via
-- SET @sql=IF(...)+PREPARE pattern.
-- ============================================================

SET @dl_oe_pending_count = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'last_reported_pending_count'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN last_reported_pending_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sync_at'
);
PREPARE dl_oe_pending_count_st FROM @dl_oe_pending_count;
EXECUTE dl_oe_pending_count_st;
DEALLOCATE PREPARE dl_oe_pending_count_st;

SET @dl_oe_pending_since = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'pending_since'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN pending_since DATETIME NULL AFTER last_reported_pending_count'
);
PREPARE dl_oe_pending_since_st FROM @dl_oe_pending_since;
EXECUTE dl_oe_pending_since_st;
DEALLOCATE PREPARE dl_oe_pending_since_st;

SET @dl_oe_pending_fields = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'pending_fields'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN pending_fields VARCHAR(255) NULL AFTER pending_since'
);
PREPARE dl_oe_pending_fields_st FROM @dl_oe_pending_fields;
EXECUTE dl_oe_pending_fields_st;
DEALLOCATE PREPARE dl_oe_pending_fields_st;

SET @dl_oe_sync_requested = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'sync_requested_at'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN sync_requested_at DATETIME NULL AFTER pending_fields'
);
PREPARE dl_oe_sync_requested_st FROM @dl_oe_sync_requested;
EXECUTE dl_oe_sync_requested_st;
DEALLOCATE PREPARE dl_oe_sync_requested_st;

SET @dl_oe_sync_requested_by = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND COLUMN_NAME = 'sync_requested_by_user_id'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD COLUMN sync_requested_by_user_id INT UNSIGNED NULL AFTER sync_requested_at'
);
PREPARE dl_oe_sync_requested_by_st FROM @dl_oe_sync_requested_by;
EXECUTE dl_oe_sync_requested_by_st;
DEALLOCATE PREPARE dl_oe_sync_requested_by_st;

-- Visibility index for the admin "devices with unsynced entries" query.
SET @dl_oe_pending_idx = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_offline_device_enrollments' AND INDEX_NAME = 'idx_dl_oe_pending'),
  'SELECT 1',
  'ALTER TABLE dl_offline_device_enrollments ADD INDEX idx_dl_oe_pending (status, last_reported_pending_count)'
);
PREPARE dl_oe_pending_idx_st FROM @dl_oe_pending_idx;
EXECUTE dl_oe_pending_idx_st;
DEALLOCATE PREPARE dl_oe_pending_idx_st;
