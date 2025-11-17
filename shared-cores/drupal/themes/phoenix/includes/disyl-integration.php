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
  $template_path = $theme_path . '/disyl/' . $template_name . '.disyl';
  
  if (!file_exists($template_path)) {
    \Drupal::logger('phoenix')->error('DiSyL template not found: @template', [
      '@template' => $template_path,
    ]);
    return '';
  }
  
  // Load DiSyL renderer
  $renderer_path = DRUPAL_ROOT . '/../kernel/DiSyL/Renderers/DrupalRenderer.php';
  if (!file_exists($renderer_path)) {
    \Drupal::logger('phoenix')->error('DrupalRenderer not found at: @path', [
      '@path' => $renderer_path,
    ]);
    return '';
  }
  
  require_once $renderer_path;
  
  try {
    $renderer = new \IkabudKernel\Core\DiSyL\Renderers\DrupalRenderer();
    $template_content = file_get_contents($template_path);
    
    // Merge context with Drupal-specific data
    $drupal_context = phoenix_get_drupal_context();
    $merged_context = array_merge($drupal_context, $context);
    
    $output = $renderer->render($template_content, $merged_context);
    
    return Markup::create($output);
  }
  catch (\Exception $e) {
    \Drupal::logger('phoenix')->error('DiSyL rendering error: @message', [
      '@message' => $e->getMessage(),
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
  
  $context = [
    'site' => [
      'name' => $config->get('name'),
      'slogan' => $config->get('slogan'),
      'theme_url' => '/' . $theme_path,
      'base_url' => \Drupal::request()->getSchemeAndHttpHost(),
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
