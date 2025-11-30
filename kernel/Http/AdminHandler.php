<?php
/**
 * Admin Handler
 * 
 * Handles admin panel requests (React SPA)
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminHandler
{
    private string $adminPath;
    
    public function __construct(string $adminPath = null)
    {
        $this->adminPath = $adminPath ?? dirname(__DIR__, 2) . '/public/admin';
    }
    
    /**
     * Handle admin request
     */
    public function handle(Request $request, Response $response): Response
    {
        $requestUri = $request->getUri()->getPath();
        $filePath = preg_replace('#^/(admin|login)#', '', $requestUri) ?: '/index.html';
        
        if ($filePath === '/' || $filePath === '') {
            $filePath = '/index.html';
        }
        
        $fullPath = $this->adminPath . $filePath;
        
        // Serve file if exists
        if (file_exists($fullPath) && is_file($fullPath)) {
            return $this->serveFile($response, $fullPath);
        }
        
        // SPA fallback - serve index.html
        $indexPath = $this->adminPath . '/index.html';
        if (file_exists($indexPath)) {
            return $this->serveFile($response, $indexPath);
        }
        
        $response->getBody()->write('Admin interface not found. Run: cd admin && npm run build');
        return $response->withStatus(404);
    }
    
    /**
     * Serve a file with proper MIME type
     */
    private function serveFile(Response $response, string $path): Response
    {
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        
        // Fix MIME types for common files
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeOverrides = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
        ];
        
        if (isset($mimeOverrides[$ext])) {
            $mimeType = $mimeOverrides[$ext];
        }
        
        $response = $response->withHeader('Content-Type', $mimeType);
        $response->getBody()->write(file_get_contents($path));
        return $response;
    }
}
