-- Control plane migration: add admin_email to kernel_tenants
-- This migration must be run against the CONTROL DB.

ALTER TABLE `kernel_tenants`
    ADD COLUMN `admin_email` VARCHAR(255) DEFAULT NULL AFTER `canonical_domain`;
