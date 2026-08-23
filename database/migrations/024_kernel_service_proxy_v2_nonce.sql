CREATE TABLE IF NOT EXISTS nonce_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    namespace VARCHAR(255) NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nonce_reservation (namespace, nonce),
    KEY idx_nonce_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
