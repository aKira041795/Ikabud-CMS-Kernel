-- ============================================================
-- 096: a branch can produce its own stock WITHOUT being a commissary
--
-- Client request 2026-10-31: "branches with production (not necessarily a commissary)
-- to have the capability to mark 'Own production' for additional qty."
--
-- Until now every production gate keyed on dl_branches.is_commissary, so a retail
-- branch that bakes could not be told apart from one that only sells - and the system
-- treated it as incapable of producing. This adds the explicit capability flag.
--
-- BACKFILL IS CURRENT-BEHAVIOUR-PRESERVING: a branch that already produces under the
-- present model keeps the capability - commissaries, and branches whose supply mode is
-- self_managed/hybrid (those resolve to local_production). Turning a branch off after
-- this migration is a deliberate admin action and is never undone by a re-run, because
-- the backfill is tied to the column being created here.
--
-- Bluehost MySQL 5.7 compatible: no CTE, no window functions, no stored procedures.
-- ============================================================

SET @dl_has_production_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND column_name = 'has_production'
);

SET @dl_has_production_ddl := IF(
    @dl_has_production_exists = 0,
    'ALTER TABLE dl_branches ADD COLUMN has_production TINYINT(1) NOT NULL DEFAULT 0 AFTER single_shift_mode',
    'SELECT 1'
);
PREPARE dl_has_production_st FROM @dl_has_production_ddl;
EXECUTE dl_has_production_st;
DEALLOCATE PREPARE dl_has_production_st;

SET @dl_has_production_backfill := IF(
    @dl_has_production_exists = 0,
    'UPDATE dl_branches SET has_production = 1 WHERE is_commissary = 1 OR default_supply_mode IN ("self_managed", "hybrid")',
    'SELECT 1'
);
PREPARE dl_has_production_bf FROM @dl_has_production_backfill;
EXECUTE dl_has_production_bf;
DEALLOCATE PREPARE dl_has_production_bf;
