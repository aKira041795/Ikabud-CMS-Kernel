-- CI clientsite CMS seed
-- Seeds an 'admin' user with role 'administrator' into the clientsite_ci database,
-- plus the minimal published pages (blog, contact) that the e2e_shared_hosting_test
-- asserts return HTTP 200 over cmsnew.test.
--
-- Admin password = 'Admin123!' — the e2e_shared_hosting_test authenticates over
-- /api/v1/auth/login (CMS auth provider → cms_users) with its default
-- admin/Admin123! credentials, so the seeded admin must match that. Other CI
-- tests create their own cms_users rows directly and do not rely on this row's
-- password.

INSERT IGNORE INTO cms_users
    (username, email, password_hash, display_name, role, is_active)
VALUES
    ('admin', 'admin@clientsite.test',
     '$2y$12$ZBC2yN9c5JhlJbCiGjSYG.GryP0EMeJR1sLfz.sc69vKxKXIzMK7y',
     'Site Admin', 'administrator', 1);

-- Blog + Contact pages: /cms/page/blog and /cms/page/contact must render 200
-- for the e2e shared-hosting test. Idempotent (ON DUPLICATE KEY UPDATE by slug).
-- cms_content.author_id is NOT NULL; use the seeded admin user's id.
INSERT INTO cms_content
    (uuid, title, slug, body, excerpt, type, content_mode, status, author_id,
     sort_order, comment_status, published_at, created_at, updated_at, deleted_at,
     is_sticky, is_featured, post_format, word_count, reading_time, comment_count)
SELECT UUID(), 'Blog', 'blog', '<p>Blog index</p>', '', 'page', 'plain', 'published',
     u.id, 0, 'open', NOW(), NOW(), NOW(), NULL, 0, 0, 'standard', 0, 0, 0
  FROM cms_users u WHERE u.username = 'admin'
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    body = VALUES(body),
    type = VALUES(type),
    content_mode = VALUES(content_mode),
    status = VALUES(status),
    published_at = NOW(),
    updated_at = NOW(),
    deleted_at = NULL;

INSERT INTO cms_content
    (uuid, title, slug, body, excerpt, type, content_mode, status, author_id,
     sort_order, comment_status, published_at, created_at, updated_at, deleted_at,
     is_sticky, is_featured, post_format, word_count, reading_time, comment_count)
SELECT UUID(), 'Contact', 'contact', '<p>Contact page</p>', '', 'page', 'plain', 'published',
     u.id, 0, 'open', NOW(), NOW(), NOW(), NULL, 0, 0, 'standard', 0, 0, 0
  FROM cms_users u WHERE u.username = 'admin'
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    body = VALUES(body),
    type = VALUES(type),
    content_mode = VALUES(content_mode),
    status = VALUES(status),
    published_at = NOW(),
    updated_at = NOW(),
    deleted_at = NULL;
