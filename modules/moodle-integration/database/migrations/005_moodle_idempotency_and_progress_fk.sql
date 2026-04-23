-- Idempotency keys on the sync queue prevent duplicate jobs on retry bursts.
-- A NULL idempotency_key is allowed for queue rows that don't require dedup
-- (MySQL treats NULLs as distinct in a unique index).
ALTER TABLE `moodle_sync_queue`
    ADD COLUMN `idempotency_key` VARCHAR(160) DEFAULT NULL AFTER `type`,
    ADD UNIQUE KEY `uk_queue_idempotency` (`tenant_id`, `idempotency_key`);

-- Canonical learning_resource_id in the user-progress table.
-- The legacy course_id (FK to moodle_courses_cache.id) is kept for now so
-- existing indexes and queries continue to work; it will be retired once all
-- readers are migrated. The new column gives the application layer a direct
-- link to learning_resources without touching provider-bridge tables.
ALTER TABLE `moodle_user_progress`
    ADD COLUMN `learning_resource_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user_id`,
    ADD KEY `idx_moodle_user_progress_resource` (`tenant_id`, `learning_resource_id`);

-- Backfill: populate learning_resource_id for every existing progress row by
-- joining through the course cache to learning_resources.
UPDATE `moodle_user_progress` p
  JOIN `moodle_courses_cache` c ON c.id = p.course_id AND c.tenant_id = p.tenant_id
SET p.`learning_resource_id` = c.`resource_id`
WHERE p.`learning_resource_id` IS NULL
  AND c.`resource_id` IS NOT NULL;
