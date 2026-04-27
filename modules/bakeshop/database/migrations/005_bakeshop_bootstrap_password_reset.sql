UPDATE `bakeshop_users`
SET
    `password_hash` = '!bakeshop-bootstrap-password-reset-required!',
    `updated_at` = NOW()
WHERE `username` = 'bakeshopadmin'
  AND `password_hash` = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
