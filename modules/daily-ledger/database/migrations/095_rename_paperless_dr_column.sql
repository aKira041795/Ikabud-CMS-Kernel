-- 095: Rename the branch switch column to say what it actually means
--
-- 094 named this column paper_dr_enabled, but the switch has always governed the
-- OPPOSITE: whether cashiers may add stock WITHOUT a paper DR (Add Stock / own
-- production). Paper DR is always available at every branch. The name cost us one
-- inverted implementation, so it is renamed to paperless_dr_enabled.
--
-- MySQL 5.7 compatible and rerun-safe in three states:
--   legacy column only  -> rename it, preserving every branch's current value
--   neither column      -> add the new column at the safe default (1 = paperless allowed)
--   new column present  -> no-op

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'paper_dr_enabled')
    AND NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'paperless_dr_enabled'),
    'ALTER TABLE dl_branches CHANGE COLUMN paper_dr_enabled paperless_dr_enabled TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'paperless_dr_enabled'),
    'SELECT 1',
    'ALTER TABLE dl_branches ADD COLUMN paperless_dr_enabled TINYINT(1) NOT NULL DEFAULT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
