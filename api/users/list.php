<?php
/**
 * List Users API
 * Returns all admin users (admin role only)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\JWTMiddleware;

// Verify JWT token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$token = $matches[1];
$jwt = new JWTMiddleware();

try {
    $decoded = $jwt->validateToken($token);
    
    // Check if user is admin (support both 'admin' and 'administrator')
    if ($decoded->role !== 'admin' && $decoded->role !== 'administrator') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden - Admin access required']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

try {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $stmt = $db->query("
        SELECT id, username, full_name, email, role, status, created_at, updated_at
        FROM admin_users
        ORDER BY created_at DESC
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'users' => $users
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch users: ' . $e->getMessage()]);
}
