-- Update admin user password for Ikabud Kernel
-- This script updates the existing admin user password
-- Username: admin
-- Password: Ikabud@2024#Secure

UPDATE `admin_users` 
SET 
  `password` = '$2y$10$Gq3/x4ZKLM/emjYlQSNXme2IdPrygpmPZAD4.wAhmR7uJIqPJrc9i',
  `status` = 'active',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `username` = 'admin';

-- Verify the update
SELECT 'Admin password updated successfully' AS status, 
       username, 
       email, 
       role, 
       status,
       updated_at
FROM admin_users 
WHERE username = 'admin';
