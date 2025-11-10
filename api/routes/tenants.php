<?php
/**
 * Tenant Management API Routes
 * 
 * RESTful API for managing multi-tenant instances
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\ResourceManager;
use IkabudKernel\CMS\CMSAdapterFactory;

$app->group('/api/tenants', function ($app) {
    
    /**
     * GET /api/tenants
     * List all tenants with resource usage
     */
    $app->get('', function (Request $request, Response $response) {
        $resourceManager = new ResourceManager();
        $allUsage = $resourceManager->getAllUsage();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $allUsage,
            'stats' => $resourceManager->getStats()
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * GET /api/tenants/{instanceId}
     * Get specific tenant details
     */
    $app->get('/{instanceId}', function (Request $request, Response $response, array $args) {
        $instanceId = $args['instanceId'];
        $resourceManager = new ResourceManager();
        
        $usage = $resourceManager->getUsage($instanceId);
        $status = $resourceManager->checkLimits($instanceId);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => array_merge($usage, [
                'status' => $status
            ])
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * POST /api/tenants/{instanceId}/limits
     * Set resource limits for a tenant
     * 
     * Body: {
     *   "memory_mb": 256,
     *   "cpu_percent": 50,
     *   "storage_mb": 1024,
     *   "cache_mb": 100,
     *   "requests_per_minute": 60
     * }
     */
    $app->post('/{instanceId}/limits', function (Request $request, Response $response, array $args) {
        $instanceId = $args['instanceId'];
        $data = json_decode($request->getBody()->getContents(), true);
        
        if (!$data) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid JSON'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $resourceManager = new ResourceManager();
        
        // Set limits
        if (isset($data['memory_mb'])) {
            $resourceManager->setMemoryLimit($instanceId, (int)$data['memory_mb']);
        }
        if (isset($data['cpu_percent'])) {
            $resourceManager->setCpuLimit($instanceId, (int)$data['cpu_percent']);
        }
        if (isset($data['storage_mb'])) {
            $resourceManager->setStorageQuota($instanceId, (int)$data['storage_mb']);
        }
        if (isset($data['cache_mb'])) {
            $resourceManager->setCacheQuota($instanceId, (int)$data['cache_mb']);
        }
        if (isset($data['requests_per_minute'])) {
            $resourceManager->setRateLimit($instanceId, (int)$data['requests_per_minute']);
        }
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Limits updated',
            'data' => $resourceManager->getUsage($instanceId)
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * POST /api/tenants/{instanceId}/enforce
     * Enforce quotas (cleanup if exceeded)
     */
    $app->post('/{instanceId}/enforce', function (Request $request, Response $response, array $args) {
        $instanceId = $args['instanceId'];
        $resourceManager = new ResourceManager();
        
        $actions = $resourceManager->enforceQuotas($instanceId);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'actions' => $actions,
            'data' => $resourceManager->getUsage($instanceId)
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * DELETE /api/tenants/{instanceId}/limits
     * Remove all limits for a tenant
     */
    $app->delete('/{instanceId}/limits', function (Request $request, Response $response, array $args) {
        $instanceId = $args['instanceId'];
        $resourceManager = new ResourceManager();
        
        $resourceManager->removeLimits($instanceId);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Limits removed'
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * POST /api/tenants/{instanceId}/usage/reset
     * Reset usage statistics
     */
    $app->post('/{instanceId}/usage/reset', function (Request $request, Response $response, array $args) {
        $instanceId = $args['instanceId'];
        $resourceManager = new ResourceManager();
        
        $resourceManager->resetUsage($instanceId);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Usage statistics reset'
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
    
    /**
     * GET /api/tenants/stats
     * Get global resource statistics
     */
    $app->get('/stats/global', function (Request $request, Response $response) {
        $resourceManager = new ResourceManager();
        $stats = $resourceManager->getStats();
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'data' => $stats
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    });
});
