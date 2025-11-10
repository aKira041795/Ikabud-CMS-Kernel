<?php

/**
 * CLI installer for Drupal - bypasses batch processing issues
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';

$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();

// Run the installation
$install_state = [
  'interactive' => FALSE,
  'parameters' => [
    'langcode' => 'en',
    'profile' => 'standard',
    'site_name' => 'Test Drupal Site',
    'site_mail' => 'admin@drupal.test',
    'account' => [
      'name' => 'admin',
      'mail' => 'admin@drupal.test',
      'pass' => 'admin123',
    ],
  ],
];

require_once 'core/includes/install.inc';
require_once 'core/includes/install.core.inc';

try {
  install_drupal($autoloader, $install_state);
  echo "Installation completed successfully!\n";
} catch (Exception $e) {
  echo "Installation failed: " . $e->getMessage() . "\n";
  echo $e->getTraceAsString() . "\n";
}
