CREATE TABLE IF NOT EXISTS cms_auth_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purpose VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    requester_ip VARCHAR(45) DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_auth_verifications_token_hash (token_hash),
    KEY idx_cms_auth_verifications_email (email),
    KEY idx_cms_auth_verifications_purpose (purpose),
    KEY idx_cms_auth_verifications_expires_at (expires_at),
    KEY idx_cms_auth_verifications_verified_at (verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;