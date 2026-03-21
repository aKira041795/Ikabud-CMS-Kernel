-- CMS Module — Content Graph / Relations
-- Addresses Major Opportunity 1: content graph layer.
-- Enables: post→author, post→category, post→related, product→ingredient, etc.
-- Bidirectional lookup via dual index on source/target.

CREATE TABLE IF NOT EXISTS cms_relations (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_content_id   INT UNSIGNED NOT NULL,
    relation_type       VARCHAR(50)     NOT NULL,
    target_content_id   INT UNSIGNED NOT NULL,
    sort_order          INT UNSIGNED    NOT NULL DEFAULT 0,
    meta_json           JSON            DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_cr_unique  (source_content_id, relation_type, target_content_id),
    INDEX idx_cr_source         (source_content_id, relation_type),
    INDEX idx_cr_target         (target_content_id, relation_type),
    INDEX idx_cr_type           (relation_type),

    CONSTRAINT fk_cr_source FOREIGN KEY (source_content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE,
    CONSTRAINT fk_cr_target FOREIGN KEY (target_content_id)
        REFERENCES cms_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
