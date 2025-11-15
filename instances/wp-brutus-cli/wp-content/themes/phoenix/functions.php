<?php
/**
 * Phoenix Theme Functions
 * 
 * DiSyL-powered WordPress theme with advanced features
 * 
 * @package Phoenix
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Disable debug display for AJAX requests to prevent JSON errors
if (defined('DOING_AJAX') && DOING_AJAX) {
    @ini_set('display_errors', 0);
}

// Fix CSP headers for customizer to allow widget saves
add_filter('customize_allowed_urls', 'phoenix_customize_allowed_urls', 10, 1);
function phoenix_customize_allowed_urls($allowed_urls) {
    // Allow all URLs for customizer (removes CSP restrictions)
    return array('*');
}

// Remove CSP headers completely for admin
add_action('send_headers', 'phoenix_remove_admin_csp', 999);
function phoenix_remove_admin_csp() {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        header_remove('Content-Security-Policy');
        header_remove('X-Frame-Options');
    }
}

/**
 * Theme Setup
 */
function phoenix_setup() {
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
        'primary' => __('Primary Menu', 'phoenix'),
        'footer'  => __('Footer Menu', 'phoenix'),
        'social'  => __('Social Links', 'phoenix'),
    ));
    
    // Add image sizes
    add_image_size('phoenix-hero', 1920, 1080, true);
    add_image_size('phoenix-featured', 800, 600, true);
    add_image_size('phoenix-thumbnail', 400, 300, true);
    add_image_size('phoenix-slider', 1600, 900, true);
}
add_action('after_setup_theme', 'phoenix_setup');

/**
 * Register Widget Areas
 */
