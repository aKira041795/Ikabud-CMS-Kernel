-- ═══════════════════════════════════════════════════════════════
-- Tier 3: WordPress-style menus + Page Builder foundations
-- ═══════════════════════════════════════════════════════════════

-- Allow multiple menus (remove unique constraint on location, make location nullable)
SET @cms_has_uk_location := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menus'
      AND index_name = 'uk_location'
);
SET @cms_drop_uk_location_sql := IF(
    @cms_has_uk_location > 0,
    'ALTER TABLE cms_menus DROP INDEX uk_location',
    'SELECT 1'
);
PREPARE cms_drop_uk_location_stmt FROM @cms_drop_uk_location_sql;
EXECUTE cms_drop_uk_location_stmt;
DEALLOCATE PREPARE cms_drop_uk_location_stmt;
ALTER TABLE cms_menus MODIFY location VARCHAR(50) DEFAULT NULL;
SET @cms_has_slug_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menus'
      AND column_name = 'slug'
);
SET @cms_add_slug_col_sql := IF(
    @cms_has_slug_col = 0,
    'ALTER TABLE cms_menus ADD COLUMN slug VARCHAR(100) NOT NULL DEFAULT '''' AFTER name',
    'SELECT 1'
);
PREPARE cms_add_slug_col_stmt FROM @cms_add_slug_col_sql;
EXECUTE cms_add_slug_col_stmt;
DEALLOCATE PREPARE cms_add_slug_col_stmt;

SET @cms_has_description_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menus'
      AND column_name = 'description'
);
SET @cms_add_description_col_sql := IF(
    @cms_has_description_col = 0,
    'ALTER TABLE cms_menus ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER slug',
    'SELECT 1'
);
PREPARE cms_add_description_col_stmt FROM @cms_add_description_col_sql;
EXECUTE cms_add_description_col_stmt;
DEALLOCATE PREPARE cms_add_description_col_stmt;

SET @cms_has_auto_add_pages_col := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menus'
      AND column_name = 'auto_add_pages'
);
SET @cms_add_auto_add_pages_col_sql := IF(
    @cms_has_auto_add_pages_col = 0,
    'ALTER TABLE cms_menus ADD COLUMN auto_add_pages TINYINT(1) NOT NULL DEFAULT 0 AFTER description',
    'SELECT 1'
);
PREPARE cms_add_auto_add_pages_col_stmt FROM @cms_add_auto_add_pages_col_sql;
EXECUTE cms_add_auto_add_pages_col_stmt;
DEALLOCATE PREPARE cms_add_auto_add_pages_col_stmt;

SET @cms_has_idx_location := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menus'
      AND index_name = 'idx_location'
);
SET @cms_add_idx_location_sql := IF(
    @cms_has_idx_location = 0,
    'ALTER TABLE cms_menus ADD INDEX idx_location (location)',
    'SELECT 1'
);
PREPARE cms_add_idx_location_stmt FROM @cms_add_idx_location_sql;
EXECUTE cms_add_idx_location_stmt;
DEALLOCATE PREPARE cms_add_idx_location_stmt;

-- Enhance menu items with description, icon, and title attribute
SET @cms_has_menu_item_description := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menu_items'
      AND column_name = 'description'
);
SET @cms_add_menu_item_description_sql := IF(
    @cms_has_menu_item_description = 0,
    'ALTER TABLE cms_menu_items ADD COLUMN description VARCHAR(500) DEFAULT NULL AFTER css_class',
    'SELECT 1'
);
PREPARE cms_add_menu_item_description_stmt FROM @cms_add_menu_item_description_sql;
EXECUTE cms_add_menu_item_description_stmt;
DEALLOCATE PREPARE cms_add_menu_item_description_stmt;

SET @cms_has_menu_item_icon := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menu_items'
      AND column_name = 'icon'
);
SET @cms_add_menu_item_icon_sql := IF(
    @cms_has_menu_item_icon = 0,
    'ALTER TABLE cms_menu_items ADD COLUMN icon VARCHAR(50) DEFAULT NULL AFTER description',
    'SELECT 1'
);
PREPARE cms_add_menu_item_icon_stmt FROM @cms_add_menu_item_icon_sql;
EXECUTE cms_add_menu_item_icon_stmt;
DEALLOCATE PREPARE cms_add_menu_item_icon_stmt;

SET @cms_has_menu_item_title_attr := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'cms_menu_items'
      AND column_name = 'title_attr'
);
SET @cms_add_menu_item_title_attr_sql := IF(
    @cms_has_menu_item_title_attr = 0,
    'ALTER TABLE cms_menu_items ADD COLUMN title_attr VARCHAR(200) DEFAULT NULL AFTER icon',
    'SELECT 1'
);
PREPARE cms_add_menu_item_title_attr_stmt FROM @cms_add_menu_item_title_attr_sql;
EXECUTE cms_add_menu_item_title_attr_stmt;
DEALLOCATE PREPARE cms_add_menu_item_title_attr_stmt;

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
