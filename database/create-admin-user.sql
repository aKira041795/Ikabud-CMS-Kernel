-- Create default admin user for Ikabud Kernel
-- Username: admin
-- Password: Ikabud@2024#Secure
-- IMPORTANT: Change this password immediately after first login!

-- Check if admin_users table exists, if not create it
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('admin','manager','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'viewer',
  `permissions` json DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user
-- Password hash for 'Ikabud@2024#Secure' using bcrypt
INSERT INTO `admin_users` (`username`, `password`, `full_name`, `email`, `role`, `permissions`, `status`)
VALUES (
  'admin',
  '$2y$10$Gq3/x4ZKLM/emjYlQSNXme2IdPrygpmPZAD4.wAhmR7uJIqPJrc9i',
  'System Administrator',
  'admin@ikabud-kernel.local',
  'admin',
  JSON_ARRAY('*'),
  'active'
)
ON DUPLICATE KEY UPDATE
  `password` = '$2y$10$Gq3/x4ZKLM/emjYlQSNXme2IdPrygpmPZAD4.wAhmR7uJIqPJrc9i',
  `status` = 'active';

-- Verify user was created
SELECT 'Admin user created successfully' AS status, username, email, role, status 
FROM admin_users 
WHERE username = 'admin';
