<?php
/**
 * DiSyL v4.0 Single-File Component
 * 
 * Parses and manages single-file components (.disyl files with embedded metadata).
 * 
 * Format:
 * {# @component ComponentName #}
 * {# @prop propName: type = default #}
 * {# @slot slotName #}
 * 
 * <template content>
 * 
 * {# @style scoped #}
 * <style>...</style>
 * 
 * @package Ikabud\Kernel\DiSyL\Component
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

use Ikabud\Kernel\DiSyL\v4\Parser;
use Ikabud\Kernel\DiSyL\v4\AST\DocumentNode;

/**
 * Single-file component definition
 */
class SingleFileComponent
{
    private string $name;
    private string $source;
    private ?DocumentNode $ast = null;
    private array $props = [];
    private array $slots = [];
    private ?string $style = null;
    private bool $scopedStyle = false;
    private string $scopeId;
    
    public function __construct(string $name, string $source)
    {
        $this->name = $name;
        $this->source = $source;
        $this->scopeId = 'disyl-' . substr(md5($name), 0, 8);
        $this->parse();
    }
    
    /**
     * Parse component source
     */
    private function parse(): void
    {
        $lines = explode("\n", $this->source);
        $templateLines = [];
        $styleLines = [];
        $inStyle = false;
        $styleScoped = false;
        
        foreach ($lines as $line) {
            // Check for component directive
            if (preg_match('/\{#\s*@component\s+(\w+)\s*#\}/', $line, $matches)) {
                $this->name = $matches[1];
                continue;
            }
            
            // Check for prop directive
            if (preg_match('/\{#\s*@prop\s+(\w+)\s*:\s*(\w+)(?:\s*=\s*(.+?))?\s*#\}/', $line, $matches)) {
                $this->props[$matches[1]] = [
                    'type' => $matches[2],
                    'default' => isset($matches[3]) ? $this->parseDefaultValue($matches[3]) : null,
                    'required' => !isset($matches[3]),
                ];
                continue;
            }
            
            // Check for slot directive
            if (preg_match('/\{#\s*@slot\s+(\w+)\s*#\}/', $line, $matches)) {
                $this->slots[] = $matches[1];
                continue;
            }
            
            // Check for style directive
            if (preg_match('/\{#\s*@style(?:\s+(scoped))?\s*#\}/', $line, $matches)) {
                $inStyle = true;
                $styleScoped = isset($matches[1]);
                continue;
            }
            
            // Collect style or template content
            if ($inStyle) {
                // Check for end of style block
                if (preg_match('/<\/style>/', $line)) {
                    $styleLines[] = preg_replace('/<\/style>.*/', '', $line);
                    $inStyle = false;
                } elseif (!preg_match('/<style[^>]*>/', $line)) {
                    $styleLines[] = $line;
                } else {
                    // Strip <style> tag
                    $styleLines[] = preg_replace('/<style[^>]*>/', '', $line);
                }
            } else {
                $templateLines[] = $line;
            }
        }
        
        $this->scopedStyle = $styleScoped;
        
        // Process style
        if (!empty($styleLines)) {
            $this->style = implode("\n", $styleLines);
            if ($this->scopedStyle) {
                $this->style = $this->scopeStyles($this->style);
            }
        }
        
        // Parse template
        $templateSource = implode("\n", $templateLines);
        $parser = new Parser();
        $this->ast = $parser->parse($templateSource, $this->name . '.disyl');
    }
    
    /**
     * Parse default value from string
     */
    private function parseDefaultValue(string $value): mixed
    {
        $value = trim($value);
        
        // String (quoted)
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
            return $matches[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // Null
        if ($value === 'null') return null;
        
        // Number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        // Array (simple)
        if ($value === '[]') return [];
        
        return $value;
    }
    
    /**
     * Scope CSS selectors
     */
    private function scopeStyles(string $css): string
    {
        // Add scope ID to all selectors
        return preg_replace_callback(
            '/([^{}]+)\{/',
            function ($matches) {
                $selectors = explode(',', $matches[1]);
                $scoped = array_map(function ($selector) {
                    $selector = trim($selector);
                    // Don't scope @-rules
                    if (strpos($selector, '@') === 0) {
                        return $selector;
                    }
                    // Add scope attribute selector
                    return "[data-{$this->scopeId}] {$selector}, {$selector}[data-{$this->scopeId}]";
                }, $selectors);
                return implode(', ', $scoped) . ' {';
            },
            $css
        );
    }
    
    /**
     * Get component name
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * Get parsed AST
     */
    public function getAST(): ?DocumentNode
    {
        return $this->ast;
    }
    
    /**
     * Get prop definitions
     */
    public function getProps(): array
    {
        return $this->props;
    }
    
    /**
     * Get slot names
     */
    public function getSlots(): array
    {
        return $this->slots;
    }
    
    /**
     * Get scoped style CSS
     */
    public function getStyle(): ?string
    {
        return $this->style;
    }
    
    /**
     * Check if style is scoped
     */
    public function isScopedStyle(): bool
    {
        return $this->scopedStyle;
    }
    
    /**
     * Get scope ID for data attribute
     */
    public function getScopeId(): string
    {
        return $this->scopeId;
    }
    
    /**
     * Validate props against definitions
     */
    public function validateProps(array $props): array
    {
        $errors = [];
        
        foreach ($this->props as $name => $def) {
            // Check required
            if ($def['required'] && !isset($props[$name])) {
                $errors[] = "Missing required prop: {$name}";
                continue;
            }
            
            // Type check
            if (isset($props[$name])) {
                $value = $props[$name];
                $type = $def['type'];
                
                $valid = match ($type) {
                    'string' => is_string($value),
                    'number', 'int', 'integer' => is_numeric($value),
                    'bool', 'boolean' => is_bool($value),
                    'array' => is_array($value),
                    'object' => is_object($value) || is_array($value),
                    'any' => true,
                    default => true,
                };
                
                if (!$valid) {
                    $errors[] = "Prop '{$name}' expected {$type}, got " . gettype($value);
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Get props with defaults applied
     */
    public function resolveProps(array $props): array
    {
        $resolved = [];
        
        foreach ($this->props as $name => $def) {
            if (isset($props[$name])) {
                $resolved[$name] = $props[$name];
            } elseif ($def['default'] !== null) {
                $resolved[$name] = $def['default'];
            }
        }
        
        // Include any extra props passed
        foreach ($props as $name => $value) {
            if (!isset($resolved[$name])) {
                $resolved[$name] = $value;
            }
        }
        
        return $resolved;
    }
}
