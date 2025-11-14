<?php
/**
 * Manifest-Driven Renderer
 * 
 * Renders components based on manifest configuration instead of hardcoded logic.
 * Follows separation of concerns: renderers generate HTML structure and CSS classes,
 * styling is defined in CSS files, configuration is in manifests.
 * 
 * @package IkabudKernel\Core\DiSyL\Renderers
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

use IkabudKernel\Core\DiSyL\ModularManifestLoader;

abstract class ManifestDrivenRenderer extends BaseRenderer
{
    /**
     * Render a tag using manifest configuration
     */
    protected function renderTag(array $node): string
    {
        $tagName = $node['name'];
        $attrs = $node['attrs'] ?? [];
        $children = $node['children'] ?? [];
        
        // Evaluate expressions in attribute values
        $attrs = $this->evaluateAttributes($attrs);
        
        // Try component-specific method first (for custom logic)
        $method = 'render' . $this->toPascalCase($tagName);
        if (method_exists($this, $method)) {
            return $this->$method($node, $attrs, $children);
        }
        
        // Use manifest-driven rendering
        return $this->renderFromManifest($tagName, $attrs, $children);
    }
    
    /**
     * Render component from manifest configuration
     */
    protected function renderFromManifest(string $componentName, array $attrs, array $children): string
    {
        // Get component definition from manifest
        $component = ModularManifestLoader::getComponent($componentName);
        
        if (!$component) {
            // Fallback for unknown components
            return $this->renderGenericTag(['name' => $componentName], $attrs, $children);
        }
        
        // Get HTML tag from manifest
        $htmlTag = $component['html_tag'] ?? 'div';
        
        // Build CSS classes from manifest
        $classes = $this->buildCssClasses($component, $attrs);
        
        // Build data attributes from manifest
        $dataAttrs = $this->buildDataAttributes($component, $attrs);
        
        // Build HTML
        $html = '<' . $htmlTag;
        
        // Add classes
        if (!empty($classes)) {
            $html .= ' class="' . esc_attr(implode(' ', $classes)) . '"';
        }
        
        // Add data attributes
        foreach ($dataAttrs as $key => $value) {
            $html .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        
        // Add custom class attribute if provided
        if (!empty($attrs['class'])) {
            $html .= ' class="' . esc_attr($attrs['class']) . '"';
        }
        
        $html .= '>';
        
        // Render children
        $html .= $this->renderChildren($children);
        
        // Close tag
        $html .= '</' . $htmlTag . '>';
        
        return $html;
    }
    
    /**
     * Build CSS classes from manifest configuration
     */
    protected function buildCssClasses(array $component, array $attrs): array
    {
        $classes = [];
        
        // Add base class from manifest
        if (!empty($component['class_prefix'])) {
            $classes[] = $component['class_prefix'];
        }
        
        // Add modifier classes from attributes
        $attributes = $component['attributes'] ?? [];
        
        foreach ($attributes as $attrName => $attrConfig) {
            // Skip if attribute not provided
            if (!isset($attrs[$attrName])) {
                continue;
            }
            
            $attrValue = $attrs[$attrName];
            
            // Check if attribute has CSS modifier configuration
            if (!empty($attrConfig['css_modifier'])) {
                $modifier = str_replace('{value}', $attrValue, $attrConfig['css_modifier']);
                $classes[] = $modifier;
            }
        }
        
        return $classes;
    }
    
    /**
     * Build data attributes from manifest configuration
     */
    protected function buildDataAttributes(array $component, array $attrs): array
    {
        $dataAttrs = [];
        
        $attributes = $component['attributes'] ?? [];
        
        foreach ($attributes as $attrName => $attrConfig) {
            // Skip if attribute not provided
            if (!isset($attrs[$attrName])) {
                continue;
            }
            
            $attrValue = $attrs[$attrName];
            
            // Check if attribute has data attribute configuration
            if (!empty($attrConfig['css_data_attr'])) {
                $dataAttrName = $attrConfig['css_data_attr'];
                $dataAttrs[$dataAttrName] = $attrValue;
            }
        }
        
        return $dataAttrs;
    }
    
    /**
     * Convert tag name to PascalCase for method names
     */
    protected function toPascalCase(string $string): string
    {
        return str_replace('_', '', ucwords($string, '_'));
    }
}
