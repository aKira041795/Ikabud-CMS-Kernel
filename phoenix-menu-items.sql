-- Create menu items for Phoenix navigation
-- Main navigation menu

INSERT INTO pho_menu (menutype, title, alias, note, path, link, type, published, parent_id, level, component_id, checked_out, checked_out_time, browserNav, access, img, template_style_id, params, lft, rgt, home, language, client_id, publish_up, publish_down)
VALUES
('mainmenu', 'DiSyL Documentation', 'disyl-documentation', '', 'disyl-documentation', 'index.php?option=com_content&view=category&id=8', 'component', 1, 1, 1, 22, NULL, NULL, 0, 1, '', 0, '{"show_page_heading":"1","page_heading":"DiSyL Documentation","pageclass_sfx":"","menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1}', 0, 0, 0, '*', 0, NULL, NULL),

('mainmenu', 'Kernel Documentation', 'kernel-documentation', '', 'kernel-documentation', 'index.php?option=com_content&view=category&id=9', 'component', 1, 1, 1, 22, NULL, NULL, 0, 1, '', 0, '{"show_page_heading":"1","page_heading":"Ikabud Kernel","pageclass_sfx":"","menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1}', 0, 0, 0, '*', 0, NULL, NULL),

('mainmenu', 'Getting Started', 'getting-started', '', 'getting-started', 'index.php?option=com_content&view=article&id=6', 'component', 1, 1, 1, 22, NULL, NULL, 0, 1, '', 0, '{"show_title":"1","link_titles":"","show_intro":"","info_block_position":"","info_block_show_title":"","show_category":"","link_category":"","show_parent_category":"","link_parent_category":"","show_associations":"","show_author":"","link_author":"","show_create_date":"","show_modify_date":"","show_publish_date":"","show_item_navigation":"","show_hits":"","show_tags":"","show_noauth":"","urls_position":"","menu-anchor_title":"","menu-anchor_css":"","menu_image":"","menu_image_css":"","menu_text":1,"menu_show":1}', 0, 0, 0, '*', 0, NULL, NULL);

-- Update nested set values for menu tree
SET @disyl_menu_id = (SELECT id FROM pho_menu WHERE alias = 'disyl-documentation' LIMIT 1);
SET @kernel_menu_id = (SELECT id FROM pho_menu WHERE alias = 'kernel-documentation' LIMIT 1);
SET @start_menu_id = (SELECT id FROM pho_menu WHERE alias = 'getting-started' LIMIT 1);

UPDATE pho_menu SET lft = 10, rgt = 11 WHERE id = @disyl_menu_id;
UPDATE pho_menu SET lft = 12, rgt = 13 WHERE id = @kernel_menu_id;
UPDATE pho_menu SET lft = 14, rgt = 15 WHERE id = @start_menu_id;
