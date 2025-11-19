<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: wp-wp-demo-site
 * Generated: Tue Nov 18 04:16:48 MST 2025
 */

// Database Configuration
define('DB_NAME', 'zdnorten_wp925');
define('DB_USER', 'zdnorten_wp925');
define('DB_PASSWORD', 'dTm3_.2A[?[h');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
define('AUTH_KEY',         ']bGgMc<nOdvEGa%*aK+wP[B_2ID%-WC=W[3b(Yz)o?{%Tq|<>X9|TjE_]q>[f7k:');
define('SECURE_AUTH_KEY',  'jQ;|wRG+E{bI!|F@rVH/.II:L6,ua>;tu+LD-:46PLGm{;H3l,Wl,FukP|g`S-$?');
define('LOGGED_IN_KEY',    'IIOyBbmrW/,6vfO<d!]*LGm{vmaW}&ZBOaU[t<[[AKe{V/P_?pcswv~R5Nsoj19k');
define('NONCE_KEY',        ';@YN`KdOb#4V2!g`>D-rF3TP|[s$z0bZ,]MtG4pgp.ge&w8tIwhh+@>CM#Ht:_04');
define('AUTH_SALT',        'F2?1]eRAInz~GL~/9*H_*/X52L[*V.tq%J~z5:(^II:mDsZaQQU0x?-xH>2kWO~Y');
define('SECURE_AUTH_SALT', 'L*[F1gaBya1#F(7Z3Vq$VrkUcTk(M*rvo??9f/dx`XPAPqn$)KW5^_psiZ-/dCo8');
define('LOGGED_IN_SALT',   'Dbxvwp`7+F2l?BA|kT{{MsD1-CvW[EzhD75NVUBF7fmk)Ko-K$>`q!uR*.emybyb');
define('NONCE_SALT',       '0&2d@x.@5afKIy[2kZS<:WoFz,!{tIvMOeGHpI5V0VQH,b(h`PFD^|0@fTmQfg|e');

// WordPress Database Table prefix
$table_prefix = 'wpDemo_';

// WordPress Debugging
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Dynamic URLs based on current host to prevent redirects
$current_host = $_SERVER['HTTP_HOST'] ?? 'wpdemo.zdnorte.net';

// Determine if this is a backend/admin subdomain
$is_backend = (
    strpos($current_host, 'backend') === 0 || 
    strpos($current_host, 'admin') === 0 || 
    strpos($current_host, 'dashboard') === 0
);

// Check if WordPress is installed and get URLs from database
$wp_installed = false;
$db_siteurl = null;
$db_home = null;

try {
    $check_pdo = new PDO("mysql:host=localhost;dbname=zdnorten_wp925", 'zdnorten_wp925', 'dTm3_.2A[?[h');
    $check_result = $check_pdo->query("SHOW TABLES LIKE 'wpDemo_options'");
    $wp_installed = ($check_result && $check_result->rowCount() > 0);
    
    // If installed, get URLs from database
    if ($wp_installed) {
        $url_query = $check_pdo->query("SELECT option_name, option_value FROM wpDemo_options WHERE option_name IN ('siteurl', 'home')");
        while ($row = $url_query->fetch(PDO::FETCH_ASSOC)) {
            if ($row['option_name'] === 'siteurl') {
                $db_siteurl = $row['option_value'];
            } elseif ($row['option_name'] === 'home') {
                $db_home = $row['option_value'];
            }
        }
    }
} catch (Exception $e) {
    $wp_installed = false;
}

// If accessing frontend and WP not installed, redirect to backend installation
if (!$is_backend && !$wp_installed && !defined('WP_INSTALLING')) {
    $backend_domain = 'admin' . $current_host;
    header('Location: https://' . $backend_domain . '/wp-admin/install.php');
    exit;
}

// Set WP_SITEURL and WP_HOME
if ($db_siteurl && $db_home) {
    // WordPress is installed - use URLs from database
    define('WP_SITEURL', $db_siteurl);
    define('WP_HOME', $db_home);
} else {
    // WordPress not installed yet - use dynamic URLs for installation
    if ($is_backend) {
        // Backend subdomain - WordPress admin/API access
        $frontend_domain = preg_replace('/^(backend|admin|dashboard)\./', '', $current_host);
        define('WP_SITEURL', 'https://' . $current_host);
        define('WP_HOME', 'https://' . $frontend_domain);
    } else {
        // Frontend domain - public site access
        define('WP_SITEURL', 'https://admin' . $current_host);
        define('WP_HOME', 'https://' . $current_host);
    }
}

// ** CRITICAL: Cross-Subdomain Cookie Configuration **
// SOLUTION: Don't set COOKIE_DOMAIN at all - let WordPress use current domain
// Instead, we'll handle authentication via REST API nonce validation
// The ikabud-cors.php plugin will ensure proper CORS headers are sent
// and WordPress will validate the nonce from the logged-in session

// Only set cookie paths
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('ADMIN_COOKIE_PATH', '/');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
// Use current host for wp-content URL to avoid cross-domain issues
define('WP_CONTENT_URL', 'https://' . $current_host . '/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', 'wp-wp-demo-site');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
