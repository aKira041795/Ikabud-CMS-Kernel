-- ============================================================
-- Daily Ledger Module — Consolidated user model
--
-- Replaces the four-table user model
--   (dl_admins, dl_supervisors, dl_cashiers, dl_production_incharges)
-- with a single dl_users table + dl_user_branches assignment table,
-- aligning the module with the conventions used by cms/bakeshop/wms/guidance.
--
-- The legacy tables are kept on disk for forensic reference only; no
-- application code touches them after this migration. Backfill maps
-- each legacy row into dl_users (preserving username, password hash,
-- soft-delete state) and remaps FK columns
-- (dl_daily_ledger.encoded_by/updated_by, dl_variance_flags.reviewed_by)
-- to point at the new dl_users.id.
--
-- Per docs/repo/migration-runner-statement-ordering.md, all CREATE TABLE
-- statements come first, then idempotent INSERT IGNORE backfills, then
-- the FK remap UPDATEs (the runner records the migration as applied
-- after success, so the remap runs exactly once per tenant).
-- ============================================================

CREATE TABLE IF NOT EXISTS dl_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','supervisor','cashier','production_in_charge') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL DEFAULT NULL,
    legacy_table VARCHAR(40) NULL DEFAULT NULL,
    legacy_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_users_username (username),
    UNIQUE KEY uq_dl_users_legacy (legacy_table, legacy_id),
    INDEX idx_dl_users_role (role),
    INDEX idx_dl_users_active (is_active),
    INDEX idx_dl_users_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dl_user_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dl_user_branch (user_id, branch_id),
    INDEX idx_dl_user_branches_branch (branch_id),
    CONSTRAINT fk_dl_user_branches_user FOREIGN KEY (user_id) REFERENCES dl_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_user_branches_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill users. Order is significant: when the same username appears in
-- multiple legacy tables, INSERT IGNORE keeps the first one inserted, so
-- precedence is admin > supervisor > production_in_charge > cashier.
INSERT IGNORE INTO dl_users (username, password_hash, full_name, role, is_active, deleted_at, legacy_table, legacy_id, created_at, updated_at)
SELECT username, password_hash, full_name, 'admin', is_active, deleted_at, 'dl_admins', id, created_at, updated_at
FROM dl_admins;

INSERT IGNORE INTO dl_users (username, password_hash, full_name, role, is_active, deleted_at, legacy_table, legacy_id, created_at, updated_at)
SELECT username, password_hash, full_name, 'supervisor', is_active, deleted_at, 'dl_supervisors', id, created_at, updated_at
FROM dl_supervisors;

INSERT IGNORE INTO dl_users (username, password_hash, full_name, role, is_active, deleted_at, legacy_table, legacy_id, created_at, updated_at)
SELECT username, password_hash, full_name, 'production_in_charge', is_active, deleted_at, 'dl_production_incharges', id, created_at, updated_at
FROM dl_production_incharges;

INSERT IGNORE INTO dl_users (username, password_hash, full_name, role, is_active, deleted_at, legacy_table, legacy_id, created_at, updated_at)
SELECT username, password_hash, full_name, 'cashier', is_active, deleted_at, 'dl_cashiers', id, created_at, updated_at
FROM dl_cashiers;

-- Backfill branch assignments.
-- Cashiers: single branch from dl_cashiers.branch_id.
INSERT IGNORE INTO dl_user_branches (user_id, branch_id)
SELECT u.id, c.branch_id
FROM dl_cashiers c
INNER JOIN dl_users u ON u.legacy_table = 'dl_cashiers' AND u.legacy_id = c.id
INNER JOIN dl_branches b ON b.id = c.branch_id
WHERE c.branch_id IS NOT NULL;

-- Supervisors: many-to-many from dl_supervisor_branches.
INSERT IGNORE INTO dl_user_branches (user_id, branch_id)
SELECT u.id, sb.branch_id
FROM dl_supervisor_branches sb
INNER JOIN dl_users u ON u.legacy_table = 'dl_supervisors' AND u.legacy_id = sb.supervisor_id
INNER JOIN dl_branches b ON b.id = sb.branch_id;

-- Production in-charge: many-to-many from dl_production_incharge_branches.
INSERT IGNORE INTO dl_user_branches (user_id, branch_id)
SELECT u.id, pb.branch_id
FROM dl_production_incharge_branches pb
INNER JOIN dl_users u ON u.legacy_table = 'dl_production_incharges' AND u.legacy_id = pb.production_incharge_id
INNER JOIN dl_branches b ON b.id = pb.branch_id;

-- Remap FK columns from legacy ids to new dl_users.id.
-- When the same legacy_id exists in multiple tables (different roles can
-- reuse low integer ids), we resolve by precedence:
-- cashier (most common encoder) > admin > supervisor > production_in_charge.
-- The trailing COALESCE fallback to the original value keeps the column
-- unchanged if no legacy mapping is found (defensive against partial reruns).
UPDATE dl_daily_ledger dl
LEFT JOIN dl_users uc ON uc.legacy_table = 'dl_cashiers' AND uc.legacy_id = dl.encoded_by
LEFT JOIN dl_users ua ON ua.legacy_table = 'dl_admins' AND ua.legacy_id = dl.encoded_by
LEFT JOIN dl_users us ON us.legacy_table = 'dl_supervisors' AND us.legacy_id = dl.encoded_by
LEFT JOIN dl_users up ON up.legacy_table = 'dl_production_incharges' AND up.legacy_id = dl.encoded_by
SET dl.encoded_by = COALESCE(uc.id, ua.id, us.id, up.id, dl.encoded_by)
WHERE dl.encoded_by IS NOT NULL;

UPDATE dl_daily_ledger dl
LEFT JOIN dl_users uc ON uc.legacy_table = 'dl_cashiers' AND uc.legacy_id = dl.updated_by
LEFT JOIN dl_users ua ON ua.legacy_table = 'dl_admins' AND ua.legacy_id = dl.updated_by
LEFT JOIN dl_users us ON us.legacy_table = 'dl_supervisors' AND us.legacy_id = dl.updated_by
LEFT JOIN dl_users up ON up.legacy_table = 'dl_production_incharges' AND up.legacy_id = dl.updated_by
SET dl.updated_by = COALESCE(uc.id, ua.id, us.id, up.id, dl.updated_by)
WHERE dl.updated_by IS NOT NULL;

UPDATE dl_variance_flags vf
LEFT JOIN dl_users uc ON uc.legacy_table = 'dl_cashiers' AND uc.legacy_id = vf.reviewed_by
LEFT JOIN dl_users ua ON ua.legacy_table = 'dl_admins' AND ua.legacy_id = vf.reviewed_by
LEFT JOIN dl_users us ON us.legacy_table = 'dl_supervisors' AND us.legacy_id = vf.reviewed_by
LEFT JOIN dl_users up ON up.legacy_table = 'dl_production_incharges' AND up.legacy_id = vf.reviewed_by
SET vf.reviewed_by = COALESCE(uc.id, ua.id, us.id, up.id, vf.reviewed_by)
WHERE vf.reviewed_by IS NOT NULL;
