<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: test-cors-001
 * Generated: Sunday, 09 November, 2025 06:37:38 PM PST
 */

// Database Configuration
define('DB_NAME', 'ikabud_test_cors');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Nds90@NXIOVRH*iy');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
define('AUTH_KEY',         'lS-KM+z1+`!?J+Bz+mmT@HA_VTkLgLnM]r.w<~rtSM{GjHQ?<QO%fror/`-b6Fx^');
define('SECURE_AUTH_KEY',  '9AGBXvzSF%l_%^+~0+yue#jOJ%04t1g!A>6GZ6I$#/R@+p]<KT`>`Bwz=<RCfZ*&');
define('LOGGED_IN_KEY',    'Rx=AZ+bj6RGR8IuQ6?TyUVW J&lXPd,R2^|;I}CaokZUe,y{#bIO-?Xz?,rj_Hu/');
define('NONCE_KEY',        'h+:.!N!s&@_*?@5&EXP7TfI,)oHI7uIVc9a$,HFI[|miVpeh/NQG?VfEHlsJ,UjD');
define('AUTH_SALT',        'yqHK^4dd#U3^)]#?Dh-2E&Z[8D_7F]~I(4S|-<:pk[m-}S|yzI%.@GxV|k=Q>ar1');
define('SECURE_AUTH_SALT', 'cF*+?L5Cy]g~Q?x9*3L1K4>DnnJ9f{A)e-HSaZ2M1prWKk1@YHo|~+|~|G:$nigo');
define('LOGGED_IN_SALT',   'M[qVigY;`31,/h,gjnRuMjbK4^Pe_fWDJthMNulMBuy|6/I^&9b<+Dh`maW]$=P!');
define('NONCE_SALT',       '.T6|6WrIXr$R9w|6hFTKQA_(RJ-d|IG)gad7}L8;YLsu4L54kEY%$>G^iq3#*,Ol');

// WordPress Database Table prefix
$table_prefix = 'wp_';

// WordPress Debugging
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Dynamic URLs based on current host to prevent redirects
$current_host = $_SERVER['HTTP_HOST'] ?? 'testcors.test';

// Determine if this is a backend/admin subdomain
$is_backend = (
    strpos($current_host, 'backend.') === 0 || 
    strpos($current_host, 'admin.') === 0 || 
    strpos($current_host, 'dashboard.') === 0
);

// Check if WordPress is installed by checking if options table exists
$wp_installed = false;
try {
    $check_pdo = new PDO("mysql:host=localhost;dbname=ikabud_test_cors", 'root', 'Nds90@NXIOVRH*iy');
    $check_result = $check_pdo->query("SHOW TABLES LIKE 'wp_options'");
    $wp_installed = ($check_result && $check_result->rowCount() > 0);
} catch (Exception $e) {
    $wp_installed = false;
}

// If accessing frontend and WP not installed, redirect to backend installation
if (!$is_backend && !$wp_installed && !defined('WP_INSTALLING')) {
    $backend_domain = 'backend.' . $current_host;
    header('Location: http://' . $backend_domain . '/wp-admin/install.php');
    exit;
}

if ($is_backend) {
    // Backend subdomain - WordPress admin/API access
    // WP_SITEURL: Where WordPress is installed (backend subdomain)
    // WP_HOME: Where the site is displayed (frontend domain)
    $frontend_domain = preg_replace('/^(backend|admin|dashboard)\./', '', $current_host);
    define('WP_SITEURL', 'http://' . $current_host);
    define('WP_HOME', 'http://' . $frontend_domain);
} else {
    // Frontend domain - public site access
    // WP_SITEURL: Where WordPress is installed (backend subdomain)
    // WP_HOME: Where the site is displayed (frontend domain - current)
    define('WP_SITEURL', 'http://backend.' . $current_host);
    define('WP_HOME', 'http://' . $current_host);
}

// Define admin cookie path
define('ADMIN_COOKIE_PATH', '/wp-admin');

// ** CRITICAL: Cookie Configuration **
// Ensure cookies work correctly when served through Kernel
// Use base domain for cookie sharing across subdomains
$base_domain = preg_replace('/^(backend|admin|dashboard)\./', '', $current_host);
define('COOKIE_DOMAIN', '.' . $base_domain);
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
// Use current host for wp-content URL to avoid cross-domain issues
define('WP_CONTENT_URL', 'http://' . $current_host . '/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', 'test-cors-001');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
