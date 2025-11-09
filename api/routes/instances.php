<?php
/**
 * Instance Management API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\JWTMiddleware;

// List all instances
$listInstancesHandler = function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $status = $request->getQueryParams()['status'] ?? null;
    
    if ($status) {
        $stmt = $db->prepare("SELECT * FROM instances WHERE status = ? ORDER BY created_at DESC");
        $stmt->execute([$status]);
    } else {
        $stmt = $db->query("SELECT * FROM instances ORDER BY created_at DESC");
    }
    
    $instances = $stmt->fetchAll();
    
    // Decode JSON fields
    foreach ($instances as &$instance) {
        $instance['config'] = json_decode($instance['config'] ?? '{}', true);
        $instance['resources'] = json_decode($instance['resources'] ?? '{}', true);
    }
    
    $response->getBody()->write(json_encode([
        'total' => count($instances),
        'instances' => $instances
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
};

// List all instances
$app->get('/api/v1/instances', $listInstancesHandler)->add(new JWTMiddleware());

// Get single instance
$app->get('/api/v1/instances/{id}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $instanceId = $args['id'];
    
    $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch();
    
    if (!$instance) {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Decode JSON fields
    $instance['config'] = json_decode($instance['config'] ?? '{}', true);
    $instance['resources'] = json_decode($instance['resources'] ?? '{}', true);
    
    // Get routes
    $stmt = $db->prepare("SELECT * FROM instance_routes WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    $instance['routes'] = $stmt->fetchAll();
    
    // Get process info
    $stmt = $db->prepare("
        SELECT * FROM kernel_processes 
        WHERE instance_id = ? 
        ORDER BY started_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $instance['process'] = $stmt->fetch();
    
    $response->getBody()->write(json_encode($instance));
    return $response->withHeader('Content-Type', 'application/json');
});

// Create new instance
$app->post('/api/v1/instances', function (Request $request, Response $response) {
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
    
    // Create default routes
    $routes = $body['routes'] ?? [$body['path_prefix'] ?? '/'];
    foreach ($routes as $route) {
        $stmt = $db->prepare("
            INSERT INTO instance_routes (instance_id, route_pattern, route_type, priority)
            VALUES (?, ?, 'prefix', 0)
        ");
        $stmt->execute([$instanceId, $route]);
    }
    
    // Create instance directory
    $instancePath = __DIR__ . '/../../instances/' . $instanceId;
    if (!is_dir($instancePath)) {
        mkdir($instancePath, 0755, true);
        mkdir($instancePath . '/themes', 0755, true);
        mkdir($instancePath . '/plugins', 0755, true);
        mkdir($instancePath . '/uploads', 0755, true);
        mkdir($instancePath . '/cache', 0755, true);
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'instance_id' => $instanceId,
        'message' => 'Instance created successfully'
    ]));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
});

// Update instance
$app->put('/api/v1/instances/{id}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $instanceId = $args['id'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    $updates = [];
    $params = [];
    
    $allowedFields = ['instance_name', 'cms_version', 'domain', 'path_prefix', 'status', 'config', 'resources'];
    
    foreach ($allowedFields as $field) {
        if (isset($body[$field])) {
            $value = $body[$field];
            if (in_array($field, ['config', 'resources']) && is_array($value)) {
                $value = json_encode($value);
            }
            $updates[] = "`{$field}` = ?";
            $params[] = $value;
        }
    }
    
    if (empty($updates)) {
        $response->getBody()->write(json_encode(['error' => 'No valid fields to update']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $params[] = $instanceId;
    
    $sql = "UPDATE instances SET " . implode(', ', $updates) . " WHERE instance_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() > 0) {
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Instance updated']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});

// Delete instance
$app->delete('/api/v1/instances/{id}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $instanceId = $args['id'];
    
    // Delete instance (cascades to routes)
    $stmt = $db->prepare("DELETE FROM instances WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    
    if ($stmt->rowCount() > 0) {
        // TODO: Clean up instance directory
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Instance deleted']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Instance not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});

// Boot instance
$app->post('/api/v1/instances/{id}/boot', function (Request $request, Response $response, array $args) {
    $instanceId = $args['id'];
    
    // Register process
    $pid = Kernel::registerProcess("instance_{$instanceId}", 'cms', [
        'instance_id' => $instanceId,
        'cms_type' => 'wordpress', // TODO: Get from instance
        'memory_limit' => 256
    ]);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'pid' => $pid,
        'message' => 'Instance booted'
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get instance logs
$app->get('/api/v1/instances/{id}/logs', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $instanceId = $args['id'];
    $limit = $request->getQueryParams()['limit'] ?? 100;
    
    $stmt = $db->prepare("
        SELECT s.* FROM kernel_syscalls s
        JOIN kernel_processes p ON s.pid = p.pid
        WHERE p.instance_id = ?
        ORDER BY s.created_at DESC
        LIMIT ?
    ");
    
    $stmt->execute([$instanceId, $limit]);
    $logs = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($logs),
        'logs' => $logs
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get instance resources
$app->get('/api/v1/instances/{id}/resources', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $instanceId = $args['id'];
    
    $stmt = $db->prepare("
        SELECT r.* FROM kernel_resources r
        JOIN kernel_processes p ON r.pid = p.pid
        WHERE p.instance_id = ?
        ORDER BY r.measured_at DESC
        LIMIT 100
    ");
    
    $stmt->execute([$instanceId]);
    $resources = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($resources),
        'resources' => $resources
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});
