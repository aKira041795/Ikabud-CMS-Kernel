-- WMS Password Reset Tokens
CREATE TABLE IF NOT EXISTS wms_password_resets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    VARCHAR(64) NOT NULL,
    requester_ip  VARCHAR(45) DEFAULT NULL,
    used_at       DATETIME DEFAULT NULL,
    expires_at    DATETIME NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wpr_user (user_id),
    INDEX idx_wpr_token (token_hash),
    INDEX idx_wpr_expires (expires_at),
    CONSTRAINT fk_wpr_user FOREIGN KEY (user_id)
        REFERENCES wms_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
