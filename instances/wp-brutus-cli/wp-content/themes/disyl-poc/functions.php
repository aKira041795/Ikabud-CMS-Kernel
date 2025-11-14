<?php
/**
 * DiSyL POC Theme Functions - Enhanced Version
 * 
 * Integrates DiSyL template engine with WordPress
 * Includes all features to compete with MoreNews theme
 * 
 * @package DiSyL-POC
 * @version 1.0.0
 */

// Enable error display for debugging
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Load DiSyL engine from ikabud-kernel
$kernel_path = dirname(dirname(dirname(dirname(dirname(__DIR__)))));

// Check if kernel path exists
if (!is_dir($kernel_path . '/kernel/DiSyL')) {
    wp_die('DiSyL engine not found at: ' . $kernel_path . '/kernel/DiSyL');
}

try {
    require_once $kernel_path . '/kernel/DiSyL/Token.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/LexerException.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/ParserException.php';
    require_once $kernel_path . '/kernel/DiSyL/Exceptions/CompilerException.php';
    require_once $kernel_path . '/kernel/DiSyL/Lexer.php';
    require_once $kernel_path . '/kernel/DiSyL/Parser.php';
    require_once $kernel_path . '/kernel/DiSyL/Grammar.php';
    require_once $kernel_path . '/kernel/DiSyL/ComponentRegistry.php';
    require_once $kernel_path . '/kernel/DiSyL/Compiler.php';
    require_once $kernel_path . '/kernel/DiSyL/Renderers/BaseRenderer.php';
    require_once $kernel_path . '/kernel/DiSyL/Renderers/WordPressRenderer.php';
    require_once $kernel_path . '/kernel/DiSyL/KernelIntegration.php';
    require_once $kernel_path . '/cms/CMSInterface.php';
    require_once $kernel_path . '/cms/Adapters/WordPressAdapter.php';
    
    // Initialize DiSyL WordPress integration
    \IkabudKernel\Core\DiSyL\KernelIntegration::initWordPress();
} catch (Exception $e) {
    wp_die('Error loading DiSyL: ' . $e->getMessage());
}

use IkabudKernel\Core\DiSyL\Engine;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

/**
 * Render DiSyL template with caching
 */
function disyl_render_template($template_name, $context = []) {
    $template_path = get_template_directory() . '/disyl/' . $template_name . '.disyl';
    
    if (!file_exists($template_path)) {
        return '<!-- DiSyL Template not found: ' . esc_html($template_name) . ' -->';
    }
    
    try {
        // Use Engine to orchestrate compilation
        static $engine = null;
        if ($engine === null) {
            $engine = new Engine();
        }
        
        // Compile template (Engine handles caching internally)
        $compiled = $engine->compileFile($template_path);
        
        // Get WordPress adapter
        global $wp_cms_adapter;
        if (!$wp_cms_adapter) {
            $wp_cms_adapter = new WordPressAdapter(ABSPATH);
        }
        
        // Render through adapter (adapter builds context if not provided)
        return $wp_cms_adapter->renderDisyl($compiled, $context);
        
    } catch (Exception $e) {
        return '<!-- DiSyL Error: ' . esc_html($e->getMessage()) . ' -->';
    }
}

/**
 * Theme setup - Enhanced with all WordPress features
 */
