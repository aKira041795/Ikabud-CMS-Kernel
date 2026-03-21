-- ═══════════════════════════════════════════════════════════════
-- CMS Theme Customizer — Stores theme customization settings
-- and footer widget configuration as JSON documents.
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS cms_theme_customizer (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section         VARCHAR(50)   NOT NULL COMMENT 'Customizer section: footer, header, colors, typography, etc.',
    settings_json   JSON          NOT NULL COMMENT 'JSON object of section settings',
    widgets_json    JSON          DEFAULT NULL COMMENT 'JSON array of widget instances with area assignments',
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT UNSIGNED  DEFAULT NULL,
    UNIQUE KEY uq_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed records removed for installer packaging.
