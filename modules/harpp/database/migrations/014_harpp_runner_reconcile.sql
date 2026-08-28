-- HARPP runner reconciliation + report-delivery state (additive to 013).
-- P1-2: attempt_count / max_attempts / stalled_at let the runner distinguish a
--   never-started claim (requeue) from a started-but-stalled child process
--   (reconcile to STALLED) so a dead process is never left RUNNING.
-- P1-4: delivery_attempts / last_delivery_error track report delivery so failed
--   status delivery is retained, retried, and dead-lettered — never swallowed.

ALTER TABLE `harpp_work_runs`
    ADD COLUMN `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `report_state`,
    ADD COLUMN `max_attempts` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `attempt_count`,
    ADD COLUMN `stalled_at` DATETIME(6) NULL DEFAULT NULL AFTER `lease_expires_at`,
    ADD COLUMN `delivery_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `finished_at`,
    ADD COLUMN `last_delivery_error` VARCHAR(2000) NULL DEFAULT NULL AFTER `delivery_attempts`;
