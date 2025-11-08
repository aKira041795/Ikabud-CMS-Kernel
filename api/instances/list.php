<?php
/**
 * Instance API - List Instances
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
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Get all instances
    $stmt = $db->query("
        SELECT *
        FROM instances
        ORDER BY created_at DESC
    ");
    
    $instances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get process information for each instance using VirtualProcessManager
    try {
        $processManager = new VirtualProcessManager($kernel);
        
        foreach ($instances as &$instance) {
            try {
                $status = $processManager->getInstanceStatus($instance['instance_id']);
                $health = $processManager->monitorInstanceHealth($instance['instance_id']);
                
                $instance['process'] = [
                    'pid' => $status['pid'],
                    'status' => $status['status'],
                    'mode' => $status['mode'] ?? 'virtual',
                    'socket' => $status['socket'],
                    'healthy' => $health['healthy'] ?? true
                ];
            } catch (Exception $e) {
                $instance['process'] = [
                    'pid' => null,
                    'status' => 'unknown',
                    'mode' => 'virtual',
                    'socket' => null,
                    'healthy' => false
                ];
            }
        }
    } catch (Exception $e) {
        // VirtualProcessManager error
        error_log("VirtualProcessManager error in list: " . $e->getMessage());
        foreach ($instances as &$instance) {
            $instance['process'] = [
                'pid' => null,
                'status' => 'error',
                'mode' => 'virtual',
                'socket' => null,
                'healthy' => false
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'instances' => $instances,
        'total' => count($instances)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
