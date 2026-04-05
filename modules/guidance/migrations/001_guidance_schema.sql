-- ============================================================
-- Guidance Monitoring System - Consolidated Install Schema
-- Version: 1.0.0
-- Generated from production schema on 2026-02-14
-- 
-- Compatible with: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4 (full Unicode support)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USERS & AUTHENTICATION
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System users (counselors, supervisors, admins)';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Password reset tokens';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Two-factor OTP codes';

CREATE TABLE IF NOT EXISTS `gm_rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rate_key` VARCHAR(255) NOT NULL,
    `attempts` INT NOT NULL DEFAULT 1,
    `window_start` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rate_key` (`rate_key`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Rate limiting for login and API endpoints';

-- ============================================================
-- 2. COLLEGES & COUNSELOR ASSIGNMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_colleges` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(20) NOT NULL COMMENT 'e.g., CAS, COE, CBA',
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Colleges/departments for counselor assignment';

CREATE TABLE IF NOT EXISTS `gm_counselor_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `counselor_id` BIGINT UNSIGNED NOT NULL,
    `college_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary counselor for this college',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Counselor to college/department assignments';

CREATE TABLE IF NOT EXISTS `gm_counselor_availability` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `counselor_id` BIGINT UNSIGNED NOT NULL,
    `day_of_week` ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
    `slot_index` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_available` TINYINT(1) NOT NULL DEFAULT 1,
    `start_time` TIME DEFAULT NULL,
    `end_time` TIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_counselor_day_slot` (`counselor_id`, `day_of_week`, `slot_index`),
    KEY `idx_counselor_available` (`counselor_id`, `is_available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Per-counselor weekly availability for booking and scheduling';

-- ============================================================
-- 3. CASES
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_cases` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `case_number` VARCHAR(50) NOT NULL,
    `student_id` VARCHAR(50) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `student_grade` VARCHAR(50) DEFAULT NULL,
    `student_status` VARCHAR(100) DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Guidance counseling cases';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Case status change audit trail';

-- ============================================================
-- 4. COUNSELOR NOTES
-- ============================================================

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
    -- Mental Status Examination fields
    `mse_appearance` TEXT DEFAULT NULL,
    `mse_behavior` TEXT DEFAULT NULL,
    `mse_speech` TEXT DEFAULT NULL,
    `mse_emotions` TEXT DEFAULT NULL,
    `mse_thinking` TEXT DEFAULT NULL,
    `mse_cognition` TEXT DEFAULT NULL,
    `mse_judgment` TEXT DEFAULT NULL,
    `mse_reliability` TEXT DEFAULT NULL,
    -- Case formulation fields
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Confidential counselor session notes';

-- ============================================================
-- 5. ATTACHMENTS
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Case file attachments';

-- ============================================================
-- 6. APPOINTMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_appointment_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 30,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6366f1' COMMENT 'Hex color for calendar',
    `requires_case` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Must be linked to a case',
    `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Available for public booking',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`),
    KEY `idx_public` (`is_public`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Appointment type definitions';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Counseling appointments';

CREATE TABLE IF NOT EXISTS `gm_blocked_dates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `counselor_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to all counselors',
    `blocked_date` DATE NOT NULL,
    `start_time` TIME DEFAULT NULL COMMENT 'NULL = entire day blocked',
    `end_time` TIME DEFAULT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `block_type` ENUM('holiday','meeting','leave','training','other') NOT NULL DEFAULT 'other',
    `is_recurring` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Recurs annually',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_date` (`blocked_date`),
    KEY `idx_counselor` (`counselor_id`),
    KEY `idx_counselor_date` (`counselor_id`, `blocked_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Blocked dates for appointment scheduling';

-- ============================================================
-- 7. NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for system notifications',
    `type` VARCHAR(50) NOT NULL COMMENT 'appointment_request, appointment_approved, etc',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON DEFAULT NULL COMMENT 'Additional context data',
    `link` VARCHAR(255) DEFAULT NULL COMMENT 'URL to related resource',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System notifications';

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
    `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'appointment, case, etc',
    `reference_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `scheduled_at` DATETIME DEFAULT NULL COMMENT 'For delayed sending',
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_channel_status` (`channel`, `status`),
    KEY `idx_scheduled` (`scheduled_at`),
    KEY `idx_reference` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Outbound notification queue';

-- ============================================================
-- 8. SETTINGS & CONFIGURATION
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Module configuration settings';

CREATE TABLE IF NOT EXISTS `gm_form_fields` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `form_type` VARCHAR(50) NOT NULL COMMENT 'case, booking, appointment, note',
    `field_name` VARCHAR(100) NOT NULL,
    `field_label` VARCHAR(255) NOT NULL,
    `field_type` VARCHAR(50) NOT NULL DEFAULT 'text' COMMENT 'text, textarea, select, checkbox, date, email, tel, number, hidden',
    `field_options` TEXT DEFAULT NULL COMMENT 'JSON array for select options or config',
    `placeholder` VARCHAR(255) DEFAULT NULL,
    `default_value` VARCHAR(255) DEFAULT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System fields cannot be deleted',
    `field_group` VARCHAR(100) DEFAULT NULL COMMENT 'Group/section heading',
    `sort_order` INT NOT NULL DEFAULT 0,
    `grid_column` VARCHAR(20) DEFAULT 'full' COMMENT 'full, half',
    `validation_rules` TEXT DEFAULT NULL COMMENT 'JSON validation rules',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_field` (`form_type`, `field_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Dynamic form field configuration';

-- ============================================================
-- 9. AUDIT & SYNC
-- ============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='System audit trail';

CREATE TABLE IF NOT EXISTS `gm_sync_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `device_id` VARCHAR(100) DEFAULT NULL COMMENT 'Unique device identifier',
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'case, note, appointment, attachment',
    `entity_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL for creates',
    `sync_id` VARCHAR(36) NOT NULL COMMENT 'Client-generated UUID',
    `operation` ENUM('create','update','delete') NOT NULL,
    `payload` JSON NOT NULL COMMENT 'Full entity data',
    `client_version` INT UNSIGNED NOT NULL COMMENT 'Version at time of change',
    `server_version` INT UNSIGNED DEFAULT NULL COMMENT 'Current server version',
    `client_timestamp` DATETIME NOT NULL COMMENT 'When change was made on client',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Offline sync queue for PWA';

-- ============================================================
-- 10. MIGRATIONS TRACKING
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_migrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Migration version tracking';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- SEED DATA: Default Colleges
-- ============================================================

INSERT INTO `gm_colleges` (`code`, `name`, `sort_order`) VALUES
    ('CAS',  'College of Arts and Sciences', 1),
    ('COE',  'College of Engineering', 2),
    ('CBA',  'College of Business Administration', 3),
    ('CED',  'College of Education', 4),
    ('CICT', 'College of Information and Communications Technology', 5),
    ('CON',  'College of Nursing', 6),
    ('GRAD', 'Graduate School', 7),
    ('SHS',  'Senior High School', 8)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ============================================================
-- SEED DATA: Default Appointment Types
-- ============================================================

INSERT INTO `gm_appointment_types` (`code`, `name`, `duration_minutes`, `color`, `requires_case`, `is_public`, `sort_order`) VALUES
    ('walkin',     'Walk-in Consultation', 30, '#6366f1', 0, 1, 1),
    ('initial',    'Initial Assessment',   45, '#8b5cf6', 0, 1, 2),
    ('followup',   'Follow-up Session',    30, '#06b6d4', 1, 0, 3),
    ('counseling', 'Counseling Session',   45, '#10b981', 1, 0, 4),
    ('crisis',     'Crisis Intervention',  60, '#ef4444', 0, 1, 5),
    ('group',      'Group Session',        60, '#f59e0b', 0, 0, 6),
    ('parent',     'Parent Conference',    45, '#ec4899', 1, 0, 7),
    ('career',     'Career Counseling',    30, '#14b8a6', 0, 1, 8)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ============================================================
-- SEED DATA: Default Form Fields (case, booking, appointment)
-- ============================================================

INSERT INTO `gm_form_fields` (`form_type`, `field_name`, `field_label`, `field_type`, `field_group`, `field_options`, `placeholder`, `default_value`, `is_required`, `is_enabled`, `sort_order`, `grid_column`) VALUES
    -- Case form fields
    ('case', 'student_name',             'Student Name',           'text',     'Student Information', NULL, 'Full name', NULL, 1, 1, 1, 'half'),
    ('case', 'student_id',               'Student ID',             'text',     'Student Information', NULL, 'e.g., 2024-0001', NULL, 1, 1, 2, 'half'),
    ('case', 'college_id',               'College',                'select',   'Student Information', NULL, NULL, NULL, 1, 1, 3, 'half'),
    ('case', 'student_grade',            'Grade / Year Level',     'text',     'Student Information', NULL, 'e.g., 3rd Year', NULL, 1, 1, 4, 'half'),
    ('case', 'student_status',           'Student Status',         'select',   'Student Information', '["Active","At Risk","On Leave","Transferred","Dropped","Graduated"]', NULL, 'Active', 0, 1, 21, 'half'),
    ('case', 'student_section',          'Section',                'text',     'Student Information', NULL, 'e.g., Section A', NULL, 0, 1, 5, 'half'),
    ('case', 'category',                 'Category',               'select',   'Case Details', '["academic","behavioral","emotional","family","peer","career","crisis","special_needs","substance","other"]', NULL, NULL, 1, 1, 6, 'half'),
    ('case', 'severity',                 'Severity',               'select',   'Case Details', '["low","medium","high","critical"]', NULL, 'medium', 1, 1, 7, 'half'),
    ('case', 'presenting_issue',         'Presenting Issue',       'textarea', 'Case Details', NULL, 'Describe the main concern or issue...', NULL, 1, 1, 8, 'full'),
    ('case', 'background_info',          'Background Information', 'textarea', 'Case Details', NULL, 'Any relevant background...', NULL, 0, 1, 9, 'full'),
    ('case', 'is_urgent',                'Mark as Urgent',         'checkbox', 'Case Details', NULL, NULL, NULL, 0, 1, 10, 'half'),
    ('case', 'is_confidential',          'Confidential',           'checkbox', 'Case Details', NULL, NULL, NULL, 0, 1, 11, 'half'),
    ('case', 'parent_guardian_name',     'Parent/Guardian Name',   'text',     'Parent/Guardian', NULL, 'Parent/Guardian name', NULL, 0, 1, 12, 'half'),
    ('case', 'parent_guardian_contact',  'Parent/Guardian Contact', 'text',    'Parent/Guardian', NULL, 'Phone or email', NULL, 0, 1, 13, 'half'),
    ('case', 'date_of_birth',            'Date of Birth',          'date',     'Student Information', NULL, NULL, NULL, 0, 1, 14, 'half'),
    ('case', 'gender',                   'Gender',                 'select',   'Student Information', 'male,female,other', NULL, NULL, 0, 1, 15, 'half'),
    ('case', 'nationality',              'Nationality',            'text',     'Student Information', NULL, 'e.g., Filipino', NULL, 0, 1, 16, 'half'),
    ('case', 'civil_status',             'Civil Status',           'select',   'Student Information', 'single,married,widowed,separated', NULL, 'single', 0, 1, 17, 'half'),
    ('case', 'address',                  'Address',                'textarea', 'Student Information', NULL, 'Complete address', NULL, 0, 1, 18, 'full'),
    ('case', 'student_mobile',           'Mobile Number',          'tel',      'Student Information', NULL, '09XX-XXX-XXXX', NULL, 0, 1, 19, 'half'),
    ('case', 'student_email',            'Email Address',          'email',    'Student Information', NULL, 'student@email.com', NULL, 0, 1, 20, 'half'),
    ('case', 'emergency_contact_address','Emergency Contact Address','textarea','Parent/Guardian', NULL, 'Address of parent/guardian', NULL, 0, 1, 22, 'full'),
    ('case', 'referral_source',          'Referral Source',        'select',   'Case Details', 'walk-in,follow-up,referred', NULL, 'walk-in', 0, 1, 23, 'half'),
    ('case', 'referred_by',             'Referred By',            'text',     'Case Details', NULL, 'Name of referring person/office', NULL, 0, 1, 24, 'half'),
    -- Booking form fields
    ('booking', 'student_name',     'Full Name',          'text',     'Personal Information', NULL, 'Your full name', NULL, 1, 1, 1, 'half'),
    ('booking', 'student_email',    'Email Address',      'email',    'Personal Information', NULL, 'your.email@example.com', NULL, 1, 1, 2, 'half'),
    ('booking', 'student_phone',    'Phone Number',       'tel',      'Personal Information', NULL, '09XX-XXX-XXXX', NULL, 0, 1, 3, 'half'),
    ('booking', 'college_id',       'College',            'select',   'Personal Information', NULL, NULL, NULL, 1, 1, 4, 'half'),
    ('booking', 'student_id_number','Student ID',         'text',     'Personal Information', NULL, '2024-00001', NULL, 0, 1, 5, 'half'),
    ('booking', 'year_level',       'Year Level',         'select',   'Personal Information', '["1st Year","2nd Year","3rd Year","4th Year","5th Year","Graduate","SHS Grade 11","SHS Grade 12"]', NULL, NULL, 1, 1, 6, 'half'),
    ('booking', 'purpose',          'Purpose / Concern',  'textarea', 'Appointment Details', NULL, 'Briefly describe your concern...', NULL, 0, 1, 7, 'full'),
    ('booking', 'is_urgent',        'This is urgent',     'checkbox', 'Appointment Details', NULL, NULL, NULL, 0, 1, 8, 'half'),
    ('booking', 'date_of_birth',    'Date of Birth',      'date',     'Personal Information', NULL, NULL, NULL, 0, 1, 9, 'half'),
    ('booking', 'gender',           'Gender',             'select',   'Personal Information', 'male,female,other', NULL, NULL, 0, 1, 10, 'half'),
    ('booking', 'nationality',      'Nationality',        'text',     'Personal Information', NULL, 'e.g., Filipino', NULL, 0, 1, 11, 'half'),
    ('booking', 'civil_status',     'Civil Status',       'select',   'Personal Information', 'single,married,widowed,separated', NULL, 'single', 0, 1, 12, 'half'),
    ('booking', 'address',          'Address',            'textarea', 'Personal Information', NULL, 'Complete address', NULL, 0, 1, 13, 'full'),
    ('booking', 'student_section',  'Section',            'text',     'Personal Information', NULL, 'e.g., A, B, C', NULL, 0, 1, 14, 'half'),
    -- Appointment form fields
    ('appointment', 'scheduled_date',      'Date',              'date',     'Schedule', NULL, NULL, NULL, 1, 1, 1, 'half'),
    ('appointment', 'scheduled_time',      'Time',              'text',     'Schedule', NULL, NULL, NULL, 1, 1, 2, 'half'),
    ('appointment', 'appointment_type_id', 'Appointment Type',  'select',   'Schedule', NULL, NULL, NULL, 1, 1, 3, 'half'),
    ('appointment', 'purpose',             'Purpose / Notes',   'textarea', 'Details', NULL, 'Brief description...', NULL, 0, 1, 4, 'full'),
    ('appointment', 'location',            'Location',          'text',     'Details', NULL, 'e.g., Guidance Office Room 101', NULL, 0, 1, 5, 'half')
ON DUPLICATE KEY UPDATE `field_label` = VALUES(`field_label`);

-- ============================================================
-- SEED DATA: Default Settings
-- ============================================================

INSERT INTO `gm_settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `is_system`) VALUES
    ('working_hours', '{"monday":{"start":"08:00","end":"17:00"},"tuesday":{"start":"08:00","end":"17:00"},"wednesday":{"start":"08:00","end":"17:00"},"thursday":{"start":"08:00","end":"17:00"},"friday":{"start":"08:00","end":"17:00"},"saturday":null,"sunday":null}', 'json', 'Counselor working hours', 0),
    ('appointment_settings', '{"default_duration_minutes":30,"min_duration_minutes":15,"max_duration_minutes":120,"buffer_minutes":5,"max_daily_appointments":12,"max_booking_days_ahead":14,"min_booking_hours_ahead":2,"allow_student_requests":true,"require_approval":true,"allow_same_day_booking":true,"show_counselor_name_public":false,"waitlist_enabled":true,"max_waitlist_per_day":5}', 'json', 'Appointment scheduling settings', 0),
    ('notification_settings', '{"email_enabled":true,"sms_enabled":false,"appointment_reminder_hours":24,"followup_reminder_days":1,"stale_case_days":14,"notify_supervisor_on_critical":true}', 'json', 'Notification configuration', 0),
    ('retention_policy', '{"active_case_years":7,"closed_case_years":5,"audit_log_years":10,"attachment_years":5,"auto_archive_after_days":365}', 'json', 'Data retention policy', 1),
    ('school_info', '{"name":"","address":"","phone":"","email":"","logo_url":""}', 'json', 'School information', 0),
    ('severity_levels', '{"low":{"label":"Low","color":"#28a745","response_days":7},"medium":{"label":"Medium","color":"#ffc107","response_days":3},"high":{"label":"High","color":"#fd7e14","response_days":1},"critical":{"label":"Critical","color":"#dc3545","response_days":0}}', 'json', 'Case severity definitions', 1),
    ('case_categories', '{"academic":{"label":"Academic"},"behavioral":{"label":"Behavioral"},"emotional":{"label":"Emotional/Mental Health"},"career":{"label":"Career/Vocational"},"family":{"label":"Family"},"social":{"label":"Social"},"crisis":{"label":"Crisis"}}', 'json', 'Case category definitions', 1),
    ('app_timezone', 'Asia/Manila', 'string', 'Application timezone', 0),
    ('app_country', 'PH', 'string', 'Application country code', 0),
    ('app_region', 'Manila', 'string', 'Application region/city', 0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- ============================================================
-- TABLE: Student Tracker
-- ============================================================

CREATE TABLE IF NOT EXISTS `gm_trackers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `academic_year` VARCHAR(20) NULL COMMENT 'e.g., 2025-2026',
    `college_id` BIGINT UNSIGNED NULL COMMENT 'NULL = all colleges',
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
    `student_id` VARCHAR(50) NULL COMMENT 'School student ID',
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

-- ============================================================
-- NOTE: Admin user is created by the installer (lock.php).
-- Do NOT hardcode credentials in this file.
-- ============================================================
