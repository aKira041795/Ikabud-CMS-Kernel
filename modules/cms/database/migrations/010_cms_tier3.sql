-- ═══════════════════════════════════════════════════════════════
-- Tier 3: WordPress-style menus + Page Builder foundations
-- ═══════════════════════════════════════════════════════════════

-- Allow multiple menus (remove unique constraint on location, make location nullable)
ALTER TABLE cms_menus DROP INDEX uk_location;
ALTER TABLE cms_menus MODIFY location VARCHAR(50) DEFAULT NULL;
ALTER TABLE cms_menus ADD COLUMN slug VARCHAR(100) NOT NULL DEFAULT '' AFTER name;
ALTER TABLE cms_menus ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER slug;
ALTER TABLE cms_menus ADD COLUMN auto_add_pages TINYINT(1) NOT NULL DEFAULT 0 AFTER description;
ALTER TABLE cms_menus ADD INDEX idx_location (location);

-- Enhance menu items with description, icon, and title attribute
ALTER TABLE cms_menu_items ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER css_class;
ALTER TABLE cms_menu_items ADD COLUMN icon VARCHAR(50) DEFAULT NULL AFTER description;
ALTER TABLE cms_menu_items ADD COLUMN title_attr VARCHAR(200) DEFAULT NULL AFTER icon;

-- Menu location registry (theme-defined locations)
CREATE TABLE IF NOT EXISTS cms_menu_locations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(50)  NOT NULL,
    label       VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    menu_id     INT UNSIGNED DEFAULT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_menu (menu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default locations (WordPress-style)
INSERT IGNORE INTO cms_menu_locations (slug, label, description, sort_order) VALUES
('primary',  'Primary Navigation', 'Main site navigation, typically in the header',     1),
('footer',   'Footer Menu',        'Links displayed in the site footer',                 2),
('mobile',   'Mobile Menu',        'Navigation for mobile/hamburger menu',               3),
('sidebar',  'Sidebar Menu',       'Optional sidebar navigation widget',                 4);

-- Saved/reusable blocks for page builder
CREATE TABLE IF NOT EXISTS cms_saved_blocks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    slug        VARCHAR(200) NOT NULL,
    category    VARCHAR(50)  NOT NULL DEFAULT 'custom',
    description VARCHAR(500) DEFAULT NULL,
    blocks_json JSON         NOT NULL,
    styles_json JSON         DEFAULT NULL,
    preview     VARCHAR(500) DEFAULT NULL,
    is_global   TINYINT(1)   NOT NULL DEFAULT 0,
    usage_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
