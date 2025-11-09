<?php
/**
 * Instance Actions API Routes (Backward Compatibility)
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\JWTMiddleware;

// Monitor instance
$monitorHandler = function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Kernel error: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $instanceId = $request->getQueryParams()['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance
        $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch();
        
        if (!$instance) {
            $response->getBody()->write(json_encode(['error' => 'Instance not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Get process info (if table exists)
        $process = null;
        try {
            $stmt = $db->prepare("
                SELECT * FROM kernel_processes 
                WHERE instance_id = ? 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $process = $stmt->fetch();
        } catch (Exception $e) {
            // Table doesn't exist yet, that's ok
            $process = null;
        }
        
        // Decode JSON fields
        $instance['config'] = json_decode($instance['config'] ?? '{}', true);
        $instance['resources'] = json_decode($instance['resources'] ?? '{}', true);
        
        $response->getBody()->write(json_encode([
            'instance' => $instance,
            'process' => $process,
            'resources' => [],
            'status' => $instance['status'],
            'healthy' => $process && $process['status'] === 'running'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
};

$app->get('/api/instances/monitor.php', $monitorHandler)->add(new JWTMiddleware());

// Start instance
$app->post('/api/instances/start.php', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance path
        $instancePath = dirname(__DIR__, 2) . '/instances/' . $instanceId;
        
        // Remove .maintenance file to disable maintenance mode
        $maintenanceFile = $instancePath . '/.maintenance';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        // Update process status
        $stmt = $db->prepare("UPDATE kernel_processes SET status = 'running' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance started - maintenance mode disabled'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to start instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Stop instance
$app->post('/api/instances/stop.php', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance path
        $instancePath = dirname(__DIR__, 2) . '/instances/' . $instanceId;
        
        if (!is_dir($instancePath)) {
            $response->getBody()->write(json_encode(['error' => 'Instance directory not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Create .maintenance file to enable maintenance mode
        $maintenanceFile = $instancePath . '/.maintenance';
        file_put_contents($maintenanceFile, json_encode([
            'time' => time(),
            'stopped_at' => date('Y-m-d H:i:s'),
            'message' => 'Site is currently under maintenance'
        ]));
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'inactive' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        // Update process status
        $stmt = $db->prepare("UPDATE kernel_processes SET status = 'stopped' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance stopped - maintenance mode enabled'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to stop instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Restart instance
$app->post('/api/instances/restart.php', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance restarted successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to restart instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Create instance (backward compatibility)
$app->post('/api/instances/create.php', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $body = json_decode($request->getBody()->getContents(), true);
    
    // Validate required fields
    $required = ['instance_name', 'cms_type', 'database_name'];
    foreach ($required as $field) {
        if (!isset($body[$field])) {
            $response->getBody()->write(json_encode(['error' => "Field '{$field}' is required"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    // Generate instance ID
    $instanceId = 'inst_' . bin2hex(random_bytes(8));
    
    // Insert instance
    $stmt = $db->prepare("
        INSERT INTO instances 
        (instance_id, instance_name, cms_type, cms_version, domain, path_prefix, 
         database_name, database_prefix, status, config, resources)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
    ");
    
    $config = json_encode($body['config'] ?? []);
    $resources = json_encode($body['resources'] ?? ['memory_limit' => 256, 'cpu_limit' => 1.0]);
    
    $stmt->execute([
        $instanceId,
        $body['instance_name'],
        $body['cms_type'],
        $body['cms_version'] ?? null,
        $body['domain'] ?? null,
        $body['path_prefix'] ?? '/',
        $body['database_name'],
        $body['database_prefix'] ?? '',
        $config,
        $resources
    ]);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'instance_id' => $instanceId,
        'message' => 'Instance created successfully'
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());
