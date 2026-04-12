-- Security Module — Initial Schema
-- Created: 2026-04-12

CREATE TABLE IF NOT EXISTS security_file_baselines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(500) NOT NULL,
    sha256_hash CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    mismatch_detected_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_path (file_path(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    user_id INT UNSIGNED DEFAULT NULL,
    user_source VARCHAR(40) DEFAULT NULL,
    detail_json JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event_type (event_type),
    KEY idx_severity (severity),
    KEY idx_created (created_at),
    KEY idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS security_admin_ip_allowlist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    label VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY uq_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
