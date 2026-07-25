-- 020_alter_dc_users_role_enum.sql
-- Expand role ENUM to support supervisor and auditor roles.
-- @mysql57-compat: InnoDB, utf8mb4, no window functions.

ALTER TABLE `dc_users`
  MODIFY COLUMN `role` ENUM('admin','supervisor','auditor','cashier') NOT NULL DEFAULT 'cashier';
