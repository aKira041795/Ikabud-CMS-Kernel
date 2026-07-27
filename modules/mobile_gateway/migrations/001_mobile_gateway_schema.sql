-- Mobile Gateway: device metadata table.
--
-- Tracks device identity (separate from push tokens in kernel_push_tokens)
-- and links to kernel_device_sessions for session-level tracking.
--
-- Privacy: push_token and device_name are privacy-sensitive.
-- They should not be included in audit log output.

CREATE TABLE IF NOT EXISTS mgw_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tenant_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL for kernel-level devices',
    device_id VARCHAR(64) NOT NULL COMMENT 'Client-generated unique device identifier',
    device_name VARCHAR(255) DEFAULT NULL COMMENT 'Human-readable device name',
    platform ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
    push_token VARCHAR(512) DEFAULT NULL COMMENT '@privacy-sensitive FCM/APNs push token',
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    device_session_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK to kernel_device_sessions.id',
    last_ip VARCHAR(45) DEFAULT NULL,
    last_user_agent TEXT DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_device (user_id, device_id),
    INDEX idx_push_token (push_token(128)),
    INDEX idx_tenant (tenant_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
