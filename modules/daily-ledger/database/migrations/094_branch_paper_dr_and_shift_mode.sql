-- 094: Per-branch paper DR switch and inert single-shift mode flag
--
-- Both switches are additive. Existing branches retain paper DR and retain the
-- current two-shift behaviour. MySQL 5.7 compatible and rerun-safe.

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'paper_dr_enabled'), 'SELECT 1', 'ALTER TABLE dl_branches ADD COLUMN paper_dr_enabled TINYINT(1) NOT NULL DEFAULT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dl_branches' AND column_name = 'single_shift_mode'), 'SELECT 1', 'ALTER TABLE dl_branches ADD COLUMN single_shift_mode TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