function phoenix_widgets_init() {
    // Sidebar
    register_sidebar(array(
        'name'          => __('Main Sidebar', 'phoenix'),
        'id'            => 'sidebar-1',
        'description'   => __('Main sidebar widget area', 'phoenix'),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ));
    
    // Footer widgets (4 columns)
    for ($i = 1; $i <= 4; $i++) {
        register_sidebar(array(
            'name'          => sprintf(__('Footer Widget %d', 'phoenix'), $i),
            'id'            => 'footer-' . $i,
            'description'   => sprintf(__('Footer widget area %d', 'phoenix'), $i),
            'before_widget' => '<div id="%1$s" class="footer-widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ));
    }
    
    // Homepage widgets
    register_sidebar(array(
        'name'          => __('Homepage Hero', 'phoenix'),
        'id'            => 'homepage-hero',
        'description'   => __('Homepage hero section widgets', 'phoenix'),
        'before_widget' => '<div id="%1$s" class="hero-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ));
    
    register_sidebar(array(
        'name'          => __('Homepage Features', 'phoenix'),
        'id'            => 'homepage-features',
        'description'   => __('Homepage features section widgets', 'phoenix'),
        'before_widget' => '<div id="%1$s" class="feature-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ));
}
add_action('widgets_init', 'phoenix_widgets_init');

/**
 * Enqueue Scripts and Styles
 */
function phoenix_scripts() {
    // Theme stylesheet
    wp_enqueue_style('phoenix-style', get_stylesheet_uri(), array(), '1.0.0');
    
    // Custom JavaScript
    wp_enqueue_script('phoenix-scripts', get_template_directory_uri() . '/assets/js/phoenix.js', array(), '1.0.0', true);
    
    // Localize script
    wp_localize_script('phoenix-scripts', 'phoenixData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('phoenix-nonce'),
    ));
    
    // Comment reply script
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'phoenix_scripts');

/**
 * DiSyL Integration
 */
function phoenix_disyl_render($template) {
    // Only process if DiSyL engine is available
    if (!class_exists('\\IkabudKernel\\Core\\DiSyL\\Lexer')) {
        return $template;
    }
    
    // Map WordPress template hierarchy to DiSyL templates
    $disyl_template = null;
    
    if (is_front_page()) {
        $disyl_template = 'home.disyl';
    } elseif (is_home()) {
        $disyl_template = 'blog.disyl';
    } elseif (is_single()) {
        $disyl_template = 'single.disyl';
    } elseif (is_page()) {
        $disyl_template = 'page.disyl';
    } elseif (is_category()) {
        $disyl_template = 'category.disyl';
    } elseif (is_tag()) {
        $disyl_template = 'tag.disyl';
    } elseif (is_archive()) {
        $disyl_template = 'archive.disyl';
    } elseif (is_search()) {
        $disyl_template = 'search.disyl';
    } elseif (is_404()) {
        $disyl_template = '404.disyl';
    }
    
    if (!$disyl_template) {
        return $template;
    }
    
    $disyl_path = get_template_directory() . '/disyl/' . $disyl_template;
    
    if (!file_exists($disyl_path)) {
        return $template;
    }
    
    try {
        // Load DiSyL classes
        $lexer = new \IkabudKernel\Core\DiSyL\Lexer();
        $parser = new \IkabudKernel\Core\DiSyL\Parser();
        $compiler = new \IkabudKernel\Core\DiSyL\Compiler();
        $renderer = new \IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer();
        
        // Read template
        $template_content = file_get_contents($disyl_path);
        
        // Process DiSyL
        $tokens = $lexer->tokenize($template_content);
        $ast = $parser->parse($tokens);
        $compiled = $compiler->compile($ast);
        
        // Build context
        $context = phoenix_build_context();
        
        // Render
        $html = $renderer->render($compiled, $context);
        
        // Output
        echo $html;
        
        return false; // Prevent WordPress from loading default template
        
    } catch (\Exception $e) {
        error_log('Phoenix DiSyL Error: ' . $e->getMessage());
        return $template; // Fallback to default template
    }
}
add_filter('template_include', 'phoenix_disyl_render', 99);

/**
 * Build DiSyL Context
 */
function phoenix_build_context() {
    global $post, $wp_query;
    
    $context = array(
        'site' => array(
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url(),
            'theme_url' => get_template_directory_uri(),
            'charset' => get_bloginfo('charset'),
            'language' => get_bloginfo('language'),
        ),
        'menu' => array(
            'primary' => phoenix_get_menu_items('primary'),
            'footer' => phoenix_get_menu_items('footer'),
            'social' => phoenix_get_menu_items('social'),
        ),
        'widgets' => array(
            'footer_1' => phoenix_get_widget_area('footer-1'),
            'footer_2' => phoenix_get_widget_area('footer-2'),
            'footer_3' => phoenix_get_widget_area('footer-3'),
            'footer_4' => phoenix_get_widget_area('footer-4'),
        ),
        'user' => array(
            'logged_in' => is_user_logged_in(),
            'id' => get_current_user_id(),
            'name' => wp_get_current_user()->display_name,
        ),
        'query' => array(
            'is_home' => is_home(),
            'is_front_page' => is_front_page(),
            'is_single' => is_single(),
            'is_page' => is_page(),
            'is_archive' => is_archive(),
            'is_search' => is_search(),
            'is_404' => is_404(),
        ),
    );
    
    // Add post data if available
    if ($post) {
        $context['post'] = array(
            'id' => $post->ID,
            'title' => get_the_title(),
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt(),
            'date' => get_the_date(),
            'author' => get_the_author(),
            'author_id' => $post->post_author,
            'author_url' => get_author_posts_url($post->post_author),
            'author_avatar' => get_avatar_url($post->post_author),
            'url' => get_permalink(),
            'thumbnail' => get_the_post_thumbnail_url($post, 'full'),
            'categories' => wp_get_post_categories($post->ID, array('fields' => 'names')),
            'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
            'comment_count' => $post->comment_count,
            'comments_open' => comments_open(),
        );
    }
    
    // Add category data if on category page
    if (is_category()) {
        try {
            $context['category'] = phoenix_get_category_context();
        } catch (Exception $e) {
            error_log('Phoenix Category Context Error: ' . $e->getMessage());
            $context['category'] = array();
        }
    }
    
    // Add tag data if on tag page
    if (is_tag()) {
        $context['tag'] = phoenix_get_tag_context();
    }
    
    // Add pagination data
    $context['pagination'] = phoenix_get_pagination_context();
    
    return $context;
}

/**
 * Custom Excerpt Length
 */
function phoenix_excerpt_length($length) {
    return 30;
}
add_filter('excerpt_length', 'phoenix_excerpt_length');

/**
 * Custom Excerpt More
 */
function phoenix_excerpt_more($more) {
    return '...';
}
add_filter('excerpt_more', 'phoenix_excerpt_more');

/**
 * Add custom classes to body
 */
function phoenix_body_classes($classes) {
    if (!is_singular()) {
        $classes[] = 'hfeed';
    }
    
    if (is_active_sidebar('sidebar-1')) {
        $classes[] = 'has-sidebar';
    }
    
    return $classes;
}
add_filter('body_class', 'phoenix_body_classes');

/**
 * Get Menu Items for DiSyL Context
 */
function phoenix_get_menu_items($location) {
    $locations = get_nav_menu_locations();
    
    // Check if menu location has a menu assigned
    if (!isset($locations[$location])) {
        return phoenix_get_fallback_menu($location);
    }
    
    $menu = wp_get_nav_menu_object($locations[$location]);
    
    if (!$menu) {
        return phoenix_get_fallback_menu($location);
    }
    
    $menu_items = wp_get_nav_menu_items($menu->term_id);
    
    if (!$menu_items) {
        return phoenix_get_fallback_menu($location);
    }
    
    // Build hierarchical menu structure properly
    return phoenix_build_menu_tree($menu_items, 0);
}

/**
 * Build hierarchical menu tree recursively
 * CMS-agnostic approach using pure value copying (no references)
 */
function phoenix_build_menu_tree($menu_items, $parent_id = 0) {
    $branch = array();
    
    foreach ($menu_items as $item) {
        // WordPress stores parent as string, convert for comparison
        $item_parent = (int)$item->menu_item_parent;
        
        if ($item_parent == $parent_id) {
            // Recursively get children first
            $children = phoenix_build_menu_tree($menu_items, $item->ID);
            
            // Build classes array
            $classes = $item->classes;
            if (!empty($children)) {
                $classes[] = 'has-submenu';
            }
            
            // Create menu item array
            $menu_item = array(
                'id' => $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'target' => $item->target,
                'classes' => implode(' ', $classes),
                'active' => ($item->url === home_url($_SERVER['REQUEST_URI'])),
                'parent_id' => $item->menu_item_parent,
                'order' => $item->menu_order,
                'children' => $children,
            );
            
            $branch[] = $menu_item;
        }
    }
    
    return $branch;
}

/**
 * Get Category Context for DiSyL
 * CMS-agnostic structure for category/taxonomy pages
 */
function phoenix_get_category_context() {
    $category = get_queried_object();
    
    if (!$category || !isset($category->term_id)) {
        return array();
    }
    
    // Get parent category if exists
    $parent = null;
    if ($category->parent) {
        $parent_cat = get_category($category->parent);
        $parent = array(
            'id' => $parent_cat->term_id,
            'name' => $parent_cat->name,
            'slug' => $parent_cat->slug,
            'url' => get_category_link($parent_cat->term_id),
            'count' => $parent_cat->count,
        );
    }
    
    // Get child categories
    $children = array();
    $child_cats = get_categories(array(
        'parent' => $category->term_id,
        'hide_empty' => false,
    ));
    
    foreach ($child_cats as $child) {
        $children[] = array(
            'id' => $child->term_id,
            'name' => $child->name,
            'slug' => $child->slug,
            'url' => get_category_link($child->term_id),
            'description' => $child->description,
            'count' => $child->count,
            'image' => phoenix_get_term_image($child->term_id),
        );
    }
    
    // Get related categories (siblings)
    $related = array();
    if ($category->parent) {
        $siblings = get_categories(array(
            'parent' => $category->parent,
            'exclude' => $category->term_id,
            'hide_empty' => false,
            'number' => 5,
        ));
        
        foreach ($siblings as $sibling) {
            $related[] = array(
                'id' => $sibling->term_id,
                'name' => $sibling->name,
                'slug' => $sibling->slug,
                'url' => get_category_link($sibling->term_id),
                'count' => $sibling->count,
            );
        }
    }
    
    return array(
        'id' => $category->term_id,
        'name' => $category->name,
        'slug' => $category->slug,
        'description' => $category->description,
        'url' => get_category_link($category->term_id),
        'count' => $category->count,
        'image' => phoenix_get_term_image($category->term_id),
        'parent' => $parent,
        'children' => $children,
        'related' => $related,
    );
}

/**
 * Get Tag Context for DiSyL
 * CMS-agnostic structure for tag pages
 */
function phoenix_get_tag_context() {
    $tag = get_queried_object();
    
    if (!$tag || !isset($tag->term_id)) {
        return array();
    }
    
    // Get related tags (by post overlap)
    $related = array();
    $related_tags = get_tags(array(
        'exclude' => $tag->term_id,
        'number' => 10,
        'orderby' => 'count',
        'order' => 'DESC',
    ));
    
    foreach ($related_tags as $rtag) {
        $related[] = array(
            'id' => $rtag->term_id,
            'name' => $rtag->name,
            'slug' => $rtag->slug,
            'url' => get_tag_link($rtag->term_id),
            'count' => $rtag->count,
        );
    }
    
    return array(
        'id' => $tag->term_id,
        'name' => $tag->name,
        'slug' => $tag->slug,
        'description' => $tag->description,
        'url' => get_tag_link($tag->term_id),
        'count' => $tag->count,
        'related' => $related,
    );
}

/**
 * Get Pagination Context for DiSyL
 * CMS-agnostic pagination structure
 */
function phoenix_get_pagination_context() {
    global $wp_query;
    
    $current_page = max(1, get_query_var('paged'));
    $total_pages = $wp_query->max_num_pages;
    
    return array(
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'prev_url' => get_previous_posts_page_link(),
        'next_url' => get_next_posts_page_link(),
        'has_prev' => $current_page > 1,
        'has_next' => $current_page < $total_pages,
    );
}

/**
 * Get Term Image (for category/tag images)
 * Uses WordPress term meta or returns null
 */
function phoenix_get_term_image($term_id) {
    // Check for common term meta keys
    $image_keys = array('thumbnail_id', 'image', 'category_image');
    
    foreach ($image_keys as $key) {
        $image_id = get_term_meta($term_id, $key, true);
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
            if ($image_url) {
                return $image_url;
            }
        }
    }
    
    return null;
}

/**
 * Get Fallback Menu Items (when no menu is assigned)
 */
function phoenix_get_fallback_menu($location) {
    // Only provide fallback for primary menu
    if ($location !== 'primary') {
        return array();
    }
    
    // Default menu items
    return array(
        array(
            'id' => 0,
            'title' => 'Home',
            'url' => home_url('/'),
            'target' => '',
            'classes' => 'menu-item',
            'active' => is_front_page(),
            'parent_id' => 0,
            'order' => 1,
        ),
        array(
            'id' => 0,
            'title' => 'Blog',
            'url' => home_url('/blog'),
            'target' => '',
            'classes' => 'menu-item',
            'active' => is_home(),
            'parent_id' => 0,
            'order' => 2,
        ),
    );
}

/**
 * Custom Walker for Navigation Menu
 */
class Phoenix_Walker_Nav_Menu extends Walker_Nav_Menu {
    function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        $classes = empty($item->classes) ? array() : (array) $item->classes;
        $classes[] = 'menu-item-' . $item->ID;
        
        if ($item->current) {
            $classes[] = 'active';
        }
        
        $class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args, $depth));
        $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';
        
        $output .= '<li' . $class_names . '>';
        
        $atts = array();
        $atts['href'] = !empty($item->url) ? $item->url : '';
        $atts = apply_filters('nav_menu_link_attributes', $atts, $item, $args, $depth);
        
        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $attributes .= ' ' . $attr . '="' . esc_attr($value) . '"';
            }
        }
        
        $item_output = $args->before;
        $item_output .= '<a' . $attributes . '>';
        $item_output .= $args->link_before . apply_filters('the_title', $item->title, $item->ID) . $args->link_after;
        $item_output .= '</a>';
        $item_output .= $args->after;
        
        $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }
}

