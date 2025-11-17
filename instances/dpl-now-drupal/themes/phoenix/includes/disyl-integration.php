<?php

/**
 * @file
 * DiSyL Integration for Drupal Phoenix Theme
 * 
 * This file provides the integration layer between Drupal and DiSyL,
 * allowing DiSyL templates to work with Drupal's architecture.
 */

use Drupal\Core\Render\Markup;

/**
 * Render a DiSyL template.
 *
 * @param string $template_name
 *   The name of the DiSyL template file (without .disyl extension).
 * @param array $context
 *   Additional context variables to pass to the template.
 *
 * @return string
 *   The rendered HTML output.
 */
function phoenix_render_disyl($template_name, array $context = []) {
  $theme_path = \Drupal::service('extension.list.theme')->getPath('phoenix');
  $drupal_root = \Drupal::root();
  $theme_path_absolute = $drupal_root . '/' . $theme_path;
  $template_path = $theme_path_absolute . '/disyl/' . $template_name . '.disyl';
  
  if (!file_exists($template_path)) {
    \Drupal::logger('phoenix')->error('DiSyL template not found: @template', [
      '@template' => $template_path,
    ]);
    return '';
  }
  
  // Load DiSyL classes
  // Use absolute theme path to find kernel
  // theme_path_absolute is /var/www/html/ikabud-kernel/instances/dpl-now-drupal/themes/phoenix
  // Go up 4 levels: phoenix -> themes -> dpl-now-drupal -> instances -> ikabud-kernel
  $ikabud_root = dirname(dirname(dirname(dirname($theme_path_absolute))));
  $kernel_path = $ikabud_root . '/kernel/DiSyL';
  if (!file_exists($kernel_path)) {
    \Drupal::logger('phoenix')->error('DiSyL kernel not found at: @path (theme_path: @theme)', [
      '@path' => $kernel_path,
      '@theme' => $theme_path,
    ]);
    return '';
  }
  
  // Require all necessary DiSyL files (order matters!)
  require_once $kernel_path . '/Token.php';
  require_once $kernel_path . '/Lexer.php';
  require_once $kernel_path . '/ParserError.php';
  require_once $kernel_path . '/Grammar.php';
  require_once $kernel_path . '/ComponentRegistry.php';
  require_once $kernel_path . '/ManifestLoader.php';
  require_once $kernel_path . '/ModularManifestLoader.php';
  require_once $kernel_path . '/Parser.php';
  require_once $kernel_path . '/Compiler.php';
  require_once $kernel_path . '/Renderers/BaseRenderer.php';
  require_once $kernel_path . '/Renderers/DrupalRenderer.php';
  require_once $kernel_path . '/Engine.php';
  
  try {
    // Disable Drupal page cache for DiSyL rendering
    \Drupal::service('page_cache_kill_switch')->trigger();
    
    // Initialize ModularManifestLoader with Drupal profile
    \IkabudKernel\Core\DiSyL\ModularManifestLoader::init('full', 'drupal');
    
    // Create DiSyL engine and renderer
    $engine = new \IkabudKernel\Core\DiSyL\Engine();
    $renderer = new \IkabudKernel\Core\DiSyL\Renderers\DrupalRenderer();
    
    // Merge context with Drupal-specific data
    $drupal_context = phoenix_get_drupal_context();
    $merged_context = array_merge($drupal_context, $context);
    
    // Compile and render the template
    $output = $engine->renderFile($template_path, $renderer, $merged_context);
    
    // Debug: Save output to file
    $debug_file = '/var/www/html/ikabud-kernel/disyl_debug_output.html';
    file_put_contents($debug_file, $output);
    \Drupal::logger('phoenix')->notice('DiSyL output saved to: @file', ['@file' => $debug_file]);
    
    return Markup::create($output);
  }
  catch (\Exception $e) {
    \Drupal::logger('phoenix')->error('DiSyL rendering error: @message in @file:@line. Trace: @trace', [
      '@message' => $e->getMessage(),
      '@file' => $e->getFile(),
      '@line' => $e->getLine(),
      '@trace' => $e->getTraceAsString(),
    ]);
    return '';
  }
}

