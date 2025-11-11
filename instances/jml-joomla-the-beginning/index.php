<?php
/**
 * Joomla Instance Entry Point
 * Modified for Ikabud Kernel shared core architecture
 */

// Define the application's minimum supported PHP version
define('JOOMLA_MINIMUM_PHP', '8.1.0');

if (version_compare(PHP_VERSION, JOOMLA_MINIMUM_PHP, '<')) {
    die('This site requires PHP ' . JOOMLA_MINIMUM_PHP . ' or later.');
}

// Constant that is checked in included files to prevent direct access
define('_JEXEC', 1);

// Load instance-specific path definitions BEFORE loading Joomla
// This ensures instance-specific cache/config directories are used
require_once __DIR__ . '/defines.php';

// Saves the start time and memory usage (from shared core's app.php)
$startTime = microtime(1);
$startMem  = memory_get_usage();

// Check for presence of vendor dependencies
if (!file_exists(JPATH_LIBRARIES . '/vendor/autoload.php')) {
    die('Joomla vendor dependencies not found. Please check shared core installation.');
}

// Load framework from shared core (skip defines.php - already defined in instance defines.php)
require_once JPATH_LIBRARIES . '/../includes/framework.php';

// Set profiler start time and memory usage
JDEBUG && \Joomla\CMS\Profiler\Profiler::getInstance('Application')->setStart($startTime, $startMem)->mark('afterLoad');

// Boot the DI container
$container = \Joomla\CMS\Factory::getContainer();

// Alias session service keys
$container->alias('session.web', 'session.web.site')
    ->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\Session::class, 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');

// Instantiate the application
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);

// Set the application as global app
\Joomla\CMS\Factory::$application = $app;

// Execute the application
$app->execute();