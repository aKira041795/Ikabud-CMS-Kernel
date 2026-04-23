-- Migration 006: Catalog fields for learning_resources.
--
-- Adds structured catalog metadata columns so the learning_resources table
-- can describe a course to learners without reaching back to the provider
-- cache. These fields mirror common LMS course-catalog attributes and allow
-- the CMS entity-list renderer to display course cards without joining
-- moodle_courses_cache for display-only data.
--
-- Safe to re-run: ALTER TABLE ... ADD COLUMN IF NOT EXISTS is not supported
-- in older MySQL, so each statement is guarded by the migration framework.

ALTER TABLE `learning_resources`
    ADD COLUMN `description` TEXT DEFAULT NULL AFTER `title`,
    ADD COLUMN `program` VARCHAR(191) DEFAULT NULL AFTER `description`,
    ADD COLUMN `difficulty_level` VARCHAR(50) DEFAULT NULL AFTER `program`,
    ADD COLUMN `duration_minutes` INT UNSIGNED DEFAULT NULL AFTER `difficulty_level`,
    ADD COLUMN `tags_json` LONGTEXT DEFAULT NULL AFTER `duration_minutes`,
    ADD COLUMN `visibility` ENUM('public','enrolled_only','hidden') NOT NULL DEFAULT 'public' AFTER `tags_json`;

-- Index to support catalog browsing by program within a tenant.
ALTER TABLE `learning_resources`
    ADD KEY `idx_learning_resources_program` (`tenant_id`, `program`);
