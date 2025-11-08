-- Ikabud Kernel - Admin UI Database Schema

-- Admin Users Table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    email VARCHAR(100),
    role ENUM('admin', 'manager', 'viewer') DEFAULT 'viewer',
    permissions JSON,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin Sessions Table
CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create default admin user
-- Password: password (hashed with bcrypt)
INSERT INTO admin_users (username, password, full_name, email, role, permissions)
VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Administrator',
    'admin@ikabud.local',
    'admin',
    '["instances.create", "instances.view", "instances.manage", "instances.delete", "users.manage", "system.config"]'
)
ON DUPLICATE KEY UPDATE username=username;

-- Create manager user
-- Password: manager123
INSERT INTO admin_users (username, password, full_name, email, role, permissions)
VALUES (
    'manager',
    '$2y$10$vI8aWBnW3fID.ZQ4/zo1G.q1lRps.9cGLcZEiGDMVr5yUP1KUOYTa',
    'Manager User',
    'manager@ikabud.local',
    'manager',
    '["instances.create", "instances.view", "instances.manage"]'
)
ON DUPLICATE KEY UPDATE username=username;

-- Create viewer user
-- Password: viewer123
INSERT INTO admin_users (username, password, full_name, email, role, permissions)
VALUES (
    'viewer',
    '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm',
    'Viewer User',
    'viewer@ikabud.local',
    'viewer',
    '["instances.view"]'
)
ON DUPLICATE KEY UPDATE username=username;
