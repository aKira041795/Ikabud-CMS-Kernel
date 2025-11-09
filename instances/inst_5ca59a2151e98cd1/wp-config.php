<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: inst_5ca59a2151e98cd1
 * Generated: Sun Nov  9 20:26:48 PST 2025
 */

// Database Configuration
define('DB_NAME', 'ikabud_akira_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Nds90@NXIOVRH*iy');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
define('AUTH_KEY',         'n]|Wbn[bGpcQ4>EqoZNo(xPV?|2%IPQ-ovJR|D dlk=h`=p[ziNU^ACXzgd31NYh');
define('SECURE_AUTH_KEY',  ']`l^Qq0j+LfOvTG<F.zc5K*Lu:iyAqzhG24Cm1H>a5>al2M.R+fs=-*`*CS{9&t:');
define('LOGGED_IN_KEY',    'vcKw. MWM<NG11xx(HiTv#s/|oBpV9aII|k??SgjFS};NI@=J30#$Th6_ SV3Eu*');
define('NONCE_KEY',        'G6+x5ER&0/H`XfPZ&::.}LNB05B(+,nn*+WRc!)K=UR;a)X?zCkGZGE<b]ar.nO}');
define('AUTH_SALT',        'apHc0[oT*(!Q`K-=IS-+hm!3h|W+RejhdQz01R5-d/E6nnO^+suP[3X;WDj!AqmZ');
define('SECURE_AUTH_SALT', 'o8P#%r~d{hx/T7[`YSN<D|*Pk)@2f8!Ye y5-r+(X`<P=LL~M2 %`3HQqd sb&r=');
define('LOGGED_IN_SALT',   'wEe6%|P,+b>2I#(EoTM[a9 LxO|<7d7@g@(_:yrxV-42A.&zKEk]:<1f&VN(}?Qr');
define('NONCE_SALT',       'w|JE<yPt(hYjx9KjgG~zw![-|CVi 5$TJHV;WcuEm<{>{+:2zUutYKYn}g]3k020');

// WordPress Database Table prefix
$table_prefix = 'aki_';

// WordPress Debugging
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Dynamic URLs based on current host to prevent redirects
$current_host = $_SERVER['HTTP_HOST'] ?? 'akira.test';

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
    $check_pdo = new PDO("mysql:host=localhost;dbname=ikabud_akira_test", 'root', 'Nds90@NXIOVRH*iy');
    $check_result = $check_pdo->query("SHOW TABLES LIKE 'aki_options'");
    $wp_installed = ($check_result && $check_result->rowCount() > 0);
    
    // If installed, get URLs from database
    if ($wp_installed) {
        $url_query = $check_pdo->query("SELECT option_name, option_value FROM aki_options WHERE option_name IN ('siteurl', 'home')");
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
define('IKABUD_INSTANCE_ID', 'inst_5ca59a2151e98cd1');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
