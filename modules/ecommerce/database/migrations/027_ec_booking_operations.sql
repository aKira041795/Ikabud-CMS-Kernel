-- ============================================================
-- Ecommerce Module — Booking Operations (Reschedule, Cancel, Reminders)
-- Adds operational columns to ec_bookings for depth features.
-- This migration is idempotent and safe to re-run.
-- ============================================================

SET @tbl = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'ec_bookings'
              AND COLUMN_NAME  = 'cancelled_at');

SET @sql_cancel = IF(@tbl = 0,
    'ALTER TABLE ec_bookings
         ADD COLUMN cancelled_at       DATETIME      NULL DEFAULT NULL AFTER notes,
         ADD COLUMN cancel_reason      VARCHAR(255)  NULL DEFAULT NULL AFTER cancelled_at,
         ADD COLUMN rescheduled_from_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER cancel_reason,
         ADD COLUMN reminder_sent_at   DATETIME      NULL DEFAULT NULL AFTER rescheduled_from_id,
         ADD INDEX idx_ec_bookings_reminder (reminder_sent_at, status, scheduled_for),
         ADD INDEX idx_ec_bookings_rescheduled (rescheduled_from_id)',
    'SELECT 1');

PREPARE stmt FROM @sql_cancel;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
