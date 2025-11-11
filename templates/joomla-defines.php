<?php
/**
 * Joomla Instance Path Definitions
 * This file is loaded BEFORE the shared core's defines.php
 * to ensure instance-specific paths are used
 */

\defined('_JEXEC') or die;

// Get the instance directory (where this file is located)
$instanceDir = __DIR__;

// Define all JPATH constants to point to instance-specific locations
// These will NOT be redefined by the shared core's defines.php because it uses \defined() || \define()

// Base paths - point to instance directory (only if not already defined)
\defined('JPATH_BASE') || \define('JPATH_BASE', $instanceDir);
\defined('JPATH_ROOT') || \define('JPATH_ROOT', $instanceDir);
\defined('JPATH_SITE') || \define('JPATH_SITE', $instanceDir);
\defined('JPATH_PUBLIC') || \define('JPATH_PUBLIC', $instanceDir);
\defined('JPATH_CONFIGURATION') || \define('JPATH_CONFIGURATION', $instanceDir);

// Shared core paths - point to shared Joomla core
$sharedCore = dirname(dirname($instanceDir)) . '/shared-cores/joomla';
\defined('JPATH_LIBRARIES') || \define('JPATH_LIBRARIES', $sharedCore . '/libraries');
\defined('JPATH_PLUGINS') || \define('JPATH_PLUGINS', $sharedCore . '/plugins');

// JPATH_THEMES - set based on context (JPATH_BASE determines site vs admin)
\defined('JPATH_THEMES') || \define('JPATH_THEMES', \defined('JPATH_BASE') ? JPATH_BASE . '/templates' : $instanceDir . '/templates');

// Instance-specific paths - these must be writable
\defined('JPATH_ADMINISTRATOR') || \define('JPATH_ADMINISTRATOR', $instanceDir . '/administrator');
\defined('JPATH_CACHE') || \define('JPATH_CACHE', $instanceDir . '/administrator/cache');
\defined('JPATH_MANIFESTS') || \define('JPATH_MANIFESTS', $instanceDir . '/administrator/manifests');

// Installation path - point to instance directory
\defined('JPATH_INSTALLATION') || \define('JPATH_INSTALLATION', $instanceDir . '/installation');

// API and CLI paths - shared from core
\defined('JPATH_API') || \define('JPATH_API', $sharedCore . '/api');
\defined('JPATH_CLI') || \define('JPATH_CLI', $sharedCore . '/cli');
