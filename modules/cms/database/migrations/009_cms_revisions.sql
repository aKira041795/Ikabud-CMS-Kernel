-- Content revisions for version history
CREATE TABLE IF NOT EXISTS cms_revisions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id  INT UNSIGNED NOT NULL,
    author_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(500) NOT NULL,
    body        LONGTEXT     DEFAULT NULL,
    blocks_json JSON         DEFAULT NULL,
    revision_note VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_content_created (content_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slug redirect history for SEO
CREATE TABLE IF NOT EXISTS cms_slug_redirects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id  INT UNSIGNED NOT NULL,
    old_slug    VARCHAR(500) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_old_slug (old_slug(191)),
    INDEX idx_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
