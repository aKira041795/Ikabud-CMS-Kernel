INSERT INTO cms_content_types (slug, label, icon, supports, is_active, sort_order)
VALUES
    ('page', 'Pages', 'file-text', '["title","body","featured_image","builder","slug"]', 1, 10),
    ('post', 'Posts', 'newspaper', '["title","body","excerpt","featured_image","builder","slug"]', 1, 20)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    icon = VALUES(icon),
    supports = VALUES(supports),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order);