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
// Define these to prevent redirect loops when loaded through Kernel
define('WP_SITEURL', 'http://wp-test.ikabud-kernel.test');
define('WP_HOME', 'http://wp-test.ikabud-kernel.test');

// Define admin cookie path
define('ADMIN_COOKIE_PATH', '/wp-admin');

// ** CRITICAL: Cookie Configuration **
// Ensure cookies work correctly when served through Kernel
define('COOKIE_DOMAIN', 'wp-test.ikabud-kernel.test');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');

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
