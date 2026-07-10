-- Push notification queue with FCM delivery.
-- Notifications are queued by the application and delivered by a worker/cron.
-- This keeps response times fast and allows retry/dead-letter handling.

CREATE TABLE IF NOT EXISTS kernel_push_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    data_json JSON DEFAULT NULL,
    status ENUM('pending', 'sending', 'sent', 'failed', 'dead') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    last_error TEXT DEFAULT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_pending (status, available_at),
    INDEX idx_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mobile device push tokens.

CREATE TABLE IF NOT EXISTS kernel_push_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(512) NOT NULL,
    platform ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
    is_valid BOOLEAN NOT NULL DEFAULT TRUE,
    invalidated_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token),
    INDEX idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
