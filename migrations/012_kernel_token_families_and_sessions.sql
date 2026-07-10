-- Token families for refresh token rotation with theft detection.
-- Each device/session gets one family. The family stores the current
-- refresh token hash and a log of consumed (already-rotated) hashes.
-- If a consumed hash is presented again, theft is detected and
-- the entire family is revoked.

CREATE TABLE IF NOT EXISTS kernel_token_families (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id CHAR(32) NOT NULL COMMENT 'Unique family identifier (hex)',
    user_id INT UNSIGNED NOT NULL,
    current_token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the current valid refresh token',
    consumed_token_hashes JSON DEFAULT NULL COMMENT 'Array of SHA-256 hashes of consumed tokens (for theft detection)',
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_family (family_id),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Device sessions: maps authenticated devices to token families.

CREATE TABLE IF NOT EXISTS kernel_device_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED DEFAULT NULL,
    device_id VARCHAR(64) NOT NULL COMMENT 'Unique device identifier (set by client)',
    device_name VARCHAR(255) DEFAULT NULL,
    token_family_id CHAR(32) NOT NULL,
    last_ip VARCHAR(45) DEFAULT NULL,
    last_user_agent TEXT DEFAULT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME DEFAULT NULL,
    INDEX idx_user (user_id, device_id),
    INDEX idx_family (token_family_id),
    UNIQUE KEY uq_device_family (device_id, token_family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
