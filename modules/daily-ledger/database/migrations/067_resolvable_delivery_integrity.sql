-- 067: editable, audited resolutions for unknown delivery origin and copied receipts
--
-- Existing rows intentionally remain NULL. A NULL count_basis is historical/unknown;
-- it must never be rewritten to either copied or independently_counted.
-- @mysql57-compat: guarded information_schema checks; no CTE/window/JSON_TABLE.

SET @resolved_origin_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_deliveries'
       AND column_name = 'resolved_origin_id'
);
SET @sql := IF(@resolved_origin_exists = 0,
    'ALTER TABLE dl_deliveries ADD COLUMN resolved_origin_id INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @count_basis_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_branch_receivings'
       AND column_name = 'count_basis'
);
SET @sql := IF(@count_basis_exists = 0,
    'ALTER TABLE dl_branch_receivings ADD COLUMN count_basis ENUM(''copied'',''independently_counted'') NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @count_resolved_by_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_branch_receivings'
       AND column_name = 'count_resolved_by'
);
SET @sql := IF(@count_resolved_by_exists = 0,
    'ALTER TABLE dl_branch_receivings ADD COLUMN count_resolved_by INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @count_resolved_at_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'dl_branch_receivings'
       AND column_name = 'count_resolved_at'
);
SET @sql := IF(@count_resolved_at_exists = 0,
    'ALTER TABLE dl_branch_receivings ADD COLUMN count_resolved_at DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
