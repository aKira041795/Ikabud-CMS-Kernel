<?php
/**
 * WordPress Theme Generator
 * 
 * Generates complete WordPress themes with DiSyL support.
 * Based on the Phoenix theme architecture.
 * 
 * @package IkabudKernel\ThemeGenerator\Generators
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator\Generators;

use IkabudKernel\ThemeGenerator\AbstractThemeGenerator;

class WordPressGenerator extends AbstractThemeGenerator
{
    public function getCmsId(): string
    {
        return 'wordpress';
    }
    
    public function getCmsName(): string
    {
        return 'WordPress';
    }
    
    public function getSupportedFeatures(): array
    {
        return [
            'customizer' => [
                'name' => 'Customizer API',
                'description' => 'Live preview theme customization',
                'enabled' => true,
            ],
            'widgets' => [
                'name' => 'Widget Areas',
                'description' => 'Sidebar and footer widget areas',
                'enabled' => true,
            ],
            'menus' => [
                'name' => 'Menu Locations',
                'description' => 'Navigation menu support',
                'enabled' => true,
            ],
            'post_thumbnails' => [
                'name' => 'Featured Images',
                'description' => 'Post thumbnail support',
                'enabled' => true,
            ],
            'custom_logo' => [
                'name' => 'Custom Logo',
                'description' => 'Site logo upload',
                'enabled' => true,
            ],
            'block_editor' => [
                'name' => 'Block Editor',
                'description' => 'Gutenberg compatibility',
                'enabled' => false,
            ],
        ];
    }
    
    public function getBaseTemplates(): array
    {
        return [
            'home' => [
                'name' => 'Homepage',
                'required' => true,
                'description' => 'Main landing page template',
                'stub' => $this->getHomeStub(),
            ],
            'single' => [
                'name' => 'Single Post',
                'required' => true,
                'description' => 'Individual post template',
                'stub' => $this->getSingleStub(),
            ],
            'page' => [
                'name' => 'Page',
                'required' => true,
                'description' => 'Static page template',
                'stub' => $this->getPageStub(),
            ],
            'archive' => [
                'name' => 'Archive',
                'required' => true,
                'description' => 'Post listing template',
                'stub' => $this->getArchiveStub(),
            ],
            'category' => [
                'name' => 'Category',
                'required' => false,
                'description' => 'Category archive template',
                'stub' => $this->getCategoryStub(),
            ],
            'search' => [
                'name' => 'Search Results',
                'required' => false,
                'description' => 'Search results template',
                'stub' => $this->getSearchStub(),
            ],
            '404' => [
                'name' => '404 Error',
                'required' => true,
                'description' => 'Page not found template',
                'stub' => $this->get404Stub(),
            ],
            'blog' => [
                'name' => 'Blog Index',
                'required' => false,
                'description' => 'Blog listing page',
                'stub' => $this->getBlogStub(),
            ],
        ];
    }
    
    public function getBaseComponents(): array
    {
        return [
            'header' => [
                'name' => 'Header',
                'required' => true,
                'description' => 'Site header with logo and navigation',
                'stub' => $this->getHeaderStub(),
            ],
            'footer' => [
                'name' => 'Footer',
                'required' => true,
                'description' => 'Site footer with widgets',
                'stub' => $this->getFooterStub(),
            ],
            'sidebar' => [
                'name' => 'Sidebar',
                'required' => false,
                'description' => 'Widget sidebar',
                'stub' => $this->getSidebarStub(),
            ],
            'slider' => [
                'name' => 'Slider',
                'required' => false,
                'description' => 'Image slider component',
                'stub' => $this->getSliderStub(),
            ],
        ];
    }
    
    public function generate(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $themeSlug = $config['themeSlug'];
        $themePath = $this->storagePath . '/' . $themeSlug;
        
        // Create directory structure
        $this->createDirectories($themePath, [
            'assets/css',
            'assets/js',
            'assets/images',
            'disyl',
            'disyl/components',
            'disyl/layouts',
            'disyl/widgets',
            'includes',
        ]);
        
        $files = [];
        
        // Generate style.css (WordPress theme header)
        $files['style.css'] = $this->generateStyleCss($config);
        $this->writeFile($themePath . '/style.css', $files['style.css']);
        
        // Generate functions.php
        $files['functions.php'] = $this->generateFunctionsPhp($config);
        $this->writeFile($themePath . '/functions.php', $files['functions.php']);
        
        // Generate index.php
        $files['index.php'] = $this->generateIndexPhp($config);
        $this->writeFile($themePath . '/index.php', $files['index.php']);
        
        // Generate includes
        $files['includes/class-theme-manifest.php'] = $this->generateManifestClass($config);
        $this->writeFile($themePath . '/includes/class-theme-manifest.php', $files['includes/class-theme-manifest.php']);
        
        $files['includes/class-theme-customizer.php'] = $this->generateCustomizerClass($config);
        $this->writeFile($themePath . '/includes/class-theme-customizer.php', $files['includes/class-theme-customizer.php']);
        
        $files['includes/template-functions.php'] = $this->generateTemplateFunctions($config);
        $this->writeFile($themePath . '/includes/template-functions.php', $files['includes/template-functions.php']);
        
        // Generate DiSyL templates
        foreach ($config['templates'] as $templateId => $content) {
            $templatePath = strpos($templateId, 'components/') === 0 
                ? "disyl/{$templateId}.disyl"
                : "disyl/{$templateId}.disyl";
            
            $files[$templatePath] = $content;
            $this->writeFile($themePath . '/' . $templatePath, $content);
        }
        
        // Generate manifest.json
        $files['manifest.json'] = $this->generateManifest($config);
        $this->writeFile($themePath . '/manifest.json', $files['manifest.json']);
        
        // Generate CSS files
        $files['assets/css/disyl-components.css'] = $this->generateDisylComponentsCss();
        $this->writeFile($themePath . '/assets/css/disyl-components.css', $files['assets/css/disyl-components.css']);
        
        // Generate JS files
        $files['assets/js/theme.js'] = $this->generateThemeJs($config);
        $this->writeFile($themePath . '/assets/js/theme.js', $files['assets/js/theme.js']);
        
        // Generate README
        $files['README.md'] = $this->generateReadme($config);
        $this->writeFile($themePath . '/README.md', $files['README.md']);
        
        // Create ZIP archive
        $zipPath = $this->storagePath . '/' . $themeSlug . '.zip';
        $this->createZipArchive($themePath, $zipPath);
        
        return [
            'theme' => [
                'name' => $config['themeName'],
                'slug' => $themeSlug,
                'version' => $config['version'],
                'cms' => $this->getCmsId(),
            ],
            'files' => array_keys($files),
            'downloadUrl' => '/storage/themes/' . $themeSlug . '.zip',
        ];
    }
    
    public function preview(array $config): array
    {
        $config = $this->normalizeConfig($config);
        
        return [
            'style.css' => $this->generateStyleCss($config),
            'functions.php' => $this->generateFunctionsPhp($config),
            'index.php' => $this->generateIndexPhp($config),
            'manifest.json' => $this->generateManifest($config),
        ];
    }
    
    // =========================================================================
    // File Generators
    // =========================================================================
    
    protected function generateStyleCss(array $config): string
    {
        return <<<CSS
/*
Theme Name: {$config['themeName']}
Theme URI: {$config['authorUri']}
Author: {$config['author']}
Author URI: {$config['authorUri']}
Description: {$config['description']}
Version: {$config['version']}
License: {$config['license']}
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: {$config['textDomain']}
Tags: disyl, custom-logo, custom-menu, featured-images, theme-options

DiSyL-powered WordPress theme generated by Ikabud Theme Builder.
*/

