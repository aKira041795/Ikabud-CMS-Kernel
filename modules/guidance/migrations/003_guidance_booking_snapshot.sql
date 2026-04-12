SET @guidance_has_booking_snapshot := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'gm_appointments'
      AND COLUMN_NAME = 'booking_snapshot_json'
);

SET @guidance_booking_snapshot_sql := IF(
    @guidance_has_booking_snapshot = 0,
    'ALTER TABLE gm_appointments ADD COLUMN booking_snapshot_json LONGTEXT DEFAULT NULL AFTER request_message',
    'SELECT 1'
);

PREPARE guidance_booking_snapshot_stmt FROM @guidance_booking_snapshot_sql;
EXECUTE guidance_booking_snapshot_stmt;
DEALLOCATE PREPARE guidance_booking_snapshot_stmt;