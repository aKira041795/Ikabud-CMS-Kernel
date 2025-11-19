// ** CRITICAL: Cross-Subdomain Cookie Configuration **
// Add this to wp-config.php BEFORE the "That's all, stop editing!" line

// Get current host
$current_host = $_SERVER['HTTP_HOST'] ?? '';

// Define the shared cookie domain for both admin and frontend subdomains
// For adminwpdemo.zdnorte.net and wpdemo.zdnorte.net to share cookies
define('COOKIE_DOMAIN', '.zdnorte.net');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('ADMIN_COOKIE_PATH', '/');

// Optional: Set site URLs dynamically based on subdomain
// Uncomment if you want WordPress to work on both subdomains
// if (strpos($current_host, 'admin') !== false) {
//     define('WP_SITEURL', 'https://' . $current_host);
//     define('WP_HOME', 'https://' . str_replace('admin', '', $current_host));
// }
