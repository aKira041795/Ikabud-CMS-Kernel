<?php
/**
 * DiSyL Compiler v0.4.0
 * 
 * Validates and optimizes AST from Parser
 * Integrates with Grammar v1.2.0 for:
 * - Component capabilities validation
 * - Filter validation with type chain checking
 * - Attribute validation with rich errors
 * - Platform compatibility checking
 * - Security validation (escaping warnings)
 * - Deprecation warnings
 * 
 * Performance optimizations:
 * - Component validation caching
 * - Lazy Grammar initialization
 * - Reduced array allocations
 * - Optimized tree traversal
 * 
 * @version 0.4.0
 */

namespace IkabudKernel\Core\DiSyL;

use IkabudKernel\Core\DiSyL\Exceptions\CompilerException;
use IkabudKernel\Core\DiSyL\Exceptions\CMSLoaderException;
use IkabudKernel\Core\Cache;

class Compiler
{
    private ?Grammar $grammar = null;
    private ?Cache $cache;
    private array $errors = [];
    private array $warnings = [];
    private ?string $cmsType = null;
    private bool $strictMode = true;
    
    /** @var array Cache for validated components */
    private static array $validatedComponents = [];
    
    /** @var array Cache for filter validation */
    private static array $validatedFilters = [];
    
    /** @var ValidationResult|null Rich validation result */
    private ?ValidationResult $validationResult = null;
    