/**
 * Get Drupal-specific context for DiSyL templates.
 *
 * @return array
 *   Context array with Drupal data.
 */
function phoenix_get_drupal_context() {
  $config = \Drupal::config('system.site');
  $theme_path = \Drupal::service('extension.list.theme')->getPath('phoenix');
  $current_user = \Drupal::currentUser();
  $route_match = \Drupal::routeMatch();
  
  // Get theme logo if configured
  $theme_config = \Drupal::config('phoenix.settings');
  $logo_path = theme_get_setting('logo.path', 'phoenix');
  
  $context = [
    'site' => [
      'name' => $config->get('name'),
      'slogan' => $config->get('slogan'),
      'theme_url' => '/' . $theme_path,
      'base_url' => \Drupal::request()->getSchemeAndHttpHost(),
      'logo' => $logo_path ? '/' . $logo_path : '',
      'show_features' => FALSE, // Hide features section by default
    ],
    'user' => [
      'is_logged_in' => $current_user->isAuthenticated(),
      'name' => $current_user->getDisplayName(),
      'uid' => $current_user->id(),
    ],
    'drupal' => [
      'version' => \Drupal::VERSION,
      'route_name' => $route_match->getRouteName(),
    ],
  ];
  
  // Add node context if available
  if ($node = $route_match->getParameter('node')) {
    $context['node'] = [
      'id' => $node->id(),
      'title' => $node->getTitle(),
      'type' => $node->bundle(),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
      'author' => $node->getOwner()->getDisplayName(),
      'published' => $node->isPublished(),
    ];
    
    // Add content/body
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $context['node']['content'] = $body->getValue()['value'];
      $context['node']['summary'] = $body->getValue()['summary'] ?? '';
      \Drupal::logger('phoenix')->notice('Node content added: ' . substr($context['node']['content'], 0, 100));
    } else {
      \Drupal::logger('phoenix')->warning('Node has no body field or is empty');
    }
    
    // Add featured image
    if ($node->hasField('field_image') && !$node->get('field_image')->isEmpty()) {
      $image = $node->get('field_image')->entity;
      if ($image) {
        $context['node']['thumbnail'] = \Drupal::service('file_url_generator')->generateAbsoluteString($image->getFileUri());
      }
    }
    
    // Add post-specific context for articles
    if ($node->bundle() === 'article') {
      $context['post'] = $context['node'];
      $context['post']['date'] = date('M j, Y', $node->getCreatedTime());
      $context['post']['url'] = $node->toUrl()->toString();
      $context['post']['author_url'] = '/user/' . $node->getOwnerId();
    }
  }
  
  return $context;
}

/**
 * Check if a region has content.
 *
 * @param string $region
 *   The region machine name.
 *
 * @return bool
 *   TRUE if the region has content, FALSE otherwise.
 */
function phoenix_region_has_content($region) {
  $blocks = \Drupal::entityTypeManager()
    ->getStorage('block')
    ->loadByProperties([
      'theme' => 'phoenix',
      'region' => $region,
    ]);
  
  return !empty($blocks);
}

/**
 * Get blocks in a region.
 *
 * @param string $region
 *   The region machine name.
 *
 * @return array
 *   Array of rendered blocks.
 */
function phoenix_get_region_blocks($region) {
  $blocks = \Drupal::entityTypeManager()
    ->getStorage('block')
    ->loadByProperties([
      'theme' => 'phoenix',
      'region' => $region,
    ]);
  
  $build = [];
  foreach ($blocks as $block) {
    if ($block->access('view')) {
      $build[] = \Drupal::entityTypeManager()
        ->getViewBuilder('block')
        ->view($block);
    }
  }
  
  return $build;
}
