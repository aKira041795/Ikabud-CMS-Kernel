<?php
/**
 * DiSyL Base Renderer
 * 
 * Abstract base class for DiSyL renderers
 * Provides common rendering logic that can be extended by CMS-specific renderers
 * 
 * Features:
 * - Filter pipeline with multiple arguments
 * - Named and positional filter arguments
 * - Enhanced expression evaluation
 * - Unicode support
 * 
 * Performance optimizations (v0.4.0):
 * - Method existence caching
 * - PascalCase conversion caching
 * - Optimized string building
 * - Reduced reflection calls
 * 
 * @version 0.4.0
 */

namespace IkabudKernel\Core\DiSyL\Renderers;

abstract class BaseRenderer
{
    protected array $context = [];
    protected array $components = [];
    protected array $filters = [];
    
    /** @var array Cache for method existence checks */
    private static array $methodCache = [];
    
    /** @var array Cache for PascalCase conversions */
    private static array $pascalCaseCache = [];
    
    /** @var array Cache for component method names */
    private array $componentMethodCache = [];
    
    /**
     * Constructor - Initialize CMS-specific setup
     */
    public function __construct()
    {
        $this->initializeCMS();
    }
    
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
        
        // Try to call component-specific method (with caching)
        $method = $this->getComponentMethod($tagName);
        if ($method !== null) {
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
            // Check if value is a structured filtered_expression array from Parser
            if (is_array($value) && isset($value['type']) && $value['type'] === 'filtered_expression') {
                // Extract the base expression (remove curly braces)
                $baseExpr = trim($value['value'], '{}');
                
                // Evaluate base expression
                $result = $this->evaluateExpression($baseExpr);
                
                // Apply filters if present
                if (!empty($value['filters'])) {
                    foreach ($value['filters'] as $filter) {
                        $filterName = $filter['name'];
                        $filterParams = $filter['params'] ?? [];
                        
                        // Apply filter
                        if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
                            $result = \IkabudKernel\Core\DiSyL\ModularManifestLoader::applyFilter($filterName, $result, $filterParams);
                        } elseif (class_exists('\\IkabudKernel\\Core\\DiSyL\\ManifestLoader')) {
                            $result = \IkabudKernel\Core\DiSyL\ManifestLoader::applyFilter($filterName, $result, $filterParams);
                        }
                    }
                }
                
                // Convert arrays to strings for attributes
                if (is_array($result)) {
                    $result = implode(', ', $result);
                }
                
                $evaluated[$key] = $result;
            }
            // Check if value is a string containing an expression
            elseif (is_string($value) && preg_match('/^\{(.+)\}$/', $value, $matches)) {
                // Extract expression and evaluate it
                $expression = $matches[1];
                
                // Check if expression contains filters (pipe character)
                if (strpos($expression, '|') !== false) {
                    // Split into base expression and filter chain
                    $parts = explode('|', $expression, 2);
                    $baseExpr = trim($parts[0]);
                    $filterChain = trim($parts[1]);
                    
                    // Evaluate base expression
                    $result = $this->evaluateExpression($baseExpr);
                    
                    // Apply filters
                    $result = $this->applyFilters($result, $filterChain);
                } else {
                    // No filters, just evaluate
                    $result = $this->evaluateExpression($expression);
                }
                
                // Convert arrays to strings for attributes
                if (is_array($result)) {
                    // Flatten nested arrays and convert to string
                    $result = $this->arrayToString($result);
                }
                
                $evaluated[$key] = $result;
            } else {
                // Not an expression, pass through as-is
                $evaluated[$key] = $value;
            }
        }
        
        return $evaluated;
    }
    
    /**
     * Render a text node
     * 
     * Handles:
     * 1. Raw HTML (preserved as-is)
     * 2. Filtered expressions: {item.title | esc_html}
     * 3. Simple expressions: {item.title}
     */
    protected function renderText(array $node): string
    {
        $text = $node['value'];
        
        // First, handle filter expressions with potential nested braces: {item.title | default:"{other.value}" | upper}
        // Pattern matches: {expression | filters} where filters can contain {nested} expressions
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\s*\|((?:[^{}]|\{[^}]*\})*)\}/', function($matches) {
            $expr = $matches[1];
            $filterChain = trim($matches[2]);
            
            // Evaluate base expression
            $value = $this->evaluateExpression($expr);
            
            // Apply filters (filters handle their own escaping)
            $value = $this->applyFilters($value, $filterChain);
            
            // Convert to string (don't double-escape - filters already did it)
            return $this->valueToString($value);
        }, $text);
        
        // Then, interpolate simple expressions like {title}, {item.title}
        $text = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function($matches) {
            $expr = $matches[1];
            $value = $this->evaluateExpression($expr);
            
            // Convert value to string and escape (no filter, so we escape)
            return htmlspecialchars($this->valueToString($value), ENT_QUOTES, 'UTF-8');
        }, $text);
        
        // Return text as-is - it may contain raw HTML which should not be escaped
        // The embedded expressions have already been processed and escaped as needed
        return $text;
    }
    
    /**
     * Apply filter chain to a value
     */
    protected function applyFilters($value, string $filterChain)
    {
        // If value is null, convert to empty string to prevent deprecation warnings
        if ($value === null) {
            $value = '';
        }
        
        // If value is an array, convert to string first
        // This handles cases like item.categories which is an array
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        
        // Split filter chain by pipe
        $filters = explode('|', $filterChain);
        
        foreach ($filters as $filter) {
            $filter = trim($filter);
            if (empty($filter)) continue;
            
            // Parse filter name and parameters (v0.2 enhanced)
            $params = [];
            if (strpos($filter, ':') !== false) {
                list($filterName, $paramStr) = explode(':', $filter, 2);
                $filterName = trim($filterName);
                $paramStr = trim($paramStr);
                
                // Parse multiple arguments separated by commas
                // Format: length=100,append="..." or just 100,"..."
                $args = $this->parseFilterArguments($paramStr);
                
                // Process each argument
                $positionalIndex = 0;
                foreach ($args as $arg) {
                    $arg = trim($arg);
                    
                    // Check if it's a named argument (key=value)
                    if (preg_match('/^(\w+)=(.+)$/', $arg, $matches)) {
                        $key = $matches[1];
                        $paramValue = $matches[2];
                        
                        // Remove quotes if present
                        if ((substr($paramValue, 0, 1) === '"' && substr($paramValue, -1) === '"') ||
                            (substr($paramValue, 0, 1) === "'" && substr($paramValue, -1) === "'")) {
                            $paramValue = substr($paramValue, 1, -1);
                        }
                        
                        $params[$key] = $paramValue;
                    } else {
                        // Positional argument
                        // Remove quotes if present
                        if ((substr($arg, 0, 1) === '"' && substr($arg, -1) === '"') ||
                            (substr($arg, 0, 1) === "'" && substr($arg, -1) === "'")) {
                            $arg = substr($arg, 1, -1);
                        }
                        
                        // Check if argument contains an expression (for default filter)
                        if ($filterName === 'default' && preg_match('/^\{([a-zA-Z0-9_.]+)\}$/', $arg, $matches)) {
                            // Evaluate the expression
                            $arg = $this->evaluateExpression($matches[1]);
                        }
                        
                        // Map positional arguments to parameter names based on filter
                        if ($filterName === 'truncate') {
                            if ($positionalIndex === 0) $params['length'] = $arg;
                            elseif ($positionalIndex === 1) $params['append'] = $arg;
                        } elseif ($filterName === 'date') {
                            if ($positionalIndex === 0) $params['format'] = $arg;
                        } elseif ($filterName === 'number_format') {
                            if ($positionalIndex === 0) $params['decimals'] = $arg;
                            elseif ($positionalIndex === 1) $params['dec_point'] = $arg;
                            elseif ($positionalIndex === 2) $params['thousands_sep'] = $arg;
                        } elseif ($filterName === 'default') {
                            if ($positionalIndex === 0) $params['fallback'] = $arg;
                        } else {
                            // Generic: use numeric index
                            $params[$positionalIndex] = $arg;
                        }
                        $positionalIndex++;
                    }
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
     * Parse filter arguments (v0.2 enhanced)
     * 
     * Handles comma-separated arguments with proper quote handling
     * Example: length=100,append="..." becomes ['length=100', 'append="..."']
     */
    protected function parseFilterArguments(string $paramStr): array
    {
        $args = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;
        $length = strlen($paramStr);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $paramStr[$i];
            
            // Handle quotes
            if (($char === '"' || $char === "'") && ($i === 0 || $paramStr[$i-1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = null;
                }
                $current .= $char;
            }
            // Handle comma separator (only outside quotes)
            elseif ($char === ',' && !$inQuotes) {
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }
            }
            // Regular character
            else {
                $current .= $char;
            }
        }
        
        // Add last argument
        if ($current !== '') {
            $args[] = $current;
        }
        
        return $args;
    }
    
    /**
     * Convert value to string
     */
    protected function valueToString($value): string
    {
        if ($value === null) {
            return '';
        } elseif (is_array($value)) {
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
     * Register a custom filter
     * 
     * @param string $filterName Filter name (e.g., 'esc_html')
     * @param callable $filter Filter function
     */
    public function registerFilter(string $filterName, callable $filter): void
    {
        $this->filters[$filterName] = $filter;
    }
    
    /**
     * Apply filter to value
     * 
     * @param string $filterName Filter name
     * @param mixed $value Value to filter
     * @param array $args Additional filter arguments
     * @return mixed Filtered value
     */
    protected function applyFilter(string $filterName, mixed $value, array $args = []): mixed
    {
        if (isset($this->filters[$filterName])) {
            return call_user_func($this->filters[$filterName], $value, ...$args);
        }
        return $value;
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
     * Convert array to string for attribute values
     * 
     * @param array $array Array to convert
     * @return string String representation
     */
    protected function arrayToString(array $array): string {
        $result = [];
        
        foreach ($array as $value) {
            if (is_array($value)) {
                // Recursively flatten nested arrays
                $result[] = $this->arrayToString($value);
            } elseif (is_scalar($value)) {
                // Only add scalar values (string, int, float, bool)
                $result[] = (string)$value;
            }
        }
        
        return implode(', ', $result);
    }
    
    /**
     * Normalize item data for DSL rendering
     * Converts CMS-specific data to universal format
     * 
     * @param array $item CMS-specific item data
     * @return array Normalized item data
     */
    protected function normalizeItemForDSL(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'title' => $item['title'] ?? '',
            'excerpt' => $item['excerpt'] ?? '',
            'content' => $item['content'] ?? '',
            'permalink' => $item['url'] ?? $item['permalink'] ?? '',
            'date' => $item['date'] ?? '',
            'author' => $item['author'] ?? '',
            'thumbnail' => $item['thumbnail'] ?? '',
            'categories' => $item['categories'] ?? []
        ];
    }
    
    /**
     * Render items using DSL format and layout engines
     * 
     * @param array $items Array of normalized items
     * @param array $attrs Query attributes (format, layout, columns, gap)
     * @return string Rendered HTML
     */
    protected function renderWithDSL(array $items, array $attrs): string
    {
        // Check if DSL classes are available
        if (!class_exists('\\IkabudKernel\\DSL\\FormatRenderer')) {
            // Fallback: DSL not available, return empty
            return '<!-- DSL rendering unavailable: FormatRenderer class not found -->';
        }
        
        try {
            // Import DSL classes
            $formatter = new \IkabudKernel\DSL\FormatRenderer();
            $layoutEngine = new \IkabudKernel\DSL\LayoutEngine();
            
            // Normalize items for DSL
            $normalizedItems = array_map([$this, 'normalizeItemForDSL'], $items);
            
            // Render with format
            $format = $attrs['format'] ?? 'card';
            $content = $formatter->render($normalizedItems, $format);
            
            // Wrap with layout
            $layout = $attrs['layout'] ?? 'vertical';
            $layoutOptions = [
                'columns' => $attrs['columns'] ?? 3,
                'gap' => $attrs['gap'] ?? 'medium'
            ];
            
            return $layoutEngine->wrap($content, $layout, $layoutOptions);
        } catch (\Exception $e) {
            return '<!-- DSL rendering error: ' . htmlspecialchars($e->getMessage()) . ' -->';
        }
    }
    
    /**
     * Check if DSL rendering should be used
     * 
     * @param array $attrs Query attributes
     * @return bool True if format attribute is set
     */
    protected function shouldUseDSLRendering(array $attrs): bool
    {
        return isset($attrs['format']) && !empty($attrs['format']);
    }
    
    /**
     * Get component render method with caching
     * 
     * @param string $tagName Component tag name
     * @return string|null Method name or null if not found
     */
    private function getComponentMethod(string $tagName): ?string
    {
        // Check instance cache first
        if (isset($this->componentMethodCache[$tagName])) {
            return $this->componentMethodCache[$tagName];
        }
        
        // Get PascalCase version (cached)
        $pascalCase = $this->toPascalCaseCached($tagName);
        $method = 'render' . $pascalCase;
        
        // Check method existence (cached per class)
        $class = static::class;
        $cacheKey = $class . '::' . $method;
        
        if (!isset(self::$methodCache[$cacheKey])) {
            self::$methodCache[$cacheKey] = method_exists($this, $method);
        }
        
        $result = self::$methodCache[$cacheKey] ? $method : null;
        $this->componentMethodCache[$tagName] = $result;
        
        return $result;
    }
    
    /**
     * Convert to PascalCase with caching
     * 
     * @param string $tagName Tag name (e.g., 'ikb_section')
     * @return string PascalCase version (e.g., 'IkbSection')
     */
    private function toPascalCaseCached(string $tagName): string
    {
        if (isset(self::$pascalCaseCache[$tagName])) {
            return self::$pascalCaseCache[$tagName];
        }
        
        $result = $this->toPascalCase($tagName);
        self::$pascalCaseCache[$tagName] = $result;
        
        return $result;
    }
    
    /**
     * Clear method caches (useful for testing)
     */
    public static function clearMethodCache(): void
    {
        self::$methodCache = [];
        self::$pascalCaseCache = [];
    }
    
    /**
     * Abstract method: CMS-specific initialization
     * 
     * Override this in CMS-specific renderers to set up CMS context
     */
    abstract protected function initializeCMS(): void;
}
