<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: wp-brutus-cli
 * Generated: Mon Nov 10 05:31:05 UTC 2025
 */

// Database Configuration
define('DB_NAME', 'ikabud_brutus');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Nds90@NXIOVRH*iy');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
define('AUTH_KEY',         ';PQx4q0w1nF;ue[.u`5JQ5O4|JqgrHyoPm.v{lh$)Gav]|AT6m_fYdcgahv]wwyQ');
define('SECURE_AUTH_KEY',  'LJ}<{3r9r(|eHK.`?E|T.xEc}E]B?hX]O-Nf=z||MTIRoQ]_7LzCQcJ<0a0F-&m0');
define('LOGGED_IN_KEY',    'D_%YRwI-pJO)^l{TS|dKf<cn,M=R;ol8xk&el>{h1U4aN3Hbgskp|]=UI1K;O>Bq');
define('NONCE_KEY',        '}$>ffv]0TUk;u}YW2_P,!XT@K2sJUTO{&#GnSNl[#Mpob%K/DZMe-Kg&2CjuBHHo');
define('AUTH_SALT',        'gXa5K/il;:,*T.(Qnw>inoRbsVR|g!45cZrro7@@9idb+Cq15x;w5:1w>(ic%sa$');
define('SECURE_AUTH_SALT', 'qqyN/,E!@>5x9UYc4`@hx.cXh&B.1?;!EM]V7Jv^wN/?IR%M24RU7@}WdBy%5Zs)');
define('LOGGED_IN_SALT',   '9BU#BfD=!Ygq#a#`>fcxF?g4Jou1fbZ%iTXN2qnE{u;A1xktSAHP,J{:Qz?F~>kI');
define('NONCE_SALT',       '8&6X{75-5E{C~]ru/NspTV.8_?+8zZE_gcp3AMA$m/EoT7x+%93@0S6ug|lKgY6a');

// WordPress Database Table prefix
$table_prefix = 'bru_';

// WordPress Debugging
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Dynamic URLs based on current host to prevent redirects
$current_host = $_SERVER['HTTP_HOST'] ?? 'brutus.test';

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
    $check_pdo = new PDO("mysql:host=localhost;dbname=ikabud_brutus", 'root', 'Nds90@NXIOVRH*iy');
    $check_result = $check_pdo->query("SHOW TABLES LIKE 'bru_options'");
    $wp_installed = ($check_result && $check_result->rowCount() > 0);
    
    // If installed, get URLs from database
    if ($wp_installed) {
        $url_query = $check_pdo->query("SELECT option_name, option_value FROM bru_options WHERE option_name IN ('siteurl', 'home')");
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
    $backend_domain = 'admin.' . $current_host;
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
        define('WP_SITEURL', 'http://admin.' . $current_host);
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
define('IKABUD_INSTANCE_ID', 'wp-brutus-cli');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
