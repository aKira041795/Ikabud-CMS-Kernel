<?php
/**
 * Instance Actions API Routes (Backward Compatibility)
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\JWTMiddleware;

// Monitor instance
$monitorHandler = function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Kernel error: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    try {
        $instanceId = $request->getQueryParams()['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance
        $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch();
        
        if (!$instance) {
            $response->getBody()->write(json_encode(['error' => 'Instance not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Get process info (if table exists)
        $process = null;
        try {
            $stmt = $db->prepare("
                SELECT * FROM kernel_processes 
                WHERE instance_id = ? 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $process = $stmt->fetch();
        } catch (Exception $e) {
            // Table doesn't exist yet, that's ok
            $process = null;
        }
        
        // Decode JSON fields
        $instance['config'] = json_decode($instance['config'] ?? '{}', true);
        $instance['resources'] = json_decode($instance['resources'] ?? '{}', true);
        
        $response->getBody()->write(json_encode([
            'instance' => $instance,
            'process' => $process,
            'resources' => [],
            'status' => $instance['status'],
            'healthy' => $process && $process['status'] === 'running'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
};

// Legacy route (without v1 prefix to avoid conflict with /api/v1/instances/{id})
$app->get('/api/instances/monitor', $monitorHandler)->add(new JWTMiddleware());

// Start instance
$app->post('/api/instances/start', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance path
        $instancePath = dirname(__DIR__, 2) . '/instances/' . $instanceId;
        
        // Remove .maintenance file to disable maintenance mode
        $maintenanceFile = $instancePath . '/.maintenance';
        if (file_exists($maintenanceFile)) {
            unlink($maintenanceFile);
        }
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        // Update process status
        $stmt = $db->prepare("UPDATE kernel_processes SET status = 'running' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance started - maintenance mode disabled'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to start instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Stop instance
$app->post('/api/instances/stop', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Get instance path
        $instancePath = dirname(__DIR__, 2) . '/instances/' . $instanceId;
        
        if (!is_dir($instancePath)) {
            $response->getBody()->write(json_encode(['error' => 'Instance directory not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Create .maintenance file to enable maintenance mode
        $maintenanceFile = $instancePath . '/.maintenance';
        file_put_contents($maintenanceFile, json_encode([
            'time' => time(),
            'stopped_at' => date('Y-m-d H:i:s'),
            'message' => 'Site is currently under maintenance'
        ]));
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'inactive' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        // Update process status
        $stmt = $db->prepare("UPDATE kernel_processes SET status = 'stopped' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance stopped - maintenance mode enabled'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to stop instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Restart instance
$app->post('/api/instances/restart', function (Request $request, Response $response) {
    try {
        $kernel = Kernel::getInstance();
        $db = $kernel->getDatabase();
        
        $body = json_decode($request->getBody()->getContents(), true);
        $instanceId = $body['instance_id'] ?? null;
        
        if (!$instanceId) {
            $response->getBody()->write(json_encode(['error' => 'instance_id is required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Update instance status
        $stmt = $db->prepare("UPDATE instances SET status = 'active' WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'Instance restarted successfully'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to restart instance: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
})->add(new JWTMiddleware());

// Create instance
$app->post('/api/instances/create', function (Request $request, Response $response) {
    $kernel = Kernel::getInstance();
    $db = $kernel->getDatabase();
    
    $body = json_decode($request->getBody()->getContents(), true);
    
    // Validate required fields
    $required = ['instance_id', 'instance_name', 'cms_type', 'database_name'];
    foreach ($required as $field) {
        if (!isset($body[$field]) || empty($body[$field])) {
            $response->getBody()->write(json_encode(['error' => "Field '{$field}' is required"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
    
    // Get and validate instance ID
    $instanceId = $body['instance_id'];
    
    // Validate instance ID format: lowercase, numbers, hyphens, 3-50 chars, must start/end with alphanumeric
    if (!preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $instanceId) || strlen($instanceId) < 3 || strlen($instanceId) > 50) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Invalid instance ID format. Must be 3-50 characters, lowercase letters, numbers, and hyphens only. Must start and end with alphanumeric.'
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if instance ID already exists in database
    $stmt = $db->prepare("SELECT COUNT(*) FROM instances WHERE instance_id = ?");
    $stmt->execute([$instanceId]);
    if ($stmt->fetchColumn() > 0) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Instance ID already exists. Please choose a different one.'
        ]));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
    
    // Check if instance directory already exists
    $rootPath = dirname(__DIR__, 2);
    $instancePath = $rootPath . '/instances/' . $instanceId;
    if (is_dir($instancePath)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Instance directory already exists. Please choose a different instance ID.'
        ]));
        return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
    }
    
    // Insert instance
    $stmt = $db->prepare("
        INSERT INTO instances 
        (instance_id, instance_name, cms_type, cms_version, domain, path_prefix, 
         database_name, database_prefix, status, config, resources)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
    ");
    
    $config = json_encode($body['config'] ?? []);
    $resources = json_encode($body['resources'] ?? ['memory_limit' => 256, 'cpu_limit' => 1.0]);
    
    $stmt->execute([
        $instanceId,
        $body['instance_name'],
        $body['cms_type'],
        $body['cms_version'] ?? null,
        $body['domain'] ?? null,
        $body['path_prefix'] ?? '/',
        $body['database_name'],
        $body['database_prefix'] ?? '',
        $config,
        $resources
    ]);
    
    // Execute instance creation script based on CMS type
    $cmsType = $body['cms_type'] ?? 'wordpress';
    
    // Determine which script to use based on CMS type (PHP CLI for shared hosting compatibility)
    $scriptMap = [
        'wordpress' => 'bin/create-wordpress-instance',
        'joomla' => 'bin/create-joomla-instance',
        'drupal' => 'bin/create-drupal-instance'
    ];
    
    $scriptName = $scriptMap[$cmsType] ?? 'bin/create-wordpress-instance';
    $scriptPath = $rootPath . '/' . $scriptName;
    
    // Check if script exists
    if (!file_exists($scriptPath)) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => "Creation script not found: $scriptName"
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    $instanceName = escapeshellarg($body['instance_name']);
    $domain = escapeshellarg($body['domain'] ?? 'localhost');
    $adminSubdomain = $body['admin_subdomain'] ?? 'admin.' . ($body['domain'] ?? 'localhost');
    $dbName = escapeshellarg($body['database_name']);
    $dbUser = escapeshellarg($body['database_user'] ?? 'root');
    $dbPass = escapeshellarg($body['database_password'] ?? '');
    $dbHost = escapeshellarg($body['database_host'] ?? 'localhost');
    $dbPrefix = escapeshellarg($body['database_prefix'] ?? 'wp_');
    
    // Get CMS version (defaults based on CMS type)
    $cmsVersionDefaults = [
        'wordpress' => 'wordpress',
        'joomla' => 'joomla',
        'drupal' => 'drupal'
    ];
    $cmsVersion = $body['cms_version'] ?? $cmsVersionDefaults[$cmsType] ?? 'wordpress';
    
    // Build command based on CMS type
    // WordPress: ./script.sh <instance_id> <instance_name> <db_name> <domain> <cms_type> <db_user> <db_pass> <db_host> <db_prefix>
    // Joomla: ./script.sh <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> <db_prefix> [joomla_version]
    // Drupal: php script.php <instance_id> <instance_name> <domain> <db_name> <db_user> <db_pass> <db_prefix> [drupal_version]
    
    // Detect PHP binary
    $phpBinary = PHP_BINARY ?: '/usr/bin/php';
    
    if ($cmsType === 'wordpress') {
        $command = "cd $rootPath && $scriptPath $instanceId $instanceName $dbName $domain $cmsType $dbUser $dbPass $dbHost $dbPrefix 2>&1";
    } else if ($cmsType === 'joomla') {
        $command = "cd $rootPath && $scriptPath $instanceId $instanceName $domain $dbName $dbUser $dbPass $dbPrefix $cmsVersion 2>&1";
    } else {
        // Drupal - use php explicitly for better compatibility
        $command = "cd $rootPath && $phpBinary $scriptPath $instanceId $instanceName $domain $dbName $dbUser $dbPass $dbPrefix $cmsVersion 2>&1";
    }
    
    // For Drupal, run in background due to long Drush installation time
    if ($cmsType === 'drupal') {
        // Create a log file for the installation output
        $logFile = $rootPath . '/storage/logs/instance-creation-' . $instanceId . '.log';
        $logDir = dirname($logFile);
        
        // Ensure log directory exists with proper permissions
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Run in background and redirect output to log file
        // Build command with proper escaping
        $bgCommand = sprintf(
            'nohup %s %s %s %s %s %s %s %s %s %s > %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($instanceId),
            $instanceName,  // Already escaped above
            $domain,        // Already escaped above
            $dbName,        // Already escaped above
            $dbUser,        // Already escaped above
            $dbPass,        // Already escaped above
            $dbPrefix,      // Already escaped above
            escapeshellarg($cmsVersion),
            escapeshellarg($logFile)
        );
        
        // Log the command for debugging
        error_log("Drupal instance creation command: " . $bgCommand);
        
        // Change to root directory and execute
        $oldDir = getcwd();
        chdir($rootPath);
        
        // Use shell_exec with sh -c to properly handle background execution
        $shellCommand = "sh -c " . escapeshellarg($bgCommand);
        shell_exec($shellCommand);
        
        chdir($oldDir);
        
        // Log execution
        error_log("Command executed via shell_exec");
        
        // Give it a moment to start and create initial files
        sleep(3);
        
        $output = ["Instance creation started in background. Check log: $logFile"];
        $returnCode = 0;
    } else {
        exec($command, $output, $returnCode);
    }
    
    // Check if instance directory was created (check for CMS-specific config file)
    $instancePath = $rootPath . '/instances/' . $instanceId;
    $configFiles = [
        'wordpress' => 'wp-config.php',
        'joomla' => 'configuration.php',
        'drupal' => 'sites/default/settings.php'
    ];
    
    $configFile = $configFiles[$cmsType] ?? 'wp-config.php';
    $instanceCreated = file_exists($instancePath . '/' . $configFile) || is_dir($instancePath);
    
    if ($returnCode !== 0 && !$instanceCreated) {
        // Rollback database entry
        $stmt = $db->prepare("DELETE FROM instances WHERE instance_id = ?");
        $stmt->execute([$instanceId]);
        
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Failed to create instance files',
            'details' => implode("\n", $output)
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    
    // Determine installation URL based on CMS type
    $installationUrls = [
        'wordpress' => "http://{$adminSubdomain}/wp-admin/install.php",
        'joomla' => "http://{$adminSubdomain}/installation/setup",
        'drupal' => "http://{$adminSubdomain}/core/install.php" // Fallback if Drush fails
    ];
    
    $installationUrl = $installationUrls[$cmsType] ?? "http://{$adminSubdomain}";
    
    // For Drupal, check if Drush installation was successful
    $drupalAutoInstalled = false;
    if ($cmsType === 'drupal') {
        // Check if Drush completed installation (settings.php should be updated)
        $settingsPath = $instancePath . '/sites/default/settings.php';
        if (file_exists($settingsPath)) {
            $settingsContent = file_get_contents($settingsPath);
            // If Drush installed successfully, settings.php will have been updated
            $drupalAutoInstalled = strpos($settingsContent, 'hash_salt') !== false;
        }
    }
    
    // Customize message for Drupal background installation
    $message = 'Instance created successfully';
    if ($cmsType === 'drupal' && !$drupalAutoInstalled) {
        $message = 'Instance created. Drupal installation running in background (2-3 minutes). Refresh page to check status.';
    }
    
    $responseData = [
        'success' => true,
        'instance_id' => $instanceId,
        'message' => $message,
        'admin_url' => "http://{$adminSubdomain}",
        'frontend_url' => "http://" . ($body['domain'] ?? 'localhost'),
        'details' => implode("\n", $output)
    ];
    
    // Add installation URL only if manual installation is needed
    if ($cmsType !== 'drupal' || !$drupalAutoInstalled) {
        $responseData['installation_url'] = $installationUrl;
    }
    
    // Add Drupal-specific info
    if ($cmsType === 'drupal') {
        $responseData['drupal_auto_installed'] = $drupalAutoInstalled;
        if ($drupalAutoInstalled) {
            $responseData['admin_credentials'] = [
                'username' => 'admin',
                'password' => 'admin123'
            ];
        }
    }
    
    $response->getBody()->write(json_encode($responseData));
    
    return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
})->add(new JWTMiddleware());
