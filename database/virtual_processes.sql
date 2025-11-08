-- Virtual Processes Table
-- Simulates process tracking for shared hosting environments

CREATE TABLE IF NOT EXISTS `virtual_processes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `instance_id` VARCHAR(64) NOT NULL UNIQUE,
  `virtual_pid` VARCHAR(20) NOT NULL,
  `status` ENUM('running', 'stopped', 'error') DEFAULT 'running',
  `started_at` TIMESTAMP NULL,
  `stopped_at` TIMESTAMP NULL,
  `last_activity` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX `idx_instance_id` (`instance_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_virtual_pid` (`virtual_pid`),
  
  FOREIGN KEY (`instance_id`) REFERENCES `instances`(`instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
