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
        $this->registerCoreComponents();
        $this->registerDrupalComponents();
        $this->registerDrupalFilters();
    }
    
    /**
     * Register core DiSyL components.
     */
    protected function registerCoreComponents(): void
    {
        // ikb_text - Text with typography
        $this->registerComponent('ikb_text', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            $children = $this->renderChildren($node['children'] ?? []);
            
            $size = $attrs['size'] ?? 'md';
            $weight = $attrs['weight'] ?? 'normal';
            $align = $attrs['align'] ?? '';
            $class = $attrs['class'] ?? '';
            $margin = $attrs['margin'] ?? '';
            
            $classes = ['ikb-text', "ikb-text-{$size}", "font-{$weight}"];
            if ($align) $classes[] = "text-{$align}";
            if ($class) $classes[] = $class;
            if ($margin) $classes[] = "margin-{$margin}";
            
            return '<div class="' . implode(' ', $classes) . '">' . $children . '</div>';
        });
        
        // ikb_container - Container with max-width
        $this->registerComponent('ikb_container', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            $children = $this->renderChildren($node['children'] ?? []);
            
            $size = $attrs['size'] ?? 'lg';
            $class = $attrs['class'] ?? '';
            
            $classes = ['ikb-container', "ikb-container-{$size}"];
            if ($class) $classes[] = $class;
            
            return '<div class="' . implode(' ', $classes) . '">' . $children . '</div>';
        });
        
        // ikb_section - Section with background and padding
        $this->registerComponent('ikb_section', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            $children = $this->renderChildren($node['children'] ?? []);
            
            $type = $attrs['type'] ?? '';
            $padding = $attrs['padding'] ?? 'normal';
            $class = $attrs['class'] ?? '';
            $id = $attrs['id'] ?? '';
            
            $classes = ['ikb-section'];
            if ($type) $classes[] = "ikb-section-{$type}";
            $classes[] = "padding-{$padding}";
            if ($class) $classes[] = $class;
            
            $idAttr = $id ? " id=\"{$id}\"" : '';
            
            return "<section class=\"" . implode(' ', $classes) . "\"{$idAttr}>" . $children . '</section>';
        });
        
        // ikb_image - Responsive image
        $this->registerComponent('ikb_image', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            
            $src = $attrs['src'] ?? '';
            $alt = $attrs['alt'] ?? '';
            $class = $attrs['class'] ?? '';
            $lazy = isset($attrs['lazy']) && $attrs['lazy'] === 'true';
            
            $classes = ['ikb-image'];
            if ($class) $classes[] = $class;
            
            $loading = $lazy ? ' loading="lazy"' : '';
            
            return "<img src=\"{$src}\" alt=\"{$alt}\" class=\"" . implode(' ', $classes) . "\"{$loading} />";
        });
        
        // ikb_include - Include template
        $this->registerComponent('ikb_include', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            $template = $attrs['template'] ?? '';
            
            if (empty($template)) {
                return '<!-- ikb_include: no template specified -->';
            }
            
            $theme_path = \Drupal::service('extension.list.theme')->getPath('phoenix');
            $drupal_root = \Drupal::root();
            $theme_path_absolute = $drupal_root . '/' . $theme_path;
            $template_path = $theme_path_absolute . '/disyl/' . $template;
            
            if (!file_exists($template_path)) {
                return "<!-- ikb_include: template not found: {$template} -->";
            }
            
            // Recursively render the included template
            try {
                $engine = new \IkabudKernel\Core\DiSyL\Engine();
                $renderer = new self();
                return $engine->renderFile($template_path, $renderer, $context);
            }
            catch (\Exception $e) {
                return '<!-- ikb_include error: ' . Html::escape($e->getMessage()) . ' -->';
            }
        });
        
        // ikb_query - Data query/loop
        $this->registerComponent('ikb_query', function($node, $context) {
            $attrs = $node['attrs'] ?? [];
            $children = $node['children'] ?? [];
            $type = $attrs['type'] ?? 'post';
            $limit = isset($attrs['limit']) ? (int)$attrs['limit'] : 10;
            $orderby = $attrs['orderby'] ?? 'created';
            $order = $attrs['order'] ?? 'DESC';
            
            try {
                // Map type to Drupal content type
                $bundle = $type === 'post' ? 'article' : $type;
                
                // Query Drupal nodes
                $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
                    ->condition('type', $bundle)
                    ->condition('status', 1)
                    ->sort($orderby, $order)
                    ->range(0, $limit)
                    ->accessCheck(TRUE);
                
                $nids = $query->execute();
                
                // Split children into query-block and empty-block
                $queryChildren = [];
                $emptyChildren = [];
                $inEmptyBlock = false;
                
                foreach ($children as $child) {
                    // Check if this is an {empty} tag
                    if (isset($child['type']) && $child['type'] === 'tag' && 
                        isset($child['name']) && $child['name'] === 'empty') {
                        $inEmptyBlock = true;
                        continue; // Skip the {empty} tag itself
                    }
                    
                    if ($inEmptyBlock) {
                        $emptyChildren[] = $child;
                    } else {
                        $queryChildren[] = $child;
                    }
                }
                
                // If no results found, render empty block
                if (empty($nids)) {
                    if (!empty($emptyChildren)) {
                        return $this->renderChildren($emptyChildren);
                    }
                    return '<!-- No posts found -->';
                }
                
                $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
                $output = '';
                
                // Render children for each node
                foreach ($nodes as $node_entity) {
                    // Create item context
                    $item_context = [
                        'id' => $node_entity->id(),
                        'title' => $node_entity->getTitle(),
                        'url' => $node_entity->toUrl()->toString(),
                        'date' => $node_entity->getCreatedTime(),
                        'changed' => $node_entity->getChangedTime(),
                        'author' => $node_entity->getOwner()->getDisplayName(),
                        'author_id' => $node_entity->getOwnerId(),
                        'type' => $node_entity->bundle(),
                        'published' => $node_entity->isPublished(),
                    ];
                    
                    error_log('[DiSyL ikb_query] Processing node: ' . $node_entity->id() . ' - ' . $node_entity->getTitle());
                    error_log('[DiSyL ikb_query] Item context: ' . json_encode($item_context));
                    
                    // Get thumbnail if available
                    if ($node_entity->hasField('field_image') && !$node_entity->get('field_image')->isEmpty()) {
                        $image = $node_entity->get('field_image')->entity;
                        if ($image) {
                            $item_context['thumbnail'] = \Drupal::service('file_url_generator')->generateAbsoluteString($image->getFileUri());
                        }
                    }
                    
                    // Get excerpt/body
                    if ($node_entity->hasField('body') && !$node_entity->get('body')->isEmpty()) {
                        $body = $node_entity->get('body')->value;
                        $item_context['excerpt'] = strip_tags($body);
                        $item_context['content'] = $body;
                    }
                    
                    // Merge with parent context and add item
                    $loop_context = array_merge($context, ['item' => $item_context]);
                    
                    // Render query children with item context
                    foreach ($queryChildren as $child) {
                        $output .= $this->renderNode($child, $loop_context);
                    }
                }
                
                return $output;
            }
            catch (\Exception $e) {
                error_log('[DiSyL ikb_query] Exception: ' . $e->getMessage());
                error_log('[DiSyL ikb_query] Trace: ' . $e->getTraceAsString());
                return '<!-- ikb_query error: ' . Html::escape($e->getMessage()) . ' | File: ' . $e->getFile() . ':' . $e->getLine() . ' -->';
            }
        });
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
        
        // Register raw HTML output component
        $this->registerComponent('raw_html', [$this, 'renderRawHtml']);
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
        $this->registerFilter('date', function($value, ...$args) {
            // Handle both positional and named parameters
            $format = 'medium';
            if (!empty($args)) {
                // Check if first arg is an array (named params)
                if (is_array($args[0])) {
                    $format = $args[0]['format'] ?? 'medium';
                } else {
                    $format = $args[0];
                }
            }
            
            if (is_numeric($value)) {
                // If format is a PHP date format (not Drupal format type)
                if (strpos($format, ' ') !== false || strlen($format) > 10) {
                    return date($format, $value);
                }
                return \Drupal::service('date.formatter')->format($value, $format);
            }
            return $value;
        });
        
        // Truncate text
        $this->registerFilter('truncate', function($value, ...$args) {
            $length = 100;
            $append = '...';
            
            if (!empty($args)) {
                if (is_array($args[0])) {
                    // Named parameters
                    $length = $args[0]['length'] ?? 100;
                    $append = $args[0]['append'] ?? '...';
                } else {
                    // Positional parameters
                    $length = $args[0] ?? 100;
                    $append = $args[1] ?? '...';
                }
            }
            
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
    protected function renderDrupalBlock(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
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
    protected function renderDrupalRegion(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
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
    protected function renderDrupalMenu(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
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
    protected function renderDrupalView(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
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
    protected function renderDrupalForm(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
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
     * Render raw HTML without escaping.
     * 
     * @param array $node
     * @param array $context
     * @return string
     */
    protected function renderRawHtml(array $node, array $context): string
    {
        $attrs = $node['attrs'] ?? [];
        $content = $attrs['content'] ?? '';
        
        error_log('[DiSyL raw_html] Received content attribute: ' . $content);
        
        // Evaluate expression if it's a variable reference
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $content)) {
            $evaluated = $this->evaluateExpression($content);
            error_log('[DiSyL raw_html] Evaluated to: ' . substr($evaluated ?? 'NULL', 0, 100));
            $content = $evaluated;
        }
        
        // Return raw HTML without escaping
        return $content ?? '';
    }
    
    /**
     * Render conditional (if) statement
     */
    protected function renderIf(array $node, array $attrs, array $children): string
    {
        $condition = $attrs['condition'] ?? '';
        
        // Evaluate condition
        $result = $this->evaluateCondition($condition);
        
        if ($result) {
            return $this->renderChildren($children);
        }
        
        return '';
    }
    
    /**
     * Evaluate condition expression
     * Supports: ||, &&, >, <, >=, <=, ==, !=
     */
    protected function evaluateCondition(string $condition): bool
    {
        // Handle OR operator (||)
        if (strpos($condition, '||') !== false) {
            $parts = explode('||', $condition);
            foreach ($parts as $part) {
                if ($this->evaluateCondition(trim($part))) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle AND operator (&&)
        if (strpos($condition, '&&') !== false) {
            $parts = explode('&&', $condition);
            foreach ($parts as $part) {
                if (!$this->evaluateCondition(trim($part))) {
                    return false;
                }
            }
            return true;
        }
        
        // Handle comparison operators
        $operators = ['>=', '<=', '==', '!=', '>', '<'];
        foreach ($operators as $op) {
            if (strpos($condition, $op) !== false) {
                $parts = explode($op, $condition, 2);
                if (count($parts) === 2) {
                    $left = trim($parts[0]);
                    $right = trim($parts[1]);
                    
                    // Evaluate both sides
                    $leftVal = $this->evaluateExpression($left);
                    $rightVal = $this->evaluateExpression($right);
                    
                    // Perform comparison
                    return match($op) {
                        '>=' => $leftVal >= $rightVal,
                        '<=' => $leftVal <= $rightVal,
                        '==' => $leftVal == $rightVal,
                        '!=' => $leftVal != $rightVal,
                        '>' => $leftVal > $rightVal,
                        '<' => $leftVal < $rightVal,
                        default => false
                    };
                }
            }
        }
        
        // Simple evaluation (truthy check)
        $value = $this->evaluateExpression($condition);
        return (bool)$value;
    }
}
