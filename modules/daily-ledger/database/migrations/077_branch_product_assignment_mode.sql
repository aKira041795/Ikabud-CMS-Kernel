-- ============================================================
-- Migration 077: per-product assignment intent for new branches
--
-- Owner decision 1 (2026-10-06): "all active branches" means branches
-- created LATER too. apiCreateBranch() therefore needs to know which
-- products opted into that intent, so the intent is persisted rather than
-- re-derived. 'all_active' keeps today's behaviour for every existing
-- product (all 182 in tenant 207); no backfill is required because the
-- DEFAULT applies to existing rows on ADD COLUMN. 'specific' products are
-- assigned only by the Slice B picker and are NOT auto-assigned to a later
-- branch.
--
-- Bluehost MySQL 5.7 compatible: guarded ADD COLUMN via the
-- SET @sql=IF(...)+PREPARE pattern already used by migration 075, no
-- window functions, no CTEs, no JSON_TABLE, InnoDB, re-run safe.
-- ============================================================

SET @dl_p_assignment_mode = IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dl_products' AND COLUMN_NAME = 'assignment_mode'),
  'SELECT 1',
  'ALTER TABLE dl_products ADD COLUMN assignment_mode ENUM(''all_active'',''specific'') NOT NULL DEFAULT ''all_active'' AFTER is_active'
);
PREPARE dl_p_assignment_mode_st FROM @dl_p_assignment_mode;
EXECUTE dl_p_assignment_mode_st;
DEALLOCATE PREPARE dl_p_assignment_mode_st;
