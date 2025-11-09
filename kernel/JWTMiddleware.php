<?php
namespace IkabudKernel\Core;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * JWT Authentication Middleware
 */
class JWTMiddleware
{
    /**
     * Invoke middleware
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Extract token from Authorization header using PSR-7 request
        $authHeader = $request->getHeaderLine('Authorization');
        $token = null;
        
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
        
        if (!$token) {
            error_log('JWT Middleware: No token found in Authorization header');
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'No authentication token provided'
            ]));
            return $response
                ->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }
        
        // Verify token
        $jwt = new JWT();
        $payload = $jwt->verify($token);
        
        if (!$payload) {
            error_log('JWT Middleware: Token verification failed for token: ' . substr($token, 0, 20) . '...');
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid or expired token'
            ]));
            return $response
                ->withStatus(401)
                ->withHeader('Content-Type', 'application/json');
        }
        
        error_log('JWT Middleware: Token verified successfully for user: ' . ($payload['username'] ?? 'unknown'));
        
        // Add user info to request attributes
        $request = $request->withAttribute('user', $payload);
        
        // Continue to next middleware/route
        return $handler->handle($request);
    }
}
