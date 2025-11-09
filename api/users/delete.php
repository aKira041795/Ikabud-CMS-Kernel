<?php
/**
 * Delete User API
 * Deletes an admin user (admin role only)
 * Prevents deleting own account
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
    
    // Check if user is admin (support both 'admin' and 'administrator')
    if ($decoded->role !== 'admin' && $decoded->role !== 'administrator') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden - Admin access required']);
        exit;
    }
    
    $currentUserId = $decoded->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// Get user ID from query string
$userId = $_GET['id'] ?? null;
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

// Prevent deleting own account
if ($userId == $currentUserId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
    exit;
}

try {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id, username FROM admin_users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Delete user (sessions will be cascade deleted)
    $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
    $stmt->execute([$userId]);
    
    echo json_encode([
        'success' => true,
        'message' => "User '{$user['username']}' deleted successfully"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete user: ' . $e->getMessage()]);
}
