-- 073: composite index audit_logs (module, created_at) for the Activity view
--
-- Why: handleAdminActivity() filters `module = 'daily-ledger'` and orders by
-- `created_at DESC` with a LIMIT. With only the single-column idx_al_module,
-- MySQL satisfies the module equality and then has to filesort the entire
-- filtered set to satisfy the ordering. A composite (module, created_at) index
-- supplies BOTH the equality predicate and the ordering, so `ORDER BY created_at
-- DESC LIMIT ...` is served straight from the index with no filesort.
--
-- Measured on the restored live copy (audit_logs, 2026-09-01..2026-10-03, LIMIT 500):
--   before: key=idx_al_module, Extra="Using where; Using filesort", ~99.5 ms
--   after : key=idx_al_module_created, Extra="Using where; Backward index scan",
--           ~15.5 ms and no filesort.
--
-- Re-runnable: information_schema-guarded. If the index is already present this
-- is a clean no-op; if it is absent the dynamic statement creates it.
--
-- @mysql57-compat: information_schema lookup + PREPARE/EXECUTE/DEALLOCATE only.
-- No MySQL 8 features, no ALTER on the table definition.

SET @al_mc_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'audit_logs'
       AND index_name = 'idx_al_module_created'
);
SET @sql := IF(@al_mc_exists = 0,
    'CREATE INDEX idx_al_module_created ON audit_logs (module, created_at)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
