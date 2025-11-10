<?php
/**
 * Drupal installer wrapper for Ikabud Kernel instance
 * Sets the correct application root before loading the shared core installer
 */

// The shared core installer does chdir('..') to go from /core to root
// So we need to be IN the /core directory when we call it
// That way chdir('..') brings us to the instance root

// We're already in /core, so the shared installer's chdir('..') will work correctly
// It will change to the instance root (parent of this /core directory)

// Now include the actual Drupal installer from shared core
// It will do chdir('..') which takes us from /core to instance root
require_once __DIR__ . '/../../../shared-cores/drupal/core/install.php';
