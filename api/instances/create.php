<?php
/**
 * Create New Instance API
 * Handles complete instance setup with admin subdomain support
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\JWTMiddleware;

// Verify JWT token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$token = $matches[1];
$jwt = new JWTMiddleware();

try {
    $decoded = $jwt->validateToken($token);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['instance_id', 'instance_name', 'cms_type', 'domain', 'admin_subdomain', 'database_name', 'database_user', 'database_password'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '{$field}' is required"]);
        exit;
    }
}

// Sanitize instance_id
$instanceId = preg_replace('/[^a-z0-9-]/', '', strtolower($input['instance_id']));
$instanceName = $input['instance_name'];
$cmsType = $input['cms_type'];
$domain = $input['domain'];
$adminSubdomain = $input['admin_subdomain'];
$databaseName = $input['database_name'];
$databaseUser = $input['database_user'];
$databasePassword = $input['database_password'];
$databaseHost = $input['database_host'] ?? 'localhost';
$databasePrefix = $input['database_prefix'] ?? 'wp_';
$memoryLimit = $input['memory_limit'] ?? '256M';
$maxExecutionTime = $input['max_execution_time'] ?? 60;
$maxChildren = $input['max_children'] ?? 5;

try {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Check if instance already exists
    $stmt = $db->prepare("SELECT instance_id FROM instances WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Instance ID already exists']);
        exit;
    }
    
    // Check if database exists
    $stmt = $db->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([$databaseName]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Database '{$databaseName}' does not exist. Please create it first."]);
        exit;
    }
    
    // Create instance directory structure
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    if (is_dir($instancePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Instance directory already exists']);
        exit;
    }
    
    mkdir($instancePath, 0755, true);
    mkdir($instancePath . '/wp-content', 0755, true);
    mkdir($instancePath . '/wp-content/themes', 0755, true);
    mkdir($instancePath . '/wp-content/plugins', 0755, true);
    mkdir($instancePath . '/wp-content/uploads', 0755, true);
    mkdir($instancePath . '/wp-content/mu-plugins', 0755, true);
    
    // Create symlinks to shared WordPress core
    $sharedCore = __DIR__ . '/../../shared-cores/wordpress';
    $coreFiles = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php',
        'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php',
        'wp-trackback.php', 'xmlrpc.php', 'wp-admin', 'wp-includes'
    ];
    
    foreach ($coreFiles as $file) {
        $source = $sharedCore . '/' . $file;
        $target = $instancePath . '/' . $file;
        if (file_exists($source)) {
            symlink($source, $target);
        }
    }
    
    // Generate authentication keys
    $authKeys = [
        'AUTH_KEY' => bin2hex(random_bytes(32)),
        'SECURE_AUTH_KEY' => bin2hex(random_bytes(32)),
        'LOGGED_IN_KEY' => bin2hex(random_bytes(32)),
        'NONCE_KEY' => bin2hex(random_bytes(32)),
        'AUTH_SALT' => bin2hex(random_bytes(32)),
        'SECURE_AUTH_SALT' => bin2hex(random_bytes(32)),
        'LOGGED_IN_SALT' => bin2hex(random_bytes(32)),
        'NONCE_SALT' => bin2hex(random_bytes(32))
    ];
    
    // Generate wp-config.php
    $wpConfig = <<<PHP
<?php
/**
 * WordPress Configuration
 * Ikabud Kernel Instance: {$instanceId}
 * Generated: {date('Y-m-d H:i:s')}
 */

