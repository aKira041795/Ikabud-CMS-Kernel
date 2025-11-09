<?php
/**
 * User Management API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\JWT;
use IkabudKernel\Core\Kernel;

// Middleware to check admin role
$requireAdmin = function (Request $request, $handler) {
    $authHeader = $request->getHeaderLine('Authorization');
    
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $token = $matches[1];
    $jwt = new JWT();
    $decoded = $jwt->verify($token);
    
    if (!$decoded) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid or expired token'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    // Verify token exists in database and hasn't been revoked
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $stmt = $db->prepare("
        SELECT s.*, u.status 
        FROM admin_sessions s
        JOIN admin_users u ON s.user_id = u.id
        WHERE s.token = ? AND s.expires_at > NOW() AND u.status = 'active'
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid or expired token'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $role = $decoded['role'] ?? '';
    if ($role !== 'admin' && $role !== 'administrator') {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Forbidden - Admin access required'
        ]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }
    
    // Store user info in request attribute
    $request = $request->withAttribute('user', $decoded);
    
    return $handler->handle($request);
};

// List all users
$app->get('/api/users', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $stmt = $db->query("
            SELECT id, username, full_name, email, role, status, created_at, updated_at
            FROM admin_users
            ORDER BY created_at DESC
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'users' => $users
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to fetch users: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($requireAdmin);

// Get single user
$app->get('/api/users/{id}', function (Request $request, Response $response, array $args) {
    try {
        $userId = $args['id'];
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $stmt = $db->prepare("
            SELECT id, username, full_name, email, role, status, permissions, created_at, updated_at
            FROM admin_users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'User not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Parse permissions
        $user['permissions'] = json_decode($user['permissions'] ?? '[]', true);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'user' => $user
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to fetch user: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($requireAdmin);

// Create user
$app->post('/api/users', function (Request $request, Response $response) {
    try {
        $body = json_decode($request->getBody()->getContents(), true);
        
        // Validate required fields
        $required = ['username', 'password', 'full_name', 'email', 'role'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => "Field '{$field}' is required"
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        $username = $body['username'];
        $password = $body['password'];
        $fullName = $body['full_name'];
        $email = $body['email'];
        $role = $body['role'];
        $status = $body['status'] ?? 'active';
        
        // Validate role
        if (!in_array($role, ['admin', 'manager', 'viewer'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid role'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Validate status
        if (!in_array($status, ['active', 'inactive'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid status'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        // Check if username already exists
        $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Username already exists'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'User created successfully',
            'user_id' => $userId
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to create user: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($requireAdmin);

// Update user
$app->put('/api/users/{id}', function (Request $request, Response $response, array $args) {
    try {
        $userId = $args['id'];
        $body = json_decode($request->getBody()->getContents(), true);
        
        // Validate required fields
        $required = ['full_name', 'email', 'role', 'status'];
        foreach ($required as $field) {
            if (!isset($body[$field]) || $body[$field] === '') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => "Field '{$field}' is required"
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
        
        $fullName = $body['full_name'];
        $email = $body['email'];
        $role = $body['role'];
        $status = $body['status'];
        $password = $body['password'] ?? null;
        
        // Validate role
        if (!in_array($role, ['admin', 'manager', 'viewer'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid role'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Validate status
        if (!in_array($status, ['active', 'inactive'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid status'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT id FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'User not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
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
        if ($password && trim($password) !== '') {
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
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'User updated successfully'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to update user: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($requireAdmin);

// Delete user
$app->delete('/api/users/{id}', function (Request $request, Response $response, array $args) {
    try {
        $userId = $args['id'];
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        // Check if user exists
        $stmt = $db->prepare("SELECT id, username FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'User not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Delete user (sessions will be cascade deleted)
        $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'User deleted successfully'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to delete user: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add($requireAdmin);
