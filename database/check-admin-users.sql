-- Check existing admin users in the database
-- Run this to see what users exist and their status

SELECT 
    id,
    username,
    full_name,
    email,
    role,
    status,
    created_at,
    SUBSTRING(password, 1, 20) as password_hash_preview
FROM admin_users
ORDER BY id;

-- If no results, the table is empty and you need to run create-admin-user.sql
