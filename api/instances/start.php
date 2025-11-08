<?php
/**
 * Instance API - Start Instance
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
use IkabudKernel\Core\VirtualProcessManager;

try {
    // Verify authentication
    $user = verifyAuth();
    
    // Check permission
    if (!hasPermission($user, 'instances.manage')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Permission denied'
        ]);
        exit;
    }
    
    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['instance_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'instance_id required'
        ]);
        exit;
    }
    
    $instanceId = $input['instance_id'];
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    
    // Create virtual process manager
    $processManager = new VirtualProcessManager($kernel);
    
    // Start instance
    $result = $processManager->startInstance($instanceId);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
