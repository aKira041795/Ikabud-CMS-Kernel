-- ============================================================
-- Migration 049: Nullable endings/sales + shift lifecycle + variances
--
-- 1. dl_daily_ledger.bal_end and sales become nullable:
--      NULL bal_end  = ending NOT yet counted (pending)
--      NULL sales    = sales pending (bal_end absent) — never 0
--    A legitimate recorded zero remains 0; "not counted" is NULL.
-- 2. New dl_ledger_shift_status (branch_id, ledger_date, shift)
--    tracks AM/PM open → finalized lifecycle. Finalized manual PM
--    is immutable and is the manual day-close gate.
-- 3. dl_variance_flags is repaired to the canonical schema:
--      + resolution_status (only present in the Bluehost installer
--        schema until now — module-migrated tenants get it here)
--      + kind ENUM(overnight,handoff,ending,sales)
--      + nullable shift, expected_end_bal, recorded_end_bal
--      + frozen_at (close freeze) and auto_clear_note
--    The old unique key (branch,product,date) is replaced with
--    (branch,product,date,kind,shift). Legacy rows are backfilled
--    conservatively: kind=overnight, shift unknown (NULL), reviewed
--    history preserved (is_reviewed=1 -> investigated).
--
-- Bluehost MySQL 5.7 compatible: no window functions / CTEs; single
-- statement ALTER via the SET @sql=IF(...)+PREPARE pattern; no
-- stored procedures (the module runner splits on ';').
-- ============================================================

-- ── 1. Nullable endings + sales ──────────────────────────────────────
ALTER TABLE dl_daily_ledger
  MODIFY COLUMN bal_end INT NULL DEFAULT NULL,
  MODIFY COLUMN sales INT NULL DEFAULT NULL;

-- ── 2. Shift lifecycle table ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dl_ledger_shift_status (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id INT UNSIGNED NOT NULL,
  ledger_date DATE NOT NULL,
  shift ENUM('AM','PM') NOT NULL,
  status ENUM('open','finalized') NOT NULL DEFAULT 'open',
  finalized_by INT UNSIGNED NULL,
  finalized_at DATETIME NULL,
  pending_notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dl_shift_status (branch_id, ledger_date, shift),
  KEY idx_dl_shift_status_branch_date (branch_id, ledger_date),
  CONSTRAINT fk_dl_shift_status_branch FOREIGN KEY (branch_id) REFERENCES dl_branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. dl_variance_flags canonical-schema repair ─────────────────────
-- resolution_status (may already exist from the Bluehost installer schema)
SET @vf_res = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'resolution_status'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN resolution_status ENUM(''unreviewed'',''investigated'',''corrected'') NOT NULL DEFAULT ''unreviewed'' AFTER is_reviewed'
);
PREPARE vf_res_st FROM @vf_res;
EXECUTE vf_res_st;
DEALLOCATE PREPARE vf_res_st;

-- kind (variance type)
SET @vf_kind = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'kind'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN kind ENUM(''overnight'',''handoff'',''ending'',''sales'') NOT NULL DEFAULT ''overnight'' AFTER ledger_date'
);
PREPARE vf_kind_st FROM @vf_kind;
EXECUTE vf_kind_st;
DEALLOCATE PREPARE vf_kind_st;

-- shift (nullable: legacy provenance unknown)
SET @vf_shift = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'shift'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN shift ENUM(''AM'',''PM'') NULL AFTER kind'
);
PREPARE vf_shift_st FROM @vf_shift;
EXECUTE vf_shift_st;
DEALLOCATE PREPARE vf_shift_st;

-- expected_end_bal / recorded_end_bal (derivation context)
SET @vf_exp = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'expected_end_bal'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN expected_end_bal INT NULL AFTER current_beg_bal'
);
PREPARE vf_exp_st FROM @vf_exp;
EXECUTE vf_exp_st;
DEALLOCATE PREPARE vf_exp_st;

