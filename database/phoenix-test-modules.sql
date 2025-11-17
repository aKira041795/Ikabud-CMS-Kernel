-- Phoenix Template Test Modules
-- Creates test modules for hero, sidebar, and footer positions
-- Instance: jml-joomla-the-beginning

-- Clean up existing test modules (optional)
-- DELETE FROM `#__modules` WHERE `title` LIKE 'Phoenix Test%';

-- ============================================
-- HERO MODULE
-- ============================================
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Hero Banner',
    'Test hero module for Phoenix template',
    '<div class="hero-content" style="text-align: center; padding: 4rem 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px;">
        <h1 style="font-size: 3rem; font-weight: 900; margin-bottom: 1rem;">Welcome to Phoenix</h1>
        <p style="font-size: 1.5rem; margin-bottom: 2rem; opacity: 0.9;">A stunning DiSyL-powered Joomla template with modern design</p>
        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
            <a href="#features" class="btn btn-primary" style="padding: 1rem 2rem; background: white; color: #667eea; text-decoration: none; border-radius: 4px; font-weight: 600;">Explore Features</a>
            <a href="#blog" class="btn btn-secondary" style="padding: 1rem 2rem; background: rgba(255,255,255,0.2); color: white; text-decoration: none; border-radius: 4px; font-weight: 600;">Read Blog</a>
        </div>
    </div>',
    1,
    'hero',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    0,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- ============================================
-- SIDEBAR MODULES
-- ============================================

-- Sidebar: About Widget
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - About',
    'Test sidebar about widget',
    '<div class="sidebar-widget about-widget">
        <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: #1a1a2e;">About Us</h3>
        <p style="color: #718096; line-height: 1.6;">Phoenix is a modern, feature-rich Joomla template powered by DiSyL rendering engine. Built for performance and flexibility.</p>
        <a href="/about" style="color: #667eea; text-decoration: none; font-weight: 600;">Learn More →</a>
    </div>',
    1,
    'sidebar-right',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"sidebar-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- Sidebar: Recent Posts
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Recent Posts',
    'Test sidebar recent posts',
    '<div class="sidebar-widget recent-posts-widget">
        <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: #1a1a2e;">Recent Posts</h3>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="padding: 0.75rem 0; border-bottom: 1px solid #e2e8f0;">
                <a href="#" style="color: #2d3748; text-decoration: none; font-weight: 500;">Getting Started with Phoenix</a>
                <span style="display: block; font-size: 0.875rem; color: #718096; margin-top: 0.25rem;">Nov 15, 2025</span>
            </li>
            <li style="padding: 0.75rem 0; border-bottom: 1px solid #e2e8f0;">
                <a href="#" style="color: #2d3748; text-decoration: none; font-weight: 500;">DiSyL Template System</a>
                <span style="display: block; font-size: 0.875rem; color: #718096; margin-top: 0.25rem;">Nov 14, 2025</span>
            </li>
            <li style="padding: 0.75rem 0;">
                <a href="#" style="color: #2d3748; text-decoration: none; font-weight: 500;">Modern Web Design Tips</a>
                <span style="display: block; font-size: 0.875rem; color: #718096; margin-top: 0.25rem;">Nov 13, 2025</span>
            </li>
        </ul>
    </div>',
    2,
    'sidebar-right',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"sidebar-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- Sidebar: Categories
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Categories',
    'Test sidebar categories',
    '<div class="sidebar-widget categories-widget">
        <h3 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: #1a1a2e;">Categories</h3>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="padding: 0.5rem 0;">
                <a href="#" style="color: #667eea; text-decoration: none; display: flex; justify-content: space-between;">
                    <span>Tutorials</span>
                    <span style="background: #f0f4ff; color: #667eea; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem;">12</span>
                </a>
            </li>
            <li style="padding: 0.5rem 0;">
                <a href="#" style="color: #667eea; text-decoration: none; display: flex; justify-content: space-between;">
                    <span>News</span>
                    <span style="background: #f0f4ff; color: #667eea; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem;">8</span>
                </a>
            </li>
            <li style="padding: 0.5rem 0;">
                <a href="#" style="color: #667eea; text-decoration: none; display: flex; justify-content: space-between;">
                    <span>Updates</span>
                    <span style="background: #f0f4ff; color: #667eea; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.875rem;">5</span>
                </a>
            </li>
        </ul>
    </div>',
    3,
    'sidebar-right',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"sidebar-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- ============================================
-- FOOTER MODULES
-- ============================================

