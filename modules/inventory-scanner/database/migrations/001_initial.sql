-- Inventory Scanner: Initial schema
-- Users, products, scan sessions, scan items

CREATE TABLE IF NOT EXISTS is_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    full_name VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'scanner',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_users_role (role),
    INDEX idx_is_users_active (is_active),
    INDEX idx_is_users_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_pr_user (user_id),
    INDEX idx_is_pr_token (token),
    INDEX idx_is_pr_expires (expires_at),
    CONSTRAINT fk_is_pr_user FOREIGN KEY (user_id) REFERENCES is_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    category VARCHAR(100) NULL DEFAULT NULL,
    unit VARCHAR(50) NOT NULL DEFAULT 'pcs',
    quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    location VARCHAR(100) NULL DEFAULT NULL,
    price DECIMAL(12,2) NULL DEFAULT NULL,
    min_quantity DECIMAL(12,3) NULL DEFAULT NULL,
    image_url VARCHAR(500) NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_products_sku (sku),
    INDEX idx_is_products_barcode (barcode),
    INDEX idx_is_products_category (category),
    INDEX idx_is_products_name (name),
    INDEX idx_is_products_location (location),
    INDEX idx_is_products_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_scan_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    session_type VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual, receive, pick, count, audit',
    status VARCHAR(50) NOT NULL DEFAULT 'open' COMMENT 'open, closed, synced',
    notes TEXT NULL,
    device_id VARCHAR(255) NULL DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_is_ss_user (user_id),
    INDEX idx_is_ss_type (session_type),
    INDEX idx_is_ss_status (status),
    INDEX idx_is_ss_device (device_id),
    INDEX idx_is_ss_started (started_at),
    CONSTRAINT fk_is_ss_user FOREIGN KEY (user_id) REFERENCES is_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS is_scan_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NULL DEFAULT NULL,
    barcode_scanned VARCHAR(100) NOT NULL,
    sku_matched VARCHAR(100) NULL DEFAULT NULL,
    product_name VARCHAR(255) NULL DEFAULT NULL,
    quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
    location_scanned VARCHAR(100) NULL DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'scanned' COMMENT 'scanned, matched, unmatched, verified',
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_si_session (session_id),
    INDEX idx_is_si_product (product_id),
    INDEX idx_is_si_barcode (barcode_scanned),
    INDEX idx_is_si_status (status),
    INDEX idx_is_si_scanned (scanned_at),
    INDEX idx_is_si_synced (synced_at),
    CONSTRAINT fk_is_si_session FOREIGN KEY (session_id) REFERENCES is_scan_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_is_si_product FOREIGN KEY (product_id) REFERENCES is_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default admin user (password: admin123 — change on first login)
INSERT INTO is_users (username, email, full_name, password_hash, role, is_active)
VALUES ('admin', 'admin@example.com', 'Administrator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1)
ON DUPLICATE KEY UPDATE username = username;
