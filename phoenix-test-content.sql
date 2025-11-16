-- Phoenix Test Content for Joomla
-- This SQL creates articles about DiSyL and Ikabud Kernel to test Phoenix template rendering

-- Insert Category for DiSyL Documentation
INSERT INTO `pho_categories` (`id`, `asset_id`, `parent_id`, `lft`, `rgt`, `level`, `path`, `extension`, `title`, `alias`, `note`, `description`, `published`, `access`, `params`, `metadesc`, `metakey`, `metadata`, `created_user_id`, `created_time`, `modified_user_id`, `modified_time`, `hits`, `language`, `version`)
VALUES 
(NULL, 0, 1, 0, 0, 1, 'disyl-documentation', 'com_content', 'DiSyL Documentation', 'disyl-documentation', '', '<p>Documentation for the Declarative Ikabud Syntax Language</p>', 1, 1, '{"category_layout":"","image":"","image_alt":""}', 'DiSyL template language documentation', 'disyl, template, language', '{"author":"","robots":""}', 42, NOW(), 42, NOW(), 0, '*', 1);

SET @disyl_cat_id = LAST_INSERT_ID();

INSERT INTO `pho_categories` (`id`, `asset_id`, `parent_id`, `lft`, `rgt`, `level`, `path`, `extension`, `title`, `alias`, `note`, `description`, `published`, `access`, `params`, `metadesc`, `metakey`, `metadata`, `created_user_id`, `created_time`, `modified_user_id`, `modified_time`, `hits`, `language`, `version`)
VALUES 
(NULL, 0, 1, 0, 0, 1, 'ikabud-kernel', 'com_content', 'Ikabud Kernel', 'ikabud-kernel', '', '<p>Documentation for the Ikabud Kernel framework</p>', 1, 1, '{"category_layout":"","image":"","image_alt":""}', 'Ikabud Kernel framework documentation', 'ikabud, kernel, framework', '{"author":"","robots":""}', 42, NOW(), 42, NOW(), 0, '*', 1);

SET @kernel_cat_id = LAST_INSERT_ID();

-- Insert Articles about DiSyL

-- Article 1: What is DiSyL?
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'What is DiSyL?', 'what-is-disyl', 
'<p>DiSyL (Declarative Ikabud Syntax Language) is a revolutionary template language that enables true cross-CMS compatibility. Write your templates once and deploy them across WordPress, Joomla, Drupal, and more.</p>',
'<h2>Key Features</h2>
<ul>
<li><strong>Cross-CMS Compatibility:</strong> Same templates work across multiple CMS platforms</li>
<li><strong>Component-Based:</strong> Build with reusable components like ikb_section, ikb_container, ikb_card</li>
<li><strong>Filter Pipeline:</strong> Transform data with filters like esc_html, truncate, date</li>
<li><strong>Secure by Default:</strong> Built-in XSS prevention and sanitization</li>
<li><strong>High Performance:</strong> Compiled AST with caching for fast rendering</li>
</ul>

<h2>Example Syntax</h2>
<pre><code>{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            {site.name | esc_html}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}</code></pre>

<h2>Why DiSyL?</h2>
<p>Traditional template systems lock you into a single CMS. DiSyL breaks free from this limitation, allowing you to:</p>
<ul>
<li>Migrate between CMS platforms without rewriting templates</li>
<li>Maintain a single codebase for multi-CMS projects</li>
<li>Reduce development time by 50% or more</li>
<li>Focus on design, not platform-specific syntax</li>
</ul>',
1, @disyl_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL, 
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":"","info_block_position":"","info_block_show_title":"","show_category":"","link_category":"","show_parent_category":"","link_parent_category":"","show_author":"","link_author":"","show_create_date":"","show_modify_date":"","show_publish_date":"","show_item_navigation":"","show_hits":"","show_noauth":"","urls_position":"","alternative_readmore":"","article_page_title":"","show_publishing_options":"","show_article_options":"","show_urls_images_backend":"","show_urls_images_frontend":""}',
1, 1, 'disyl, template language, cross-cms', 'Learn about DiSyL, the declarative template language for cross-CMS development', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Article 2: DiSyL Components Guide
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'DiSyL Components Guide', 'disyl-components-guide',
'<p>DiSyL provides a rich set of components for building modern websites. This guide covers all available components and their usage.</p>',
'<h2>Layout Components</h2>

<h3>ikb_section</h3>
<p>Creates semantic sections with customizable padding and backgrounds.</p>
<pre><code>{ikb_section type="hero" padding="xlarge" background="gradient"}
    Content here
{/ikb_section}</code></pre>

<h3>ikb_container</h3>
<p>Responsive container with size options.</p>
<pre><code>{ikb_container size="large"}
    Content here
{/ikb_container}</code></pre>

<h3>ikb_grid</h3>
<p>Flexible grid layout system.</p>
<pre><code>{ikb_grid columns="3" gap="large"}
    {ikb_card}Card 1{/ikb_card}
    {ikb_card}Card 2{/ikb_card}
    {ikb_card}Card 3{/ikb_card}
{/ikb_grid}</code></pre>

