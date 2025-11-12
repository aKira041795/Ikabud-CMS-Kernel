<?php
/**
 * Instance Creation Log Streaming API
 * Provides real-time log streaming for Drupal instance creation
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Stream instance creation log
$app->get('/api/instances/{instance_id}/creation-log', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instance_id'];
    $rootPath = dirname(__DIR__, 2);
    $logFile = $rootPath . '/storage/logs/instance-creation-' . $instanceId . '.log';
    
    // Validate instance ID format
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $instanceId)) {
        $response->getBody()->write(json_encode(['error' => 'Invalid instance ID']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if log file exists
    if (!file_exists($logFile)) {
        $response->getBody()->write(json_encode([
            'exists' => false,
            'content' => '',
            'message' => 'Log file not found. Installation may not have started yet.'
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    // Get file size and last modified time
    $fileSize = filesize($logFile);
    $lastModified = filemtime($logFile);
    
    // Read the log file
    $content = file_get_contents($logFile);
    
    // Check if installation is complete
    $isComplete = strpos($content, 'Drupal Instance Created Successfully!') !== false;
    // Check for actual failures (not just the word "failed" in success messages)
    $hasFailed = (strpos($content, '✗ Drupal installation failed') !== false) || 
                 (strpos($content, 'Installation failed') !== false && strpos($content, '[success] Installation complete') === false);
    
    $response->getBody()->write(json_encode([
        'exists' => true,
        'content' => $content,
        'size' => $fileSize,
        'last_modified' => $lastModified,
        'is_complete' => $isComplete,
        'has_failed' => $hasFailed,
        'timestamp' => time()
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});

// Get log file tail (last N lines)
$app->get('/api/instances/{instance_id}/creation-log/tail', function (Request $request, Response $response, array $args) {
    $instanceId = $args['instance_id'];
    $lines = $request->getQueryParams()['lines'] ?? 50;
    $rootPath = dirname(__DIR__, 2);
    $logFile = $rootPath . '/storage/logs/instance-creation-' . $instanceId . '.log';
    
    // Validate instance ID format
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $instanceId)) {
        $response->getBody()->write(json_encode(['error' => 'Invalid instance ID']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if log file exists
    if (!file_exists($logFile)) {
        $response->getBody()->write(json_encode([
            'exists' => false,
            'content' => ''
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    // Read last N lines using tail command
    $output = [];
    exec("tail -n " . intval($lines) . " " . escapeshellarg($logFile), $output);
    $content = implode("\n", $output);
    
    $response->getBody()->write(json_encode([
        'exists' => true,
        'content' => $content,
        'timestamp' => time()
    ]));
    
    return $response->withHeader('Content-Type', 'application/json');
});
