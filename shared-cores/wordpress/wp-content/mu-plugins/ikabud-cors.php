<?php
/**
 * Plugin Name: Ikabud CORS Handler
 * Description: Handles CORS headers for cross-domain API requests between dashboard and frontend domains
 * Version: 1.0.0
 * Author: Ikabud Kernel
 */

// Handle CORS preflight and regular requests
add_action('init', 'ikabud_handle_cors');
function ikabud_handle_cors() {
    $origin = get_http_origin();
    
    // Check if request is from a dashboard subdomain
    if ($origin && strpos($origin, 'dashboard.') !== false) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, X-WP-Nonce, Content-Type, Accept, Authorization');
        
        // Handle OPTIONS preflight request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit();
        }
    }
}