<h2>Content Components</h2>

<h3>ikb_text</h3>
<p>Typography component with size, weight, and alignment options.</p>
<pre><code>{ikb_text size="xl" weight="bold" align="center"}
    Heading Text
{/ikb_text}</code></pre>

<h3>ikb_button</h3>
<p>Styled button with variants.</p>
<pre><code>{ikb_button href="/contact" variant="primary" size="large"}
    Contact Us
{/ikb_button}</code></pre>

<h3>ikb_image</h3>
<p>Responsive image with lazy loading.</p>
<pre><code>{ikb_image src="{post.thumbnail | esc_url}" alt="{post.title | esc_attr}" lazy=true /}</code></pre>

<h2>Dynamic Components</h2>

<h3>ikb_query</h3>
<p>Query and loop through content.</p>
<pre><code>{ikb_query type="post" limit="6" category="news"}
    <article>
        <h2>{item.title | esc_html}</h2>
        <p>{item.excerpt | truncate:length=150}</p>
    </article>
{/ikb_query}</code></pre>

<h3>ikb_menu</h3>
<p>Render navigation menus.</p>
<pre><code>{ikb_menu location="primary" class="main-nav"}</code></pre>',
1, @disyl_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL,
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":""}',
1, 2, 'disyl, components, templates', 'Complete guide to DiSyL components', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Article 3: DiSyL Filters Reference
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'DiSyL Filters Reference', 'disyl-filters-reference',
'<p>Filters transform data in DiSyL templates. Learn about all available filters and how to use them effectively.</p>',
'<h2>Security Filters</h2>

<h3>esc_html</h3>
<p>Escape HTML entities to prevent XSS attacks.</p>
<pre><code>{item.title | esc_html}</code></pre>

<h3>esc_url</h3>
<p>Sanitize and escape URLs.</p>
<pre><code>{item.link | esc_url}</code></pre>

<h3>esc_attr</h3>
<p>Escape HTML attributes.</p>
<pre><code>{item.alt | esc_attr}</code></pre>

<h2>String Filters</h2>

<h3>truncate</h3>
<p>Truncate text by character length.</p>
<pre><code>{item.excerpt | truncate:length=150,append="..."}</code></pre>

<h3>wp_trim_words</h3>
<p>Truncate text by word count.</p>
<pre><code>{item.content | wp_trim_words:num_words=20}</code></pre>

<h3>strip_tags</h3>
<p>Remove HTML tags.</p>
<pre><code>{item.content | strip_tags}</code></pre>

<h3>upper / lower</h3>
<p>Convert case.</p>
<pre><code>{item.title | upper}
{item.title | lower}</code></pre>

<h2>Formatting Filters</h2>

<h3>date</h3>
<p>Format dates.</p>
<pre><code>{item.date | date:format="F j, Y"}</code></pre>

<h2>Filter Chaining</h2>
<p>Combine multiple filters:</p>
<pre><code>{item.content | strip_tags | truncate:length=100 | upper}</code></pre>',
1, @disyl_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL,
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":""}',
1, 3, 'disyl, filters, security', 'Complete reference for DiSyL filters', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Article 4: Ikabud Kernel Overview
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'Ikabud Kernel Overview', 'ikabud-kernel-overview',
'<p>The Ikabud Kernel is a powerful framework that provides the foundation for DiSyL and enables cross-CMS compatibility.</p>',
'<h2>Architecture</h2>
<p>The Ikabud Kernel follows a modular architecture with clear separation of concerns:</p>

<h3>Core Components</h3>
<ul>
<li><strong>DiSyL Engine:</strong> Template parsing, compilation, and rendering</li>
<li><strong>Renderers:</strong> CMS-specific rendering implementations</li>
<li><strong>Manifest System:</strong> Component and filter definitions</li>
<li><strong>Cache Layer:</strong> Performance optimization</li>
</ul>

<h3>Directory Structure</h3>
<pre><code>kernel/
├── DiSyL/
│   ├── Engine.php
│   ├── Lexer.php
│   ├── Parser.php
│   ├── Compiler.php
│   ├── Renderers/
│   │   ├── BaseRenderer.php
│   │   ├── WordPressRenderer.php
│   │   ├── JoomlaRenderer.php
│   │   └── NativeRenderer.php
│   └── Manifests/
│       ├── Core/
│       ├── WordPress/
│       ├── Joomla/
│       └── Drupal/
└── README.md</code></pre>

<h2>Key Features</h2>

<h3>Modular Design</h3>
<p>Each CMS gets its own renderer while sharing the core engine.</p>

<h3>Manifest-Driven</h3>
<p>Components and filters are defined in JSON manifests for easy extensibility.</p>

<h3>Performance</h3>
<p>Compiled AST with caching ensures fast rendering (0.20ms average).</p>

