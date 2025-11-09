<?php
/**
 * Authentication API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\JWT;
use IkabudKernel\Core\Config;

// Login endpoint
$app->post('/api/auth/login', function (Request $request, Response $response) {
    try {
        $body = json_decode($request->getBody()->getContents(), true);
        
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        
        // Validate input
        if (empty($username) || empty($password)) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Username and password are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get database connection from kernel
        $kernel = \IkabudKernel\Core\Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        // Rate limiting: Check failed login attempts
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ipAddress]);
        $attempts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($attempts && $attempts['attempts'] >= 5) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Too many failed login attempts. Please try again in 15 minutes.'
            ]));
            return $response->withStatus(429)->withHeader('Content-Type', 'application/json');
        }
        
        // Check user credentials in database
        $stmt = $db->prepare("
            SELECT id, username, password, role, full_name, email, permissions
            FROM admin_users
            WHERE username = ? AND status = 'active'
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Log failed attempt
            $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())");
            $stmt->execute([$ipAddress, $username]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid username or password'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Log failed attempt
            $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())");
            $stmt->execute([$ipAddress, $username]);
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid username or password'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // Clear failed attempts on successful login
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->execute([$ipAddress]);
        
        // Parse permissions
        $permissions = json_decode($user['permissions'] ?? '[]', true);
        if (empty($permissions)) {
            // Default permissions based on role
            $permissions = ['*'];
        }
        
        // Generate JWT token
        $jwt = new JWT();
        $token = $jwt->generate([
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'permissions' => $permissions
        ]);
        
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Store session with JWT token
        $stmt = $db->prepare("
            INSERT INTO admin_sessions (user_id, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$user['id'], $token, $expiresAt]);
        
        $response->getBody()->write(json_encode([
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
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

// Verify token endpoint
$app->post('/api/auth/verify', function (Request $request, Response $response) {
    $token = JWT::extractFromHeader();
    
    if (!$token) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'No token provided'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $jwt = new JWT();
    $payload = $jwt->verify($token);
    
    if (!$payload) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid or expired token'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'user' => [
            'username' => $payload['username'] ?? 'Unknown',
            'role' => $payload['role'] ?? 'user',
            'permissions' => $payload['permissions'] ?? []
        ]
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Refresh token endpoint
$app->post('/api/auth/refresh', function (Request $request, Response $response) {
    $token = JWT::extractFromHeader();
    
    if (!$token) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'No token provided'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $jwt = new JWT();
    $newToken = $jwt->refresh($token);
    
    if (!$newToken) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid or expired token'
        ]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'token' => $newToken
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Logout endpoint - invalidates token in database
$app->post('/api/auth/logout', function (Request $request, Response $response) {
    try {
        $token = JWT::extractFromHeader();
        
        if ($token) {
            $kernel = \IkabudKernel\Core\Kernel::getInstance();
            $db = $kernel->getDatabase();
            
            // Delete the session from database
            $stmt = $db->prepare("DELETE FROM admin_sessions WHERE token = ?");
            $stmt->execute([$token]);
        }
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Logged out successfully'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Logged out successfully'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
});
