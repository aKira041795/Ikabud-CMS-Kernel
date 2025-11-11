-- Clear all failed login attempts
-- Use this to reset rate limiting

DELETE FROM `login_attempts`;

-- Verify cleared
SELECT 'Login attempts cleared successfully' AS status,
       COUNT(*) as remaining_attempts
FROM login_attempts;
