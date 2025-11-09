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
    
    // Check credentials (in production, check against database)
    $adminUsername = Config::get('ADMIN_USERNAME', 'admin');
    $adminPassword = Config::get('ADMIN_PASSWORD', 'password');
    
    if ($username === $adminUsername && $password === $adminPassword) {
        // Generate JWT token
        $jwt = new JWT();
        $token = $jwt->generate([
            'username' => $username,
            'role' => 'administrator',
            'permissions' => ['*']
        ]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'token' => $token,
            'user' => [
                'username' => $username,
                'role' => 'administrator',
                'email' => Config::get('ADMIN_EMAIL', 'admin@ikabud.local')
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    // Invalid credentials
    $response->getBody()->write(json_encode([
        'success' => false,
        'error' => 'Invalid username or password'
    ]));
    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
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

// Logout endpoint (client-side token removal, but we can log it)
$app->post('/api/auth/logout', function (Request $request, Response $response) {
    // In a more complex system, you might invalidate the token in a blacklist
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});
