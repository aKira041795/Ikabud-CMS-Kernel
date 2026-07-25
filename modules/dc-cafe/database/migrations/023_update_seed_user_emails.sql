-- 023_update_seed_user_emails.sql
-- Add email addresses to existing seed users (idempotent).
-- @mysql57-compat: InnoDB, utf8mb4.

UPDATE `dc_users` SET `email` = 'admin@dccafe.test'      WHERE `username` = 'admin'      AND (`email` IS NULL OR `email` = '');
UPDATE `dc_users` SET `email` = 'supervisor@dccafe.test' WHERE `username` = 'supervisor' AND (`email` IS NULL OR `email` = '');
UPDATE `dc_users` SET `email` = 'auditor@dccafe.test'    WHERE `username` = 'auditor'    AND (`email` IS NULL OR `email` = '');
UPDATE `dc_users` SET `email` = 'cashier@dccafe.test'    WHERE `username` = 'cashier'    AND (`email` IS NULL OR `email` = '');
