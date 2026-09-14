-- ============================================================
-- Migration 060: Snapshot the charged person's name
--
-- dl_cashier_withdrawals records WHO a charge is billed to by id only
-- (liable_user_id), and every surface resolved the name live from dl_users.
-- Renaming that account — reusing one shift account for whoever is on duty —
-- therefore re-labelled every past adjustment, silently changing who history
-- says was charged.
--
-- This migration makes the charge store the name as it was when the entry was
-- recorded. New charges write it; the operator surfaces prefer it and fall back
-- to the live user row for rows written before (see the backfill below).
--
-- Additive + idempotent: guarded ADD COLUMN (MySQL 5.7-safe), and the backfill
-- only touches rows that have no snapshot yet, so re-running is a no-op.
-- ============================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dl_cashier_withdrawals' AND column_name = 'liable_user_name'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE dl_cashier_withdrawals ADD COLUMN liable_user_name VARCHAR(150) NULL DEFAULT NULL AFTER liable_user_id",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: freeze the best name we have today for already-attributed rows, so a
-- later rename cannot rewrite them. Rows whose user is already gone stay NULL and
-- keep resolving live.
UPDATE dl_cashier_withdrawals cw
  JOIN dl_users u ON u.id = cw.liable_user_id
   SET cw.liable_user_name = COALESCE(NULLIF(u.full_name, ''), u.username)
 WHERE cw.liable_user_id IS NOT NULL
   AND (cw.liable_user_name IS NULL OR cw.liable_user_name = '');
