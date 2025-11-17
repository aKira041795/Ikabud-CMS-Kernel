<?php

namespace IkabudKernel\Core\DiSyL\Renderers;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;

/**
 * Drupal-specific DiSyL Renderer
 * 
 * This renderer provides Drupal-native functionality through DiSyL templates,
 * respecting Drupal's architecture and APIs.
 */
class DrupalRenderer extends BaseRenderer
{
    /**
     * {@inheritdoc}
     */
    protected function initializeCMS(): void
    {
        $this->cms = 'drupal';
        $this->loadManifests();
        $this->registerDrupalComponents();
        $this->registerDrupalFilters();
    }

    /**
     * Register Drupal-specific components.
     */
    protected function registerDrupalComponents(): void
    {
        // Register Drupal block rendering
        $this->registerComponent('drupal_block', [$this, 'renderDrupalBlock']);
        
        // Register Drupal region rendering
        $this->registerComponent('drupal_region', [$this, 'renderDrupalRegion']);
        
        // Register Drupal menu rendering
        $this->registerComponent('drupal_menu', [$this, 'renderDrupalMenu']);
        
        // Register Drupal view rendering
        $this->registerComponent('drupal_view', [$this, 'renderDrupalView']);
        
        // Register Drupal form rendering
        $this->registerComponent('drupal_form', [$this, 'renderDrupalForm']);
    }

    /**
     * Register Drupal-specific filters.
     */
    protected function registerDrupalFilters(): void
    {
        // HTML escaping
        $this->registerFilter('esc_html', function($value) {
            return Html::escape($value);
        });
        
        // URL escaping
        $this->registerFilter('esc_url', function($value) {
            return Xss::filterAdmin($value);
        });
        
        // Attribute escaping
        $this->registerFilter('esc_attr', function($value) {
            return Html::escape($value);
        });
        
        // Date formatting
        $this->registerFilter('date', function($value, $format = 'medium') {
            if (is_numeric($value)) {
                return \Drupal::service('date.formatter')->format($value, $format);
            }
            return $value;
        });
        
        // Truncate text
        $this->registerFilter('truncate', function($value, $length = 100, $append = '...') {
            if (mb_strlen($value) > $length) {
                return mb_substr($value, 0, $length) . $append;
            }
            return $value;
        });
        
        // Strip tags
        $this->registerFilter('strip_tags', function($value) {
            return strip_tags($value);
        });
        
        // Translate
        $this->registerFilter('t', function($value) {
            return t($value);
        });
    }

    /**
     * Render a Drupal block.
     *
     * @param array $node
     * @param array $attrs
     * @param array $children
     * @return string
     */
    protected function renderDrupalBlock(array $node, array $attrs, array $children): string
    {
        $block_id = $attrs['id'] ?? '';
        
        if (empty($block_id)) {
            return '<!-- drupal_block: no id specified -->';
        }
        
        try {
            $block = \Drupal::entityTypeManager()
                ->getStorage('block')
                ->load($block_id);
            
            if (!$block || !$block->access('view')) {
                return '';
            }
            
            $render = \Drupal::entityTypeManager()
                ->getViewBuilder('block')
                ->view($block);
            
            return \Drupal::service('renderer')->renderPlain($render);
        }
        catch (\Exception $e) {
            return '<!-- drupal_block error: ' . Html::escape($e->getMessage()) . ' -->';
        }
    }

    /**
     * Render a Drupal region.
     *
     * @param array $node
     * @param array $attrs
     * @param array $children
     * @return string
     */
    protected function renderDrupalRegion(array $node, array $attrs, array $children): string
    {
        $region = $attrs['name'] ?? '';
        
        if (empty($region)) {
            return '<!-- drupal_region: no name specified -->';
        }
        
        try {
            $blocks = \Drupal::entityTypeManager()
                ->getStorage('block')
                ->loadByProperties([
                    'theme' => \Drupal::theme()->getActiveTheme()->getName(),
                    'region' => $region,
                ]);
            
            if (empty($blocks)) {
                return '';
            }
            
            $output = '';
            foreach ($blocks as $block) {
                if ($block->access('view')) {
                    $render = \Drupal::entityTypeManager()
                        ->getViewBuilder('block')
                        ->view($block);
                    $output .= \Drupal::service('renderer')->renderPlain($render);
                }
            }
            
            return $output;
        }
        catch (\Exception $e) {
            return '<!-- drupal_region error: ' . Html::escape($e->getMessage()) . ' -->';
        }
    }

    /**
     * Render a Drupal menu.
     *
     * @param array $node
     * @param array $attrs
     * @param array $children
     * @return string
     */
    protected function renderDrupalMenu(array $node, array $attrs, array $children): string
    {
        $menu_name = $attrs['name'] ?? 'main';
        $level = isset($attrs['level']) ? (int)$attrs['level'] : 1;
        $depth = isset($attrs['depth']) ? (int)$attrs['depth'] : 0;
        
        try {
            $menu_tree = \Drupal::menuTree();
            $parameters = $menu_tree->getCurrentRouteMenuTreeParameters($menu_name);
            
            if ($level > 1) {
                $parameters->setMinDepth($level);
            }
            
            if ($depth > 0) {
                $parameters->setMaxDepth($level + $depth - 1);
            }
            
            $tree = $menu_tree->load($menu_name, $parameters);
            $manipulators = [
                ['callable' => 'menu.default_tree_manipulators:checkAccess'],
                ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
            ];
            $tree = $menu_tree->transform($tree, $manipulators);
            $build = $menu_tree->build($tree);
            
            return \Drupal::service('renderer')->renderPlain($build);
        }
        catch (\Exception $e) {
            return '<!-- drupal_menu error: ' . Html::escape($e->getMessage()) . ' -->';
        }
    }

    /**
     * Render a Drupal view.
     *
     * @param array $node
     * @param array $attrs
     * @param array $children
     * @return string
     */
    protected function renderDrupalView(array $node, array $attrs, array $children): string
    {
        $view_id = $attrs['id'] ?? '';
        $display_id = $attrs['display'] ?? 'default';
        
        if (empty($view_id)) {
            return '<!-- drupal_view: no id specified -->';
        }
        
        try {
            $view = \Drupal\views\Views::getView($view_id);
            
            if (!$view || !$view->access($display_id)) {
                return '';
            }
            
            $view->setDisplay($display_id);
            $view->preExecute();
            $view->execute();
            
            $render = $view->buildRenderable($display_id);
            
            return \Drupal::service('renderer')->renderPlain($render);
        }
        catch (\Exception $e) {
            return '<!-- drupal_view error: ' . Html::escape($e->getMessage()) . ' -->';
        }
    }

    /**
     * Render a Drupal form.
     *
     * @param array $node
     * @param array $attrs
     * @param array $children
     * @return string
     */
    protected function renderDrupalForm(array $node, array $attrs, array $children): string
    {
        $form_id = $attrs['id'] ?? '';
        
        if (empty($form_id)) {
            return '<!-- drupal_form: no id specified -->';
        }
        
        try {
            $form = \Drupal::formBuilder()->getForm($form_id);
            return \Drupal::service('renderer')->renderPlain($form);
        }
        catch (\Exception $e) {
            return '<!-- drupal_form error: ' . Html::escape($e->getMessage()) . ' -->';
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function evaluateCondition(string $condition, array $context): bool
    {
        // Add Drupal-specific condition helpers
        $context['drupal_user_logged_in'] = \Drupal::currentUser()->isAuthenticated();
        $context['drupal_is_front'] = \Drupal::service('path.matcher')->isFrontPage();
        
        return parent::evaluateCondition($condition, $context);
    }
}
