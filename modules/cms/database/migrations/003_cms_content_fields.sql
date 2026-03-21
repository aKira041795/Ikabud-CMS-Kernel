-- CMS Module — Queryable Content Fields
-- Addresses Structural Risk 1: content_meta JOIN bottleneck at scale.
-- Typed, indexed fields for efficient WHERE/ORDER BY queries.
-- content_meta remains for unstructured/low-query data.

CREATE TABLE IF NOT EXISTS cms_content_fields (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id    INT UNSIGNED NOT NULL,
    field_key     VARCHAR(100)    NOT NULL,
    field_string  VARCHAR(500)    DEFAULT NULL,
    field_number  DECIMAL(18,4)   DEFAULT NULL,
    field_date    DATETIME        DEFAULT NULL,

    INDEX idx_cf_content  (content_id),
    INDEX idx_cf_key      (field_key),
    INDEX idx_cf_string   (field_key, field_string(191)),
    INDEX idx_cf_number   (field_key, field_number),
    INDEX idx_cf_date     (field_key, field_date),

    CONSTRAINT fk_cf_content FOREIGN KEY (content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
