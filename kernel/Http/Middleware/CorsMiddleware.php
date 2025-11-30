<?php
/**
 * CORS Middleware
 * 
 * Handles Cross-Origin Resource Sharing for API requests
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CorsMiddleware
{
    private array $allowedOriginPatterns;
    private array $allowedMethods;
    private array $allowedHeaders;
    private bool $allowCredentials;
    
    public function __construct(array $config = [])
    {
        $this->allowedOriginPatterns = $config['origins'] ?? [
            '/^https?:\/\/(.+\.)?[^.]+\.test$/',  // *.test domains
            '/^https?:\/\/localhost(:\d+)?$/',    // localhost
            '/^https?:\/\/127\.0\.0\.1(:\d+)?$/', // 127.0.0.1
        ];
        
        $this->allowedMethods = $config['methods'] ?? [
            'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'
        ];
        
        $this->allowedHeaders = $config['headers'] ?? [
            'Content-Type',
            'Authorization',
            'X-WP-Nonce',
            'X-Requested-With',
            'X-HTTP-Method-Override',
            'Origin',
            'Accept'
        ];
        
        $this->allowCredentials = $config['credentials'] ?? true;
    }
    
    /**
     * Invoke middleware
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);
        
        $origin = $request->getHeaderLine('Origin');
        
        if ($origin && $this->isAllowedOrigin($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Methods', implode(',', $this->allowedMethods))
                ->withHeader('Access-Control-Allow-Headers', implode(',', $this->allowedHeaders));
            
            if ($this->allowCredentials) {
                $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
            }
        }
        
        return $response;
    }
    
    /**
     * Check if origin is allowed
     */
    private function isAllowedOrigin(string $origin): bool
    {
        foreach ($this->allowedOriginPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        return false;
    }
}
