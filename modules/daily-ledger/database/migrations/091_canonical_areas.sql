-- 091: Canonical Daily Ledger areas (compatibility phase).
-- @mysql57-compat: guarded dynamic ALTERs; legacy text columns are retained.

CREATE TABLE IF NOT EXISTS dl_areas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dl_areas_code (code),
    KEY idx_dl_areas_active_order (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normalisation is deliberately used only as a guard. If two spellings reduce
-- to one key, stop and require the snapshot report + human decision; never
-- auto-merge them and never silently create two canonical rows.
SET @dl_area_near_duplicates := (
    SELECT COUNT(*) FROM (
        SELECT normalized
          FROM (
              SELECT LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) AS normalized,
                     TRIM(area) AS raw_value
                FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> ''
              UNION
              SELECT LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) AS normalized,
                     TRIM(area) AS raw_value
                FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> ''
          ) values_by_spelling
         GROUP BY normalized
        HAVING COUNT(DISTINCT raw_value) > 1
    ) duplicate_normalisations
);
SET @sql := IF(@dl_area_near_duplicates = 0, 'SELECT 1',
    'SELECT dl_area_near_duplicates_require_human_decision()');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO dl_areas (code, name, is_active, sort_order)
SELECT CASE normalized
           WHEN 'dapitan' THEN 'DAPITAN'
           WHEN 'dipolog' THEN 'DIPOLOG'
           WHEN 'mahayag' THEN 'MAHAYAG'
           WHEN 'molave' THEN 'MOLAVE'
           WHEN 'pagadian city' THEN 'PAGADIAN'
           ELSE CONCAT('AREA_', UPPER(SUBSTRING(SHA1(normalized), 1, 12)))
       END AS code,
       MIN(raw_value) AS name,
       1,
       0
  FROM (
      SELECT LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) AS normalized,
             TRIM(area) AS raw_value
        FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> ''
      UNION
      SELECT LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) AS normalized,
             TRIM(area) AS raw_value
        FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> ''
  ) historical_areas
 GROUP BY normalized;

SET @column_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'area_id');
SET @sql := IF(@column_exists = 0, 'ALTER TABLE dl_branches ADD COLUMN area_id INT UNSIGNED NULL AFTER area, ADD KEY idx_dl_branches_area_id (area_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_consignees' AND column_name = 'area_id');
SET @sql := IF(@column_exists = 0, 'ALTER TABLE dl_consignees ADD COLUMN area_id INT UNSIGNED NULL AFTER area, ADD KEY idx_dl_consignees_area_id (area_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE dl_branches b
JOIN dl_areas a ON LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(b.area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) = LOWER(TRIM(a.name))
SET b.area_id = a.id
WHERE b.area IS NOT NULL AND TRIM(b.area) <> '';

UPDATE dl_consignees c
JOIN dl_areas a ON LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.area, '\t', ' '), '\n', ' '), '  ', ' '), '  ', ' '), '  ', ' '))) = LOWER(TRIM(a.name))
SET c.area_id = a.id
WHERE c.area IS NOT NULL AND TRIM(c.area) <> '';

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'dl_branches' AND constraint_name = 'fk_dl_branches_area' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE dl_branches ADD CONSTRAINT fk_dl_branches_area FOREIGN KEY (area_id) REFERENCES dl_areas(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'dl_consignees' AND constraint_name = 'fk_dl_consignees_area' AND constraint_type = 'FOREIGN KEY');
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE dl_consignees ADD CONSTRAINT fk_dl_consignees_area FOREIGN KEY (area_id) REFERENCES dl_areas(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Falsifier: every non-empty historical value must now map exactly once.
SET @unmapped_areas := (
    SELECT (SELECT COUNT(*) FROM dl_branches WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL)
         + (SELECT COUNT(*) FROM dl_consignees WHERE area IS NOT NULL AND TRIM(area) <> '' AND area_id IS NULL)
);
SET @sql := IF(@unmapped_areas = 0, 'SELECT 1', 'SELECT dl_unmapped_areas_require_human_decision()');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
