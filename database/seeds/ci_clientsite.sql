-- CI clientsite CMS seed
-- Seeds an 'admin' user with role 'administrator' into the clientsite_ci database.
-- Password hash = bcrypt of 'password' (standard test fixture hash).

INSERT IGNORE INTO cms_users
    (username, email, password_hash, display_name, role, is_active)
VALUES
    ('admin', 'admin@clientsite.test',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Site Admin', 'administrator', 1);
