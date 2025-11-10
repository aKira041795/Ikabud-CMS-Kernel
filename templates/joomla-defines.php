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
\define('JPATH_LIBRARIES', $sharedCore . '/libraries');
\define('JPATH_PLUGINS', $sharedCore . '/plugins');
// Don't define JPATH_THEMES - let Joomla set it based on context (site vs admin)

// Instance-specific paths - these must be writable
\define('JPATH_ADMINISTRATOR', $instanceDir . '/administrator');
\define('JPATH_CACHE', $instanceDir . '/administrator/cache');
\define('JPATH_MANIFESTS', $instanceDir . '/administrator/manifests');

// Installation path - point to non-existent directory to prevent redirect
\define('JPATH_INSTALLATION', $instanceDir . '/installation');

// API and CLI paths - shared from core
\define('JPATH_API', $sharedCore . '/api');
\define('JPATH_CLI', $sharedCore . '/cli');
