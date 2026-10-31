-- 097: Preserve the supply source of each individual receiving line.
--
-- Mixed DRs can contain commissary bread and Bodega non-bread.  The receiving
-- header remains unchanged; this line-level fact is the audit source of truth.
-- MySQL 5.7 / rerun-safe (no stored procedure required).

SET @dl_supply_origin_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branch_receiving_items'
       AND column_name = 'supply_origin'
);

SET @dl_supply_origin_ddl := IF(
    @dl_supply_origin_exists = 0,
    'ALTER TABLE dl_branch_receiving_items ADD COLUMN supply_origin ENUM(''bodega'',''commissary'',''branch'',''local_production'',''supplier'',''manual_adjustment'') NOT NULL DEFAULT ''commissary'' AFTER product_id',
    'SELECT 1'
);
PREPARE dl_supply_origin_st FROM @dl_supply_origin_ddl;
EXECUTE dl_supply_origin_st;
DEALLOCATE PREPARE dl_supply_origin_st;

-- Only rows that pre-date this column are backfilled.  Their parent receiving is
-- the historical source; product category must not rewrite that history.
SET @dl_supply_origin_backfill := IF(
    @dl_supply_origin_exists = 0,
    'UPDATE dl_branch_receiving_items ri INNER JOIN dl_branch_receivings br ON br.id = ri.receiving_id SET ri.supply_origin = br.origin_type',
    'SELECT 1'
);
PREPARE dl_supply_origin_bf FROM @dl_supply_origin_backfill;
EXECUTE dl_supply_origin_bf;
DEALLOCATE PREPARE dl_supply_origin_bf;

SET @dl_supply_origin_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'dl_branch_receiving_items'
       AND index_name = 'idx_dl_receiving_items_supply_origin'
);
SET @dl_supply_origin_index_ddl := IF(
    @dl_supply_origin_index_exists = 0,
    'ALTER TABLE dl_branch_receiving_items ADD INDEX idx_dl_receiving_items_supply_origin (supply_origin)',
    'SELECT 1'
);
PREPARE dl_supply_origin_idx FROM @dl_supply_origin_index_ddl;
EXECUTE dl_supply_origin_idx;
DEALLOCATE PREPARE dl_supply_origin_idx;
