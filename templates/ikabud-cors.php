<?php
/**
 * Plugin Name: Ikabud CORS Handler
 * Description: Handles CORS headers for cross-domain API requests between dashboard and frontend domains
 * Version: 1.0.0
 * Author: Ikabud Kernel
 */

// Handle CORS and CSP - must run VERY early, at send_headers
add_action('send_headers', 'ikabud_handle_cors', 1);
function ikabud_handle_cors() {
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Get base domain for cross-subdomain support
    $host_parts = explode('.', $current_host);
    $base_domain = implode('.', array_slice($host_parts, -2)); // e.g., "brutus.test"
    
    // Detect if this is customizer preview (frontend being framed)
    $is_customizer_preview = isset($_GET['customize_changeset_uuid']) || 
                             isset($_POST['customize_changeset_uuid']) ||
                             isset($_GET['customize_theme']) ||
                             isset($_GET['customize_messenger_channel']);
    
    // For customizer preview, override CSP to allow framing from backend subdomain
    if ($is_customizer_preview) {
        header_remove('Content-Security-Policy');
        header_remove('X-Frame-Options');
        
        // Set CSP that allows framing from same domain and subdomains
        $csp = "default-src 'self' https: http: data: blob:; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: http: blob:; " .
               "style-src 'self' 'unsafe-inline' https: http:; " .
               "img-src 'self' data: https: http:; " .
               "font-src 'self' data: https: http:; " .
               "connect-src 'self' https: http: wss: ws:; " .
               "frame-src 'self' https: http:; " .
               "frame-ancestors 'self' http://*." . $base_domain . " https://*." . $base_domain . "; " .
               "worker-src 'self' blob:;";
        header("Content-Security-Policy: " . $csp);
        
        // Set cookie domain to allow sharing between subdomains
        @ini_set('session.cookie_domain', '.' . $base_domain);
        
        return;
    }
    
    // Skip CORS for admin (Kernel already skips CSP for these)
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!$origin) {
        return;
    }
    
    // Extract domain from origin and current request
    $origin_host = parse_url($origin, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    
    // Get base domain (e.g., "magic.test" from "dashboard.magic.test")
    $origin_parts = explode('.', $origin_host);
    $current_parts = explode('.', $current_host);
    
    // Get last two parts (domain.tld)
    $origin_base = implode('.', array_slice($origin_parts, -2));
    $current_base = implode('.', array_slice($current_parts, -2));
    
    // Allow if same base domain (e.g., admin.thejake.test <-> thejake.test)
    if ($origin_base === $current_base) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override');
        
        // Handle OPTIONS preflight request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit();
        }
    }
}

// Also hook into REST API specific filters for additional coverage
add_filter('rest_pre_serve_request', 'ikabud_rest_cors_headers', 10, 4);
function ikabud_rest_cors_headers($served, $result, $request, $server) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!$origin) {
        return $served;
    }
    
    $origin_host = parse_url($origin, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    
    $origin_parts = explode('.', $origin_host);
    $current_parts = explode('.', $current_host);
    
    $origin_base = implode('.', array_slice($origin_parts, -2));
    $current_base = implode('.', array_slice($current_parts, -2));
    
    if ($origin_base === $current_base) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override');
    }
    
    return $served;
}

/**
 * Fix WordPress Customizer cookies to work across subdomains
 * This prevents "Non-existent changeset UUID" errors
 */
add_action('init', 'ikabud_fix_customizer_cookies', 1);
function ikabud_fix_customizer_cookies() {
    // Only run if in customizer context
    if (!is_customize_preview() && !isset($_GET['customize_changeset_uuid'])) {
        return;
    }
    
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $host_parts = explode('.', $current_host);
    
    // Get base domain (e.g., "brutus.test" from "backend.brutus.test")
    if (count($host_parts) >= 2) {
        $base_domain = '.' . implode('.', array_slice($host_parts, -2));
        
        // Set WordPress cookie constants if not already defined
        if (!defined('COOKIE_DOMAIN')) {
            define('COOKIE_DOMAIN', $base_domain);
        }
        
        // Update PHP session cookie domain
        @ini_set('session.cookie_domain', $base_domain);
    }
}

/**
 * Clean up stale customizer changesets on admin init
 * Prevents accumulation of auto-draft changesets
 */
add_action('admin_init', 'ikabud_cleanup_stale_changesets');
function ikabud_cleanup_stale_changesets() {
    // Only run occasionally (1% of admin page loads)
    if (rand(1, 100) !== 1) {
        return;
    }
    
    global $wpdb;
    
    // Delete auto-draft changesets older than 7 days
    $wpdb->query(
        "DELETE FROM {$wpdb->posts} 
        WHERE post_type = 'customize_changeset' 
        AND post_status = 'auto-draft' 
        AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 50"
    );
}