SET @vf_rec = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'recorded_end_bal'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN recorded_end_bal INT NULL AFTER expected_end_bal'
);
PREPARE vf_rec_st FROM @vf_rec;
EXECUTE vf_rec_st;
DEALLOCATE PREPARE vf_rec_st;

-- frozen_at (manual day-close freeze snapshot)
SET @vf_frozen = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'frozen_at'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN frozen_at DATETIME NULL AFTER reviewed_at'
);
PREPARE vf_frozen_st FROM @vf_frozen;
EXECUTE vf_frozen_st;
DEALLOCATE PREPARE vf_frozen_st;

-- auto_clear_note (reviewed flag recomputed to zero)
SET @vf_note = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND COLUMN_NAME = 'auto_clear_note'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD COLUMN auto_clear_note VARCHAR(190) NULL AFTER review_note'
);
PREPARE vf_note_st FROM @vf_note;
EXECUTE vf_note_st;
DEALLOCATE PREPARE vf_note_st;

-- Replace the old unique keys with the kind+shift identity. Legacy deployments
-- carry the day-only key under either `uq_dl_variance` (canonical) or the older
-- `uq_variance` name; drop whichever exists, then add only when absent.
SET @vf_drop_uq_legacy = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND INDEX_NAME = 'uq_variance'),
  'ALTER TABLE dl_variance_flags DROP INDEX uq_variance',
  'SELECT 1'
);
PREPARE vf_drop_uq_legacy_st FROM @vf_drop_uq_legacy;
EXECUTE vf_drop_uq_legacy_st;
DEALLOCATE PREPARE vf_drop_uq_legacy_st;

SET @vf_drop_uq = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND INDEX_NAME = 'uq_dl_variance'),
  'ALTER TABLE dl_variance_flags DROP INDEX uq_dl_variance',
  'SELECT 1'
);
PREPARE vf_drop_uq_st FROM @vf_drop_uq;
EXECUTE vf_drop_uq_st;
DEALLOCATE PREPARE vf_drop_uq_st;

SET @vf_add_uq = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_variance_flags' AND INDEX_NAME = 'uq_dl_variance'),
  'SELECT 1',
  'ALTER TABLE dl_variance_flags ADD UNIQUE KEY uq_dl_variance (branch_id, product_id, ledger_date, kind, shift)'
);
PREPARE vf_add_uq_st FROM @vf_add_uq;
EXECUTE vf_add_uq_st;
DEALLOCATE PREPARE vf_add_uq_st;

-- ── 4. Conservative legacy backfills ─────────────────────────────────
-- Variance flags: existing rows were the beg-vs-prior-ending check →
-- kind=overnight, provenance shift unknown (NULL). Preserve review
-- history: is_reviewed=1 maps to 'investigated' (closest label).
UPDATE dl_variance_flags
   SET kind = 'overnight',
       expected_end_bal = COALESCE(expected_end_bal, prev_bal_end),
       recorded_end_bal = COALESCE(recorded_end_bal, current_beg_bal),
       resolution_status = CASE WHEN is_reviewed = 1 THEN 'investigated' ELSE resolution_status END
 WHERE kind = 'overnight' AND shift IS NULL;

-- Shift status: closed historical days are finalized (both shifts); open
-- days remain open (absent rows mean open). Recorded counts are untouched.
INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at)
SELECT lds.branch_id, lds.ledger_date, s.shift, 'finalized', lds.closed_by, lds.closed_at
  FROM dl_ledger_day_status lds
  JOIN (SELECT 'AM' AS shift UNION ALL SELECT 'PM') s
 WHERE lds.status = 'closed'
   AND NOT EXISTS (
       SELECT 1 FROM dl_ledger_shift_status x
        WHERE x.branch_id = lds.branch_id AND x.ledger_date = lds.ledger_date AND x.shift = s.shift
   );
