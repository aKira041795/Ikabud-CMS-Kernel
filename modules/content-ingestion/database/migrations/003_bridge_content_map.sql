-- ============================================================
-- Content Ingestion — Content Provenance Map
-- Maps (source, external_id) → cms_content.id and tracks sync state.
-- Owned by content-ingestion — no cross-module CMS meta writes needed.
-- Idempotent (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS bridge_content_map (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source          VARCHAR(255) NOT NULL COMMENT 'Ingestion source slug (e.g. emmanuel-church)',
    external_id     VARCHAR(255) NOT NULL COMMENT 'CMS ID from the source (e.g. WP post_id)',
    cms_content_id  INT UNSIGNED NOT NULL COMMENT 'Linked cms_content.id',

    -- Sync state
    bridge_status   VARCHAR(64)  NOT NULL DEFAULT 'external-managed'
                    COMMENT 'external-managed | cms-managed | review-required | retired',
    synced_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                    COMMENT 'Last time this mapping was written',
    source_modified VARCHAR(64)  NOT NULL DEFAULT ''
                    COMMENT 'external_modified value from the last processed event',

    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_source_external (source(191), external_id(191)),
    KEY idx_cms_content_id (cms_content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
