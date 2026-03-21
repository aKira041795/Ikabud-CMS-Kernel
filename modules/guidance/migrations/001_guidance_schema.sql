-- Guidance Module Schema (imported from /var/www/html/guidance/database/install.sql)
-- Note: keep this idempotent (CREATE TABLE IF NOT EXISTS) for safe re-runs.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `gm_users` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) DEFAULT NULL,
    `last_name` VARCHAR(100) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `role` ENUM('admin','supervisor','counselor') NOT NULL DEFAULT 'counselor',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_role` (`role`),
    KEY `idx_active` (`is_active`),
    KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_token` (`token`),
    KEY `idx_email` (`email`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_otp_codes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `code` VARCHAR(10) NOT NULL,
    `purpose` VARCHAR(50) NOT NULL DEFAULT 'login',
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_purpose` (`email`, `purpose`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rate_key` VARCHAR(255) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 1,
    `window_start` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rate_key` (`rate_key`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_colleges` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_counselor_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `counselor_id` BIGINT UNSIGNED NOT NULL,
    `college_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_counselor_college` (`counselor_id`, `college_id`),
    KEY `idx_college` (`college_id`),
    KEY `idx_primary` (`college_id`, `is_primary`),
    CONSTRAINT `fk_assignment_college`
        FOREIGN KEY (`college_id`) REFERENCES `gm_colleges` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_cases` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_number` VARCHAR(50) NOT NULL,
    `student_id` VARCHAR(50) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `student_grade` VARCHAR(50) DEFAULT NULL,
    `student_section` VARCHAR(50) DEFAULT NULL,
    `date_of_birth` DATE DEFAULT NULL,
    `gender` VARCHAR(20) DEFAULT NULL,
    `nationality` VARCHAR(100) DEFAULT NULL,
    `civil_status` VARCHAR(30) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `student_mobile` VARCHAR(50) DEFAULT NULL,
    `student_email` VARCHAR(255) DEFAULT NULL,
    `college_id` INT UNSIGNED DEFAULT NULL,
    `counselor_id` INT DEFAULT NULL,
    `category` ENUM('general','academic','behavioral','emotional','family','peer','career','crisis','special_needs','substance','other') NOT NULL DEFAULT 'general',
    `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `presenting_issue` TEXT NOT NULL,
    `background_info` TEXT DEFAULT NULL,
    `status` ENUM('open','in_progress','on_hold','closed','archived') NOT NULL DEFAULT 'open',
    `is_urgent` TINYINT(1) NOT NULL DEFAULT 0,
    `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
    `parent_guardian_name` VARCHAR(255) DEFAULT NULL,
    `parent_guardian_contact` VARCHAR(100) DEFAULT NULL,
    `emergency_contact_address` TEXT DEFAULT NULL,
    `referral_source` ENUM('walk-in','follow-up','referred') DEFAULT 'walk-in',
    `referred_by` VARCHAR(255) DEFAULT NULL,
    `next_followup_date` DATE DEFAULT NULL,
    `resolution_summary` TEXT DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `closed_by` INT DEFAULT NULL,
    `sync_id` VARCHAR(100) DEFAULT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `last_modified_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    `deleted_by` INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_case_number` (`case_number`),
    UNIQUE KEY `uk_sync_id` (`sync_id`),
    KEY `idx_student` (`student_id`),
    KEY `idx_counselor` (`counselor_id`),
    KEY `idx_status` (`status`),
    KEY `idx_category` (`category`),
    KEY `idx_severity` (`severity`),
    KEY `idx_college` (`college_id`),
    KEY `idx_deleted` (`deleted_at`),
    KEY `idx_counselor_status` (`counselor_id`, `status`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_case_status_history` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `previous_status` VARCHAR(50) DEFAULT NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `changed_by` INT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_case` (`case_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_counselor_notes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `counselor_id` INT NOT NULL,
    `note_type` ENUM('session','phone','observation','consultation','followup','referral','other') NOT NULL DEFAULT 'session',
    `session_type` ENUM('walk-in','follow-up','referred') DEFAULT 'walk-in',
    `session_date` DATE DEFAULT NULL,
    `session_duration_minutes` INT DEFAULT NULL,
    `note_content` TEXT NOT NULL,
    `intervention_used` TEXT DEFAULT NULL,
    `student_response` TEXT DEFAULT NULL,
    `risk_level` ENUM('none','low','moderate','high','critical') NOT NULL DEFAULT 'none',
    `mood_assessment` VARCHAR(50) DEFAULT NULL,
    `action_taken` TEXT DEFAULT NULL,
    `mse_appearance` TEXT DEFAULT NULL,
    `mse_behavior` TEXT DEFAULT NULL,
    `mse_speech` TEXT DEFAULT NULL,
    `mse_emotions` TEXT DEFAULT NULL,
    `mse_thinking` TEXT DEFAULT NULL,
    `mse_cognition` TEXT DEFAULT NULL,
    `mse_judgment` TEXT DEFAULT NULL,
    `mse_reliability` TEXT DEFAULT NULL,
    `case_predisposition` TEXT DEFAULT NULL,
    `case_precipitating` TEXT DEFAULT NULL,
    `case_perpetuating` TEXT DEFAULT NULL,
    `case_protective` TEXT DEFAULT NULL,
    `observation_recommendation` TEXT DEFAULT NULL,
    `followup_required` TINYINT(1) NOT NULL DEFAULT 0,
    `followup_notes` TEXT DEFAULT NULL,
    `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
    `sync_id` VARCHAR(100) DEFAULT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sync_id` (`sync_id`),
    KEY `idx_case` (`case_id`),
    KEY `idx_counselor` (`counselor_id`),
    KEY `idx_session_date` (`session_date`),
    KEY `idx_risk_level` (`risk_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_type` VARCHAR(100) DEFAULT NULL,
    `file_size` BIGINT UNSIGNED DEFAULT NULL,
    `uploaded_by` INT DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sync_id` VARCHAR(100) DEFAULT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    `deleted_by` INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_case_id` (`case_id`),
    KEY `idx_uploaded_by` (`uploaded_by`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_deleted_at` (`deleted_at`),
    CONSTRAINT `fk_attachments_case`
        FOREIGN KEY (`case_id`) REFERENCES `gm_cases` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_appointment_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 30,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    `requires_case` TINYINT(1) NOT NULL DEFAULT 0,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`),
    KEY `idx_public` (`is_public`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_appointments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_id` INT DEFAULT NULL,
    `student_id` VARCHAR(50) DEFAULT NULL,
    `student_name` VARCHAR(255) DEFAULT NULL,
    `student_email` VARCHAR(255) DEFAULT NULL,
    `student_phone` VARCHAR(50) DEFAULT NULL,
    `student_college_id` INT UNSIGNED DEFAULT NULL,
    `student_year_level` VARCHAR(20) DEFAULT NULL,
    `counselor_id` INT NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `scheduled_time` TIME NOT NULL,
    `duration_minutes` INT NOT NULL DEFAULT 30,
    `appointment_type` ENUM('individual','group','parent','teacher','crisis','followup') DEFAULT 'individual',
    `appointment_type_id` INT UNSIGNED DEFAULT NULL,
    `purpose` TEXT DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT 'Guidance Office',
    `status` ENUM('pending','requested','confirmed','scheduled','rescheduled','completed','cancelled','no_show','rejected','waitlist') NOT NULL DEFAULT 'pending',
    `approved_at` DATETIME DEFAULT NULL,
    `approved_by` INT DEFAULT NULL,
    `rejected_at` DATETIME DEFAULT NULL,
    `rejected_by` INT DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL,
    `requested_by_student` TINYINT(1) NOT NULL DEFAULT 0,
    `request_message` TEXT DEFAULT NULL,
    `is_urgent` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `actual_start_time` TIME DEFAULT NULL,
    `actual_end_time` TIME DEFAULT NULL,
    `cancellation_reason` TEXT DEFAULT NULL,
    `cancelled_at` DATETIME DEFAULT NULL,
    `reminder_sent_at` DATETIME DEFAULT NULL,
    `sync_id` VARCHAR(100) DEFAULT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `created_by` INT DEFAULT NULL,
    `last_modified_by` INT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sync_id` (`sync_id`),
    KEY `idx_counselor_date` (`counselor_id`, `scheduled_date`),
    KEY `idx_status` (`status`),
    KEY `idx_student` (`student_id`),
    KEY `idx_scheduled_date` (`scheduled_date`),
    KEY `idx_date_status` (`scheduled_date`, `status`),
    KEY `idx_case_status` (`case_id`, `status`),
    KEY `idx_type_id` (`appointment_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_blocked_dates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `counselor_id` BIGINT UNSIGNED DEFAULT NULL,
    `blocked_date` DATE NOT NULL,
    `start_time` TIME DEFAULT NULL,
    `end_time` TIME DEFAULT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `block_type` ENUM('holiday','meeting','leave','training','other') NOT NULL DEFAULT 'other',
    `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_date` (`blocked_date`),
    KEY `idx_counselor` (`counselor_id`),
    KEY `idx_counselor_date` (`counselor_id`, `blocked_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL,
    `link` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME DEFAULT NULL,
    `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `email_sent_at` DATETIME DEFAULT NULL,
    `sms_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `sms_sent_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_user_unread` (`user_id`, `is_read`),
    KEY `idx_type` (`type`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_notification_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `recipient_email` VARCHAR(255) DEFAULT NULL,
    `recipient_phone` VARCHAR(50) DEFAULT NULL,
    `channel` ENUM('email','sms') NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `body` TEXT NOT NULL,
    `template` VARCHAR(100) DEFAULT NULL,
    `template_data` JSON DEFAULT NULL,
    `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts` INT NOT NULL DEFAULT 0,
    `last_attempt_at` DATETIME DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `reference_type` VARCHAR(50) DEFAULT NULL,
    `reference_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduled_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_channel_status` (`channel`, `status`),
    KEY `idx_scheduled` (`scheduled_at`),
    KEY `idx_reference` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` VARCHAR(50) NOT NULL DEFAULT 'string',
    `description` TEXT DEFAULT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by` INT DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_form_fields` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_type` VARCHAR(50) NOT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `field_label` VARCHAR(255) NOT NULL,
    `field_type` VARCHAR(50) NOT NULL DEFAULT 'text',
    `field_options` TEXT DEFAULT NULL,
    `placeholder` VARCHAR(255) DEFAULT NULL,
    `default_value` VARCHAR(255) DEFAULT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `field_group` VARCHAR(100) DEFAULT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `grid_column` VARCHAR(20) DEFAULT 'full',
    `validation_rules` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_field` (`form_type`, `field_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_audit_logs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `action` VARCHAR(100) NOT NULL,
    `table_name` VARCHAR(100) DEFAULT NULL,
    `record_id` INT DEFAULT NULL,
    `old_data` JSON DEFAULT NULL,
    `new_data` JSON DEFAULT NULL,
    `user_id` INT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_action` (`action`),
    KEY `idx_user` (`user_id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_table_record` (`table_name`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_sync_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `device_id` VARCHAR(100) DEFAULT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` BIGINT UNSIGNED DEFAULT NULL,
    `sync_id` VARCHAR(36) NOT NULL,
    `operation` ENUM('create','update','delete') NOT NULL,
    `payload` JSON NOT NULL,
    `client_version` INT UNSIGNED NOT NULL,
    `server_version` INT UNSIGNED DEFAULT NULL,
    `client_timestamp` DATETIME NOT NULL,
    `status` ENUM('pending','processing','completed','conflict','failed') NOT NULL DEFAULT 'pending',
    `conflict_type` VARCHAR(50) DEFAULT NULL,
    `conflict_data` JSON DEFAULT NULL,
    `resolution` ENUM('pending','client_wins','server_wins','merged','rejected') DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `resolved_by` BIGINT UNSIGNED DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_retries` INT UNSIGNED NOT NULL DEFAULT 3,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sync_id` (`sync_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_device_id` (`device_id`),
    KEY `idx_entity` (`entity_type`, `entity_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_trackers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `academic_year` VARCHAR(20) NULL,
    `college_id` BIGINT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`is_active`),
    KEY `idx_college` (`college_id`),
    KEY `idx_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_tracker_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tracker_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `deadline` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tracker` (`tracker_id`),
    KEY `idx_sort` (`tracker_id`, `sort_order`),
    CONSTRAINT `fk_tracker_items_tracker` FOREIGN KEY (`tracker_id`) REFERENCES `gm_trackers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_tracker_students` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tracker_id` BIGINT UNSIGNED NOT NULL,
    `student_id` VARCHAR(50) NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `college_id` BIGINT UNSIGNED NULL,
    `year_level` VARCHAR(20) NULL,
    `section` VARCHAR(50) NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tracker` (`tracker_id`),
    KEY `idx_student_id` (`student_id`),
    KEY `idx_college` (`college_id`),
    KEY `idx_tracker_student` (`tracker_id`, `student_id`),
    CONSTRAINT `fk_tracker_students_tracker` FOREIGN KEY (`tracker_id`) REFERENCES `gm_trackers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_tracker_submissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tracker_student_id` BIGINT UNSIGNED NOT NULL,
    `tracker_item_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'submitted', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    `submitted_at` DATETIME NULL,
    `verified_by` BIGINT UNSIGNED NULL,
    `remarks` TEXT NULL,
    `file_path` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_student_item` (`tracker_student_id`, `tracker_item_id`),
    KEY `idx_status` (`status`),
    KEY `idx_item` (`tracker_item_id`),
    CONSTRAINT `fk_submissions_student` FOREIGN KEY (`tracker_student_id`) REFERENCES `gm_tracker_students` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submissions_item` FOREIGN KEY (`tracker_item_id`) REFERENCES `gm_tracker_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gm_tracker_custom_fields` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tracker_id` BIGINT UNSIGNED NOT NULL,
    `column_name` VARCHAR(64) NOT NULL,
    `display_label` VARCHAR(255) NOT NULL,
    `field_type` VARCHAR(20) NOT NULL DEFAULT 'text',
    `source` ENUM('manual', 'csv_import') NOT NULL DEFAULT 'csv_import',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tracker_column` (`tracker_id`, `column_name`),
    CONSTRAINT `fk_custom_fields_tracker` FOREIGN KEY (`tracker_id`) REFERENCES `gm_trackers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
