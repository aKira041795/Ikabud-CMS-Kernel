-- Anti Spam Module — Initial Schema
-- Created: 2026-03-20

CREATE TABLE IF NOT EXISTS antispam_blocked_ips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    blocked_until DATETIME DEFAULT NULL,
    is_permanent TINYINT(1) NOT NULL DEFAULT 0,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS antispam_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    request_uri VARCHAR(500) NOT NULL DEFAULT '',
    check_type ENUM('honeypot','rate_limit','keyword','ip_block','manual') NOT NULL,
    result ENUM('pass','fail') NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_created (ip_address, created_at),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS antispam_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO antispam_settings (setting_key, setting_value) VALUES
('enabled', '1'),
('auto_protect_web_apis', '1'),
('skip_authenticated_api_users', '1'),
('honeypot_enabled', '1'),
('rate_limit_enabled', '1'),
('rate_limit_window', '60'),
('rate_limit_max', '10'),
('keyword_block_enabled', '1'),
('blocked_keywords', 'viagra,casino,lottery,nigerian prince,click here now,buy now cheap'),
('log_retention_days', '30');
