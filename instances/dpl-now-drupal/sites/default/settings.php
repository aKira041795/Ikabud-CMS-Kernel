<?php

/**
 * Drupal Configuration
 * Ikabud Kernel Instance: dpl-now-drupal
 * Generated: {date('D M j H:i:s T Y')}
 */

// Database settings
$databases['default']['default'] = [
  'database' => 'ikabud_drupal_new',
  'username' => 'root',
  'password' => 'Nds90@NXIOVRH*iy',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

// Salt for one-time login links, cancel links, form tokens, etc.
$settings['hash_salt'] = 'a0c3fe0c3be6053df7810bcc532a70ba1b41aa41ce56cea543a7cf9d67717e6e';

// Location of the site configuration files
$settings['config_sync_directory'] = '/var/www/html/ikabud-kernel/instances/dpl-now-drupal/sites/default/files/config/sync';

// Private file path
$settings['file_private_path'] = '/var/www/html/ikabud-kernel/instances/dpl-now-drupal/sites/default/private';

// Temporary directory
$settings['file_temp_path'] = '/tmp';

// Trusted host patterns
$settings['trusted_host_patterns'] = [
  '^' . preg_quote('genesis.test') . '$',
  '^admin\.' . preg_quote('genesis.test') . '$',
  '^backend\.' . preg_quote('genesis.test') . '$',
];

// Reverse proxy configuration (for Ikabud Kernel)
$settings['reverse_proxy'] = FALSE;

// File system settings
$settings['file_public_path'] = 'sites/default/files';
$settings['file_public_base_url'] = '';

// Update free access (set to FALSE after installation)
$settings['update_free_access'] = FALSE;

// Skip file system permissions hardening
$settings['skip_permissions_hardening'] = TRUE;

// Ikabud Kernel Integration
$settings['ikabud_instance_id'] = 'dpl-now-drupal';
$settings['ikabud_kernel_path'] = dirname(dirname(__DIR__)) . '/kernel';

// Base URL (dynamic based on current host)
$base_url = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'genesis.test');

// Development settings (disable in production)
// $config['system.logging']['error_level'] = 'verbose';
// $config['system.performance']['css']['preprocess'] = FALSE;
// $config['system.performance']['js']['preprocess'] = FALSE;
