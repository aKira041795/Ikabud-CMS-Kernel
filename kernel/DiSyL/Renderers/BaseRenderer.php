<?php
/**
 * DiSyL Base Renderer
 * 
 * Abstract base class for DiSyL renderers
 * Provides common rendering logic that can be extended by CMS-specific renderers
 * 
 * @version 0.1.0
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

abstract class BaseRenderer
{
    protected array $context = [];
    protected array $components = [];
    
    /**
     * Render AST to HTML
     * 
     * @param array $ast Compiled DiSyL AST
     * @param array $context Rendering context
     * @return string Rendered HTML
     */
    public function render(array $ast, array $context = []): string
    {
        $this->context = $context;
        
        if ($ast['type'] !== 'document') {
            throw new \Exception('Invalid AST: root must be a document node');
        }
        
        return $this->renderChildren($ast['children'] ?? []);
    }
    
    /**
     * Render array of child nodes
     */
    protected function renderChildren(array $children): string
    {
        $html = '';
        
        foreach ($children as $child) {
            $html .= $this->renderNode($child);
        }
        
        return $html;
    }
    
    /**
     * Render a single node
     */
    protected function renderNode(array $node): string
    {
        return match($node['type']) {
            'tag' => $this->renderTag($node),
            'text' => $this->renderText($node),
            'expression' => $this->renderExpression($node),
            'comment' => $this->renderComment($node),
            default => ''
        };
    }
    
    /**
     * Render a tag node
     */
    protected function renderTag(array $node): string
    {
        $tagName = $node['name'];
        $attrs = $node['attrs'] ?? [];
        $children = $node['children'] ?? [];
        
        // Evaluate expressions in attribute values
        $attrs = $this->evaluateAttributes($attrs);
        
        // Check if we have a custom renderer for this component
        if (isset($this->components[$tagName])) {
            return $this->components[$tagName]($node, $this->context);
        }
        
        // Try to call component-specific method
        $method = 'render' . $this->toPascalCase($tagName);
        if (method_exists($this, $method)) {
            return $this->$method($node, $attrs, $children);
        }
        
        // Default: render as generic div
        return $this->renderGenericTag($node, $attrs, $children);
    }
    
    /**
     * Evaluate expressions in attribute values
     */
    protected function evaluateAttributes(array $attrs): array
    {
        $evaluated = [];
        
        foreach ($attrs as $key => $value) {
            // Check if value is a string containing an expression
            if (is_string($value) && preg_match('/^\{(.+)\}$/', $value, $matches)) {
                // Extract expression and evaluate it
                $expression = $matches[1];
                $evaluated[$key] = $this->evaluateExpression($expression);
            } else {
                $evaluated[$key] = $value;
            }
        }
        
        return $evaluated;
    }
    
    /**
     * Render a text node
     */
    protected function renderText(array $node): string
    {
        $text = $node['value'];
        $originalText = $text;
        
        error_log('[DiSyL Renderer] TEXT NODE: ' . substr($text, 0, 200));
        
        // First, handle filter expressions: {item.title | upper}
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\s*\|\s*([^}]+)\}/', function($matches) {
            $fullMatch = $matches[0];
            $expr = $matches[1];
            $filterChain = $matches[2];
            
            error_log('[DiSyL Renderer] Processing filter expression: ' . $fullMatch);
            error_log('[DiSyL Renderer] Expression: ' . $expr . ', Filters: ' . $filterChain);
            
            // Evaluate base expression
            $value = $this->evaluateExpression($expr);
            error_log('[DiSyL Renderer] Base value: ' . var_export($value, true));
            
            // Apply filters
            $value = $this->applyFilters($value, $filterChain);
            error_log('[DiSyL Renderer] After filters: ' . var_export($value, true));
            
            // Convert to string and escape
            $result = htmlspecialchars($this->valueToString($value), ENT_QUOTES, 'UTF-8');
            error_log('[DiSyL Renderer] Final result: ' . $result);
            
            return $result;
        }, $text);
        
        // Then, interpolate simple expressions like {title}, {item.title}
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function($matches) {
            $expr = $matches[1];
            $value = $this->evaluateExpression($expr);
            
            // Convert value to string and escape
            return htmlspecialchars($this->valueToString($value), ENT_QUOTES, 'UTF-8');
        }, $text);
        
        // Don't double-escape - we already escaped in the callbacks
        return $text;
    }
    
    /**
     * Apply filter chain to a value
     */
    protected function applyFilters($value, string $filterChain)
    {
        // Split filter chain by pipe
        $filters = explode('|', $filterChain);
        
        foreach ($filters as $filter) {
            $filter = trim($filter);
            if (empty($filter)) continue;
            
            // Parse filter name and parameters
            $params = [];
            if (strpos($filter, ':') !== false) {
                list($filterName, $paramStr) = explode(':', $filter, 2);
                $filterName = trim($filterName);
                
                // Parse parameters: format="Y-m-d" or length=100
                if (preg_match('/(\w+)=(["\']?)([^"\']+)\2/', $paramStr, $matches)) {
                    $params[$matches[1]] = $matches[3];
                }
            } else {
                $filterName = $filter;
            }
            
            // Apply filter using ModularManifestLoader (v0.4) or fallback to ManifestLoader (v0.2)
            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
                $value = \IkabudKernel\Core\DiSyL\ModularManifestLoader::applyFilter($filterName, $value, $params);
            } elseif (class_exists('\\IkabudKernel\\Core\\DiSyL\\ManifestLoader')) {
                $value = \IkabudKernel\Core\DiSyL\ManifestLoader::applyFilter($filterName, $value, $params);
            }
        }
        
        return $value;
    }
    
    /**
     * Convert value to string
     */
    protected function valueToString($value): string
    {
        if (is_array($value)) {
            return implode(', ', $value);
        } elseif (is_object($value)) {
            return method_exists($value, '__toString') ? (string)$value : '';
        } else {
            return (string)$value;
        }
    }
    
    /**
     * Render an expression node
     */
    protected function renderExpression(array $node): string
    {
        $expr = $node['value'];
        $value = $this->evaluateExpression($expr);
        
        // Convert value to string
        if (is_array($value)) {
            return htmlspecialchars(implode(', ', $value), ENT_QUOTES, 'UTF-8');
        } elseif (is_object($value)) {
            $str = method_exists($value, '__toString') ? (string)$value : '';
            return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
        } else {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
    
    /**
     * Render a comment node
     */
    protected function renderComment(array $node): string
    {
        // Comments are not rendered in output
        return '';
    }
    
    /**
     * Render generic tag as HTML div
     */
    protected function renderGenericTag(array $node, array $attrs, array $children): string
    {
        $html = '<div';
        
        // Add data attributes
        $html .= ' data-disyl-component="' . htmlspecialchars($node['name']) . '"';
        
        foreach ($attrs as $key => $value) {
            $html .= ' data-' . htmlspecialchars($key) . '="' . htmlspecialchars((string)$value) . '"';
        }
        
        $html .= '>';
        $html .= $this->renderChildren($children);
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Register a custom component renderer
     * 
     * @param string $componentName Component name (e.g., 'ikb_section')
     * @param callable $renderer Renderer function
     */
    public function registerComponent(string $componentName, callable $renderer): void
    {
        $this->components[$componentName] = $renderer;
    }
    
    /**
     * Get context value
     */
    protected function getContext(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
    
    /**
     * Set context value
     */
    protected function setContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }
    
    /**
     * Evaluate expression in context
     * 
     * Simple expression evaluation for {item.title} syntax
     */
    protected function evaluateExpression(string $expr): mixed
    {
        // Remove curly braces if present
        $expr = trim($expr, '{}');
        
        // Split by dot notation
        $parts = explode('.', $expr);
        $value = $this->context;
        
        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Convert snake_case to PascalCase
     */
    protected function toPascalCase(string $string): string
    {
        return str_replace('_', '', ucwords($string, '_'));
    }
    
    /**
     * Build HTML attributes string
     */
    protected function buildAttributes(array $attrs): string
    {
        $html = '';
        
        foreach ($attrs as $key => $value) {
            if ($value === true) {
                $html .= ' ' . htmlspecialchars($key);
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars((string)$value) . '"';
            }
        }
        
        return $html;
    }
    
    /**
     * Abstract method: CMS-specific initialization
     * 
     * Override this in CMS-specific renderers to set up CMS context
     */
    abstract protected function initializeCMS(): void;
}