/* ==========================================================================
   Base Styles
   ========================================================================== */

:root {
    --color-primary: #3b82f6;
    --color-secondary: #64748b;
    --color-accent: #8b5cf6;
    --color-background: #ffffff;
    --color-surface: #f8fafc;
    --color-text: #1e293b;
    --color-text-muted: #64748b;
    --font-sans: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;
    --spacing-unit: 0.25rem;
    --border-radius: 0.5rem;
    --transition-fast: 150ms ease;
    --transition-normal: 300ms ease;
}

*, *::before, *::after {
    box-sizing: border-box;
}

html {
    font-size: 16px;
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 1rem;
    line-height: 1.6;
    color: var(--color-text);
    background-color: var(--color-background);
}

/* ==========================================================================
   Typography
   ========================================================================== */

h1, h2, h3, h4, h5, h6 {
    margin: 0 0 1rem;
    font-weight: 700;
    line-height: 1.2;
}

h1 { font-size: 2.5rem; }
h2 { font-size: 2rem; }
h3 { font-size: 1.75rem; }
h4 { font-size: 1.5rem; }
h5 { font-size: 1.25rem; }
h6 { font-size: 1rem; }

p {
    margin: 0 0 1rem;
}

a {
    color: var(--color-primary);
    text-decoration: none;
    transition: color var(--transition-fast);
}

a:hover {
    color: var(--color-accent);
}

/* ==========================================================================
   Layout
   ========================================================================== */

.container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
}

.container-sm { max-width: 640px; }
.container-md { max-width: 768px; }
.container-lg { max-width: 1024px; }
.container-xl { max-width: 1280px; }
.container-xlarge { max-width: 1400px; }