function disyl_theme_setup() {
    // Make theme available for translation
    load_theme_textdomain('disyl-poc', get_template_directory() . '/languages');
    
    // Add default posts and comments RSS feed links to head
    add_theme_support('automatic-feed-links');
    
    // Let WordPress manage the document title
    add_theme_support('title-tag');
    
    // Enable support for Post Thumbnails
    add_theme_support('post-thumbnails');
    
    // Add custom image sizes (like MoreNews)
    add_image_size('disyl-large', 825, 575, true);
    add_image_size('disyl-medium', 590, 410, true);
    add_image_size('disyl-small', 360, 240, true);
    add_image_size('disyl-thumbnail', 150, 150, true);
    
    // Enable support for Post Formats
    add_theme_support('post-formats', [
        'aside',
        'image',
        'video',
        'quote',
        'link',
        'gallery',
        'audio'
    ]);
    
    // Register navigation menus
    register_nav_menus([
        'primary' => __('Primary Menu', 'disyl-poc'),
        'footer' => __('Footer Menu', 'disyl-poc'),
        'social' => __('Social Links Menu', 'disyl-poc'),
    ]);
    
    // Switch default core markup to output valid HTML5
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script'
    ]);
    
    // Set up the WordPress core custom background feature
    add_theme_support('custom-background', [
        'default-color' => 'ffffff',
        'default-image' => '',
    ]);
    
    // Add theme support for selective refresh for widgets
    add_theme_support('customize-selective-refresh-widgets');
    
    // Add support for custom logo
    add_theme_support('custom-logo', [
        'height'      => 100,
        'width'       => 400,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    
    // Add support for Block Styles
    add_theme_support('wp-block-styles');
    
    // Add support for full and wide align images
    add_theme_support('align-wide');
    
    // Add support for editor styles
    add_theme_support('editor-styles');
    
    // Add support for responsive embeds
    add_theme_support('responsive-embeds');
    
    // Set content width
    if (!isset($content_width)) {
        $GLOBALS['content_width'] = 1024;
    }
}
add_action('after_setup_theme', 'disyl_theme_setup');

/**
 * Register widget areas
 */
function disyl_widgets_init() {
    register_sidebar([
        'name'          => __('Primary Sidebar', 'disyl-poc'),
        'id'            => 'sidebar-1',
        'description'   => __('Add widgets here to appear in your sidebar.', 'disyl-poc'),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);
    
    register_sidebar([
        'name'          => __('Footer Widget Area 1', 'disyl-poc'),
        'id'            => 'footer-1',
        'description'   => __('Appears in the footer section of the site.', 'disyl-poc'),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);
    
    register_sidebar([
        'name'          => __('Footer Widget Area 2', 'disyl-poc'),
        'id'            => 'footer-2',
        'description'   => __('Appears in the footer section of the site.', 'disyl-poc'),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);
    
    register_sidebar([
        'name'          => __('Footer Widget Area 3', 'disyl-poc'),
        'id'            => 'footer-3',
        'description'   => __('Appears in the footer section of the site.', 'disyl-poc'),
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);
}
add_action('widgets_init', 'disyl_widgets_init');

/**
 * Enqueue scripts and styles
 */
function disyl_theme_scripts() {
    // Main stylesheet
    wp_enqueue_style('disyl-poc-style', get_stylesheet_uri(), [], '1.0.0');
    
    // DiSyL components CSS (always load)
    wp_enqueue_style('disyl-components', get_template_directory_uri() . '/css/disyl-components.css', ['disyl-poc-style'], '1.0.0');
    
    // Enhanced header/footer CSS
    wp_enqueue_style('disyl-header-footer', get_template_directory_uri() . '/css/header-footer.css', ['disyl-poc-style'], '1.0.0');
    
    // Enhanced home CSS (only on homepage)
    if (is_front_page() || is_home()) {
        wp_enqueue_style('disyl-home', get_template_directory_uri() . '/css/home.css', ['disyl-poc-style'], '1.0.0');
    }
    
    // Google Fonts
    wp_enqueue_style('disyl-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap', [], null);
    
    // Comment reply script
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
    
    // Custom JavaScript (if exists)
    if (file_exists(get_template_directory() . '/js/theme.js')) {
        wp_enqueue_script('disyl-poc-script', get_template_directory_uri() . '/js/theme.js', ['jquery'], '1.0.0', true);
        
        // Localize script
        wp_localize_script('disyl-poc-script', 'disylData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('disyl-nonce'),
        ]);
    }
}
add_action('wp_enqueue_scripts', 'disyl_theme_scripts');

/**
 * Add body classes
 */
function disyl_body_classes($classes) {
    $classes[] = 'disyl-theme';
    
    // Add class if sidebar is active
    if (is_active_sidebar('sidebar-1')) {
        $classes[] = 'has-sidebar';
    }
    
    // Add class for post format
    if (is_singular() && has_post_format()) {
        $classes[] = 'has-post-format-' . get_post_format();
    }
    
    return $classes;
}
add_filter('body_class', 'disyl_body_classes');

/**
 * Custom excerpt length
 */
function disyl_excerpt_length($length) {
    return 30;
}
add_filter('excerpt_length', 'disyl_excerpt_length');

/**
 * Custom excerpt more
 */
function disyl_excerpt_more($more) {
    return '...';
}
add_filter('excerpt_more', 'disyl_excerpt_more');

/**
 * Add custom meta boxes
 */
function disyl_add_meta_boxes() {
    add_meta_box(
        'disyl_post_options',
        __('Post Options', 'disyl-poc'),
        'disyl_post_options_callback',
        'post',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'disyl_add_meta_boxes');

/**
 * Meta box callback
 */
function disyl_post_options_callback($post) {
    wp_nonce_field('disyl_post_options', 'disyl_post_options_nonce');
    $featured = get_post_meta($post->ID, '_disyl_featured', true);
    ?>
    <p>
        <label>
            <input type="checkbox" name="disyl_featured" value="1" <?php checked($featured, '1'); ?> />
            <?php _e('Mark as Featured Post', 'disyl-poc'); ?>
        </label>
    </p>
    <?php
}

/**
 * Save meta box data
 */
function disyl_save_post_options($post_id) {
    if (!isset($_POST['disyl_post_options_nonce']) || 
        !wp_verify_nonce($_POST['disyl_post_options_nonce'], 'disyl_post_options')) {
        return;
    }
    
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    $featured = isset($_POST['disyl_featured']) ? '1' : '0';
    update_post_meta($post_id, '_disyl_featured', $featured);
}
add_action('save_post', 'disyl_save_post_options');

/**
 * Customizer settings
 */
function disyl_customize_register($wp_customize) {
    // Add section for theme options
    $wp_customize->add_section('disyl_options', [
        'title'    => __('DiSyL Theme Options', 'disyl-poc'),
        'priority' => 30,
    ]);
    
    // Add setting for header text color
    $wp_customize->add_setting('disyl_header_text_color', [
        'default'           => '#333333',
        'sanitize_callback' => 'sanitize_hex_color',
    ]);
    
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'disyl_header_text_color', [
        'label'    => __('Header Text Color', 'disyl-poc'),
        'section'  => 'disyl_options',
        'settings' => 'disyl_header_text_color',
    ]));
    
    // Add setting for primary color
    $wp_customize->add_setting('disyl_primary_color', [
        'default'           => '#667eea',
        'sanitize_callback' => 'sanitize_hex_color',
    ]);
    
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'disyl_primary_color', [
        'label'    => __('Primary Color', 'disyl-poc'),
        'section'  => 'disyl_options',
        'settings' => 'disyl_primary_color',
    ]));
    
    // Add setting for footer text
    $wp_customize->add_setting('disyl_footer_text', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    
    $wp_customize->add_control('disyl_footer_text', [
        'label'    => __('Footer Text', 'disyl-poc'),
        'section'  => 'disyl_options',
        'settings' => 'disyl_footer_text',
        'type'     => 'textarea',
    ]);
}
add_action('customize_register', 'disyl_customize_register');

/**
 * Add custom CSS from customizer
 */
function disyl_customizer_css() {
    $header_color = get_theme_mod('disyl_header_text_color', '#333333');
    $primary_color = get_theme_mod('disyl_primary_color', '#667eea');
    ?>
    <style type="text/css">
        :root {
            --primary-color: <?php echo esc_attr($primary_color); ?>;
            --header-color: <?php echo esc_attr($header_color); ?>;
        }
        .site-header { color: var(--header-color); }
        a { color: var(--primary-color); }
        .btn-primary { background-color: var(--primary-color); }
    </style>
    <?php
}
add_action('wp_head', 'disyl_customizer_css');

/**
 * Add theme support for WooCommerce
 */
function disyl_woocommerce_support() {
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
}
add_action('after_setup_theme', 'disyl_woocommerce_support');

/**
 * Performance: Remove WordPress emoji script
 */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

/**
 * Performance: Defer JavaScript loading
 */
function disyl_defer_scripts($tag, $handle, $src) {
    $defer_scripts = ['disyl-poc-script'];
    
    if (in_array($handle, $defer_scripts)) {
        return str_replace(' src', ' defer src', $tag);
    }
    
    return $tag;
}
add_filter('script_loader_tag', 'disyl_defer_scripts', 10, 3);

/**
 * Security: Remove WordPress version from head
 */
remove_action('wp_head', 'wp_generator');

/**
 * Add schema.org markup
 */
function disyl_add_schema() {
    if (is_singular('post')) {
        global $post;
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => get_the_title(),
            'datePublished' => get_the_date('c'),
            'dateModified' => get_the_modified_date('c'),
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author(),
            ],
        ];
        
        if (has_post_thumbnail()) {
            $schema['image'] = get_the_post_thumbnail_url($post, 'full');
        }
        
        echo '<script type="application/ld+json">' . json_encode($schema) . '</script>';
    }
}
add_action('wp_head', 'disyl_add_schema');
