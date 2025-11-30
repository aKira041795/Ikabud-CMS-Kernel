<?php
/**
 * Router
 * 
 * Handles lazy loading of API routes based on request path
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\Core\Http;

use Slim\App;
use IkabudKernel\Core\Kernel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Router
{
    private App $app;
    private string $routesDir;
    
    /**
     * Route map: URL prefix => route file(s)
     */
    private static array $lazyRouteMap = [
        'auth'        => ['auth.php'],
        'users'       => ['users.php'],
        'kernel'      => ['kernel.php'],
        'instances'   => ['instances.php', 'instances-actions.php', 'instance-logs.php', 'conditional-loading.php'],
        'themes'      => ['themes.php', 'theme-generator.php'],
        'theme'       => ['theme-generator.php'],
        'filesystem'  => ['themes.php'],
        'dsl'         => ['dsl.php'],
        'cores'       => ['cores.php'],
        'cache'       => ['cache.php'],
        'tenants'     => ['tenants.php'],
    ];
    
    public function __construct(App $app, string $routesDir = null)
    {
        $this->app = $app;
        $this->routesDir = $routesDir ?? dirname(__DIR__, 2) . '/api/routes';
    }
    
    /**
     * Register all routes
     */
    public function register(): void
    {
        $this->registerHealthCheck();
        $this->registerOptionsHandler();
        $this->loadApiRoutes();
        $this->registerAdminRoutes();
        $this->registerCatchAll();
    }
    
    /**
     * Register health check endpoint
     */
    private function registerHealthCheck(): void
    {
        $this->app->get('/api/health', function (Request $request, Response $response) {
            $stats = Kernel::getStats();
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'kernel' => $stats,
                'timestamp' => time()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });
    }
    
    /**
     * Register OPTIONS handler for CORS preflight
     */
    private function registerOptionsHandler(): void
    {
        $this->app->options('/{routes:.+}', function (Request $request, Response $response) {
            return $response;
        });
    }
    
    /**
     * Load API routes lazily based on request path
     */
    private function loadApiRoutes(): void
    {
        $requestPath = $_SERVER['REQUEST_URI'] ?? '/';
        
        if (strpos($requestPath, '/api/') !== 0) {
            return; // Not an API request
        }
        
        $apiPrefix = $this->extractApiPrefix($requestPath);
        $routesLoaded = false;
        
        foreach (self::$lazyRouteMap as $prefix => $files) {
            if ($apiPrefix === $prefix || strpos($apiPrefix, $prefix) === 0) {
                foreach ($files as $file) {
                    $routePath = $this->routesDir . '/' . $file;
                    if (file_exists($routePath)) {
                        $app = $this->app; // Make available in route files
                        require $routePath;
                        $routesLoaded = true;
                    }
                }
                break;
            }
        }
        
        // Fallback: load auth routes for login/logout
        if (!$routesLoaded) {
            $authPath = $this->routesDir . '/auth.php';
            if (file_exists($authPath)) {
                $app = $this->app;
                require $authPath;
            }
        }
    }
    
    /**
     * Extract API prefix from request path
     */
    private function extractApiPrefix(string $path): string
    {
        $parts = explode('/', trim($path, '/'));
        
        // Handle /api/v1/prefix and /api/prefix
        if (isset($parts[1]) && $parts[1] === 'v1') {
            return $parts[2] ?? '';
        }
        
        return $parts[1] ?? '';
    }
    
    /**
     * Register admin panel routes
     */
    private function registerAdminRoutes(): void
    {
        $adminHandler = new AdminHandler();
        
        $this->app->get('/admin[/{path:.*}]', function (Request $request, Response $response) use ($adminHandler) {
            return $adminHandler->handle($request, $response);
        });
        
        $this->app->get('/login', function (Request $request, Response $response) use ($adminHandler) {
            return $adminHandler->handle($request, $response);
        });
    }
    
    /**
     * Register catch-all route for CMS
     */
    private function registerCatchAll(): void
    {
        $debugMode = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $cmsHandler = new CMSHandler($debugMode);
        
        $this->app->any('[/{path:.*}]', function (Request $request, Response $response) use ($cmsHandler) {
            return $cmsHandler->handle($request, $response);
        });
    }
}
