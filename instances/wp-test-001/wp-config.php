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
define('AUTH_KEY',         'put your unique phrase here');
define('SECURE_AUTH_KEY',  'put your unique phrase here');
define('LOGGED_IN_KEY',    'put your unique phrase here');
define('NONCE_KEY',        'put your unique phrase here');
define('AUTH_SALT',        'put your unique phrase here');
define('SECURE_AUTH_SALT', 'put your unique phrase here');
define('LOGGED_IN_SALT',   'put your unique phrase here');
define('NONCE_SALT',       'put your unique phrase here');

// WordPress Database Table prefix
$table_prefix = 'wp_';

// WordPress Debugging
define('WP_DEBUG', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://wp-test.ikabud-kernel.test/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', 'wp-test-001');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';
