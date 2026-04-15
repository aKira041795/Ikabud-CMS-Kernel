-- Tier 4.2: i18n Foundation Schema
-- Adds kernel-level translation infrastructure.
-- This is the foundational schema; content-level locale columns
-- (e.g. cms_content.locale, cms_content_translations) will be added
-- in the CMS module migration when i18n content support is implemented.

CREATE TABLE IF NOT EXISTS kernel_locales (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    code        VARCHAR(10)  NOT NULL UNIQUE,   -- e.g. 'en', 'fr', 'es'
    name        VARCHAR(100) NOT NULL,           -- e.g. 'English', 'French'
    native_name VARCHAR(100) DEFAULT NULL,       -- e.g. 'Français', 'Español'
    direction   ENUM('ltr','rtl') NOT NULL DEFAULT 'ltr',
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default locale
INSERT IGNORE INTO kernel_locales (code, name, native_name, is_default, is_active)
VALUES ('en', 'English', 'English', 1, 1);

CREATE TABLE IF NOT EXISTS kernel_translations (
    id         INTEGER PRIMARY KEY AUTO_INCREMENT,
    locale     VARCHAR(10)  NOT NULL,
    namespace  VARCHAR(64)  NOT NULL DEFAULT 'kernel',  -- module id or 'kernel'
    group_key  VARCHAR(64)  NOT NULL DEFAULT 'messages', -- file/group name
    item_key   VARCHAR(255) NOT NULL,                     -- dot-notation key
    value      TEXT         NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_translation (locale, namespace, group_key, item_key),
    INDEX idx_locale_ns (locale, namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
