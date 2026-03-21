-- CMS Module — Categories (taxonomies)
-- Tables: cms_categories, cms_content_categories

CREATE TABLE IF NOT EXISTS cms_categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200)    NOT NULL,
    slug          VARCHAR(200)    NOT NULL,
    description   TEXT            DEFAULT NULL,
    parent_id     INT UNSIGNED    DEFAULT NULL,
    sort_order    INT             NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_cms_cat_slug (slug),
    INDEX idx_cms_cat_parent (parent_id),
    INDEX idx_cms_cat_sort   (sort_order),

    CONSTRAINT fk_cms_cat_parent FOREIGN KEY (parent_id)
        REFERENCES cms_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_content_categories (
    content_id    INT UNSIGNED NOT NULL,
    category_id   INT UNSIGNED NOT NULL,

    PRIMARY KEY (content_id, category_id),
    INDEX idx_cms_cc_cat (category_id),

    CONSTRAINT fk_cms_cc_content FOREIGN KEY (content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_cc_cat FOREIGN KEY (category_id)
        REFERENCES cms_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed records removed for installer packaging.
