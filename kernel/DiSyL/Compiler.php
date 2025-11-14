<?php
/**
 * DiSyL Compiler v0.2
 * 
 * Validates and optimizes AST from Parser
 * Integrates with Manifest v0.2 for:
 * - Component capabilities validation
 * - Filter validation
 * - Attribute validation
 * - Deprecation warnings
 * 
 * @version 0.2.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Exceptions\CompilerException;
use IkabudKernel\Core\Cache;

class Compiler
{
    private Grammar $grammar;
    private ?Cache $cache;
    private array $errors = [];
    private array $warnings = [];
    private ?string $cmsType = null;
    
    /**
     * Constructor
     */
    public function __construct(?Cache $cache = null)
    {
        $this->grammar = new Grammar();
        $this->cache = $cache;
    }
    
    /**
     * Set CMS type for validation
     */
    public function setCMSType(?string $cmsType): void
    {
        $this->cmsType = $cmsType;
    }
    
    /**
     * Compile AST (validate, normalize, optimize)
     * 
     * @param array $ast AST from Parser
     * @param array $context Compilation context
     * @return array Compiled AST
     */
    public function compile(array $ast, array $context = []): array
    {
        $startTime = microtime(true);
        
        $this->errors = [];
        $this->warnings = [];
        
        // Extract CMS type from context
        $this->cmsType = $context['cms_type'] ?? null;
        
        // Check cache first
        $cacheKey = $this->generateCacheKey($ast, $context);
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // 1. Validate AST structure
        $ast = $this->validateStructure($ast);
        
        // 2. Validate components and attributes (v0.2: with capabilities)
        $ast = $this->validateComponents($ast);
        
        // 3. Validate component capabilities (v0.2)
        $ast = $this->validateCapabilities($ast);
        
        // 4. Validate filters (v0.2)
        $ast = $this->validateFilters($ast);
        
        // 5. Check for deprecated components (v0.2)
        $this->checkDeprecations($ast);
        
        // 6. Apply defaults (normalize attributes)
        $ast = $this->applyDefaults($ast);
        
        // 7. Optimize AST
        $ast = $this->optimize($ast);
        
        // 5. Add compilation metadata
        $ast['metadata'] = [
            'compilation_time_ms' => (microtime(true) - $startTime) * 1000,
            'cache_key' => $cacheKey,
            'version' => '0.1',
            'compiled_at' => time(),
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
        
        // Cache compiled AST
        if ($this->cache !== null && empty($this->errors)) {
            $this->cache->set($cacheKey, $ast, 3600); // Cache for 1 hour
        }
        
        return $ast;
    }
    
    /**
     * Validate AST structure
     */
    private function validateStructure(array $ast): array
    {
        if (!isset($ast['type']) || $ast['type'] !== 'document') {
            throw new CompilerException('Invalid AST: root must be a document node');
        }
        
        if (!isset($ast['children']) || !is_array($ast['children'])) {
            throw new CompilerException('Invalid AST: document must have children array');
        }
        
        return $ast;
    }
    
    /**
     * Validate components and their attributes
     */
    private function validateComponents(array $ast): array
    {
        if (isset($ast['children'])) {
            $ast['children'] = array_map(
                fn($child) => $this->validateNode($child),
                $ast['children']
            );
        }
        
        return $ast;
    }
    
    /**
     * Validate a single node
     */
    private function validateNode(array $node): array
    {
        // Only validate tag nodes
        if ($node['type'] !== 'tag') {
            return $node;
        }
        
        $tagName = $node['name'];
        
        // Check if component is registered
        if (!ComponentRegistry::has($tagName)) {
            $this->addWarning(
                sprintf('Unknown component: %s', $tagName),
                $node
            );
            return $node;
        }
        
        // Get component definition
        $component = ComponentRegistry::get($tagName);
        
        // Validate attributes
        $schemas = $component['attributes'] ?? [];
        $attrs = $node['attrs'] ?? [];
        
        $validationErrors = $this->grammar->validateAttributes($attrs, $schemas);
        
        foreach ($validationErrors as $error) {
            $this->addError($error, $node);
        }
        
        // Check if leaf component has children
        if ($component['leaf'] === true && !empty($node['children'])) {
            $this->addWarning(
                sprintf('Component "%s" is a leaf and should not have children', $tagName),
                $node
            );
        }
        
        // Recursively validate children
        if (isset($node['children'])) {
            $node['children'] = array_map(
                fn($child) => $this->validateNode($child),
                $node['children']
            );
        }
        
        return $node;
    }
    
    /**
     * Apply default values to attributes
     */
    private function applyDefaults(array $ast): array
    {
        if (isset($ast['children'])) {
            $ast['children'] = array_map(
                fn($child) => $this->applyDefaultsToNode($child),
                $ast['children']
            );
        }
        
        return $ast;
    }
    
    /**
     * Apply defaults to a single node
     */
    private function applyDefaultsToNode(array $node): array
    {
        // Only process tag nodes
        if ($node['type'] !== 'tag') {
            return $node;
        }
        
        $tagName = $node['name'];
        
        // Get component schemas
        if (ComponentRegistry::has($tagName)) {
            $schemas = ComponentRegistry::getAttributeSchemas($tagName);
            $node['attrs'] = $this->grammar->normalizeAttributes(
                $node['attrs'] ?? [],
                $schemas
            );
        }
        
        // Recursively apply defaults to children
        if (isset($node['children'])) {
            $node['children'] = array_map(
                fn($child) => $this->applyDefaultsToNode($child),
                $node['children']
            );
        }
        
        return $node;
    }
    
    /**
     * Optimize AST
     */
    private function optimize(array $ast): array
    {
        if (isset($ast['children'])) {
            $ast['children'] = $this->optimizeChildren($ast['children']);
        }
        
        return $ast;
    }
    
    /**
     * Optimize children array
     */
    private function optimizeChildren(array $children): array
    {
        $optimized = [];
        
        foreach ($children as $child) {
            // Remove empty text nodes
            if ($child['type'] === 'text' && trim($child['value']) === '') {
                continue;
            }
            
            // Merge consecutive text nodes
            if ($child['type'] === 'text' && 
                !empty($optimized) && 
                end($optimized)['type'] === 'text') {
                $lastIndex = count($optimized) - 1;
                $optimized[$lastIndex]['value'] .= $child['value'];
                continue;
            }
            
            // Recursively optimize tag children
            if ($child['type'] === 'tag' && isset($child['children'])) {
                $child['children'] = $this->optimizeChildren($child['children']);
            }
            
            $optimized[] = $child;
        }
        
        return $optimized;
    }
    
    /**
     * Generate cache key for AST
     */
    private function generateCacheKey(array $ast, array $context): string
    {
        $data = [
            'ast' => $ast,
            'context' => $context,
            'version' => '0.1'
        ];
        
        return 'disyl_compiled_' . md5(json_encode($data));
    }
    
    /**
     * Add error
     */
    private function addError(string $message, ?array $node = null): void
    {
        $error = [
            'type' => 'error',
            'message' => $message
        ];
        
        if ($node !== null && isset($node['loc'])) {
            $error['line'] = $node['loc']['line'] ?? 0;
            $error['column'] = $node['loc']['column'] ?? 0;
        }
        
        $this->errors[] = $error;
    }
    
    /**
     * Add warning
     */
    private function addWarning(string $message, ?array $node = null): void
    {
        $warning = [
            'type' => 'warning',
            'message' => $message
        ];
        
        if ($node !== null && isset($node['loc'])) {
            $warning['line'] = $node['loc']['line'] ?? 0;
            $warning['column'] = $node['loc']['column'] ?? 0;
        }
        
        $this->warnings[] = $warning;
    }
    
    /**
     * Get compilation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Get compilation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    /**
     * Validate component capabilities (v0.2)
     */
    private function validateCapabilities(array $ast): array
    {
        if (!isset($ast['children'])) {
            return $ast;
        }
        
        foreach ($ast['children'] as &$node) {
            if ($node['type'] === 'tag') {
                $componentName = $node['name'];
                
                // Get component capabilities from manifest (v0.4 or v0.2)
                if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
                    $capabilities = \IkabudKernel\Core\DiSyL\ModularManifestLoader::getCapabilities($componentName, $this->cmsType);
                } else {
                    $capabilities = ManifestLoader::getCapabilities($componentName, $this->cmsType);
                }
                
                if ($capabilities) {
                    // Validate supports_children
                    $hasChildren = !empty($node['children']);
                    $supportsChildren = $capabilities['supports_children'] ?? false;
                    
                    if ($hasChildren && !$supportsChildren) {
                        $this->addError(
                            "Component '{$componentName}' does not support children",
                            $node
                        );
                    }
                    
                    // Store capabilities in node for renderer
                    $node['capabilities'] = $capabilities;
                }
                
                // Recursively validate children
                if (isset($node['children'])) {
                    $node['children'] = $this->validateCapabilities(['children' => $node['children']])['children'];
                }
            }
        }
        
        return $ast;
    }
    
    /**
     * Validate filters in expressions (v0.2)
     */
    private function validateFilters(array $ast): array
    {
        if (!isset($ast['children'])) {
            return $ast;
        }
        
        foreach ($ast['children'] as &$node) {
            if ($node['type'] === 'tag' && isset($node['attrs'])) {
                foreach ($node['attrs'] as $attrName => &$attrValue) {
                    if (is_array($attrValue) && ($attrValue['type'] ?? '') === 'filtered_expression') {
                        // Validate each filter
                        foreach ($attrValue['filters'] as $filter) {
                            $filterName = $filter['name'];
                            
                            // Get filter definition (v0.4 or v0.2)
                            if (class_exists('\\IkabudKernel\\Core\\DiSyL\\ModularManifestLoader')) {
                                $filterDef = \IkabudKernel\Core\DiSyL\ModularManifestLoader::getFilter($filterName);
                            } else {
                                $filterDef = ManifestLoader::getFilter($filterName);
                            }
                            
                            if (!$filterDef) {
                                $this->addError(
                                    "Unknown filter '{$filterName}' in attribute '{$attrName}'",
                                    $node
                                );
                            }
                        }
                    }
                }
            }
            
            // Recursively validate children
            if (isset($node['children'])) {
                $node['children'] = $this->validateFilters(['children' => $node['children']])['children'];
            }
        }
        
        return $ast;
    }
    
    /**
     * Check for deprecated components (v0.2)
     */
    private function checkDeprecations(array $ast): void
    {
        if (!isset($ast['children'])) {
            return;
        }
        
        foreach ($ast['children'] as $node) {
            if ($node['type'] === 'tag') {
                $componentName = $node['name'];
                
                if (ManifestLoader::isDeprecated($componentName)) {
                    $deprecationInfo = ManifestLoader::getDeprecationInfo($componentName);
                    
                    $message = $deprecationInfo['message'] ?? "Component '{$componentName}' is deprecated";
                    if (isset($deprecationInfo['replacement'])) {
                        $message .= ". Use '{$deprecationInfo['replacement']}' instead.";
                    }
                    
                    $this->addWarning($message, $node);
                }
            }
            
            // Recursively check children
            if (isset($node['children'])) {
                $this->checkDeprecations(['children' => $node['children']]);
            }
        }
    }
}