/* ==========================================================================
   Buttons
   ========================================================================== */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
    font-weight: 500;
    line-height: 1;
    border: 2px solid transparent;
    border-radius: var(--border-radius);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.btn-primary {
    background-color: var(--color-primary);
    color: white;
}

.btn-primary:hover {
    background-color: #2563eb;
    color: white;
}

.btn-outline {
    background-color: transparent;
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.btn-outline:hover {
    background-color: var(--color-primary);
    color: white;
}

/* ==========================================================================
   Cards
   ========================================================================== */

.card {
    background: var(--color-background);
    border-radius: var(--border-radius);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: box-shadow var(--transition-normal);
}

.card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* ==========================================================================
   Utilities
   ========================================================================== */

.text-center { text-align: center; }
.text-left { text-align: left; }
.text-right { text-align: right; }

.mt-small { margin-top: 0.5rem; }
.mt-medium { margin-top: 1rem; }
.mt-large { margin-top: 2rem; }
.mb-small { margin-bottom: 0.5rem; }
.mb-medium { margin-bottom: 1rem; }
.mb-large { margin-bottom: 2rem; }

.flex { display: flex; }
.flex-center { display: flex; align-items: center; justify-content: center; }
.flex-wrap { flex-wrap: wrap; }
.gap-small { gap: 0.5rem; }
.gap-medium { gap: 1rem; }
.gap-large { gap: 2rem; }

CSS;
    }
    
    protected function generateFunctionsPhp(array $config): string
    {
        $themeName = $config['themeName'];
        $textDomain = $config['textDomain'];
        $themeSlug = $config['themeSlug'];
        $menuLocations = $config['options']['menuLocations'] ?? ['primary', 'footer'];
        $menuLocationsPhp = '';
        foreach ($menuLocations as $location) {
            $label = ucfirst($location) . ' Menu';
            $menuLocationsPhp .= "        '{$location}' => __('{$label}', '{$textDomain}'),\n";
        }
        
        return <<<PHP
<?php
/**
 * {$themeName} Theme Functions
 * 
 * DiSyL-powered WordPress theme
 * Generated by Ikabud Theme Builder
 * 
 * @package {$themeSlug}
 * @version {$config['version']}
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Theme constants
define('{$this->constantName($themeSlug)}_VERSION', '{$config['version']}');
define('{$this->constantName($themeSlug)}_DIR', get_template_directory());
define('{$this->constantName($themeSlug)}_URI', get_template_directory_uri());

/**
 * Load theme includes
 */
require_once get_template_directory() . '/includes/class-theme-manifest.php';
require_once get_template_directory() . '/includes/class-theme-customizer.php';
require_once get_template_directory() . '/includes/template-functions.php';

/**
 * Theme Setup
 */
function {$themeSlug}_setup() {
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ));
    add_theme_support('custom-logo', array(
        'height'      => 100,
        'width'       => 400,
        'flex-height' => true,
        'flex-width'  => true,
    ));
    add_theme_support('custom-background');
    add_theme_support('customize-selective-refresh-widgets');
    
    // Register navigation menus
    register_nav_menus(array(
{$menuLocationsPhp}    ));
    
    // Add image sizes
    add_image_size('{$themeSlug}-hero', 1920, 1080, true);
    add_image_size('{$themeSlug}-featured', 800, 600, true);
    add_image_size('{$themeSlug}-thumbnail', 400, 300, true);
}
add_action('after_setup_theme', '{$themeSlug}_setup');

/**
 * Register Widget Areas
 */
