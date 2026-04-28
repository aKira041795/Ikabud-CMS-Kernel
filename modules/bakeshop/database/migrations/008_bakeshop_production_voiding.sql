SET @_bakeshop_production_runs_voided_at := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_production_runs'
      AND column_name = 'voided_at'
);
SET @_bakeshop_production_runs_voided_at_sql := IF(
    @_bakeshop_production_runs_voided_at = 0,
    'ALTER TABLE bakeshop_production_runs ADD COLUMN voided_at DATETIME NULL AFTER notes',
    'SELECT 1'
);
PREPARE _bakeshop_production_runs_voided_at_stmt FROM @_bakeshop_production_runs_voided_at_sql;
EXECUTE _bakeshop_production_runs_voided_at_stmt;
DEALLOCATE PREPARE _bakeshop_production_runs_voided_at_stmt;

SET @_bakeshop_production_runs_voided_by := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_production_runs'
      AND column_name = 'voided_by'
);
SET @_bakeshop_production_runs_voided_by_sql := IF(
    @_bakeshop_production_runs_voided_by = 0,
    'ALTER TABLE bakeshop_production_runs ADD COLUMN voided_by VARCHAR(255) NULL AFTER voided_at',
    'SELECT 1'
);
PREPARE _bakeshop_production_runs_voided_by_stmt FROM @_bakeshop_production_runs_voided_by_sql;
EXECUTE _bakeshop_production_runs_voided_by_stmt;
DEALLOCATE PREPARE _bakeshop_production_runs_voided_by_stmt;

SET @_bakeshop_production_runs_void_reason := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_production_runs'
      AND column_name = 'void_reason'
);
SET @_bakeshop_production_runs_void_reason_sql := IF(
    @_bakeshop_production_runs_void_reason = 0,
    'ALTER TABLE bakeshop_production_runs ADD COLUMN void_reason TEXT NULL AFTER voided_by',
    'SELECT 1'
);
PREPARE _bakeshop_production_runs_void_reason_stmt FROM @_bakeshop_production_runs_void_reason_sql;
EXECUTE _bakeshop_production_runs_void_reason_stmt;
DEALLOCATE PREPARE _bakeshop_production_runs_void_reason_stmt;

DROP VIEW IF EXISTS `bakeshop_ingredient_usage`;

CREATE VIEW `bakeshop_ingredient_usage` AS
SELECT
    branch_rows.`branch_id` AS `branch_id`,
    branches.`name` AS `branch_name`,
    branch_rows.`ingredient_id` AS `ingredient_id`,
    ingredients.`name` AS `ingredient_name`,
    branch_rows.`dimension` AS `dimension`,
    branch_rows.`period_date` AS `period_date`,
    SUM(branch_rows.`delivered_qty_base`) AS `delivered_qty_base`,
    SUM(branch_rows.`consumed_qty_base`) AS `consumed_qty_base`,
    SUM(branch_rows.`delivered_qty_base`) - SUM(branch_rows.`consumed_qty_base`) AS `variance_qty_base`
FROM (
    SELECT
        d.`branch_id` AS `branch_id`,
        di.`ingredient_id` AS `ingredient_id`,
        DATE(d.`delivered_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        di.`qty` * u.`factor_to_base` AS `delivered_qty_base`,
        0.0000 AS `consumed_qty_base`
    FROM `bakeshop_deliveries` d
    INNER JOIN `bakeshop_delivery_items` di ON di.`delivery_id` = d.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = di.`unit_id`

    UNION ALL

    SELECT
        pr.`branch_id` AS `branch_id`,
        pi.`ingredient_id` AS `ingredient_id`,
        DATE(pr.`produced_at`) AS `period_date`,
        u.`dimension` AS `dimension`,
        0.0000 AS `delivered_qty_base`,
        pi.`qty_used` * u.`factor_to_base` AS `consumed_qty_base`
    FROM `bakeshop_production_runs` pr
    INNER JOIN `bakeshop_production_items` pi ON pi.`run_id` = pr.`id`
    INNER JOIN `bakeshop_units` u ON u.`id` = pi.`unit_id`
    WHERE pr.`voided_at` IS NULL
) AS branch_rows
INNER JOIN `bakeshop_branches` branches ON branches.`id` = branch_rows.`branch_id`
INNER JOIN `bakeshop_ingredients` ingredients ON ingredients.`id` = branch_rows.`ingredient_id`
GROUP BY
    branch_rows.`branch_id`,
    branches.`name`,
    branch_rows.`ingredient_id`,
    ingredients.`name`,
    branch_rows.`dimension`,
    branch_rows.`period_date`;