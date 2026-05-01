-- ============================================================
-- MIGRATION 007: Add Mental Status Examination columns to
--   gm_cases for initial case assessment.
-- ============================================================

ALTER TABLE `gm_cases`
    ADD COLUMN `mse_appearance`      VARCHAR(50)  DEFAULT NULL AFTER `case_protective`,
    ADD COLUMN `mse_mood`            VARCHAR(50)  DEFAULT NULL AFTER `mse_appearance`,
    ADD COLUMN `mse_affect`          VARCHAR(50)  DEFAULT NULL AFTER `mse_mood`,
    ADD COLUMN `mse_behavior`        VARCHAR(50)  DEFAULT NULL AFTER `mse_affect`,
    ADD COLUMN `mse_speech`          VARCHAR(50)  DEFAULT NULL AFTER `mse_behavior`,
    ADD COLUMN `mse_thought_process` VARCHAR(50)  DEFAULT NULL AFTER `mse_speech`,
    ADD COLUMN `mse_insight`         VARCHAR(20)  DEFAULT NULL AFTER `mse_thought_process`,
    ADD COLUMN `mse_judgment`        VARCHAR(20)  DEFAULT NULL AFTER `mse_insight`,
    ADD COLUMN `mse_notes`           TEXT         DEFAULT NULL AFTER `mse_judgment`;
