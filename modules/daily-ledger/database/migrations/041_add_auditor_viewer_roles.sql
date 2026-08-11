-- ============================================================
-- Daily Ledger Module — Add auditor + viewer roles to dl_users.role
--
-- dl_users.role is an ENUM that previously only allowed
-- admin/supervisor/cashier/production_in_charge. The new read-only
-- auditor and viewer (business-owner) roles must be storable so
-- user creation via apiCreateUser succeeds. MODIFY extends the ENUM.
--
-- Idempotent: re-running sets the same column definition.
-- Bluehost MySQL 5.7 compatible (ALTER TABLE MODIFY COLUMN).
-- ============================================================

ALTER TABLE dl_users
  MODIFY COLUMN role ENUM('admin','supervisor','cashier','production_in_charge','auditor','viewer') NOT NULL;