-- Footer Column 1: About
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Footer About',
    'Test footer about column',
    '<div class="footer-widget">
        <h4 style="color: white; font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem;">Phoenix Template</h4>
        <p style="color: rgba(255,255,255,0.8); line-height: 1.6; margin-bottom: 1rem;">A modern, powerful Joomla template built with DiSyL rendering engine for maximum flexibility and performance.</p>
        <div style="display: flex; gap: 1rem;">
            <a href="#" style="color: white; font-size: 1.5rem;">📘</a>
            <a href="#" style="color: white; font-size: 1.5rem;">🐦</a>
            <a href="#" style="color: white; font-size: 1.5rem;">📷</a>
            <a href="#" style="color: white; font-size: 1.5rem;">💼</a>
        </div>
    </div>',
    1,
    'footer-1',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"footer-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- Footer Column 2: Quick Links
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Footer Links',
    'Test footer quick links',
    '<div class="footer-widget">
        <h4 style="color: white; font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem;">Quick Links</h4>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="margin-bottom: 0.5rem;"><a href="/" style="color: rgba(255,255,255,0.8); text-decoration: none;">Home</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/about" style="color: rgba(255,255,255,0.8); text-decoration: none;">About Us</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/services" style="color: rgba(255,255,255,0.8); text-decoration: none;">Services</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/blog" style="color: rgba(255,255,255,0.8); text-decoration: none;">Blog</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/contact" style="color: rgba(255,255,255,0.8); text-decoration: none;">Contact</a></li>
        </ul>
    </div>',
    1,
    'footer-2',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"footer-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- Footer Column 3: Resources
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Footer Resources',
    'Test footer resources',
    '<div class="footer-widget">
        <h4 style="color: white; font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem;">Resources</h4>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <li style="margin-bottom: 0.5rem;"><a href="/docs" style="color: rgba(255,255,255,0.8); text-decoration: none;">Documentation</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/tutorials" style="color: rgba(255,255,255,0.8); text-decoration: none;">Tutorials</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/support" style="color: rgba(255,255,255,0.8); text-decoration: none;">Support</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/changelog" style="color: rgba(255,255,255,0.8); text-decoration: none;">Changelog</a></li>
            <li style="margin-bottom: 0.5rem;"><a href="/license" style="color: rgba(255,255,255,0.8); text-decoration: none;">License</a></li>
        </ul>
    </div>',
    1,
    'footer-3',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"footer-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- Footer Column 4: Contact
INSERT INTO `#__modules` (
    `asset_id`, `title`, `note`, `content`, `ordering`, `position`, 
    `checked_out`, `checked_out_time`, `publish_up`, `publish_down`, 
    `published`, `module`, `access`, `showtitle`, `params`, `client_id`, `language`
) VALUES (
    0,
    'Phoenix Test - Footer Contact',
    'Test footer contact info',
    '<div class="footer-widget">
        <h4 style="color: white; font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem;">Contact Us</h4>
        <ul style="list-style: none; padding: 0; margin: 0; color: rgba(255,255,255,0.8);">
            <li style="margin-bottom: 0.75rem; display: flex; gap: 0.5rem;">
                <span>📍</span>
                <span>123 Phoenix Street<br>Digital City, DC 12345</span>
            </li>
            <li style="margin-bottom: 0.75rem; display: flex; gap: 0.5rem;">
                <span>📧</span>
                <span>info@phoenix-template.com</span>
            </li>
            <li style="margin-bottom: 0.75rem; display: flex; gap: 0.5rem;">
                <span>📞</span>
                <span>+1 (555) 123-4567</span>
            </li>
        </ul>
    </div>',
    1,
    'footer-4',
    0,
    NULL,
    NULL,
    NULL,
    1,
    'mod_custom',
    1,
    1,
    '{"prepare_content":"1","backgroundimage":"","layout":"_:default","moduleclass_sfx":"footer-widget","cache":"1","cache_time":"900","cachemode":"static","module_tag":"div","bootstrap_size":"0","header_tag":"h3","header_class":"","style":"0"}',
    0,
    '*'
);

-- ============================================
-- MODULE MENU ASSIGNMENTS (All Pages)
-- ============================================

-- Get the module IDs (these will be auto-incremented)
-- Assign all modules to all menu items (position 0 = all pages)

-- Note: You may need to adjust the module IDs after running the INSERT statements
-- Run this query to see the new module IDs:
-- SELECT id, title, position FROM `#__modules` WHERE title LIKE 'Phoenix Test%' ORDER BY id;

-- Then manually insert into #__modules_menu or use Joomla admin to assign to all pages
