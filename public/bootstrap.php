<?php
/**
 * Ikabud Kernel - Bootstrap
 * 
 * Handles kernel boot, configuration loading, and app setup
 * 
 * @version 1.0.0
 */

declare(strict_types=1);

use IkabudKernel\Core\Kernel;
use IkabudKernel\Core\Config;
use Slim\Factory\AppFactory;

// Composer autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = Config::getInstance();
$debugMode = ($config->get('APP_DEBUG') === 'true');

// Boot kernel
try {
    Kernel::boot();
} catch (Exception $e) {
    http_response_code(500);
    if ($debugMode) {
        die("Kernel boot failed: " . $e->getMessage());
    }
    die("Service temporarily unavailable");
}

// Create Slim application
$app = AppFactory::create();

// Error middleware
$app->addErrorMiddleware($debugMode, true, true);

return [
    'app' => $app,
    'config' => $config,
    'debugMode' => $debugMode,
];
