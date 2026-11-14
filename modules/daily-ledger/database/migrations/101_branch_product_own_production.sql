-- 101: Per-branch product eligibility for the cashier Own production entry.
-- Default 1 preserves every existing branch's choices on deploy.
-- MySQL 5.7 / rerun-safe.

SET @dl_allow_own_production_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branch_products'
       AND column_name = 'allow_own_production'
);
SET @dl_allow_own_production_ddl := IF(
    @dl_allow_own_production_exists = 0,
    'ALTER TABLE dl_branch_products ADD COLUMN allow_own_production TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
    'SELECT 1'
);
PREPARE dl_allow_own_production_st FROM @dl_allow_own_production_ddl;
EXECUTE dl_allow_own_production_st;
DEALLOCATE PREPARE dl_allow_own_production_st;
