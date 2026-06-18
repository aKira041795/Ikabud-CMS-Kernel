-- Migration 014: Bootstrap admin user for attendance-wage tenant
INSERT INTO `attendance_wage_users` (`username`, `email`, `phone`, `password_hash`, `full_name`, `role`, `is_active`)
SELECT * FROM (
    SELECT
        'zapadmin' AS `username`,
        'admin@zapattendance.test' AS `email`,
        NULL AS `phone`,
        '!attendance-wage-bootstrap-password-reset-required!' AS `password_hash`,
        'ZAP Admin' AS `full_name`,
        'admin' AS `role`,
        1 AS `is_active`
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `attendance_wage_users` WHERE `username` = 'zapadmin' LIMIT 1);
