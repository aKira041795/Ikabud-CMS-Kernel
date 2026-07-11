-- Attendance Groups — team lead + members for attendance checking
-- Also serves as the PAL cross-module bridge (pal_team_lead_email column)

CREATE TABLE IF NOT EXISTS `attendance_groups` (
    `group_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(36) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `leader_profile_id` INT UNSIGNED NOT NULL COMMENT 'FK to employee_profiles.profile_id',
    `pal_team_lead_email` VARCHAR(255) DEFAULT NULL COMMENT 'Bridge to PAL pal_team_leads.email for cross-module attendance view',
    `description` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`group_id`),
    INDEX `idx_ag_tenant` (`tenant_id`),
    INDEX `idx_ag_leader` (`leader_profile_id`),
    INDEX `idx_ag_pal_bridge` (`pal_team_lead_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Attendance check groups — team lead + members';

CREATE TABLE IF NOT EXISTS `attendance_group_members` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` VARCHAR(36) NOT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `profile_id` INT UNSIGNED NOT NULL COMMENT 'FK to employee_profiles.profile_id',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agm_group_profile` (`group_id`, `profile_id`),
    INDEX `idx_agm_tenant` (`tenant_id`),
    INDEX `idx_agm_profile` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Members of attendance groups';
