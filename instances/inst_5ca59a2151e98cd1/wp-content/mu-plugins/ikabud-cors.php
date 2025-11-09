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
    // Load instance manifest for CSP configuration
    $manifest_file = dirname(dirname(__DIR__)) . '/instance.json';
    $manifest = file_exists($manifest_file) ? json_decode(file_get_contents($manifest_file), true) : null;
    
    // Always set CSP header to allow framing from admin subdomain
    if ($manifest && isset($manifest['admin_subdomain'])) {
        $admin_subdomain = $manifest['admin_subdomain'];
        header('Content-Security-Policy: frame-ancestors \'self\' http://' . $admin_subdomain . ' https://' . $admin_subdomain);
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
