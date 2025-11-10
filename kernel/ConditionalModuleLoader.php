<?php
namespace IkabudKernel\Core;

/**
 * Conditional Module Loader for Drupal
 * 
 * Loads Drupal modules conditionally based on request context
 * to minimize resource usage and maximize performance.
 */
class ConditionalModuleLoader implements ConditionalLoaderInterface
{
    private string $instanceDir;
    private array $manifest;
    private array $loadedModules = [];
    private array $context = [];
    private bool $enabled = true;
    private array $stats = [
        'total_modules' => 0,
        'loaded_modules' => 0,
        'skipped_modules' => 0,
        'load_time_ms' => 0
    ];
    
    public function __construct(string $instanceDir)
    {
        $this->instanceDir = $instanceDir;
        $this->manifest = $this->loadManifest();
    }
    
    /**
     * Determine which modules to load based on request
     */
    public function determineExtensions(string $requestUri, array $context = []): array
    {
        $this->context = $context;
        $modulesToLoad = [];
        
        if (empty($this->manifest['modules'])) {
            return [];
        }
        
        $this->stats['total_modules'] = count($this->manifest['modules']);
        
        foreach ($this->manifest['modules'] as $moduleName => $config) {
            if ($this->shouldLoadModule($moduleName, $config, $requestUri)) {
                $modulesToLoad[] = [
                    'name' => $moduleName,
                    'priority' => $config['priority'] ?? 10,
                    'config' => $config
                ];
            } else {
                $this->stats['skipped_modules']++;
            }
        }
        
        // Sort by priority (lower number = higher priority)
        usort($modulesToLoad, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        $this->stats['loaded_modules'] = count($modulesToLoad);
        
        return $modulesToLoad;
    }
    
    /**
     * Load the determined modules
     */
    public function loadExtensions(array $modules): void
    {
        $startTime = microtime(true);
        
        foreach ($modules as $moduleInfo) {
            $this->loadModule($moduleInfo['name'], $moduleInfo['config']);
        }
        
        $this->stats['load_time_ms'] = (microtime(true) - $startTime) * 1000;
    }
    
    /**
     * Get list of loaded modules
     */
    public function getLoadedExtensions(): array
    {
        return $this->loadedModules;
    }
    
    /**
     * Check if conditional loading is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Get loading statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'efficiency' => $this->stats['total_modules'] > 0 
                ? round((1 - ($this->stats['loaded_modules'] / $this->stats['total_modules'])) * 100, 2) . '%'
                : '0%'
        ]);
    }
    
    /**
     * Get the CMS type this loader handles
     */
    public function getCMSType(): string
    {
        return 'drupal';
    }
    
    // ========================================================================
    // PRIVATE METHODS
    // ========================================================================
    
    /**
     * Check if module should load for this request
     */
    private function shouldLoadModule(string $moduleName, array $config, string $requestUri): bool
    {
        $loadOn = $config['load_on'] ?? [];
        
        // Always load if no rules defined or if it's a core/required module
        if (empty($loadOn) || ($config['required'] ?? false)) {
            return true;
        }
        
        // Check if explicitly disabled
        if (isset($config['enabled']) && $config['enabled'] === false) {
            return false;
        }
        
        // Check route patterns
        if (isset($loadOn['routes'])) {
            foreach ($loadOn['routes'] as $route) {
                if ($route === '*' || $this->matchesRoute($requestUri, $route)) {
                    return true;
                }
            }
        }
        
        // Check content types (node types)
        if (isset($loadOn['content_types']) && isset($this->context['content_type'])) {
            if (in_array($this->context['content_type'], $loadOn['content_types'])) {
                return true;
            }
        }
        
        // Check if user is admin
        if (isset($loadOn['admin_only']) && $loadOn['admin_only'] === true) {
            if (isset($this->context['is_admin']) && $this->context['is_admin']) {
                return true;
            }
            return false;
        }
        
        // Check field types (if content uses specific field types)
        if (isset($loadOn['field_types']) && isset($this->context['field_types'])) {
            foreach ($loadOn['field_types'] as $fieldType) {
                if (in_array($fieldType, $this->context['field_types'])) {
                    return true;
                }
            }
        }
        
        // Check taxonomy vocabularies
        if (isset($loadOn['vocabularies']) && isset($this->context['vocabulary'])) {
            if (in_array($this->context['vocabulary'], $loadOn['vocabularies'])) {
                return true;
            }
        }
        
        // Check view modes
        if (isset($loadOn['view_modes']) && isset($this->context['view_mode'])) {
            if (in_array($this->context['view_mode'], $loadOn['view_modes'])) {
                return true;
            }
        }
        
        // If we have load_on rules but none matched, don't load
        return false;
    }
    
    /**
     * Check if URI matches route pattern
     */
    private function matchesRoute(string $uri, string $pattern): bool
    {
        // Convert pattern to regex
        $regex = str_replace(
            ['*', '/'],
            ['.*', '\\/'],
            $pattern
        );
        
        return (bool) preg_match('/^' . $regex . '$/', $uri);
    }
    
    /**
     * Load a specific module
     */
    private function loadModule(string $moduleName, array $config): void
    {
        // In Drupal, modules are loaded by the module system
        // This is more of a tracking/logging function
        $this->loadedModules[] = $moduleName;
        
        error_log("Ikabud Conditional Loader: Loaded module '{$moduleName}'");
    }
    
    /**
     * Load manifest file
     */
    private function loadManifest(): array
    {
        $manifestFile = $this->instanceDir . '/ikabud-modules-manifest.json';
        
        if (!file_exists($manifestFile)) {
            return $this->generateDefaultManifest();
        }
        
        $content = file_get_contents($manifestFile);
        $manifest = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Ikabud: Failed to parse module manifest: " . json_last_error_msg());
            return $this->generateDefaultManifest();
        }
        
        return $manifest;
    }
    
