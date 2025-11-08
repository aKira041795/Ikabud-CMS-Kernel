<?php
/**
 * Authentication Middleware
 */

use IkabudKernel\Core\Kernel;

/**
 * Verify authentication token
 */
function verifyAuth() {
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
    
    // Boot kernel if not already booted
    if (!Kernel::isBooted()) {
        Kernel::boot();
    }
    
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
    
    return [
        'id' => $session['user_id'],
        'username' => $session['username'],
        'full_name' => $session['full_name'],
        'email' => $session['email'],
        'role' => $session['role'],
        'permissions' => $permissions
    ];
}

/**
 * Check if user has permission
 */
function hasPermission($user, $permission) {
    // Admin role has all permissions
    if ($user['role'] === 'admin') {
        return true;
    }
    
    // Check specific permission
    return in_array($permission, $user['permissions']);
}
