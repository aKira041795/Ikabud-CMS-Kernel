-- Control-plane migration 007: expand kernel_tenants.status to accept the CAS
-- provisioning states 'pending' and 'provisioning'.
--
-- The status column is VARCHAR(30) in current installs and already accepts
-- these values. This idempotent migration guards deployments where the column
-- may have been narrowed (e.g. to an ENUM): MODIFY restores it to VARCHAR(30)
-- with the canonical default 'active'. Repeated runs are no-ops (MySQL 5.7-safe
-- MODIFY; the migration runner tracks it anyway).
ALTER TABLE `kernel_tenants` MODIFY `status` VARCHAR(30) NOT NULL DEFAULT 'active';