/**
 * AJAX Load More Posts
 */
function phoenix_load_more_posts() {
    check_ajax_referer('phoenix-nonce', 'nonce');
    
    $paged = isset($_POST['page']) ? intval($_POST['page']) : 1;
    
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => 6,
        'paged' => $paged,
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        ob_start();
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('disyl/components/post', 'card');
        }
        $html = ob_get_clean();
        wp_reset_postdata();
        
        wp_send_json_success(array(
            'html' => $html,
            'has_more' => $paged < $query->max_num_pages,
        ));
    } else {
        wp_send_json_error(array('message' => 'No more posts'));
    }
}
add_action('wp_ajax_phoenix_load_more', 'phoenix_load_more_posts');
add_action('wp_ajax_nopriv_phoenix_load_more', 'phoenix_load_more_posts');

/**
 * Get widget area content
 */
function phoenix_get_widget_area($sidebar_id) {
    if (!is_active_sidebar($sidebar_id)) {
        return array(
            'active' => false,
            'content' => ''
        );
    }
    
    // Capture widget output
    ob_start();
    dynamic_sidebar($sidebar_id);
    $content = ob_get_clean();
    
    return array(
        'active' => true,
        'content' => $content
    );
}

/**
 * Customizer additions
 */
function phoenix_customize_register($wp_customize) {
    // Hero Section
    $wp_customize->add_section('phoenix_hero', array(
        'title' => __('Hero Section', 'phoenix'),
        'priority' => 30,
    ));
    
    $wp_customize->add_setting('phoenix_hero_title', array(
        'default' => 'Welcome to Phoenix',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    
    $wp_customize->add_control('phoenix_hero_title', array(
        'label' => __('Hero Title', 'phoenix'),
        'section' => 'phoenix_hero',
        'type' => 'text',
    ));
    
    $wp_customize->add_setting('phoenix_hero_subtitle', array(
        'default' => 'A beautiful DiSyL-powered WordPress theme',
        'sanitize_callback' => 'sanitize_textarea_field',
    ));
    
    $wp_customize->add_control('phoenix_hero_subtitle', array(
        'label' => __('Hero Subtitle', 'phoenix'),
        'section' => 'phoenix_hero',
        'type' => 'textarea',
    ));
    
    // Colors
    $wp_customize->add_setting('phoenix_primary_color', array(
        'default' => '#667eea',
        'sanitize_callback' => 'sanitize_hex_color',
    ));
    
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'phoenix_primary_color', array(
        'label' => __('Primary Color', 'phoenix'),
        'section' => 'colors',
    )));
}
add_action('customize_register', 'phoenix_customize_register');
