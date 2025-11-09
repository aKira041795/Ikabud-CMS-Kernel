<?php
/**
 * Create User API
 * Creates a new admin user (admin role only)
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
    
    // Check if user is admin
    if ($decoded->role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden - Admin access required']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['username', 'password', 'full_name', 'email', 'role'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '{$field}' is required"]);
        exit;
    }
}

$username = $input['username'];
$password = $input['password'];
$fullName = $input['full_name'];
$email = $input['email'];
$role = $input['role'];
$status = $input['status'] ?? 'active';

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
    
    // Check if username already exists
    $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    
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
    
    // Insert user
    $stmt = $db->prepare("
        INSERT INTO admin_users (username, password, full_name, email, role, permissions, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $username,
        $hashedPassword,
        $fullName,
        $email,
        $role,
        json_encode($permissions),
        $status
    ]);
    
    $userId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'User created successfully',
        'user_id' => $userId
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create user: ' . $e->getMessage()]);
}
