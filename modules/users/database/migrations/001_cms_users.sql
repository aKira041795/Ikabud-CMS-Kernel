-- Users Module
-- Owns: cms_users

CREATE TABLE IF NOT EXISTS cms_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    role ENUM('superadmin','administrator','editor','author','contributor','subscriber') NOT NULL DEFAULT 'subscriber',
    avatar_url VARCHAR(500) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cms_username (username),
    UNIQUE KEY uk_cms_email (email),
    KEY idx_cms_role (role),
    KEY idx_cms_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a default superadmin (password: admin123)
INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active)
VALUES ('cmsadmin', 'admin@cms.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'CMS Admin', 'superadmin', 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();
