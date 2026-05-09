CREATE TABLE IF NOT EXISTS kernel_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requester_ip VARCHAR(64) NOT NULL DEFAULT '',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kernel_password_resets_token_hash (token_hash),
    KEY idx_kernel_password_resets_user_id (user_id),
    KEY idx_kernel_password_resets_expires_at (expires_at),
    CONSTRAINT fk_kernel_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;