-- 030_create_dc_ledger_groups.sql
-- Normalized ledger group taxonomy for Settings → Ledger configuration.
-- Decouples presentation grouping from free-text dc_categories.ledger_group.
-- dc_categories.ledger_group retained as readable compat; runtime queries
-- use ledger_group_id after this migration.
-- @mysql57-compat: InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `dc_ledger_groups` (
  `ledger_group_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ledger_group_id`),
  UNIQUE KEY `uk_dc_ledger_groups_name` (`name`),
  KEY `idx_dc_ledger_groups_sort` (`sort_order`),
  KEY `idx_dc_ledger_groups_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add ledger_group_id FK to dc_categories
SET @fkey_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dc_categories' AND column_name = 'ledger_group_id'
);

SET @sql := IF(@fkey_exists = 0,
    'ALTER TABLE `dc_categories`
     ADD COLUMN `ledger_group_id` INT DEFAULT NULL AFTER `ledger_group`,
     ADD KEY `idx_dc_categories_ledger_group_id` (`ledger_group_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: create one normalized group per distinct non-empty legacy ledger_group,
-- plus a fallback group for categories with NULL ledger_group (using category name).
INSERT IGNORE INTO `dc_ledger_groups` (`name`, `sort_order`, `is_active`, `version`)
SELECT TRIM(COALESCE(NULLIF(TRIM(c.`ledger_group`), ''), c.`name`)),
       MIN(c.`sort_order`), 1, 1
FROM `dc_categories` c
WHERE c.`is_active` = 1
GROUP BY TRIM(COALESCE(NULLIF(TRIM(c.`ledger_group`), ''), c.`name`));

-- Assign categories to their matching group
UPDATE `dc_categories` c
JOIN `dc_ledger_groups` g
  ON g.`name` = TRIM(COALESCE(NULLIF(TRIM(c.`ledger_group`), ''), c.`name`))
SET c.`ledger_group_id` = g.`ledger_group_id`
WHERE c.`is_active` = 1 AND c.`ledger_group_id` IS NULL;

-- Add FK constraint after backfill (categories may reference groups that now exist)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'dc_categories' AND constraint_name = 'fk_dc_categories_ledger_group'
);
SET @sql2 := IF(@fk_exists = 0,
    'ALTER TABLE `dc_categories`
     ADD CONSTRAINT `fk_dc_categories_ledger_group`
     FOREIGN KEY (`ledger_group_id`) REFERENCES `dc_ledger_groups` (`ledger_group_id`)
     ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;
