CREATE TABLE IF NOT EXISTS ehr_portal_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    requester_ip VARCHAR(64) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ehr_portal_password_resets_token (token_hash),
    KEY idx_ehr_portal_password_resets_account (account_id, used_at),
    KEY idx_ehr_portal_password_resets_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
