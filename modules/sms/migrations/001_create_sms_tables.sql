CREATE TABLE IF NOT EXISTS `sms_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `recipient` VARCHAR(20) NOT NULL,
    `recipient_name` VARCHAR(100) NULL,
    `message` TEXT NOT NULL,
    `provider` VARCHAR(20) NOT NULL DEFAULT 'semaphore',
    `status` ENUM('pending', 'sent', 'failed', 'simulated') NOT NULL DEFAULT 'pending',
    `provider_message_id` VARCHAR(100) NULL,
    `provider_response` TEXT NULL,
    `error_message` VARCHAR(500) NULL,
    `trigger_event` VARCHAR(50) NULL COMMENT 'e.g. manual, test',
    `trigger_ref_id` INT UNSIGNED NULL,
    `sent_by` INT UNSIGNED NULL,
    `cost_credits` DECIMAL(8,4) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    INDEX `idx_sms_recipient` (`recipient`),
    INDEX `idx_sms_status` (`status`),
    INDEX `idx_sms_trigger` (`trigger_event`, `trigger_ref_id`),
    INDEX `idx_sms_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_key` VARCHAR(50) NOT NULL UNIQUE,
    `label` VARCHAR(100) NOT NULL,
    `template` TEXT NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed records removed for installer packaging.
