<?php
/**
 * Conditional Loading API Routes
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Get conditional loading stats for an instance
$app->get('/api/instances/{instanceId}/conditional-loading/stats', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $instanceDir = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instanceDir)) {
        $response->getBody()->write(json_encode([
            'error' => 'Instance not found',
            'instance_id' => $instanceId
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $pluginLoader = new \IkabudKernel\Core\ConditionalPluginLoader($instanceDir);
    $manifest = $pluginLoader->getManifest();
    
    $stats = [
        'instance_id' => $instanceId,
        'enabled' => $pluginLoader->isEnabled(),
        'total_plugins' => count($manifest['plugins'] ?? []),
        'plugins' => []
    ];
    
    // Analyze each plugin
    foreach ($manifest['plugins'] ?? [] as $pluginFile => $config) {
        $stats['plugins'][] = [
            'file' => $pluginFile,
            'name' => $config['name'] ?? basename($pluginFile),
            'enabled' => $config['enabled'] ?? true,
            'priority' => $config['priority'] ?? 10,
            'routes' => $config['load_on']['routes'] ?? [],
            'load_in_admin' => $config['load_on']['admin'] ?? false
        ];
    }
    
    // Sort by priority
    usort($stats['plugins'], fn($a, $b) => $a['priority'] <=> $b['priority']);
    
    $response->getBody()->write(json_encode($stats, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Get manifest for an instance
$app->get('/api/instances/{instanceId}/conditional-loading/manifest', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $manifestFile = __DIR__ . "/../../instances/$instanceId/plugin-manifest.json";
    
    if (!file_exists($manifestFile)) {
        $response->getBody()->write(json_encode([
            'error' => 'Manifest not found',
            'instance_id' => $instanceId,
            'path' => $manifestFile
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $manifest = json_decode(file_get_contents($manifestFile), true);
    
    $response->getBody()->write(json_encode($manifest, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Update manifest for an instance
$app->put('/api/instances/{instanceId}/conditional-loading/manifest', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $manifestFile = __DIR__ . "/../../instances/$instanceId/plugin-manifest.json";
    
    $body = $request->getBody()->getContents();
    $newManifest = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response->getBody()->write(json_encode([
            'error' => 'Invalid JSON',
            'message' => json_last_error_msg()
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Validate manifest structure
    if (!isset($newManifest['plugins']) || !is_array($newManifest['plugins'])) {
        $response->getBody()->write(json_encode([
            'error' => 'Invalid manifest structure',
            'message' => 'Manifest must contain "plugins" array'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Backup existing manifest
    if (file_exists($manifestFile)) {
        $backupFile = $manifestFile . '.backup.' . time();
        copy($manifestFile, $backupFile);
    }
    
    // Save new manifest
    $json = json_encode($newManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($manifestFile, $json);
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Manifest updated',
        'instance_id' => $instanceId,
        'backup_created' => $backupFile ?? null
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Test plugin loading for a specific route
$app->post('/api/instances/{instanceId}/conditional-loading/test', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $instanceDir = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instanceDir)) {
        $response->getBody()->write(json_encode([
            'error' => 'Instance not found'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    $body = json_decode($request->getBody()->getContents(), true);
    $testUri = $body['uri'] ?? '/';
    $context = $body['context'] ?? [];
    
    $pluginLoader = new \IkabudKernel\Core\ConditionalPluginLoader($instanceDir);
    $pluginsToLoad = $pluginLoader->determinePlugins($testUri, $context);
    
    $result = [
        'instance_id' => $instanceId,
        'test_uri' => $testUri,
        'context' => $context,
        'plugins_to_load' => count($pluginsToLoad),
        'plugins' => array_map(function($plugin) {
            return [
                'file' => $plugin['file'],
                'priority' => $plugin['priority']
            ];
        }, $pluginsToLoad)
    ];
    
    $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Generate manifest for an instance
$app->post('/api/instances/{instanceId}/conditional-loading/generate', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instanceId'];
    $instanceDir = __DIR__ . '/../../instances/' . $instanceId;
    
    if (!is_dir($instanceDir)) {
        $response->getBody()->write(json_encode([
            'error' => 'Instance not found'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    
    // Execute the CLI tool
    $command = "php " . __DIR__ . "/../../bin/generate-plugin-manifest " . escapeshellarg($instanceId) . " 2>&1";
    $output = [];
    $returnCode = 0;
    
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Manifest generated successfully',
            'instance_id' => $instanceId,
            'output' => implode("\n", $output)
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        $response->getBody()->write(json_encode([
            'error' => 'Failed to generate manifest',
            'return_code' => $returnCode,
            'output' => implode("\n", $output)
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});
