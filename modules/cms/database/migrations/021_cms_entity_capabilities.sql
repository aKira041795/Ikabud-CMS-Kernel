-- Migration: 021_cms_entity_capabilities
-- Purpose: Store feature capability profiles attached to CMS entities.
-- Capability IDs (e.g. "pricing", "inventory", "booking") are feature profiles
-- that drive universal template block rendering, distinct from Kernel service caps.

CREATE TABLE IF NOT EXISTS cms_entity_capabilities (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_id     INT UNSIGNED NOT NULL,
    capability_id VARCHAR(64)  NOT NULL,
    config        JSON         NOT NULL DEFAULT (JSON_OBJECT()),
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_entity_capability (entity_id, capability_id),
    INDEX idx_entity_id (entity_id),
    INDEX idx_capability_id (capability_id),

    CONSTRAINT fk_ec_entity
        FOREIGN KEY (entity_id)
        REFERENCES cms_content (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
