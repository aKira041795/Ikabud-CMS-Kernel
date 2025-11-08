<?php
/**
 * Authentication API - Verify Token
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

use IkabudKernel\Core\Kernel;

try {
    // Get token from header
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'No token provided'
        ]);
        exit;
    }
    
    $token = $matches[1];
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Verify token
    $stmt = $db->prepare("
        SELECT s.*, u.id as user_id, u.username, u.role, u.full_name, u.email, u.permissions
        FROM admin_sessions s
        JOIN admin_users u ON s.user_id = u.id
        WHERE s.token = ? AND s.expires_at > NOW() AND u.status = 'active'
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }
    
    // Parse permissions
    $permissions = json_decode($session['permissions'] ?? '[]', true);
    
    // Return user info
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $session['user_id'],
            'username' => $session['username'],
            'full_name' => $session['full_name'],
            'email' => $session['email'],
            'role' => $session['role'],
            'permissions' => $permissions
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
