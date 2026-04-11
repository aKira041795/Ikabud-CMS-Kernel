-- Content Ingestion: Media ingestion log
-- Tracks every media file downloaded from WordPress, with URL-based and content-based dedup.
--
-- Dedup strategy:
--   url_hash  (UNIQUE) — same URL is never downloaded twice
--   file_hash (INDEX)  — same file content at a different URL reuses the existing cms_media row
--   local_url          — stored alongside cms_media_id to avoid JOIN queries on every lookup

CREATE TABLE IF NOT EXISTS bridge_media_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source          VARCHAR(50)   NOT NULL,           -- 'wordpress', 'joomla', etc.
    external_url    VARCHAR(2000) NOT NULL,           -- original URL from WP attachment
    url_hash        CHAR(64)      NOT NULL,           -- sha256(lower(external_url))
    file_hash       CHAR(64)      NULL,               -- sha256(file content) — NULL on failure
    cms_media_id    INT UNSIGNED  NULL,               -- resulting cms_media.id (NULL on failure)
    local_url       VARCHAR(1000) NULL,               -- resolved CMS URL (cached for body rewrite)
    status          ENUM('fetched','failed') NOT NULL DEFAULT 'fetched',
    error_message   TEXT          NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bridge_media_url  (url_hash),
    KEY idx_bridge_media_file_hash  (file_hash),
    KEY idx_bridge_media_cms        (cms_media_id),
    KEY idx_bridge_media_source     (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
