CREATE TABLE IF NOT EXISTS dl_admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dl_admins_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing admins from dl_supervisors to dl_admins
INSERT INTO dl_admins (username, password_hash, full_name, is_active, created_at, updated_at)
SELECT username, password_hash, full_name, is_active, created_at, updated_at
FROM dl_supervisors
WHERE username LIKE 'admin%';

DELETE FROM dl_supervisors WHERE username LIKE 'admin%';
