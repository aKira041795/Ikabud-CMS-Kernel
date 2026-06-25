-- Add days_worth to production runs so bakers can record fractional production days
-- (e.g., 0.5 for a half-day shift, 2.0 for extended weekend production).

SET @_bakeshop_production_runs_days_worth := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_production_runs'
      AND column_name = 'days_worth'
);
SET @_bakeshop_production_runs_days_worth_sql := IF(
    @_bakeshop_production_runs_days_worth = 0,
    'ALTER TABLE bakeshop_production_runs ADD COLUMN days_worth DECIMAL(14,4) NOT NULL DEFAULT 1.0000 AFTER void_reason',
    'SELECT 1'
);
PREPARE _bakeshop_production_runs_days_worth_stmt FROM @_bakeshop_production_runs_days_worth_sql;
EXECUTE _bakeshop_production_runs_days_worth_stmt;
DEALLOCATE PREPARE _bakeshop_production_runs_days_worth_stmt;