// Database Configuration
define('DB_NAME', '{$databaseName}');
define('DB_USER', '{$databaseUser}');
define('DB_PASSWORD', '{$databasePassword}');
define('DB_HOST', '{$databaseHost}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Authentication Keys and Salts
define('AUTH_KEY',         '{$authKeys['AUTH_KEY']}');
define('SECURE_AUTH_KEY',  '{$authKeys['SECURE_AUTH_KEY']}');
define('LOGGED_IN_KEY',    '{$authKeys['LOGGED_IN_KEY']}');
define('NONCE_KEY',        '{$authKeys['NONCE_KEY']}');
define('AUTH_SALT',        '{$authKeys['AUTH_SALT']}');
define('SECURE_AUTH_SALT', '{$authKeys['SECURE_AUTH_SALT']}');
define('LOGGED_IN_SALT',   '{$authKeys['LOGGED_IN_SALT']}');
define('NONCE_SALT',       '{$authKeys['NONCE_SALT']}');

// WordPress Database Table prefix
\$table_prefix = '{$databasePrefix}';

// WordPress Debugging
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Force direct filesystem method (no FTP needed)
define('FS_METHOD', 'direct');

// ** CRITICAL: WordPress URLs **
// Admin subdomain for direct access to WordPress admin
define('WP_SITEURL', 'http://{$adminSubdomain}');
// Frontend domain served through Ikabud Kernel (cached)
define('WP_HOME', 'http://{$domain}');

// Define admin cookie path
define('ADMIN_COOKIE_PATH', '/wp-admin');

// ** CRITICAL: Cookie Configuration **
// Use main domain for cookies to work across frontend and admin
define('COOKIE_DOMAIN', '{$domain}');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');

// ** CRITICAL: Instance-specific wp-content paths **
// This ensures themes, plugins, and uploads are stored in the instance folder
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://{$adminSubdomain}/wp-content');

// Ikabud Kernel Integration
define('IKABUD_INSTANCE_ID', '{$instanceId}');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// Absolute path to WordPress directory (shared core)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

// Sets up WordPress vars and included files
require_once ABSPATH . 'wp-settings.php';

PHP;
    
    file_put_contents($instancePath . '/wp-config.php', $wpConfig);
    
    // Copy conditional loader mu-plugin
    $muPluginSource = __DIR__ . '/../../templates/ikabud-conditional-loader.php';
    $muPluginTarget = $instancePath . '/wp-content/mu-plugins/ikabud-conditional-loader.php';
    if (file_exists($muPluginSource)) {
        copy($muPluginSource, $muPluginTarget);
    }
    
    // Create instance configuration
    $instanceConfig = [
        'memory_limit' => $memoryLimit,
        'max_execution_time' => $maxExecutionTime,
        'max_children' => $maxChildren,
        'admin_subdomain' => $adminSubdomain
    ];
    
    $resources = [
        'memory_limit' => (int)filter_var($memoryLimit, FILTER_SANITIZE_NUMBER_INT),
        'cpu_limit' => 1.0
    ];
    
    // Insert instance into database
    $stmt = $db->prepare("
        INSERT INTO instances 
        (instance_id, instance_name, cms_type, cms_version, domain, path_prefix, 
         database_name, database_prefix, status, config, resources, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $instanceId,
        $instanceName,
        $cmsType,
        '6.8.3', // WordPress version
        $domain,
        '/instances/' . $instanceId,
        $databaseName,
        $databasePrefix,
        json_encode($instanceConfig),
        json_encode($resources)
    ]);
    
    // Create default route
    $stmt = $db->prepare("
        INSERT INTO instance_routes (instance_id, route_pattern, route_type, priority)
        VALUES (?, '/', 'prefix', 0)
    ");
    $stmt->execute([$instanceId]);
    
    // Generate setup instructions
    $setupInstructions = <<<INSTRUCTIONS

Instance created successfully!

Setup Instructions:
==================

1. Add Admin Subdomain in cPanel/Hosting:
   - Subdomain: {$adminSubdomain}
   - Document Root: /path/to/ikabud-kernel/instances/{$instanceId}

2. Update DNS or /etc/hosts:
   - {$domain} → Your server IP
   - {$adminSubdomain} → Your server IP

3. Install WordPress:
   - Visit: http://{$adminSubdomain}/wp-admin/install.php
   - Complete WordPress installation

4. Access Your Site:
   - Frontend: http://{$domain} (cached through kernel)
   - Admin: http://{$adminSubdomain}/wp-admin

INSTRUCTIONS;
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'instance_id' => $instanceId,
        'message' => 'Instance created successfully',
        'setup_instructions' => $setupInstructions,
        'config' => [
            'frontend_url' => "http://{$domain}",
            'admin_url' => "http://{$adminSubdomain}/wp-admin",
            'install_url' => "http://{$adminSubdomain}/wp-admin/install.php"
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create instance: ' . $e->getMessage()
    ]);
}
