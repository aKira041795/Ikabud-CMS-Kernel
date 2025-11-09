<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: wp-test-001
 */

// Database Configuration
define('DB_NAME', 'ikabud_wp_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Nds90@NXIOVRH*iy');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
// Generate new keys at: https://api.wordpress.org/secret-key/1.1/salt/
define('AUTH_KEY',         'mZ{iv{mY U`Z5L#K%C!hGybk,J`1!:(lzqM%&zcIbS+8>4);I?[vP}PTB=G8C99W');
define('SECURE_AUTH_KEY',  'yUgzLIx.<JiY]vVMFuN.dAtMedcU|YUe.>:d%xUodOKTNj*Sm8}6/ESb?A@9q,+N');
define('LOGGED_IN_KEY',    'q9YMu2TL)gE`vk41G9e(,22^1]2.i.*RfmV0E8!X+|xp;A+-:P%@~;02^e(OIOT`');
define('NONCE_KEY',        '$/1P*%MKC(./?`MNF~!yNdc_BzEhs[-hG}.lqFBlln+d-VkD([yOf>L=a_B%C=R0');
define('AUTH_SALT',        '^^`+}45}/Rx/fA&ejnb&J*+$&-6(#h%vlLJt8H4Dx{k/i-G&y@iAq;9,FiZ_vr[L');
define('SECURE_AUTH_SALT', 'id4:[f;v_7H[v3-yal7M/.SHL$0RuS^G=mR~=DtUGS-lVTE*wgH|uPaT@.E0@I<@');
define('LOGGED_IN_SALT',   'hPrIU.#+9``Fc@w]M1&N3=9uAsrl<Th@c;+%=)~VxR[zi+o%O1^J|Z$? ,$D,|P+');
define('NONCE_SALT',       'LglxO|Z<@{of|u8` jQ(o@cx]8S3K;s-a_ B D}5~@zyws7*;5o)i@09Th<f|W_~');
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
$current_host = $_SERVER['HTTP_HOST'] ?? 'thejake.test';

// Determine if this is a backend/admin subdomain
$is_backend = (
    strpos($current_host, 'backend.') === 0 || 
    strpos($current_host, 'admin.') === 0 || 
    strpos($current_host, 'dashboard.') === 0
);

// Check if WordPress is installed and get URLs from database
$wp_installed = false;
$db_siteurl = null;
$db_home = null;

try {
    $check_pdo = new PDO("mysql:host=localhost;dbname=ikabud_wp_test", 'root', 'Nds90@NXIOVRH*iy');
    $check_result = $check_pdo->query("SHOW TABLES LIKE 'wp_options'");
    $wp_installed = ($check_result && $check_result->rowCount() > 0);
    
    // If installed, get URLs from database
    if ($wp_installed) {
        $url_query = $check_pdo->query("SELECT option_name, option_value FROM wp_options WHERE option_name IN ('siteurl', 'home')");
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
    $backend_domain = 'backend.' . $current_host;
    header('Location: http://' . $backend_domain . '/wp-admin/install.php');
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
        define('WP_SITEURL', 'http://' . $current_host);
        define('WP_HOME', 'http://' . $frontend_domain);
    } else {
        // Frontend domain - public site access
        define('WP_SITEURL', 'http://backend.' . $current_host);
        define('WP_HOME', 'http://' . $current_host);
    }
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
define('IKABUD_INSTANCE_ID', 'wp-test-001');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
