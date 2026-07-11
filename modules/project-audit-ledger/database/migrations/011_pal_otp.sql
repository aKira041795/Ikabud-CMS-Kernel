SET FOREIGN_KEY_CHECKS = 0;

-- Team lead OTP codes (email-based one-time passwords)
-- Pattern follows guidance module's gm_otp_codes
CREATE TABLE IF NOT EXISTS pal_otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(10) NOT NULL,
    purpose VARCHAR(50) NOT NULL DEFAULT 'team_lead_login',
    expires_at DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_otp_email_purpose (email, purpose),
    INDEX idx_pal_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
