-- Menus and menu items
CREATE TABLE IF NOT EXISTS cms_menus (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    location    VARCHAR(50)  NOT NULL DEFAULT 'header',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cms_menu_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id     INT UNSIGNED NOT NULL,
    parent_id   INT UNSIGNED DEFAULT NULL,
    label       VARCHAR(200) NOT NULL,
    url         VARCHAR(500) NOT NULL DEFAULT '',
    link_type   VARCHAR(20)  NOT NULL DEFAULT 'custom',
    link_ref    VARCHAR(200) DEFAULT NULL,
    target      VARCHAR(10)  NOT NULL DEFAULT '_self',
    css_class   VARCHAR(200) DEFAULT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    INDEX idx_menu (menu_id, sort_order),
    INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
