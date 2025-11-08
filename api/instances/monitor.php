<?php
/**
 * Instance API - Monitor Instance
 * 
 * Get detailed monitoring data for an instance
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../middleware/auth.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\VirtualProcessManager;
use IkabudKernel\CMS\CMSRegistry;

try {
    // Verify authentication
    $user = verifyAuth();
    
    // Check permission
    if (!hasPermission($user, 'instances.view')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Permission denied'
        ]);
        exit;
    }
    
    // Get instance ID
    $instanceId = $_GET['instance_id'] ?? null;
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Instance ID required'
        ]);
        exit;
    }
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Get instance info
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$instance) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Instance not found'
        ]);
        exit;
    }
    
    // Get process information using VirtualProcessManager
    $processInfo = null;
    $healthInfo = null;
    $resourceUsage = null;
    
    try {
        $processManager = new VirtualProcessManager($kernel);
        $processInfo = $processManager->getInstanceStatus($instanceId);
        $healthInfo = $processManager->monitorInstanceHealth($instanceId);
        $resourceUsage = $processManager->getResourceUsage($instanceId);
    } catch (Exception $e) {
        // Process manager not available
        error_log("VirtualProcessManager error: " . $e->getMessage());
    }
    
    // Get CMS adapter info
    $cmsInfo = null;
    try {
        CMSRegistry::initialize();
        $cms = CMSRegistry::get($instanceId);
        
        if ($cms) {
            $cmsInfo = [
                'type' => $cms->getType(),
                'version' => $cms->getVersion(),
                'initialized' => $cms->isInitialized(),
                'booted' => $cms->isBooted(),
                'resources' => $cms->getResourceUsage()
            ];
        }
    } catch (Exception $e) {
        // CMS not registered yet
    }
    
    // Get disk usage
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    $diskUsage = 0;
    if (is_dir($instancePath)) {
        $diskUsage = getDirSize($instancePath);
    }
    
    // Return monitoring data
    echo json_encode([
        'success' => true,
        'instance' => $instance,
        'process' => $processInfo,
        'health' => $healthInfo,
        'cms' => $cmsInfo,
        'disk_usage' => $resourceUsage['disk_usage'] ?? $diskUsage,
        'resources' => $resourceUsage,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Get directory size recursively
 */
function getDirSize($dir) {
    $size = 0;
    foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
        $size += is_file($each) ? filesize($each) : getDirSize($each);
    }
    return $size;
}
