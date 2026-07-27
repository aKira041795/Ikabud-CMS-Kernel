-- Harden the initial device table for tenant-safe upgrades.
-- Kept separate so installations that already ran 001 receive the corrected key.

ALTER TABLE mgw_devices
    MODIFY tenant_id INT UNSIGNED NOT NULL,
    DROP INDEX uq_user_device,
    ADD UNIQUE KEY uq_tenant_user_device (tenant_id, user_id, device_id),
    ADD INDEX idx_device_session (device_session_id);
