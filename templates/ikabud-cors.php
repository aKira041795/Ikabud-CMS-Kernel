<?php
/**
 * Plugin Name: Ikabud CORS Handler
 * Description: Handles CORS headers for cross-domain API requests between dashboard and frontend domains
 * Version: 1.3.0
 * Author: Ikabud Kernel
 * 
 * BLUEHOST SHARED HOSTING FIXES:
 * ===============================
 * This plugin includes 6 critical fixes for Bluehost/shared hosting environments
 * where frontend is on main domain (e.g., ikabudkernel.com) and backend is on 
 * subdomain (e.g., admin.ikabudkernel.com):
 * 
 * 1. HTTPS Protocol Fix - Forces HTTPS detection for X-Forwarded-Proto header
 * 2. OPTIONS Preflight - Handles OPTIONS requests before WordPress loads
 * 3. Early CORS Headers - Sets headers before WordPress can override them
 * 4. REST API Override - Removes WordPress default CORS and sets custom headers
 * 5. REST URL Fix - Forces admin to use backend domain for API calls (NOT frontend)
 * 6. Mixed Content Fix - Forces all URLs to HTTPS to prevent mixed content errors
 * 
 * These fixes address the most common CORS and HTTPS issues in subdomain headless setups.
 * 
 * IMPORTANT: 
 * - Fix #5 prevents WordPress admin from trying to fetch REST API from the frontend
 * - Fix #6 prevents "Mixed Content" errors when database has HTTP URLs but site uses HTTPS
 */

// ============================================================================
// FIX #1: Force HTTPS for Bluehost/Shared Hosting
// ============================================================================
// Bluehost often reports HTTP internally even when HTTPS is used externally
// This causes CORS failures due to protocol mismatch
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Set cookie domain BEFORE WordPress loads (must be very early)
$current_host = $_SERVER['HTTP_HOST'] ?? '';
$host_parts = explode('.', $current_host);
if (count($host_parts) >= 2) {
    $base_domain = '.' . implode('.', array_slice($host_parts, -2));
    
    // Define cookie constants before WordPress loads
    if (!defined('COOKIE_DOMAIN')) {
        define('COOKIE_DOMAIN', $base_domain);
    }
    if (!defined('ADMIN_COOKIE_PATH')) {
        define('ADMIN_COOKIE_PATH', '/');
    }
    if (!defined('COOKIEPATH')) {
        define('COOKIEPATH', '/');
    }
    if (!defined('SITECOOKIEPATH')) {
        define('SITECOOKIEPATH', '/');
    }
}

// ============================================================================
// FIX #2: Handle OPTIONS Preflight BEFORE WordPress Loads
// ============================================================================
// This prevents WordPress from returning 403/404 on OPTIONS requests
// which is a common cause of CORS failures
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if ($origin) {
        $origin_host = parse_url($origin, PHP_URL_HOST);
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        
        if ($origin_host && $current_host) {
            $origin_parts = explode('.', $origin_host);
            $current_parts = explode('.', $current_host);
            
            if (count($origin_parts) >= 2 && count($current_parts) >= 2) {
                $origin_base = implode('.', array_slice($origin_parts, -2));
                $current_base = implode('.', array_slice($current_parts, -2));
                
                // Allow if same base domain
                if ($origin_base === $current_base) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
                    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With, Accept, Origin, X-HTTP-Method-Override');
                    header('Access-Control-Allow-Credentials: true');
                    header('Access-Control-Max-Age: 86400');
                    http_response_code(200);
                    exit(0);
                }
            }
        }
    }
}

// ============================================================================
// FIX #3: Set CORS headers immediately for REST API requests
// ============================================================================
// Set headers before WordPress loads to ensure they're not overridden
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if ($origin) {
        $origin_host = parse_url($origin, PHP_URL_HOST);
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        
        if ($origin_host && $current_host && $origin_host !== $current_host) {
            $origin_parts = explode('.', $origin_host);
            $current_parts = explode('.', $current_host);
            
            if (count($origin_parts) >= 2 && count($current_parts) >= 2) {
                $origin_base = implode('.', array_slice($origin_parts, -2));
                $current_base = implode('.', array_slice($current_parts, -2));
                
                // Allow if same base domain
                if ($origin_base === $current_base) {
                    header('Access-Control-Allow-Origin: ' . $origin);
                    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE, PATCH');
                    header('Access-Control-Allow-Credentials: true');
                    header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override');
                    header('Access-Control-Max-Age: 86400');
                }
            }
        }
    }
}

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
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!$origin) {
        return;
    }
    
    // Extract domain from origin and current request
    $origin_host = parse_url($origin, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    
    // Skip CORS only if origin matches current host (same-origin request)
    if ($origin_host === $current_host) {
        return;
    }
    
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

// ============================================================================
// FIX #4: Override WordPress Default REST API CORS Headers
// ============================================================================
// Remove WordPress default CORS headers and set our own
// This is critical for subdomain setups
add_action('rest_api_init', 'ikabud_rest_api_cors_headers', 1);
function ikabud_rest_api_cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!$origin) {
        return;
    }
    
    $origin_host = parse_url($origin, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    
    // Skip if same origin
    if ($origin_host === $current_host) {
        return;
    }
    
    $origin_parts = explode('.', $origin_host);
    $current_parts = explode('.', $current_host);
    
    if (count($origin_parts) < 2 || count($current_parts) < 2) {
        return;
    }
    
    $origin_base = implode('.', array_slice($origin_parts, -2));
    $current_base = implode('.', array_slice($current_parts, -2));
    
    // Allow if same base domain
    if ($origin_base === $current_base) {
        // CRITICAL: Remove WordPress default CORS headers first
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        
        // Add our custom CORS headers
        add_filter('rest_pre_serve_request', function($value) use ($origin) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE, PATCH');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override');
            header('Access-Control-Max-Age: 86400');
            return $value;
        }, 15);
    }
}

