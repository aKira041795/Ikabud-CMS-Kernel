-- ============================================================
-- Daily Ledger Module — Branch price-group assignment
-- ============================================================

SET @has_branch_price_group := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND column_name = 'price_group_id'
);

SET @sql := IF(
    @has_branch_price_group = 0,
    'ALTER TABLE dl_branches ADD COLUMN price_group_id INT UNSIGNED NULL DEFAULT NULL AFTER assigned_commissary_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_branch_price_group_idx := (
    SELECT COUNT(*)
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND index_name = 'idx_dl_branches_price_group'
);

SET @sql := IF(
    @has_branch_price_group_idx = 0,
    'ALTER TABLE dl_branches ADD INDEX idx_dl_branches_price_group (price_group_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_branch_price_group_fk := (
    SELECT COUNT(*)
      FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'dl_branches'
       AND constraint_name = 'fk_dl_branches_pricegroup'
);

SET @sql := IF(
    @has_branch_price_group_fk = 0,
    'ALTER TABLE dl_branches ADD CONSTRAINT fk_dl_branches_pricegroup FOREIGN KEY (price_group_id) REFERENCES dl_price_groups(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE dl_branches b
JOIN dl_price_groups pg ON pg.is_default = 1 AND pg.is_active = 1
   SET b.price_group_id = pg.id
 WHERE b.price_group_id IS NULL;