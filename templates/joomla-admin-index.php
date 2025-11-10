<?php
/**
 * Joomla Administrator Entry Point
 * Modified for Ikabud Kernel shared core architecture
 */

// Define minimum PHP version
define('JOOMLA_MINIMUM_PHP', '8.1.0');

if (version_compare(PHP_VERSION, JOOMLA_MINIMUM_PHP, '<')) {
    die('This site requires PHP ' . JOOMLA_MINIMUM_PHP . ' or later.');
}

// Define _JEXEC constant
define('_JEXEC', 1);

// Define JPATH_BASE for administrator context BEFORE loading defines
define('JPATH_BASE', __DIR__);

// Load instance-specific path definitions
require_once dirname(__DIR__) . '/defines.php';

// Saves the start time and memory usage
$startTime = microtime(1);
$startMem  = memory_get_usage();

// Check for vendor dependencies
if (!file_exists(JPATH_LIBRARIES . '/vendor/autoload.php')) {
    die('Joomla vendor dependencies not found.');
}

// Load Joomla's defines.php to set JPATH_THEMES and other constants
require_once JPATH_LIBRARIES . '/../includes/defines.php';

// Load administrator framework from shared core
require_once JPATH_LIBRARIES . '/../administrator/includes/framework.php';

// Set profiler start time
JDEBUG && \Joomla\CMS\Profiler\Profiler::getInstance('Application')->setStart($startTime, $startMem)->mark('afterLoad');

// Boot the DI container
$container = \Joomla\CMS\Factory::getContainer();

// Alias session service keys
$container->alias('session.web', 'session.web.administrator')
    ->alias('session', 'session.web.administrator')
    ->alias('JSession', 'session.web.administrator')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.web.administrator')
    ->alias(\Joomla\Session\Session::class, 'session.web.administrator')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.administrator');

// Instantiate the administrator application
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);

// Set as global app
\Joomla\CMS\Factory::$application = $app;

// Execute
$app->execute();
