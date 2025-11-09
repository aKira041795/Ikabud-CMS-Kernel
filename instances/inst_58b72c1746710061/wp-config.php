<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: inst_58b72c1746710061
 * Generated: Sun Nov  9 15:02:24 PST 2025
 */

// DEBUG: Track when this config is loaded
$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
file_put_contents('/tmp/ikabud-magic-wp-config-loaded.txt', date('Y-m-d H:i:s') . ' - magic wp-config.php loaded from: ' . __FILE__ . PHP_EOL . 'Backtrace:' . PHP_EOL . print_r($backtrace, true) . PHP_EOL, FILE_APPEND);

// Database Configuration
define('DB_NAME', 'ikabud_magic_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'Nds90@NXIOVRH*iy');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// DEBUG: Verify database connection
error_log("MAGIC_WP_CONFIG: DB_NAME=" . DB_NAME . ", connecting to database...");

// Authentication Keys and Salts
define('AUTH_KEY',         '*T,m6JU L?H+.Ql:#$|acuY3i7Vk@W4?>bOi+4P(+0CUlr@qO&C^g^M.X9:-+|kZ');
define('SECURE_AUTH_KEY',  '+4+CWfgf|Gh~CQr@pWo`4gN I=-5F^uy+_LvWBnjO.ULt6+ 8AextP,}Sx<j|]FV');
define('LOGGED_IN_KEY',    '[]ZNpoBDbtr&6T{^(e=u^4L$Z.?0dutgl+=z53M1=(D77U=rx]08,BF0~njF.E04');
define('NONCE_KEY',        's?279N;:+JQRv+g&m+R5VaJ~KB/VV,0wg==-`doennlD5]P|OIc).Ptn?076f$%g');
define('AUTH_SALT',        ' mi2!KicRbVP-tXtZh%kDn[Z#4d%brR#-_jS)i(R #<n$+,~&RV_PQ<S_j)NT6S0');
define('SECURE_AUTH_SALT', 'HYEy-^`7]>xu+!/Yrrx:Mk^3D<snp-{0P+>qasc5&bb-2K-e|_JI:2}zjRnpJ;o>');
define('LOGGED_IN_SALT',   '<|Q[ck!PjBByj4711BF4NoxjJq;J6kCdo5o)j=5WNgK:KI2~/0!|-_DaX m1yUay');
define('NONCE_SALT',       'W-|;yw)FULT4V,o2lM]wnq!L|o>%T|CvS0$#f7~RbutA}v!mF1|-[:#4RRp /ol%');

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
$current_host = $_SERVER['HTTP_HOST'] ?? 'magic.test';

// Determine if this is a backend/admin subdomain
$is_backend = (
    strpos($current_host, 'backend.') === 0 || 
    strpos($current_host, 'admin.') === 0 || 
    strpos($current_host, 'dashboard.') === 0
);

// Check if WordPress is installed by checking if options table exists
$wp_installed = false;
try {
    $check_pdo = new PDO("mysql:host=localhost;dbname=ikabud_magic_test", 'root', 'Nds90@NXIOVRH*iy');
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
define('IKABUD_INSTANCE_ID', 'inst_58b72c1746710061');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
