-- Migration 007: Rename course_id → course_cache_id in moodle_user_progress.
--
-- `course_id` (FK to moodle_courses_cache.id) was the original progress
-- anchor. Migration 005 added `learning_resource_id` as the canonical anchor.
-- This rename completes the transition: the column is kept but its name now
-- clearly communicates that it is a provider-specific cache pointer, not the
-- canonical resource identifier.
--
-- All PHP code referencing `p.course_id` in moodle_user_progress queries has
-- been updated in the same release as this migration.

ALTER TABLE `moodle_user_progress`
    CHANGE COLUMN `course_id` `course_cache_id` BIGINT UNSIGNED NOT NULL;
