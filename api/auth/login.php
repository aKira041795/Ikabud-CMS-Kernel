<?php
/**
 * Authentication API - Login
 * 
 * Handles user authentication for Ikabud Kernel Admin
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';

use IkabudKernel\Core\Kernel;

try {
    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Username and password required'
        ]);
        exit;
    }
    
    $username = $input['username'];
    $password = $input['password'];
    
    // Boot kernel
    Kernel::boot();
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Check user credentials
    $stmt = $db->prepare("
        SELECT id, username, password, role, full_name, email, permissions
        FROM admin_users
        WHERE username = ? AND status = 'active'
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid credentials'
        ]);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid credentials'
        ]);
        exit;
    }
    
    // Generate session token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Store session
    $stmt = $db->prepare("
        INSERT INTO admin_sessions (user_id, token, expires_at, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$user['id'], $token, $expiresAt]);
    
    // Parse permissions
    $permissions = json_decode($user['permissions'] ?? '[]', true);
    
    // Return success
    echo json_encode([
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'permissions' => $permissions
        ],
        'expires_at' => $expiresAt
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
