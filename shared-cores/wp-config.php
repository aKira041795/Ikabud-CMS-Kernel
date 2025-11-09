<?php
/**
 * Ikabud Kernel - WordPress Config Bootstrap
 * This file is loaded by wp-load.php when wp-config.php is not found in ABSPATH
 * It determines which instance's wp-config.php to load
 */

// Determine instance directory
$instanceDir = null;

// Method 1: Check $_SERVER variable (set by kernel routing)
if (isset($_SERVER['IKABUD_INSTANCE_ID'])) {
    $instanceDir = __DIR__ . '/../instances/' . $_SERVER['IKABUD_INSTANCE_ID'];
}
// Method 2: Check DOCUMENT_ROOT (set by Apache for direct access)
elseif (isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], '/instances/') !== false) {
    // Extract instance directory from DOCUMENT_ROOT
    $instanceDir = $_SERVER['DOCUMENT_ROOT'];
}
// Method 3: Fallback - try to detect from script filename
elseif (isset($_SERVER['SCRIPT_FILENAME']) && strpos($_SERVER['SCRIPT_FILENAME'], '/instances/') !== false) {
    preg_match('#(/var/www/html/ikabud-kernel/instances/[^/]+)#', $_SERVER['SCRIPT_FILENAME'], $matches);
    if (!empty($matches[1])) {
        $instanceDir = $matches[1];
    }
}

if (!$instanceDir || !is_dir($instanceDir)) {
    die('Error: Could not determine instance directory. DOCUMENT_ROOT: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . ', SCRIPT_FILENAME: ' . ($_SERVER['SCRIPT_FILENAME'] ?? 'not set'));
}

// Load instance-specific wp-config.php
$instanceConfigFile = $instanceDir . '/wp-config.php';
if (file_exists($instanceConfigFile)) {
    require_once $instanceConfigFile;
} else {
    die('Error: wp-config.php not found at: ' . $instanceConfigFile);
}
