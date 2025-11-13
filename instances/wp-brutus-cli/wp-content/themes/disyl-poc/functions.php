<?php
/**
 * DiSyL POC Theme Functions
 * 
 * Integrates DiSyL template engine with WordPress
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
    require_once $kernel_path . '/cms/CMSInterface.php';
    require_once $kernel_path . '/cms/Adapters/WordPressAdapter.php';
} catch (Exception $e) {
    wp_die('Error loading DiSyL: ' . $e->getMessage());
}

use IkabudKernel\Core\DiSyL\{Lexer, Parser, Compiler};
use IkabudKernel\Core\DiSyL\Renderers\WordPressRenderer;
use IkabudKernel\CMS\Adapters\WordPressAdapter;

/**
 * Render DiSyL template
 */
function disyl_render_template($template_name, $context = []) {
    $template_path = get_template_directory() . '/disyl/' . $template_name . '.disyl';
    
    if (!file_exists($template_path)) {
        return '<!-- DiSyL Template not found: ' . esc_html($template_name) . ' -->';
    }
    
    // Get template content
    $template_content = file_get_contents($template_path);
    
    // Cache key based on template name and modification time
    $cache_key = 'disyl_' . md5($template_name . filemtime($template_path));
    $compiled = wp_cache_get($cache_key, 'disyl');
    
    if ($compiled === false) {
        try {
            $lexer = new Lexer();
            $parser = new Parser();
            $compiler = new Compiler();
            
            $tokens = $lexer->tokenize($template_content);
            $ast = $parser->parse($tokens);
            $compiled = $compiler->compile($ast);
            
            // Cache for 1 hour
            wp_cache_set($cache_key, $compiled, 'disyl', 3600);
        } catch (Exception $e) {
            return '<!-- DiSyL Compilation Error: ' . esc_html($e->getMessage()) . ' -->';
        }
    }
    
    // Render through WordPress adapter
    global $wp_cms_adapter;
    if (!$wp_cms_adapter) {
        $wp_cms_adapter = new WordPressAdapter(ABSPATH);
    }
    
    try {
        return $wp_cms_adapter->renderDisyl($compiled, $context);
    } catch (Exception $e) {
        return '<!-- DiSyL Rendering Error: ' . esc_html($e->getMessage()) . ' -->';
    }
}

/**
 * Theme setup
 */
function disyl_theme_setup() {
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption'
    ]);
    
    // Register navigation menus
    register_nav_menus([
        'primary' => __('Primary Menu', 'disyl-poc'),
    ]);
    
    // Set content width
    if (!isset($content_width)) {
        $content_width = 1024;
    }
}
add_action('after_setup_theme', 'disyl_theme_setup');

/**
 * Enqueue scripts and styles
 */
function disyl_theme_scripts() {
    wp_enqueue_style('disyl-poc-style', get_stylesheet_uri(), [], '0.1.0');
}
add_action('wp_enqueue_scripts', 'disyl_theme_scripts');

/**
 * Add body classes
 */
function disyl_body_classes($classes) {
    $classes[] = 'disyl-theme';
    return $classes;
}
add_filter('body_class', 'disyl_body_classes');
