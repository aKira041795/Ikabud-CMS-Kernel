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

// Define instance-specific paths BEFORE loading Joomla
define('JPATH_BASE', __DIR__);
define('JPATH_CACHE', __DIR__ . '/administrator/cache');

// Run the application from shared core
require_once __DIR__ . '/../../shared-cores/joomla/includes/app.php';
