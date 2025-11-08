<?php
/**
 * Instance API - Create Instance
 * 
 * Creates a new CMS instance with process isolation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../middleware/auth.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\ProcessManager;

try {
    // Verify authentication
    $user = verifyAuth();
    
    // Check permission
    if (!hasPermission($user, 'instances.create')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Permission denied'
        ]);
        exit;
    }
    
    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['instance_id', 'instance_name', 'cms_type', 'domain', 'database_name'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Field '{$field}' is required"
            ]);
            exit;
        }
    }
    
    // Validate instance_id format
    if (!preg_match('/^[a-z0-9-]+$/', $input['instance_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Instance ID must contain only lowercase letters, numbers, and hyphens'
        ]);
        exit;
    }
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Check if instance already exists
    $stmt = $db->prepare("SELECT instance_id FROM instances WHERE instance_id = ?");
    $stmt->execute([$input['instance_id']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Instance ID already exists'
        ]);
        exit;
    }
    
    // Prepare instance configuration
    $config = [
        'instance_id' => $input['instance_id'],
        'instance_name' => $input['instance_name'],
        'cms_type' => $input['cms_type'],
        'domain' => $input['domain'],
        'path_prefix' => $input['path_prefix'] ?? '',
        'database_name' => $input['database_name'],
        'database_prefix' => $input['database_prefix'] ?? 'wp_',
        'config' => json_encode([
            'memory_limit' => $input['memory_limit'] ?? '256M',
            'max_execution_time' => $input['max_execution_time'] ?? 60,
            'max_children' => $input['max_children'] ?? 5,
            'start_servers' => $input['start_servers'] ?? 2
        ]),
        'status' => 'creating',
        'created_by' => $user['id']
    ];
    
    // Insert instance into database
    $stmt = $db->prepare("
        INSERT INTO instances 
        (instance_id, instance_name, cms_type, domain, path_prefix, database_name, database_prefix, config, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $config['instance_id'],
        $config['instance_name'],
        $config['cms_type'],
        $config['domain'],
        $config['path_prefix'],
        $config['database_name'],
        $config['database_prefix'],
        $config['config'],
        $config['status'],
        $config['created_by']
    ]);
    
    // Create instance directory structure
    $instancePath = __DIR__ . '/../../instances/' . $config['instance_id'];
    
    if (!is_dir($instancePath)) {
        mkdir($instancePath, 0755, true);
        mkdir($instancePath . '/wp-content', 0755, true);
        mkdir($instancePath . '/wp-content/themes', 0755, true);
        mkdir($instancePath . '/wp-content/plugins', 0755, true);
        mkdir($instancePath . '/wp-content/uploads', 0755, true);
    }
    
    // Create wp-config.php
    $wpConfigContent = generateWpConfig($config);
    file_put_contents($instancePath . '/wp-config.php', $wpConfigContent);
    
    // Create symlinks to shared core
    $sharedCore = __DIR__ . '/../../shared-cores/wordpress';
    if (is_dir($sharedCore)) {
        // Symlink wp-admin
        if (!file_exists($instancePath . '/wp-admin')) {
            symlink($sharedCore . '/wp-admin', $instancePath . '/wp-admin');
        }
        
        // Symlink wp-includes
        if (!file_exists($instancePath . '/wp-includes')) {
            symlink($sharedCore . '/wp-includes', $instancePath . '/wp-includes');
        }
        
        // Symlink PHP files
        $phpFiles = glob($sharedCore . '/*.php');
        foreach ($phpFiles as $file) {
            $filename = basename($file);
            if ($filename !== 'wp-config.php' && !file_exists($instancePath . '/' . $filename)) {
                symlink($file, $instancePath . '/' . $filename);
            }
        }
    }
    
    // Create process (if root access available)
    $processInfo = null;
    try {
        $processManager = new ProcessManager($kernel);
        $processInfo = $processManager->createInstanceProcess($config['instance_id'], json_decode($config['config'], true));
        
        // Update status
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$config['instance_id']]);
        
    } catch (Exception $e) {
        // Process creation failed (probably no root access)
        // Instance still works with symlink architecture
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$config['instance_id']]);
    }
    
    // Return success
    echo json_encode([
        'success' => true,
        'message' => 'Instance created successfully',
        'instance' => [
            'instance_id' => $config['instance_id'],
            'instance_name' => $config['instance_name'],
            'cms_type' => $config['cms_type'],
            'domain' => $config['domain'],
            'database_name' => $config['database_name'],
            'status' => 'active',
            'path' => $instancePath,
            'process' => $processInfo
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Generate wp-config.php content
 */
function generateWpConfig($config) {
    $dbName = $config['database_name'];
    $dbPrefix = $config['database_prefix'];
    $instanceId = $config['instance_id'];
    
    return <<<PHP
<?php
/**
 * WordPress Configuration - {$config['instance_name']}
 * Generated by Ikabud Kernel
 */

// ** Database settings **
define('DB_NAME', '{$dbName}');
define('DB_USER', getenv('DB_USERNAME') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

\$table_prefix = '{$dbPrefix}';

// ** Instance-specific wp-content paths **
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', 'http://{$config['domain']}/wp-content');

// ** Ikabud Kernel Integration **
define('IKABUD_INSTANCE_ID', '{$instanceId}');
define('IKABUD_KERNEL_PATH', dirname(dirname(__DIR__)) . '/kernel');

// ** Security keys **
define('AUTH_KEY',         '" . bin2hex(random_bytes(32)) . "');
define('SECURE_AUTH_KEY',  '" . bin2hex(random_bytes(32)) . "');
define('LOGGED_IN_KEY',    '" . bin2hex(random_bytes(32)) . "');
define('NONCE_KEY',        '" . bin2hex(random_bytes(32)) . "');
define('AUTH_SALT',        '" . bin2hex(random_bytes(32)) . "');
define('SECURE_AUTH_SALT', '" . bin2hex(random_bytes(32)) . "');
define('LOGGED_IN_SALT',   '" . bin2hex(random_bytes(32)) . "');
define('NONCE_SALT',       '" . bin2hex(random_bytes(32)) . "');

// ** WordPress debugging **
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

// ** Force direct filesystem method **
define('FS_METHOD', 'direct');

// ** Absolute path to WordPress directory **
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(__DIR__)) . '/shared-cores/wordpress/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
PHP;
}