    /**
     * Generate default manifest for common Drupal modules
     */
    private function generateDefaultManifest(): array
    {
        return [
            'version' => '1.0.0',
            'modules' => [
                // Core modules - always load
                'system' => [
                    'required' => true,
                    'priority' => 1
                ],
                'user' => [
                    'required' => true,
                    'priority' => 1
                ],
                'node' => [
                    'required' => true,
                    'priority' => 1
                ],
                
                // Admin-only modules
                'toolbar' => [
                    'load_on' => [
                        'admin_only' => true
                    ],
                    'priority' => 5
                ],
                'admin_toolbar' => [
                    'load_on' => [
                        'admin_only' => true
                    ],
                    'priority' => 5
                ],
                
                // Content editing modules
                'ckeditor' => [
                    'load_on' => [
                        'routes' => ['/node/add/*', '/node/*/edit'],
                        'admin_only' => true
                    ],
                    'priority' => 10
                ],
                
                // Media modules
                'media' => [
                    'load_on' => [
                        'content_types' => ['article', 'page'],
                        'field_types' => ['image', 'media']
                    ],
                    'priority' => 15
                ],
                'image' => [
                    'load_on' => [
                        'field_types' => ['image']
                    ],
                    'priority' => 15
                ],
                
                // Comment module
                'comment' => [
                    'load_on' => [
                        'content_types' => ['article'],
                        'routes' => ['/comment/*']
                    ],
                    'priority' => 20
                ],
                
                // Search module
                'search' => [
                    'load_on' => [
                        'routes' => ['/search', '/search/*']
                    ],
                    'priority' => 25
                ],
                
                // Contact module
                'contact' => [
                    'load_on' => [
                        'routes' => ['/contact', '/contact/*']
                    ],
                    'priority' => 30
                ],
                
                // Views module - load on most pages
                'views' => [
                    'load_on' => [
                        'routes' => ['*']
                    ],
                    'priority' => 10
                ],
                
                // Taxonomy
                'taxonomy' => [
                    'load_on' => [
                        'routes' => ['/taxonomy/*'],
                        'content_types' => ['article']
                    ],
                    'priority' => 12
                ],
                
                // Path/Pathauto
                'path' => [
                    'required' => true,
                    'priority' => 5
                ],
                'pathauto' => [
                    'load_on' => [
                        'admin_only' => true
                    ],
                    'priority' => 15
                ],
                
                // Performance modules
                'big_pipe' => [
                    'load_on' => [
                        'routes' => ['*']
                    ],
                    'priority' => 50
                ],
                'dynamic_page_cache' => [
                    'load_on' => [
                        'routes' => ['*']
                    ],
                    'priority' => 50
                ],
            ]
        ];
    }
}
