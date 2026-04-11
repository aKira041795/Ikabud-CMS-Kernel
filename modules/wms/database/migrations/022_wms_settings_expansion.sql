-- Migration: 022_wms_settings_expansion
-- Purpose: Seed additional must-have WMS configuration keys for the expanded settings page.

INSERT IGNORE INTO wms_configs (config_key, config_value, description) VALUES
    ('general.warehouse_name', '""', 'Display name of the warehouse operation.'),
    ('general.timezone', '"UTC"', 'Timezone used for display and scheduling (e.g. America/New_York).'),
    ('general.date_format', '"Y-m-d"', 'PHP date format string for display dates.'),
    ('general.weight_unit', '"kg"', 'Default weight unit: kg or lb.'),
    ('general.dimension_unit', '"cm"', 'Default dimension unit: cm or in.'),
    ('inventory.reorder_point_buffer_pct', '20', 'Percentage buffer above minimum stock level to trigger reorder suggestions.'),
    ('inventory.cycle_count_frequency_days', '30', 'How often (in days) to prompt cycle count campaigns.'),
    ('picking.require_scan_confirmation', 'false', 'If true, requires barcode scan to confirm each pick line.'),
    ('picking.wave_batch_size', '20', 'Maximum number of orders in a single wave pick batch.'),
    ('picking.auto_assign_tasks', 'true', 'Automatically assign picking tasks to available operators.'),
    ('receiving.auto_create_putaway_tasks', 'true', 'Automatically generate putaway tasks when items are received.'),
    ('receiving.require_quality_check', 'false', 'Require a quality inspection step on inbound receipts.'),
    ('receiving.over_receive_tolerance_pct', '0', 'Percentage over the expected quantity that receiving will tolerate. 0 = exact match required.'),
    ('returns.require_inspection', 'true', 'Require inspection before returned items can be restocked.'),
    ('returns.auto_quarantine_damaged', 'true', 'Automatically route items in damaged condition to quarantine on return.'),
    ('notifications.low_stock_alerts_enabled', 'true', 'Enable low-stock email/webhook alerts.'),
    ('notifications.task_escalation_hours', '4', 'Hours before unassigned tasks are flagged for escalation.');
