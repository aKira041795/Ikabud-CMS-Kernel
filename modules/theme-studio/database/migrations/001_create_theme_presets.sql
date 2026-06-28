-- Theme Studio — Migration 001
-- Creates the preset, element, and token override tables.

CREATE TABLE IF NOT EXISTS theme_studio_presets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(200) NOT NULL,
    description TEXT,
    preset_data JSON NOT NULL COMMENT 'Full token overrides + layout config + element assignments',
    source VARCHAR(50) DEFAULT 'custom' COMMENT 'builtin, custom, imported',
    surface VARCHAR(50) DEFAULT 'public' COMMENT 'public, admin, print, email',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_preset_slug (slug),
    INDEX idx_preset_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theme_studio_elements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(200) NOT NULL,
    element_type VARCHAR(50) NOT NULL COMMENT 'hook, hero, header, layout, block, navigation, modal, drawer, pattern, token_override',
    slot_name VARCHAR(100) DEFAULT NULL COMMENT 'Target slot for hook/hero/header elements',
    component VARCHAR(100) DEFAULT 'ikb_panel' COMMENT 'Governed component to render',
    component_attrs JSON DEFAULT NULL COMMENT 'Component attributes',
    display_conditions JSON DEFAULT NULL COMMENT 'Condition set (entity_type, view, route, role, capability, tenant, taxonomy)',
    priority INT DEFAULT 10,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_element_slug (slug),
    INDEX idx_element_type (element_type),
    INDEX idx_element_slot (slot_name),
    INDEX idx_element_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS theme_studio_token_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 0,
    theme_slug VARCHAR(100) NOT NULL DEFAULT '',
    token_key VARCHAR(200) NOT NULL COMMENT 'e.g., color.primary, spacing.lg',
    token_value VARCHAR(500) NOT NULL COMMENT 'CSS value, e.g., #1d4ed8',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tenant_theme_token (tenant_id, theme_slug, token_key),
    INDEX idx_token_tenant (tenant_id),
    INDEX idx_token_theme (theme_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
