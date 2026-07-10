-- Idempotency key storage for safe mobile retries.
-- Keys are SHA-256 hashed; plain keys never stored.
-- Expired keys (older than 24h) should be purged periodically.

CREATE TABLE IF NOT EXISTS kernel_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idempotency_key_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the client-supplied idempotency key',
    tenant_id INT UNSIGNED NOT NULL,
    status ENUM('processing', 'completed') NOT NULL DEFAULT 'processing',
    response_json LONGTEXT NULL COMMENT 'Cached response for completed keys',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_key_tenant (idempotency_key_hash, tenant_id),
    INDEX idx_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
