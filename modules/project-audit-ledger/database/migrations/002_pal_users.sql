-- Project Audit Ledger — Users table + bootstrap seed

CREATE TABLE IF NOT EXISTS pal_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin','supervisor','encoder') NOT NULL DEFAULT 'encoder',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    token_version INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pal_user_username (tenant_id, username),
    INDEX idx_pal_user_tenant (tenant_id),
    INDEX idx_pal_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bootstrap admin user (placeholder password requires reset)
INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active)
SELECT
    1 AS tenant_id,
    'admin' AS username,
    'admin@project-ledger.local' AS email,
    '!pal-bootstrap-password-reset-required!' AS password_hash,
    'System Admin' AS full_name,
    'admin' AS role,
    1 AS is_active
WHERE NOT EXISTS (SELECT 1 FROM pal_users LIMIT 1);
