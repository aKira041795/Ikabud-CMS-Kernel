-- Migration: 022_cms_entity_progress
-- Purpose: Dedicated table for per-user entity progress tracking.
-- Replaces the row-per-user-per-entity pattern in cms_content_meta
-- (_progress_user_{userId}) which does not scale past ~10K users.

CREATE TABLE IF NOT EXISTS cms_entity_progress (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    percent     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    metadata    JSON         DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_entity_user (entity_id, user_id),
    INDEX idx_user_id (user_id),

    CONSTRAINT fk_ep_entity
        FOREIGN KEY (entity_id)
        REFERENCES cms_content (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing progress data from cms_content_meta (best-effort)
-- Pattern: meta_key = '_progress_user_{user_id}', meta_value = JSON or int
INSERT IGNORE INTO cms_entity_progress (entity_id, user_id, percent)
SELECT
    m.content_id,
    CAST(SUBSTRING(m.meta_key, 16) AS UNSIGNED),
    LEAST(100, GREATEST(0,
        CASE
            WHEN JSON_VALID(m.meta_value) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(m.meta_value, '$.percent')), 0)
            ELSE CAST(m.meta_value AS UNSIGNED)
        END
    ))
FROM cms_content_meta m
WHERE m.meta_key LIKE '_progress\_user\_%'
  AND CAST(SUBSTRING(m.meta_key, 16) AS UNSIGNED) > 0;
