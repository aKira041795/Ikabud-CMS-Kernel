-- Industry-standard EHR user/staff identity, credential, employment, and security fields.
-- Fields chosen to align with common HL7/EHR provider directory conventions and
-- US HIPAA / DEA / state licensure expectations. All new columns are nullable so
-- existing rows continue to function with no data migration required.

ALTER TABLE `ehr_users`
    ADD COLUMN `title` VARCHAR(20) NULL DEFAULT NULL AFTER `full_name`,
    ADD COLUMN `first_name` VARCHAR(80) NULL DEFAULT NULL AFTER `title`,
    ADD COLUMN `middle_name` VARCHAR(80) NULL DEFAULT NULL AFTER `first_name`,
    ADD COLUMN `last_name` VARCHAR(80) NULL DEFAULT NULL AFTER `middle_name`,
    ADD COLUMN `suffix` VARCHAR(20) NULL DEFAULT NULL AFTER `last_name`,
    ADD COLUMN `preferred_name` VARCHAR(80) NULL DEFAULT NULL AFTER `suffix`,
    ADD COLUMN `credentials` VARCHAR(80) NULL DEFAULT NULL AFTER `preferred_name`,
    ADD COLUMN `npi` VARCHAR(15) NULL DEFAULT NULL AFTER `credentials`,
    ADD COLUMN `dea_number` VARCHAR(20) NULL DEFAULT NULL AFTER `npi`,
    ADD COLUMN `license_number` VARCHAR(50) NULL DEFAULT NULL AFTER `dea_number`,
    ADD COLUMN `license_state` VARCHAR(10) NULL DEFAULT NULL AFTER `license_number`,
    ADD COLUMN `license_expires_on` DATE NULL DEFAULT NULL AFTER `license_state`,
    ADD COLUMN `specialty` VARCHAR(120) NULL DEFAULT NULL AFTER `license_expires_on`,
    ADD COLUMN `taxonomy_code` VARCHAR(20) NULL DEFAULT NULL AFTER `specialty`,
    ADD COLUMN `provider_type` VARCHAR(40) NULL DEFAULT NULL AFTER `taxonomy_code`,
    ADD COLUMN `can_prescribe` TINYINT(1) NOT NULL DEFAULT 0 AFTER `provider_type`,
    ADD COLUMN `employee_id` VARCHAR(40) NULL DEFAULT NULL AFTER `can_prescribe`,
    ADD COLUMN `job_title` VARCHAR(120) NULL DEFAULT NULL AFTER `employee_id`,
    ADD COLUMN `department` VARCHAR(120) NULL DEFAULT NULL AFTER `job_title`,
    ADD COLUMN `hire_date` DATE NULL DEFAULT NULL AFTER `department`,
    ADD COLUMN `termination_date` DATE NULL DEFAULT NULL AFTER `hire_date`,
    ADD COLUMN `phone` VARCHAR(40) NULL DEFAULT NULL AFTER `termination_date`,
    ADD COLUMN `mobile` VARCHAR(40) NULL DEFAULT NULL AFTER `phone`,
    ADD COLUMN `mfa_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `mobile`,
    ADD COLUMN `password_changed_at` DATETIME NULL DEFAULT NULL AFTER `mfa_enabled`,
    ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_changed_at`,
    ADD COLUMN `failed_login_count` INT NOT NULL DEFAULT 0 AFTER `force_password_change`,
    ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL AFTER `failed_login_count`,
    ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL AFTER `locked_until`,
    ADD COLUMN `last_login_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `last_login_at`,
    ADD COLUMN `notes` TEXT NULL DEFAULT NULL AFTER `last_login_ip`;

-- Helpful lookups for compliance and admin search.
ALTER TABLE `ehr_users`
    ADD UNIQUE KEY `uq_ehr_users_npi` (`npi`),
    ADD UNIQUE KEY `uq_ehr_users_employee_id` (`employee_id`),
    ADD KEY `idx_ehr_users_last_name` (`last_name`),
    ADD KEY `idx_ehr_users_department` (`department`),
    ADD KEY `idx_ehr_users_license_expires_on` (`license_expires_on`);
