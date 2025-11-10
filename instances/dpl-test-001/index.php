<?php

/**
 * @file
 * The PHP page that serves all page requests on a Drupal installation.
 * 
 * Custom index.php for Ikabud Kernel instance
 * This ensures Drupal uses the instance's sites directory, not the shared core's
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Set the application root to this instance directory
$app_root = __DIR__;
chdir($app_root);

$autoloader = require_once 'autoload.php';

// Pass the app_root to DrupalKernel so it uses this instance's sites directory
$kernel = new DrupalKernel('prod', $autoloader, FALSE, $app_root);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
