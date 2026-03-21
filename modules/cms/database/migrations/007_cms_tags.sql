-- Tags and content-tag pivot
CREATE TABLE IF NOT EXISTS cms_tags (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    slug        VARCHAR(100) NOT NULL UNIQUE,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cms_content_tags (
    content_id  INT UNSIGNED NOT NULL,
    tag_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (content_id, tag_id),
    INDEX idx_tag_content (tag_id, content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
