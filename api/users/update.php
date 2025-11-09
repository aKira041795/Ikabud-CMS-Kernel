<?php
/**
 * Update User API
 * Updates an existing admin user (admin role only)
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
$jwt = new \IkabudKernel\Core\JWT();

$decoded = $jwt->verify($token);

if (!$decoded) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

// Check if user is admin (support both 'admin' and 'administrator')
$role = $decoded['role'] ?? '';
if ($role !== 'admin' && $role !== 'administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden - Admin access required']);
    exit;
}

// Get user ID from query string
$userId = $_GET['id'] ?? null;
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['full_name', 'email', 'role', 'status'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '{$field}' is required"]);
        exit;
    }
}

$fullName = $input['full_name'];
$email = $input['email'];
$role = $input['role'];
$status = $input['status'];
$password = $input['password'] ?? null;

// Validate role
if (!in_array($role, ['admin', 'manager', 'viewer'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role']);
    exit;
}

// Validate status
if (!in_array($status, ['active', 'inactive'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM admin_users WHERE id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Set permissions based on role
    $permissions = [];
    switch ($role) {
        case 'admin':
            $permissions = ['instances.create', 'instances.view', 'instances.manage', 'instances.delete', 'users.manage', 'system.config'];
            break;
        case 'manager':
            $permissions = ['instances.create', 'instances.view', 'instances.manage'];
            break;
        case 'viewer':
            $permissions = ['instances.view'];
            break;
    }
    
    // Update user
    if ($password) {
        // Update with password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            UPDATE admin_users 
            SET password = ?, full_name = ?, email = ?, role = ?, permissions = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $hashedPassword,
            $fullName,
            $email,
            $role,
            json_encode($permissions),
            $status,
            $userId
        ]);
    } else {
        // Update without password
        $stmt = $db->prepare("
            UPDATE admin_users 
            SET full_name = ?, email = ?, role = ?, permissions = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $fullName,
            $email,
            $role,
            json_encode($permissions),
            $status,
            $userId
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'User updated successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update user: ' . $e->getMessage()]);
}
