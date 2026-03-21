-- CMS Module — Normalized Block Storage
-- Addresses Structural Risk 2: blocks_json column growing too large.
-- Blocks stored individually for partial loading, pagination, diffing.
-- blocks_json column remains as a denormalized cache / backward compat.

CREATE TABLE IF NOT EXISTS cms_blocks (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id    INT UNSIGNED NOT NULL,
    block_type    VARCHAR(50)     NOT NULL DEFAULT 'paragraph',
    sort_order    INT UNSIGNED    NOT NULL DEFAULT 0,
    block_json    JSON            DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cb_content   (content_id, sort_order),
    INDEX idx_cb_type      (block_type),

    CONSTRAINT fk_cb_content FOREIGN KEY (content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
