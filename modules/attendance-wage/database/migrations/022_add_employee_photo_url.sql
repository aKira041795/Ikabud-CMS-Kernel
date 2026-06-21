-- Adds photo_url column to employee_profiles for employee portrait/ID photo
-- Uses a procedural check for idempotency across re-runs

SET @dbname = DATABASE();
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'employee_profiles' AND COLUMN_NAME = 'photo_url');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `employee_profiles` ADD COLUMN `photo_url` VARCHAR(500) DEFAULT NULL AFTER `basic_salary`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'employee_profiles' AND INDEX_NAME = 'idx_photo');
SET @sql2 = IF(@idx_exists = 0,
    'ALTER TABLE `employee_profiles` ADD INDEX `idx_photo` (`photo_url`(191))',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
