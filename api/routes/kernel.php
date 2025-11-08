<?php
/**
 * Kernel Management API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;

// Get kernel status
$app->get('/api/v1/kernel/status', function (Request $request, Response $response) {
    $stats = Kernel::getStats();
    $response->getBody()->write(json_encode($stats));
    return $response->withHeader('Content-Type', 'application/json');
});

// Get process table
$app->get('/api/v1/kernel/processes', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $stmt = $db->query("
        SELECT pid, instance_id, process_name, process_type, cms_type, status, 
               memory_usage, cpu_time, boot_time, started_at
        FROM kernel_processes
        ORDER BY started_at DESC
    ");
    
    $processes = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($processes),
        'processes' => $processes
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get syscall logs
$app->get('/api/v1/kernel/syscalls', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $limit = $request->getQueryParams()['limit'] ?? 100;
    
    $stmt = $db->prepare("
        SELECT id, pid, syscall_name, execution_time, memory_delta, status, created_at
        FROM kernel_syscalls
        ORDER BY created_at DESC
        LIMIT ?
    ");
    
    $stmt->execute([$limit]);
    $syscalls = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($syscalls),
        'syscalls' => $syscalls
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get boot log
$app->get('/api/v1/kernel/boot-log', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $bootId = $request->getQueryParams()['boot_id'] ?? null;
    
    if ($bootId) {
        $stmt = $db->prepare("
            SELECT * FROM kernel_boot_log
            WHERE boot_id = ?
            ORDER BY phase ASC
        ");
        $stmt->execute([$bootId]);
    } else {
        $stmt = $db->query("
            SELECT * FROM kernel_boot_log
            ORDER BY created_at DESC
            LIMIT 50
        ");
    }
    
    $logs = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($logs),
        'logs' => $logs
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get resource usage
$app->get('/api/v1/kernel/resources', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $pid = $request->getQueryParams()['pid'] ?? null;
    
    if ($pid) {
        $stmt = $db->prepare("
            SELECT * FROM kernel_resources
            WHERE pid = ?
            ORDER BY measured_at DESC
            LIMIT 100
        ");
        $stmt->execute([$pid]);
    } else {
        $stmt = $db->query("
            SELECT * FROM kernel_resources
            ORDER BY measured_at DESC
            LIMIT 100
        ");
    }
    
    $resources = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($resources),
        'resources' => $resources
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get kernel configuration
$app->get('/api/v1/kernel/config', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $stmt = $db->query("SELECT * FROM kernel_config ORDER BY `key`");
    $configs = $stmt->fetchAll();
    
    $response->getBody()->write(json_encode([
        'total' => count($configs),
        'config' => $configs
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Update kernel configuration
$app->put('/api/v1/kernel/config/{key}', function (Request $request, Response $response, array $args) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $key = $args['key'];
    $body = json_decode($request->getBody()->getContents(), true);
    $value = $body['value'] ?? null;
    
    if ($value === null) {
        $response->getBody()->write(json_encode(['error' => 'Value is required']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $stmt = $db->prepare("
        UPDATE kernel_config 
        SET value = ?, updated_at = NOW()
        WHERE `key` = ? AND is_system = FALSE
    ");
    
    $stmt->execute([$value, $key]);
    
    if ($stmt->rowCount() > 0) {
        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Configuration updated']));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode(['error' => 'Configuration not found or is system-protected']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
});
