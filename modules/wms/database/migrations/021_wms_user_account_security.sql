-- Migration: 021_wms_user_account_security
-- Purpose: Add user phone metadata for staff records and support account-management features.

DROP PROCEDURE IF EXISTS wms_user_account_security;

CREATE PROCEDURE wms_user_account_security()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wms_users'
          AND COLUMN_NAME = 'phone'
    ) THEN
        ALTER TABLE `wms_users`
            ADD COLUMN `phone` VARCHAR(50) NULL AFTER `email`;
    END IF;
END;

CALL wms_user_account_security();
DROP PROCEDURE IF EXISTS wms_user_account_security;