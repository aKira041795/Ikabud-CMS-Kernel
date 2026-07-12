SET FOREIGN_KEY_CHECKS = 0;

-- Add client snapshot columns to pal_sales so invoices retain the
-- client information at the time of issuance, even if the master
-- client record is later modified (name change, address update, etc.).
--
-- These columns are populated at invoice creation time from the
-- pal_clients record and are NOT kept in sync with the master record.
-- This ensures completed invoices are immutable with respect to
-- client identity.

ALTER TABLE pal_sales
    ADD COLUMN client_name VARCHAR(255) DEFAULT NULL AFTER client_id,
    ADD COLUMN client_contact VARCHAR(255) DEFAULT NULL AFTER client_name,
    ADD COLUMN client_email VARCHAR(255) DEFAULT NULL AFTER client_contact,
    ADD COLUMN client_phone VARCHAR(50) DEFAULT NULL AFTER client_email,
    ADD COLUMN client_address TEXT DEFAULT NULL AFTER client_phone;

SET FOREIGN_KEY_CHECKS = 1;