<h2>Supported Platforms</h2>
<ul>
<li>WordPress 5.0+</li>
<li>Joomla 4.0+</li>
<li>Drupal 9.0+ (planned)</li>
<li>Ikabud CMS (native)</li>
</ul>',
1, @kernel_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL,
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":""}',
1, 1, 'ikabud, kernel, framework', 'Overview of the Ikabud Kernel framework', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Article 5: Phoenix Theme Features
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'Phoenix Theme Features', 'phoenix-theme-features',
'<p>Phoenix is a stunning, modern theme powered by DiSyL. It demonstrates the full power of cross-CMS template development.</p>',
'<h2>Design Features</h2>

<h3>Modern Gradients</h3>
<p>Beautiful gradient backgrounds that captivate visitors.</p>

<h3>Smooth Animations</h3>
<p>Reveal animations and smooth transitions for a polished experience.</p>

<h3>Fully Responsive</h3>
<p>Looks perfect on all devices from mobile to desktop.</p>

<h2>Technical Features</h2>

<h3>DiSyL-Powered</h3>
<p>Built entirely with DiSyL templates for maximum flexibility.</p>

<h3>Lightning Fast</h3>
<p>Optimized for performance with efficient rendering.</p>

<h3>SEO Optimized</h3>
<p>Clean semantic HTML and proper meta tags.</p>

<h3>Secure</h3>
<p>Built-in XSS prevention and sanitization.</p>

<h2>Cross-CMS Compatibility</h2>
<p>The same Phoenix theme works on:</p>
<ul>
<li>WordPress</li>
<li>Joomla</li>
<li>Drupal (coming soon)</li>
</ul>

<h2>Customization</h2>
<p>Phoenix offers extensive customization options:</p>
<ul>
<li>Multiple color schemes</li>
<li>Flexible layouts</li>
<li>Widget/module areas</li>
<li>Custom logo and branding</li>
<li>Typography options</li>
</ul>',
1, @kernel_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL,
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":""}',
1, 2, 'phoenix, theme, disyl', 'Features of the Phoenix DiSyL theme', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Article 6: Getting Started with DiSyL
INSERT INTO `pho_content` (`id`, `asset_id`, `title`, `alias`, `introtext`, `fulltext`, `state`, `catid`, `created`, `created_by`, `modified`, `modified_by`, `publish_up`, `publish_down`, `images`, `urls`, `attribs`, `version`, `ordering`, `metakey`, `metadesc`, `access`, `hits`, `metadata`, `featured`, `language`, `note`)
VALUES
(NULL, 0, 'Getting Started with DiSyL', 'getting-started-with-disyl',
'<p>Ready to start building with DiSyL? This guide will get you up and running in minutes.</p>',
'<h2>Installation</h2>

<h3>Requirements</h3>
<ul>
<li>PHP 8.0 or higher</li>
<li>Composer</li>
<li>WordPress 5.0+, Joomla 4.0+, or Drupal 9.0+</li>
</ul>

<h3>Install via Composer</h3>
<pre><code>composer require ikabud/disyl-kernel</code></pre>

<h2>Your First Template</h2>

<h3>Create a Template File</h3>
<p>Create a file named <code>home.disyl</code>:</p>
<pre><code>{ikb_section type="hero" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="3xl" weight="bold"}
            Welcome to {site.name | esc_html}
        {/ikb_text}
    {/ikb_container}
{/ikb_section}</code></pre>

<h3>Render the Template</h3>
<pre><code>use IkabudKernel\\Core\\DiSyL\\Engine;
use IkabudKernel\\Core\\DiSyL\\Renderers\\WordPressRenderer;

$engine = new Engine();
$renderer = new WordPressRenderer();

$context = [
    \'site\' => [\'name\' => \'My Site\']
];

$html = $engine->renderFile(\'home.disyl\', $renderer, $context);
echo $html;</code></pre>

<h2>Next Steps</h2>
<ul>
<li>Explore the component library</li>
<li>Learn about filters</li>
<li>Build your first theme</li>
<li>Join the community</li>
</ul>',
1, @disyl_cat_id, NOW(), 42, NOW(), 42, NOW(), NULL,
'{"image_intro":"","image_intro_alt":"","float_intro":"","image_intro_caption":"","image_fulltext":"","image_fulltext_alt":"","float_fulltext":"","image_fulltext_caption":""}',
'{"urla":"","urlatext":"","targeta":"","urlb":"","urlbtext":"","targetb":"","urlc":"","urlctext":"","targetc":""}',
'{"article_layout":"","show_title":"","link_titles":"","show_tags":"","show_intro":""}',
1, 4, 'disyl, tutorial, getting started', 'Get started with DiSyL in minutes', 1, 0, '{"robots":"","author":"","rights":""}', 1, '*', '');

-- Update category nested set values
UPDATE `pho_categories` SET `lft` = 1, `rgt` = 2 WHERE `id` = @disyl_cat_id;
UPDATE `pho_categories` SET `lft` = 3, `rgt` = 4 WHERE `id` = @kernel_cat_id;