// Additional REST API filter for comprehensive coverage
add_filter('rest_pre_serve_request', 'ikabud_rest_cors_headers', 10, 4);
function ikabud_rest_cors_headers($served, $result, $request, $server) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (!$origin) {
        return $served;
    }
    
    $origin_host = parse_url($origin, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    
    if (!$origin_host || !$current_host) {
        return $served;
    }
    
    $origin_parts = explode('.', $origin_host);
    $current_parts = explode('.', $current_host);
    
    if (count($origin_parts) < 2 || count($current_parts) < 2) {
        return $served;
    }
    
    $origin_base = implode('.', array_slice($origin_parts, -2));
    $current_base = implode('.', array_slice($current_parts, -2));
    
    if ($origin_base === $current_base) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE, PATCH');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization, X-HTTP-Method-Override');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin'); // Important for caching
    }
    
    return $served;
}

/**
 * Fix WordPress cookies to work across subdomains
 * This allows authentication to work between admin.domain.com and domain.com
 */
add_action('plugins_loaded', 'ikabud_fix_subdomain_cookies', 1);
function ikabud_fix_subdomain_cookies() {
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    $host_parts = explode('.', $current_host);
    
    // Get base domain (e.g., "zdnorte.net" from "adminwpdemo.zdnorte.net")
    if (count($host_parts) >= 2) {
        $base_domain = '.' . implode('.', array_slice($host_parts, -2));
        
        // Set WordPress cookie constants if not already defined
        if (!defined('COOKIE_DOMAIN')) {
            define('COOKIE_DOMAIN', $base_domain);
        }
        
        if (!defined('ADMIN_COOKIE_PATH')) {
            define('ADMIN_COOKIE_PATH', '/');
        }
        
        if (!defined('COOKIEPATH')) {
            define('COOKIEPATH', '/');
        }
        
        if (!defined('SITECOOKIEPATH')) {
            define('SITECOOKIEPATH', '/');
        }
        
        // Update PHP session cookie domain
        @ini_set('session.cookie_domain', $base_domain);
        @ini_set('session.cookie_path', '/');
    }
}

/**
 * FIX #6: Force HTTPS for all URLs to prevent Mixed Content errors
 * Bluehost serves pages over HTTPS but database may have HTTP URLs
 */
add_filter('option_siteurl', 'ikabud_force_https_url');
add_filter('option_home', 'ikabud_force_https_url');
add_filter('wp_get_attachment_url', 'ikabud_force_https_url');
add_filter('the_content', 'ikabud_force_https_content');
add_filter('script_loader_src', 'ikabud_force_https_url');
add_filter('style_loader_src', 'ikabud_force_https_url');

function ikabud_force_https_url($url) {
    if (is_string($url) && strpos($url, 'http://') === 0) {
        return str_replace('http://', 'https://', $url);
    }
    return $url;
}

function ikabud_force_https_content($content) {
    if (is_string($content)) {
        return str_replace('http://', 'https://', $content);
    }
    return $content;
}

/**
 * CRITICAL FIX: Force REST API to use backend domain (WP_SITEURL) instead of frontend (WP_HOME)
 * This prevents WordPress admin from trying to fetch from the frontend domain
 */
add_filter('rest_url', 'ikabud_force_backend_rest_url', 10, 2);
function ikabud_force_backend_rest_url($url, $path) {
    // Only modify if we're in admin or this is a REST request
    if (!is_admin() && strpos($_SERVER['REQUEST_URI'] ?? '', '/wp-json/') === false) {
        return $url;
    }
    
    // If WP_SITEURL and WP_HOME are different (headless setup)
    if (defined('WP_SITEURL') && defined('WP_HOME') && WP_SITEURL !== WP_HOME) {
        // Force REST API to use WP_SITEURL (backend domain)
        $rest_url = WP_SITEURL . '/wp-json/';
        if ($path) {
            $rest_url .= ltrim($path, '/');
        }
        return $rest_url;
    }
    
    return $url;
}

/**
 * Force REST API root to use backend domain
 */
add_filter('rest_url_prefix', 'ikabud_rest_url_prefix');
function ikabud_rest_url_prefix($prefix) {
    return 'wp-json';
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
