-- Kernel Security Enhancements Migration
-- Adds tables for security logging, async jobs, and enhanced syscall tracking

-- Security log table
CREATE TABLE IF NOT EXISTS `kernel_security_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(50) NOT NULL,
  `syscall_name` VARCHAR(100) DEFAULT NULL,
  `identifier` VARCHAR(255) DEFAULT NULL COMMENT 'IP address, user role, or other identifier',
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_syscall_name` (`syscall_name`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Security events and violations log';

-- Async jobs table for background syscalls
CREATE TABLE IF NOT EXISTS `kernel_async_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` VARCHAR(50) NOT NULL UNIQUE,
  `syscall_name` VARCHAR(100) NOT NULL,
  `args` JSON NOT NULL,
  `result` JSON DEFAULT NULL,
  `status` ENUM('pending', 'running', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_job_id` (`job_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Async background job queue';

-- Transaction log table
CREATE TABLE IF NOT EXISTS `kernel_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` VARCHAR(50) NOT NULL,
  `level` INT NOT NULL DEFAULT 1,
  `status` ENUM('active', 'committed', 'rolled_back') NOT NULL DEFAULT 'active',
  `operations_count` INT NOT NULL DEFAULT 0,
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `duration_ms` DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_transaction_id` (`transaction_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Transaction tracking and audit log';

-- Health check log table
CREATE TABLE IF NOT EXISTS `kernel_health_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` ENUM('healthy', 'warning', 'critical') NOT NULL,
  `checks` JSON NOT NULL COMMENT 'Detailed health check results',
  `uptime_seconds` DECIMAL(10,2) NOT NULL,
  `memory_usage_mb` DECIMAL(10,2) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Health check history';

-- Rate limit tracking table (in-memory alternative)
CREATE TABLE IF NOT EXISTS `kernel_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `syscall_name` VARCHAR(100) NOT NULL,
  `identifier` VARCHAR(255) NOT NULL,
  `request_count` INT NOT NULL DEFAULT 1,
  `window_start` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_request` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_syscall_identifier` (`syscall_name`, `identifier`, `window_start`),
  INDEX `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rate limit tracking per syscall and identifier';

-- Add indexes to existing kernel_syscalls table if it exists
ALTER TABLE `kernel_syscalls` 
  ADD INDEX IF NOT EXISTS `idx_status` (`status`),
  ADD INDEX IF NOT EXISTS `idx_execution_time` (`execution_time`);

-- Add resource usage tracking columns to instances table if they don't exist
ALTER TABLE `instances`
  ADD COLUMN IF NOT EXISTS `memory_limit_mb` INT DEFAULT 256 COMMENT 'Memory limit in MB',
  ADD COLUMN IF NOT EXISTS `cpu_limit_percent` INT DEFAULT 100 COMMENT 'CPU limit percentage',
  ADD COLUMN IF NOT EXISTS `storage_limit_mb` INT DEFAULT 5120 COMMENT 'Storage limit in MB (5GB default)',
  ADD COLUMN IF NOT EXISTS `cache_limit_mb` INT DEFAULT 512 COMMENT 'Cache limit in MB';

-- Create view for recent security events
CREATE OR REPLACE VIEW `v_recent_security_events` AS
SELECT 
  event_type,
  syscall_name,
  identifier,
  COUNT(*) as event_count,
  MAX(created_at) as last_occurrence
FROM kernel_security_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY event_type, syscall_name, identifier
ORDER BY event_count DESC;

-- Create view for syscall performance
CREATE OR REPLACE VIEW `v_syscall_performance` AS
SELECT 
  syscall_name,
  COUNT(*) as call_count,
  AVG(execution_time) as avg_time_ms,
  MAX(execution_time) as max_time_ms,
  MIN(execution_time) as min_time_ms,
  SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count,
  (SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) / COUNT(*)) * 100 as error_rate_percent
FROM kernel_syscalls
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY syscall_name
ORDER BY call_count DESC;

-- Insert default kernel configuration for new features
INSERT IGNORE INTO `kernel_config` (`key`, `value`, `type`, `description`) VALUES
('syscall_logging', 'true', 'boolean', 'Enable syscall execution logging'),
('rate_limiting_enabled', 'true', 'boolean', 'Enable rate limiting for syscalls'),
('security_strict_mode', 'false', 'boolean', 'Enable strict security mode'),
('health_check_interval', '300', 'integer', 'Health check interval in seconds'),
('transaction_timeout', '30', 'integer', 'Transaction timeout in seconds'),
('max_async_jobs', '100', 'integer', 'Maximum concurrent async jobs');
