-- WMS Bootstrap Admin
-- Creates the initial admin user for fresh tenant installations.
-- Password hash must be changed on first login (blocked by auth_owned.blocked_password_hashes).

INSERT INTO wms_users (username, email, password_hash, full_name, role, is_active)
VALUES ('wmsadmin', 'admin@wms.local', '$2y$10$MpYxDIlYvs1xuzfEDFxxyuxMgyMtotMy8zfak9eDa2EVa..IBNTuW', 'WMS Admin', 'admin', 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Default configs for new tenants
INSERT IGNORE INTO wms_configs (config_key, config_value) VALUES
    ('warehouse_name', 'Main Warehouse'),
    ('picking_strategy', 'fifo'),
    ('allow_negative_stock', '0'),
    ('auto_replenish_enabled', '1');
