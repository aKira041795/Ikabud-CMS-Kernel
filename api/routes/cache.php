<?php
/**
 * Cache Management API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\Cache;
use IkabudKernel\Core\JWTMiddleware;

// Get cache statistics
$app->get('/api/v1/cache/stats', function (Request $request, Response $response) {
    $cache = new Cache();
    $stats = $cache->getStats();
    
    $response->getBody()->write(json_encode($stats));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());

// Get cache size for instance
$app->get('/api/v1/cache/size/{instance_id}', function (Request $request, Response $response, array $args) {
    $cache = new Cache();
    $instanceId = $args['instance_id'];
    
    $size = $cache->getSize($instanceId);
    
    $response->getBody()->write(json_encode($size));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());

// Clear cache for instance
$app->delete('/api/v1/cache/{instance_id}', function (Request $request, Response $response, array $args) {
    $cache = new Cache();
    $instanceId = $args['instance_id'];
    
    $cache->clear($instanceId);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => "Cache cleared for instance: {$instanceId}"
    ]));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());

// Clear cache by pattern
$app->post('/api/v1/cache/{instance_id}/clear-pattern', function (Request $request, Response $response, array $args) {
    $cache = new Cache();
    $instanceId = $args['instance_id'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    $pattern = $body['pattern'] ?? '';
    
    if (empty($pattern)) {
        $response->getBody()->write(json_encode([
            'error' => 'Pattern is required'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $cache->clearByPattern($instanceId, $pattern);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => "Cache cleared for pattern: {$pattern}"
    ]));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());

// Clear all cache
$app->delete('/api/v1/cache', function (Request $request, Response $response) {
    $cache = new Cache();
    $cache->clearAll();
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'All cache cleared'
    ]));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());

// Warm cache
$app->post('/api/v1/cache/{instance_id}/warm', function (Request $request, Response $response, array $args) {
    $cache = new Cache();
    $instanceId = $args['instance_id'];
    $body = json_decode($request->getBody()->getContents(), true);
    
    $urls = $body['urls'] ?? [];
    
    if (empty($urls)) {
        $response->getBody()->write(json_encode([
            'error' => 'URLs array is required'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    $results = $cache->warm($instanceId, $urls);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'results' => $results
    ]));
    return $response->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());
