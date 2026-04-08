-- Migration: 016_wms_phase6_platformization
-- Purpose: Adds configuration layer and initial onboarding flags for platformization (Phase 6)

CREATE TABLE IF NOT EXISTS wms_configs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL,
    config_value JSON,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_wms_configs_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configurations
INSERT IGNORE INTO wms_configs (config_key, config_value, description) VALUES
    ('system.allow_negative_stock', 'false', 'If true, allows creating out movements when stock is insufficient.'),
    ('picking.default_strategy', '"FIFO"', 'Default picking strategy: FIFO, FEFO, or LIFO.'),
    ('returns.default_quarantine_location_id', 'null', 'Default location ID to route returned items.'),
    ('auto_replenishment.enabled', 'true', 'If true, auto-generates replenishment tasks when stock falls below reorder points.'),
    ('onboarding.completed', 'false', 'Flag indicating if the tenant has completed the initial setup wizard.');