function {$themeSlug}_widgets_init() {
    // Main Sidebar
    register_sidebar(array(
        'name'          => __('Main Sidebar', '{$textDomain}'),
        'id'            => 'sidebar-1',
        'description'   => __('Main sidebar widget area', '{$textDomain}'),
        'before_widget' => '<div id="%1\$s" class="widget %2\$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ));
    
    // Footer widgets
    for (\$i = 1; \$i <= 4; \$i++) {
        register_sidebar(array(
            'name'          => sprintf(__('Footer Widget %d', '{$textDomain}'), \$i),
            'id'            => 'footer-' . \$i,
            'description'   => sprintf(__('Footer widget area %d', '{$textDomain}'), \$i),
            'before_widget' => '<div id="%1\$s" class="footer-widget %2\$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ));
    }
}
add_action('widgets_init', '{$themeSlug}_widgets_init');

/**
 * Enqueue Scripts and Styles
 */
function {$themeSlug}_scripts() {
    // DiSyL components stylesheet
    wp_enqueue_style('disyl-components', get_template_directory_uri() . '/assets/css/disyl-components.css', array(), {$this->constantName($themeSlug)}_VERSION);
    
    // Theme stylesheet
    wp_enqueue_style('{$themeSlug}-style', get_stylesheet_uri(), array('disyl-components'), {$this->constantName($themeSlug)}_VERSION);
    
    // Theme JavaScript
    wp_enqueue_script('{$themeSlug}-scripts', get_template_directory_uri() . '/assets/js/theme.js', array(), {$this->constantName($themeSlug)}_VERSION, true);
    
    // Localize script
    wp_localize_script('{$themeSlug}-scripts', '{$this->camelCase($themeSlug)}Data', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('{$themeSlug}-nonce'),
    ));
    
    // Comment reply script
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', '{$themeSlug}_scripts');

/**
 * Enable DiSyL support
 */
add_theme_support('ikabud-disyl');

/**
 * Extend DiSyL context with theme-specific data
 */
function {$themeSlug}_extend_disyl_context(\$context) {
    // Add menu data
    \$context['menu'] = array(
        'primary' => {$themeSlug}_get_menu_items('primary'),
        'footer' => {$themeSlug}_get_menu_items('footer'),
    );
    
    // Add widget areas
    \$context['widgets'] = array(
        'sidebar' => {$themeSlug}_get_widget_area('sidebar-1'),
        'footer_1' => {$themeSlug}_get_widget_area('footer-1'),
        'footer_2' => {$themeSlug}_get_widget_area('footer-2'),
        'footer_3' => {$themeSlug}_get_widget_area('footer-3'),
        'footer_4' => {$themeSlug}_get_widget_area('footer-4'),
    );
    
    return \$context;
}
add_filter('ikabud_disyl_context', '{$themeSlug}_extend_disyl_context');

/**
 * Get Menu Items for DiSyL Context
 */
function {$themeSlug}_get_menu_items(\$location) {
    \$locations = get_nav_menu_locations();
    
    if (!isset(\$locations[\$location])) {
        return array();
    }
    
    \$menu = wp_get_nav_menu_object(\$locations[\$location]);
    
    if (!\$menu) {
        return array();
    }
    
    \$menu_items = wp_get_nav_menu_items(\$menu->term_id);
    
    if (!\$menu_items) {
        return array();
    }
    
    return {$themeSlug}_build_menu_tree(\$menu_items, 0);
}

/**
 * Build hierarchical menu tree
 */
function {$themeSlug}_build_menu_tree(\$menu_items, \$parent_id = 0) {
    \$branch = array();
    
    foreach (\$menu_items as \$item) {
        \$item_parent = (int)\$item->menu_item_parent;
        
        if (\$item_parent == \$parent_id) {
            \$children = {$themeSlug}_build_menu_tree(\$menu_items, \$item->ID);
            
            \$classes = \$item->classes;
            if (!empty(\$children)) {
                \$classes[] = 'has-submenu';
            }
            
            \$branch[] = array(
                'id' => \$item->ID,
                'title' => \$item->title,
                'url' => \$item->url,
                'target' => \$item->target,
                'classes' => implode(' ', \$classes),
                'active' => (\$item->url === home_url(\$_SERVER['REQUEST_URI'])),
                'children' => \$children,
            );
        }
    }
    
    return \$branch;
}

/**
 * Get widget area content
 */
function {$themeSlug}_get_widget_area(\$sidebar_id) {
    if (!is_active_sidebar(\$sidebar_id)) {
        return array(
            'active' => false,
            'content' => '',
        );
    }
    
    ob_start();
    dynamic_sidebar(\$sidebar_id);
    \$content = ob_get_clean();
    
    return array(
        'active' => true,
        'content' => \$content,
    );
}

PHP;
    }
    
    protected function generateIndexPhp(array $config): string
    {
        $themeSlug = $config['themeSlug'];
        
        return <<<PHP
<?php
/**
 * Main Template File
 * 
 * DiSyL template loader for {$config['themeName']}
 * 
 * @package {$themeSlug}
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Determine which template to load
\$template = 'home';

if (is_singular('post')) {
    \$template = 'single';
} elseif (is_page()) {
    \$template = 'page';
} elseif (is_archive()) {
    \$template = 'archive';
} elseif (is_category()) {
    \$template = 'category';
} elseif (is_search()) {
    \$template = 'search';
} elseif (is_404()) {
    \$template = '404';
} elseif (is_home()) {
    \$template = 'blog';
}

// Load DiSyL template
\$template_file = get_template_directory() . '/disyl/' . \$template . '.disyl';

if (file_exists(\$template_file)) {
    // Use Ikabud DiSyL renderer if available
    if (function_exists('ikabud_render_disyl')) {
        echo ikabud_render_disyl(\$template_file);
    } else {
        // Fallback: include raw template (for development)
        include \$template_file;
    }
} else {
    // Fallback to basic WordPress loop
    get_header();
    
    if (have_posts()) {
        while (have_posts()) {
            the_post();
            the_content();
        }
    }
    
    get_footer();
}

PHP;
    }
    
    protected function generateManifestClass(array $config): string
    {
        $themeSlug = $config['themeSlug'];
        $className = $this->pascalCase($themeSlug) . '_Manifest';
        
        return <<<PHP
<?php
/**
 * Theme Manifest Loader
 * 
 * Loads and parses the theme manifest.json for component definitions
 * 
 * @package {$themeSlug}
 */

class {$className} {
    
    private static \$instance = null;
    private \$manifest = null;
    
    public static function get_instance() {
        if (self::\$instance === null) {
            self::\$instance = new self();
        }
        return self::\$instance;
    }
    
    private function __construct() {
        \$this->load_manifest();
    }
    
    private function load_manifest() {
        \$manifest_path = get_template_directory() . '/manifest.json';
        
        if (file_exists(\$manifest_path)) {
            \$content = file_get_contents(\$manifest_path);
            \$this->manifest = json_decode(\$content, true);
        }
    }
    
    public function get(\$key = null) {
        if (\$key === null) {
            return \$this->manifest;
        }
        
        return \$this->manifest[\$key] ?? null;
    }
    
    public function get_component(\$name) {
        return \$this->manifest['components'][\$name] ?? null;
    }
    
    public function get_components() {
        return \$this->manifest['components'] ?? array();
    }
}

// Initialize manifest
{$className}::get_instance();

PHP;
    }
    
    protected function generateCustomizerClass(array $config): string
    {
        $themeSlug = $config['themeSlug'];
        $textDomain = $config['textDomain'];
        $className = $this->pascalCase($themeSlug) . '_Customizer';
        
        return <<<PHP
<?php
/**
 * Theme Customizer
 * 
 * Registers customizer settings based on manifest.json
 * 
 * @package {$themeSlug}
 */

class {$className} {
    
    public function __construct() {
        add_action('customize_register', array(\$this, 'register'));
    }
    
    public function register(\$wp_customize) {
        // Colors Section
        \$wp_customize->add_section('{$themeSlug}_colors', array(
            'title'    => __('Theme Colors', '{$textDomain}'),
            'priority' => 30,
        ));
        
        // Primary Color
        \$wp_customize->add_setting('{$themeSlug}_primary_color', array(
            'default'           => '#3b82f6',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'postMessage',
        ));
        
        \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$themeSlug}_primary_color', array(
            'label'   => __('Primary Color', '{$textDomain}'),
            'section' => '{$themeSlug}_colors',
        )));
        
        // Header Section
        \$wp_customize->add_section('{$themeSlug}_header', array(
            'title'    => __('Header Settings', '{$textDomain}'),
            'priority' => 40,
        ));
        
        // Sticky Header
        \$wp_customize->add_setting('{$themeSlug}_sticky_header', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        
        \$wp_customize->add_control('{$themeSlug}_sticky_header', array(
            'label'   => __('Enable Sticky Header', '{$textDomain}'),
            'section' => '{$themeSlug}_header',
            'type'    => 'checkbox',
        ));
        
        // Footer Section
        \$wp_customize->add_section('{$themeSlug}_footer', array(
            'title'    => __('Footer Settings', '{$textDomain}'),
            'priority' => 50,
        ));
        
        // Copyright Text
        \$wp_customize->add_setting('{$themeSlug}_copyright', array(
            'default'           => '© ' . date('Y') . ' {$config['themeName']}. All rights reserved.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        \$wp_customize->add_control('{$themeSlug}_copyright', array(
            'label'   => __('Copyright Text', '{$textDomain}'),
            'section' => '{$themeSlug}_footer',
            'type'    => 'text',
        ));
    }
}

// Initialize customizer
new {$className}();

PHP;
    }
    
    protected function generateTemplateFunctions(array $config): string
    {
        $themeSlug = $config['themeSlug'];
        
        return <<<PHP
<?php
/**
 * Template Functions
 * 
 * Helper functions for DiSyL templates
 * 
 * @package {$themeSlug}
 */

/**
 * Get theme option with default fallback
 */
function {$themeSlug}_get_option(\$key, \$default = '') {
    return get_theme_mod('{$themeSlug}_' . \$key, \$default);
}

/**
 * Get post data formatted for DiSyL
 */
function {$themeSlug}_get_post_data(\$post = null) {
    if (!\$post) {
        \$post = get_post();
    }
    
    if (!\$post) {
        return null;
    }
    
    return array(
        'id' => \$post->ID,
        'title' => get_the_title(\$post),
        'content' => apply_filters('the_content', \$post->post_content),
        'excerpt' => get_the_excerpt(\$post),
        'url' => get_permalink(\$post),
        'date' => get_the_date('', \$post),
        'modified' => get_the_modified_date('', \$post),
        'author' => get_the_author_meta('display_name', \$post->post_author),
        'author_url' => get_author_posts_url(\$post->post_author),
        'thumbnail' => get_the_post_thumbnail_url(\$post, 'large'),
        'categories' => wp_get_post_categories(\$post->ID, array('fields' => 'names')),
        'tags' => wp_get_post_tags(\$post->ID, array('fields' => 'names')),
    );
}

/**
 * Get posts formatted for DiSyL query component
 */
function {$themeSlug}_get_posts(\$args = array()) {
    \$defaults = array(
        'post_type' => 'post',
        'posts_per_page' => 10,
        'post_status' => 'publish',
    );
    
    \$args = wp_parse_args(\$args, \$defaults);
    \$query = new WP_Query(\$args);
    \$posts = array();
    
    if (\$query->have_posts()) {
        while (\$query->have_posts()) {
            \$query->the_post();
            \$posts[] = {$themeSlug}_get_post_data();
        }
        wp_reset_postdata();
    }
    
    return \$posts;
}

/**
 * Get pagination data for DiSyL
 */
function {$themeSlug}_get_pagination() {
    global \$wp_query;
    
    return array(
        'current' => max(1, get_query_var('paged')),
        'total' => \$wp_query->max_num_pages,
        'prev_url' => get_previous_posts_link() ? get_previous_posts_page_link() : null,
        'next_url' => get_next_posts_link() ? get_next_posts_page_link() : null,
    );
}

PHP;
    }
    
    protected function generateDisylComponentsCss(): string
    {
        // Load from templates or use default styles
        $cssPath = dirname(__DIR__, 2) . '/templates/disyl-components.css';
        if (file_exists($cssPath)) {
            return file_get_contents($cssPath);
        }
        
        // Return default CSS if file doesn't exist
        return $this->getDefaultDisylComponentsCss();
    }
    
    protected function generateThemeJs(array $config): string
    {
        $themeSlug = $config['themeSlug'];
        
        return <<<JS
/**
 * {$config['themeName']} Theme JavaScript
 * 
 * @package {$themeSlug}
 */

(function() {
    'use strict';
    
    // Mobile menu toggle
    const menuToggle = document.querySelector('.menu-toggle');
    const mobileMenu = document.querySelector('.mobile-menu');
    
    if (menuToggle && mobileMenu) {
        menuToggle.addEventListener('click', function() {
            mobileMenu.classList.toggle('active');
            menuToggle.setAttribute('aria-expanded', 
                menuToggle.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'
            );
        });
    }
    
    // Sticky header
    const header = document.querySelector('.site-header');
    
    if (header && header.classList.contains('sticky-header')) {
        let lastScroll = 0;
        
        window.addEventListener('scroll', function() {
            const currentScroll = window.pageYOffset;
            
            if (currentScroll > 100) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
            
            lastScroll = currentScroll;
        });
    }
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const target = document.querySelector(targetId);
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Reveal animations on scroll
    const revealElements = document.querySelectorAll('.reveal');
    
    if (revealElements.length > 0) {
        const revealOnScroll = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                }
            });
        }, { threshold: 0.1 });
        
        revealElements.forEach(el => revealOnScroll.observe(el));
    }
    
})();

