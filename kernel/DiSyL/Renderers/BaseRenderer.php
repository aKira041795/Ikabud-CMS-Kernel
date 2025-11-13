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
     * Render a text node
     */
    protected function renderText(array $node): string
    {
        return htmlspecialchars($node['value'], ENT_QUOTES, 'UTF-8');
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
