-- 109: per-branch official data start.
--
-- Owner 2026-11-20: *"let's go further with this feature: per branch specificity"*.
--
-- The module already has ONE global `official_data_start` setting: the first date whose records count
-- as official. That is too blunt for a fleet whose branches did not all go live on the same day - a
-- shop onboarded in November must not inherit October, and a shop that has been official since
-- September must not have its September hidden because a newer branch joined later.
--
-- So each branch may now carry its OWN first official date. NULL means "inherit the global setting",
-- which keeps today's behaviour exactly for every existing row (this migration writes no data).
--
-- Effective start for a branch = COALESCE(dl_branches.official_data_start, <global setting>)
-- where an unset global means "no boundary at all". The helpers that own that rule are
-- dl_branchOfficialStart() and dl_officialStartSqlExpr(); nothing should re-derive it inline.
--
-- Idempotent: guarded by INFORMATION_SCHEMA, the same pattern as 101 / 104 / 106 / 107 / 108.
-- MySQL 5.7 compatible: no IF NOT EXISTS DDL, no CHECK constraints, no CTEs, no window functions.

SET @dl_branches_official_start_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND column_name = 'official_data_start'
);
SET @dl_branches_official_start_ddl := IF(
    @dl_branches_official_start_exists = 0,
    'ALTER TABLE dl_branches ADD COLUMN official_data_start DATE NULL DEFAULT NULL AFTER has_production',
    'SELECT 1'
);
PREPARE dl_branches_official_start_st FROM @dl_branches_official_start_ddl;
EXECUTE dl_branches_official_start_st;
DEALLOCATE PREPARE dl_branches_official_start_st;