JS;
    }
    
    protected function generateReadme(array $config): string
    {
        return <<<MD
# {$config['themeName']}

{$config['description']}

## Installation

1. Upload the theme folder to `/wp-content/themes/`
2. Activate the theme in WordPress Admin > Appearance > Themes
3. Configure theme options in Appearance > Customize

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Ikabud DiSyL Integration plugin (for full DiSyL support)

## Features

- DiSyL template engine support
- Customizer integration
- Widget areas (sidebar + 4 footer columns)
- Navigation menus (primary + footer)
- Responsive design
- Custom image sizes

## File Structure

```
{$config['themeSlug']}/
├── assets/
│   ├── css/
│   │   └── disyl-components.css
│   └── js/
│       └── theme.js
├── disyl/
│   ├── components/
│   │   ├── header.disyl
│   │   └── footer.disyl
│   ├── home.disyl
│   ├── single.disyl
│   ├── page.disyl
│   └── ...
├── includes/
│   ├── class-theme-manifest.php
│   ├── class-theme-customizer.php
│   └── template-functions.php
├── functions.php
├── index.php
├── manifest.json
├── style.css
└── README.md
```

## Credits

Generated by [Ikabud Theme Builder](https://ikabud.com)

## License

{$config['license']}

MD;
    }
    
    // =========================================================================
    // Template Stubs
    // =========================================================================
    
    protected function getHomeStub(): string
    {
        return <<<DISYL
{!-- Homepage Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{!-- Hero Section --}
{ikb_section type="hero" padding="xlarge"}
    {ikb_container size="xlarge"}
        <div class="text-center">
            {ikb_text size="4xl" weight="bold" class="hero-title"}
                Welcome to {site.name}
            {/ikb_text}
            
            {ikb_text size="xl" class="hero-subtitle"}
                {site.description}
            {/ikb_text}
            
            <div class="mt-large">
                <a href="#content" class="btn btn-primary">Get Started</a>
            </div>
        </div>
    {/ikb_container}
{/ikb_section}

{!-- Latest Posts --}
{ikb_section type="content" id="content" padding="xlarge"}
    {ikb_container size="xlarge"}
        {ikb_text size="2xl" weight="bold" class="section-title" align="center"}
            Latest Posts
        {/ikb_text}
        
        <div class="post-grid">
            {ikb_query type="post" limit=6}
                <article class="card">
                    {if condition="item.thumbnail"}
                        <a href="{item.url | esc_url}">
                            {ikb_image src="{item.thumbnail}" alt="{item.title | esc_attr}" /}
                        </a>
                    {/if}
                    <div class="card-content">
                        {ikb_text size="lg" weight="semibold"}
                            <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                        {/ikb_text}
                        {ikb_text class="post-excerpt"}
                            {item.excerpt | strip_tags | truncate:120}
                        {/ikb_text}
                    </div>
                </article>
            {/ikb_query}
        </div>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function getSingleStub(): string
    {
        return <<<DISYL
{!-- Single Post Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{ikb_section type="content" padding="large"}
    {ikb_container size="lg"}
        <article class="single-post">
            <header class="post-header">
                {ikb_text size="3xl" weight="bold" class="post-title"}
                    {post.title | esc_html}
                {/ikb_text}
                
                <div class="post-meta">
                    <span class="post-date">{post.date | date:format="F j, Y"}</span>
                    <span class="post-author">by {post.author | esc_html}</span>
                </div>
            </header>
            
            {if condition="post.thumbnail"}
                {ikb_image src="{post.thumbnail}" alt="{post.title | esc_attr}" class="post-featured-image" /}
            {/if}
            
            <div class="post-content">
                {post.content | raw}
            </div>
            
            <footer class="post-footer">
                {if condition="post.categories"}
                    <div class="post-categories">
                        Categories: {post.categories | join:", "}
                    </div>
                {/if}
            </footer>
        </article>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function getPageStub(): string
    {
        return <<<DISYL
{!-- Page Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{ikb_section type="content" padding="large"}
    {ikb_container size="lg"}
        <article class="page">
            {ikb_text size="3xl" weight="bold" class="page-title"}
                {post.title | esc_html}
            {/ikb_text}
            
            <div class="page-content">
                {post.content | raw}
            </div>
        </article>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function getArchiveStub(): string
    {
        return <<<DISYL
{!-- Archive Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{ikb_section type="content" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="2xl" weight="bold" class="archive-title"}
            Archive
        {/ikb_text}
        
        <div class="post-grid">
            {ikb_query type="post" limit=12}
                <article class="card">
                    {if condition="item.thumbnail"}
                        <a href="{item.url | esc_url}">
                            {ikb_image src="{item.thumbnail}" alt="{item.title | esc_attr}" /}
                        </a>
                    {/if}
                    <div class="card-content">
                        {ikb_text size="lg" weight="semibold"}
                            <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                        {/ikb_text}
                        <div class="post-meta">
                            <span>{item.date | date:format="M j, Y"}</span>
                        </div>
                    </div>
                </article>
            {/ikb_query}
        </div>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function getCategoryStub(): string
    {
        return $this->getArchiveStub();
    }
    
    protected function getSearchStub(): string
    {
        return <<<DISYL
{!-- Search Results Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{ikb_section type="content" padding="large"}
    {ikb_container size="xlarge"}
        {ikb_text size="2xl" weight="bold"}
            Search Results for: {search.query | esc_html}
        {/ikb_text}
        
        {if condition="posts"}
            <div class="post-list">
                {for items="{posts}" as="item"}
                    <article class="search-result">
                        {ikb_text size="lg" weight="semibold"}
                            <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                        {/ikb_text}
                        {ikb_text class="excerpt"}
                            {item.excerpt | strip_tags | truncate:200}
                        {/ikb_text}
                    </article>
                {/for}
            </div>
        {else}
            {ikb_text}
                No results found. Please try a different search term.
            {/ikb_text}
        {/if}
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function get404Stub(): string
    {
        return <<<DISYL
{!-- 404 Error Template --}
{ikb_platform type="web" targets="wordpress" /}
{include file="components/header.disyl"}

{ikb_section type="content" padding="xlarge"}
    {ikb_container size="md"}
        <div class="text-center">
            {ikb_text size="6xl" weight="bold" class="error-code"}
                404
            {/ikb_text}
            
            {ikb_text size="2xl" weight="semibold"}
                Page Not Found
            {/ikb_text}
            
            {ikb_text class="mt-medium"}
                The page you're looking for doesn't exist or has been moved.
            {/ikb_text}
            
            <div class="mt-large">
                <a href="{site.url}" class="btn btn-primary">Go Home</a>
            </div>
        </div>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl"}

DISYL;
    }
    
    protected function getBlogStub(): string
    {
        return $this->getArchiveStub();
    }
    
    protected function getHeaderStub(): string
    {
        return <<<DISYL
{!-- Header Component --}
<!DOCTYPE html>
<html {html_attributes}>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {wp_head}
</head>
<body {body_class}>

<header class="site-header sticky-header">
    {ikb_container size="xlarge"}
        <div class="header-inner">
            <div class="site-branding">
                {if condition="site.logo"}
                    <a href="{site.url}" class="custom-logo-link">
                        {ikb_image src="{site.logo}" alt="{site.name}" class="custom-logo" /}
                    </a>
                {else}
                    <a href="{site.url}" class="site-title">{site.name}</a>
                {/if}
            </div>
            
            <nav class="main-navigation">
                <ul class="menu">
                    {for items="{menu.primary}" as="item"}
                        <li class="menu-item {item.classes}">
                            <a href="{item.url | esc_url}">{item.title | esc_html}</a>
                            {if condition="item.children"}
                                <ul class="sub-menu">
                                    {for items="{item.children}" as="child"}
                                        <li class="menu-item">
                                            <a href="{child.url | esc_url}">{child.title | esc_html}</a>
                                        </li>
                                    {/for}
                                </ul>
                            {/if}
                        </li>
                    {/for}
                </ul>
            </nav>
            
            <button class="menu-toggle" aria-expanded="false">
                <span class="menu-icon"></span>
            </button>
        </div>
    {/ikb_container}
</header>

<main class="site-main">

DISYL;
    }
    
    protected function getFooterStub(): string
    {
        return <<<DISYL
{!-- Footer Component --}
</main>

<footer class="site-footer">
    {ikb_section type="footer" padding="large"}
        {ikb_container size="xlarge"}
            <div class="footer-widgets">
                {if condition="widgets.footer_1.active"}
                    <div class="footer-widget-area">
                        {widgets.footer_1.content | raw}
                    </div>
                {/if}
                {if condition="widgets.footer_2.active"}
                    <div class="footer-widget-area">
                        {widgets.footer_2.content | raw}
                    </div>
                {/if}
                {if condition="widgets.footer_3.active"}
                    <div class="footer-widget-area">
                        {widgets.footer_3.content | raw}
                    </div>
                {/if}
                {if condition="widgets.footer_4.active"}
                    <div class="footer-widget-area">
                        {widgets.footer_4.content | raw}
                    </div>
                {/if}
            </div>
            
            <div class="footer-bottom">
                {ikb_text size="sm" class="copyright" align="center"}
                    {theme.copyright | esc_html}
                {/ikb_text}
            </div>
        {/ikb_container}
    {/ikb_section}
</footer>

{wp_footer}
</body>
</html>

DISYL;
    }
    
    protected function getSidebarStub(): string
    {
        return <<<DISYL
{!-- Sidebar Component --}
<aside class="sidebar">
    {if condition="widgets.sidebar.active"}
        {widgets.sidebar.content | raw}
    {/if}
</aside>

DISYL;
    }
    
    protected function getSliderStub(): string
    {
        return <<<DISYL
{!-- Slider Component --}
<div class="slider-container">
    <div class="slider">
        {for items="{slides}" as="slide"}
            <div class="slide">
                {ikb_image src="{slide.image}" alt="{slide.title | esc_attr}" /}
                {if condition="slide.title"}
                    <div class="slide-content">
                        {ikb_text size="2xl" weight="bold"}
                            {slide.title | esc_html}
                        {/ikb_text}
                        {if condition="slide.description"}
                            {ikb_text}
                                {slide.description | esc_html}
                            {/ikb_text}
                        {/if}
                    </div>
                {/if}
            </div>
        {/for}
    </div>
</div>

DISYL;
    }
    
    // =========================================================================
    // Helper Methods
    // =========================================================================
    
    protected function constantName(string $slug): string
    {
        return strtoupper(str_replace('-', '_', $slug));
    }
    
    protected function camelCase(string $slug): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))));
    }
    
    protected function pascalCase(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    }
}
