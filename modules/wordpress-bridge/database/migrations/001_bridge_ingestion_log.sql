-- ============================================================
-- WordPress Bridge — Ingestion Log
-- Tracks every content ingestion event for idempotency,
-- audit, debugging, and future replay.
-- This migration is idempotent and safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS bridge_ingestion_log (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source            VARCHAR(50)  NOT NULL              COMMENT 'Origin platform: wordpress, joomla, etc.',
    external_id       VARCHAR(100) NOT NULL              COMMENT 'Source-side content ID (WP post ID)',
    external_modified VARCHAR(30)  NOT NULL              COMMENT 'Source-side last-modified timestamp at ingest time',
    event_name        VARCHAR(100) NOT NULL              COMMENT 'Kernel event name that triggered ingestion',
    status            ENUM('processed','skipped','failed') NOT NULL DEFAULT 'processed',
    cms_content_id    INT UNSIGNED NULL                  COMMENT 'Resulting cms_content.id on success',
    payload_json      LONGTEXT     NULL                  COMMENT 'Full normalized payload (for replay)',
    error_message     TEXT         NULL                  COMMENT 'Error details on failure',
    request_id        VARCHAR(50)  NULL                  COMMENT 'Kernel request ID for correlation',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_bridge_ingest (source, external_id, external_modified),
    KEY idx_bridge_status (status),
    KEY idx_bridge_source (source),
    KEY idx_bridge_cms_content (cms_content_id),
    KEY idx_bridge_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