    /**
     * Constructor
     */
    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache;
    }
    
    /**
     * Get Grammar instance (lazy-loaded)
     */
    private function getGrammar(): Grammar
    {
        if ($this->grammar === null) {
            $this->grammar = new Grammar();
            // Sync validation mode
            $this->grammar->setMode($this->strictMode ? Grammar::MODE_STRICT : Grammar::MODE_LENIENT);
        }
        return $this->grammar;
    }
    
    /**
     * Set validation mode (strict or lenient)
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        if ($this->grammar !== null) {
            $this->grammar->setMode($strict ? Grammar::MODE_STRICT : Grammar::MODE_LENIENT);
        }
        return $this;
    }
    
    /**
     * Get rich validation result
     */
    public function getValidationResult(): ?ValidationResult
    {
        return $this->validationResult;
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
        $this->validationResult = new ValidationResult();
        
        // Process CMS header declaration if present
        if (isset($ast['cms_header']) && $ast['cms_header'] !== null) {
            $this->processCMSHeader($ast['cms_header']);
        }
        
        // Extract CMS type from context (can be overridden by header)
        $this->cmsType = $context['cms_type'] ?? $this->cmsType ?? null;
        
        // Validate platform if specified
        if ($this->cmsType !== null && !$this->getGrammar()->validatePlatform($this->cmsType)) {
            $this->addWarning(sprintf('Unknown platform: %s', $this->cmsType));
        }
        
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
            'compiler_version' => '0.4.0',
            'grammar_version' => Grammar::SCHEMA_VERSION,
            'platform' => $this->cmsType,
            'compiled_at' => time(),
            'strict_mode' => $this->strictMode,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'validation' => $this->validationResult->jsonSerialize(),
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
            // Validate expressions for security
            if ($node['type'] === 'expression' && isset($node['value'])) {
                $this->validateExpressionSecurity($node);
            }
            return $node;
        }
        
        $tagName = $node['name'];
        $line = $node['loc']['line'] ?? $node['line'] ?? null;
        $column = $node['loc']['column'] ?? $node['column'] ?? null;
        
        // Validate tag name syntax
        if (!$this->getGrammar()->validateNamespacedIdentifier($tagName)) {
            $this->addError(sprintf('Invalid component name: %s', $tagName), $node);
            return $node;
        }
        
        // Check platform compatibility
        if ($this->cmsType !== null && !$this->getGrammar()->isComponentCompatible($tagName, $this->cmsType)) {
            $this->addWarning(
                sprintf('Component "%s" may not be compatible with platform "%s"', $tagName, $this->cmsType),
                $node
            );
        }
        
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
        
        // Use Grammar's rich validation for props
        $attrs = $node['attrs'] ?? [];
        $result = $this->getGrammar()->validateComponentPropsRich($tagName, $attrs, $line, $column);
        
        // Merge validation results
        $this->validationResult->merge($result);
        
        // Also add to legacy error/warning arrays
        foreach ($result->getErrors() as $error) {
            $this->addError($error->message, $node);
        }
        foreach ($result->getWarnings() as $warning) {
            $this->addWarning($warning->message, $node);
        }
        
        // Validate slots
        $slots = isset($node['children']) && !empty($node['children']) ? ['default' => true] : [];
        $slotErrors = $this->getGrammar()->validateSlots($tagName, $slots);
        foreach ($slotErrors as $error) {
            $this->addWarning($error, $node);
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
     * Validate expression for security (escaping)
     */
    private function validateExpressionSecurity(array $node): void
    {
        $expr = $node['value'] ?? '';
        if (empty($expr)) {
            return;
        }
        
        // Parse and check for escaping
        $parsed = $this->getGrammar()->parseExpression('{' . $expr . '}');
        
        if (!$parsed['hasEscaping'] && $this->strictMode) {
            $this->addWarning(
                sprintf('Expression "{%s}" has no escaping filter - consider using esc_html', $expr),
                $node
            );
            
            $this->validationResult->addError(new ValidationError(
                sprintf('Expression has no escaping filter', $expr),
                'MISSING_ESCAPING',
                'expression',
                null,
                $node['loc']['line'] ?? $node['line'] ?? null,
                $node['loc']['column'] ?? $node['column'] ?? null,
                $expr,
                'warning'
            ));
        }
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
            $node['attrs'] = $this->getGrammar()->normalizeAttributes(
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
     * Check if there are compilation errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    /**
     * Get compilation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    
    /**
     * Check if there are compilation warnings
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
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
     * Validate filters in expressions (v0.4 - uses Grammar filter registry)
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
                        // Build filter chain string for validation
                        $filterChain = '|' . implode('|', array_map(
                            fn($f) => $f['name'] . (isset($f['args']) ? ':' . implode(',', $f['args']) : ''),
                            $attrValue['filters']
                        ));
                        
                        // Use Grammar's filter chain validation with platform check
                        $result = $this->getGrammar()->validateFilterChainRich(
                            $filterChain,
                            $this->cmsType
                        );
                        
                        // Merge results
                        $this->validationResult->merge($result);
                        
                        foreach ($result->getErrors() as $error) {
                            $this->addError($error->message, $node);
                        }
                        foreach ($result->getWarnings() as $warning) {
                            $this->addWarning($warning->message, $node);
                        }
                    }
                    
                    // Also check string attributes that might contain expressions
                    if (is_string($attrValue) && preg_match('/\{[^}]+\|[^}]+\}/', $attrValue)) {
                        $errors = $this->getGrammar()->validateFilterChain($attrValue, $this->cmsType);
                        foreach ($errors as $error) {
                            $this->addWarning($error, $node);
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
    
    /**
     * Process CMS header declaration and load manifests
     */
    private function processCMSHeader(array $cmsHeader): void
    {
        $cmsType = $cmsHeader['type'] ?? null;
        $sets = $cmsHeader['sets'] ?? [];
        
        // Use Grammar's CMS declaration validation
        $errors = $this->getGrammar()->validateCMSDeclaration([
            'type' => $cmsType,
            'set' => implode(',', $sets),
        ]);
        
        foreach ($errors as $error) {
            $this->addError($error);
        }
        
        if (!empty($errors)) {
            return;
        }
        
        // Also validate with CMSLoader for backward compatibility
        if (!CMSLoader::isValidCMSType($cmsType)) {
            $this->addError(
                sprintf(
                    'Invalid CMS type "%s". Valid types: %s',
                    $cmsType,
                    implode(', ', CMSLoader::getValidCMSTypes())
                )
            );
            return;
        }
        
        // Validate sets
        foreach ($sets as $set) {
            if (!CMSLoader::isValidSet($set)) {
                $this->addWarning(
                    sprintf(
                        'Invalid set "%s". Valid sets: %s',
                        $set,
                        implode(', ', CMSLoader::getValidSets())
                    )
                );
            }
        }
        
        // Load CMS manifests
        try {
            $manifestData = CMSLoader::load($cmsType, $sets);
            $this->cmsType = $cmsType;
            
            // Log successful load
            error_log(sprintf(
                '[DiSyL] Loaded CMS manifests: type=%s, sets=%s, components=%d, filters=%d',
                $cmsType,
                implode(',', $sets),
                count($manifestData['components']),
                count($manifestData['filters'])
            ));
        } catch (CMSLoaderException $e) {
            $this->addError('Failed to load CMS manifests: ' . $e->getMessage());
        }
    }
    
    /**
     * Clear validation caches
     */
    public static function clearCache(): void
    {
        self::$validatedComponents = [];
        self::$validatedFilters = [];
        Grammar::clearCache();
    }
}
