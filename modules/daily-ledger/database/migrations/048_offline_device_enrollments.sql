-- ============================================================
-- Migration 048: Offline device enrollments + sync receipts
--
-- Server-side state for the encrypted offline vault (PWA). A
-- device becomes eligible for offline access only after an
-- authenticated cashier/supervisor/admin explicitly enrolls the
-- device. The device stores an encrypted, tenant/user/branch
-- scoped snapshot + operation queue in IndexedDB; this table is
-- the server-authoritative enrollment/revocation/expiry record.
--
-- Security contract (enforced in code, not by the DB):
--   * NEVER store the PIN, the data-wrapping key, a bearer token,
--     a refresh token, CSRF, or any cloud credential.
--   * device_hash is a SHA-256 of (tenant_scope + '|' + device_id)
--     so the raw device id is not persisted in plaintext.
--   * tenant_scope/actor/branch/shift are DERIVED server-side from
--     the authenticated session at enroll time. The client never
--     supplies ownership claims.
--
-- Bluehost MySQL 5.7 compatible (no window functions / CTEs).
-- Rerun-safe: CREATE TABLE IF NOT EXISTS; the migration runner
-- applies each migration once per tenant by name. Inline keys keep
-- the CREATE atomic (MySQL 5.7 has no CREATE INDEX IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_offline_device_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_scope VARCHAR(190) NOT NULL,
  enrollment_id CHAR(36) NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  device_hash CHAR(64) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  branch_id INT UNSIGNED NOT NULL,
  role VARCHAR(40) NOT NULL,
  shift ENUM('AM','PM') NULL,
  grant_version INT UNSIGNED NOT NULL DEFAULT 1,
  schema_version INT UNSIGNED NOT NULL DEFAULT 1,
  bootstrap_version VARCHAR(40) NOT NULL DEFAULT '1',
  status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  issued_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by_user_id INT UNSIGNED NULL,
  revoked_reason VARCHAR(190) NULL,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_oe_enrollment_id (enrollment_id),
  KEY idx_dl_oe_tenant_device (tenant_scope, device_id),
  KEY idx_dl_oe_tenant_actor (tenant_scope, actor_user_id),
  KEY idx_dl_oe_tenant_branch (tenant_scope, branch_id),
  KEY idx_dl_oe_status_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactional sync receipts: each applied client operation records
-- a stable result so replays return an already-applied response and
-- are never double-applied. Scoped by enrollment + client_op_id.
CREATE TABLE IF NOT EXISTS dl_offline_sync_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_scope VARCHAR(190) NOT NULL,
  enrollment_id CHAR(36) NOT NULL,
  client_op_id VARCHAR(64) NOT NULL,
  operation_type VARCHAR(40) NOT NULL,
  status ENUM('applied','conflict','rejected') NOT NULL DEFAULT 'applied',
  result_json TEXT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_osr_enrollment_client_op (enrollment_id, client_op_id),
  KEY idx_dl_osr_tenant (tenant_scope),
  KEY idx_dl_osr_enrollment (enrollment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
