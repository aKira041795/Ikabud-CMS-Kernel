-- Migration: 020_wms_phase76_exception_dispositions
-- Purpose: Add explicit disposition metadata for task exceptions so remediation actions are auditable.

DROP PROCEDURE IF EXISTS wms_phase76_exception_dispositions;

CREATE PROCEDURE wms_phase76_exception_dispositions()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_task_exceptions'
          AND COLUMN_NAME = 'disposition_type'
    ) THEN
        ALTER TABLE `wms_task_exceptions`
            ADD COLUMN `disposition_type` VARCHAR(50) NULL AFTER `status`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_task_exceptions'
          AND COLUMN_NAME = 'disposition_payload'
    ) THEN
        ALTER TABLE `wms_task_exceptions`
            ADD COLUMN `disposition_payload` JSON NULL AFTER `scan_payload`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_task_exceptions'
          AND INDEX_NAME = 'idx_wms_task_exceptions_disposition'
    ) THEN
        ALTER TABLE `wms_task_exceptions`
            ADD KEY `idx_wms_task_exceptions_disposition` (`status`, `disposition_type`, `created_at`);
    END IF;
END;

CALL wms_phase76_exception_dispositions();
DROP PROCEDURE IF EXISTS wms_phase76_exception_dispositions;