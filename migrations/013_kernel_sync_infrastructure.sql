-- Entity revision tracking for offline synchronization.
--
-- Every entity create/update/delete increments the revision counter.
-- Mobile clients poll for changes since their last cursor (which encodes
-- the last-seen revision number).
--
-- Architecture:
--   kernel_entity_revisions: monotonically increasing global revision counter
--     per entity type. Every mutation INSERTs a row.
--   kernel_entity_tombstones: tracks deleted records so mobile clients can
--     soft-delete locally.
--   kernel_sync_cursors: tracks each client's sync state (optional).

CREATE TABLE IF NOT EXISTS kernel_entity_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(64) NOT NULL COMMENT 'e.g. ledger_entry, product, order',
    entity_id VARCHAR(128) NOT NULL COMMENT 'Server-side entity identifier (string for UUID support)',
    operation ENUM('created', 'updated', 'deleted') NOT NULL,
    payload_json LONGTEXT DEFAULT NULL COMMENT 'Full or partial entity snapshot at this revision',
    actor_user_id INT UNSIGNED DEFAULT NULL,
    actor_module VARCHAR(64) DEFAULT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_entity (entity_type, revision),
    INDEX idx_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tombstones: minimal record of deleted entities for sync.
-- These can be cleaned up after N days (configurable).

CREATE TABLE IF NOT EXISTS kernel_entity_tombstones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    deleted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_entity (entity_type, entity_id),
    INDEX idx_revision (revision),
    INDEX idx_cleanup (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync cursors: tracks each device's last-seen revision per entity type.
-- Used for the cursor-based incremental sync endpoint.

CREATE TABLE IF NOT EXISTS kernel_sync_cursors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED DEFAULT NULL,
    entity_type VARCHAR(64) NOT NULL,
    last_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_device_entity (device_id, entity_type),
    INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
