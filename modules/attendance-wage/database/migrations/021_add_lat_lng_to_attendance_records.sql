-- Add dedicated latitude/longitude columns to attendance_records
-- so coordinates are always stored regardless of clock-in type (office or on-site).
-- Uses procedural checks for idempotency across re-runs

SET @dbname = DATABASE();

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'latitude_in');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_records` ADD COLUMN `latitude_in` DECIMAL(10,7) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'longitude_in');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_records` ADD COLUMN `longitude_in` DECIMAL(10,7) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'latitude_out');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_records` ADD COLUMN `latitude_out` DECIMAL(10,7) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'longitude_out');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `attendance_records` ADD COLUMN `longitude_out` DECIMAL(10,7) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
