<?php
namespace IkabudKernel\Core;

/**
 * Joomla Conditional Loader
 * 
 * Loads Joomla extensions (plugins/modules) conditionally based on request context
 */
class JoomlaConditionalLoader implements ConditionalLoaderInterface
{
    private string $instanceDir;
    private array $manifest;
    private array $loadedExtensions = [];
    private array $context = [];
    
    public function __construct(string $instanceDir)
    {
        $this->instanceDir = $instanceDir;
        $this->manifest = $this->loadManifest();
    }
    
    /**
     * Get CMS type
     */
    public function getCMSType(): string
    {
        return 'joomla';
    }
    
    /**
     * Determine which extensions to load based on request
     */
    public function determineExtensions(string $requestUri, array $context = []): array
    {
        $this->context = $context;
        $extensionsToLoad = [];
        
        if (empty($this->manifest['extensions'])) {
            return [];
        }
        
        foreach ($this->manifest['extensions'] as $extensionName => $config) {
            if ($this->shouldLoadExtension($extensionName, $config, $requestUri)) {
                $extensionsToLoad[] = [
                    'name' => $extensionName,
                    'priority' => $config['priority'] ?? 10,
                    'config' => $config,
                    'type' => $config['type'] ?? 'plugin' // plugin, module, component
                ];
            }
        }
        
        // Sort by priority (lower number = higher priority)
        usort($extensionsToLoad, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        return $extensionsToLoad;
    }
    
    /**
     * Check if extension should load for this request
     */
    private function shouldLoadExtension(string $extensionName, array $config, string $requestUri): bool
    {
        $loadOn = $config['load_on'] ?? [];
        
        // Always load if no rules defined
        if (empty($loadOn)) {
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
        
        // Check component context
        if (isset($loadOn['components']) && isset($this->context['component'])) {
            if (in_array($this->context['component'], $loadOn['components'])) {
                return true;
            }
        }
        
        // Check menu item types
        if (isset($loadOn['menu_types']) && isset($this->context['menu_type'])) {
            if (in_array($this->context['menu_type'], $loadOn['menu_types'])) {
                return true;
            }
        }
        
        // Check admin context
        if (isset($loadOn['admin']) && $loadOn['admin'] === true) {
            if (str_contains($requestUri, '/administrator')) {
                return true;
            }
        }
        
        // Check query parameters
        if (isset($loadOn['query_params']) && !empty($_GET)) {
            foreach ($loadOn['query_params'] as $param) {
                if (isset($_GET[$param])) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Load determined extensions
     */
    public function loadExtensions(array $extensions): void
    {
        if (empty($extensions)) {
            return;
        }
        
        foreach ($extensions as $extension) {
            try {
                switch ($extension['type']) {
                    case 'plugin':
                        $this->loadPlugin($extension);
                        break;
                        
                    case 'module':
                        $this->loadModule($extension);
                        break;
                        
                    case 'component':
                        $this->loadComponent($extension);
                        break;
                        
                    default:
                        error_log("Unknown Joomla extension type: {$extension['type']}");
                }
            } catch (\Throwable $e) {
                error_log("Failed to load Joomla extension {$extension['name']}: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Load Joomla plugin
     */
    private function loadPlugin(array $extension): void
    {
        $config = $extension['config'];
        $group = $config['group'] ?? 'system';
        $pluginName = $extension['name'];
        
        $pluginPath = $this->instanceDir . "/plugins/$group/$pluginName/$pluginName.php";
        
        if (file_exists($pluginPath)) {
            // Import Joomla plugin if framework is loaded
            if (class_exists('JPluginHelper')) {
                \JPluginHelper::importPlugin($group, $pluginName);
            } else {
                // Fallback: direct include
                include_once $pluginPath;
            }
            
            $this->loadedExtensions[] = [
                'name' => $pluginName,
                'type' => 'plugin',
                'group' => $group
            ];
        }
    }
    
    /**
     * Load Joomla module
     */
    private function loadModule(array $extension): void
    {
        $moduleName = $extension['name'];
        $modulePath = $this->instanceDir . "/modules/$moduleName/$moduleName.php";
        
        if (file_exists($modulePath)) {
            // Modules are typically loaded by Joomla's module renderer
            // We just track that it should be loaded
            $this->loadedExtensions[] = [
                'name' => $moduleName,
                'type' => 'module'
            ];
        }
    }
    
    /**
     * Load Joomla component
     */
    private function loadComponent(array $extension): void
    {
        $componentName = $extension['name'];
        $componentPath = $this->instanceDir . "/components/$componentName/$componentName.php";
        
        if (file_exists($componentPath)) {
            // Components are loaded by Joomla's component dispatcher
            // We just track that it should be loaded
            $this->loadedExtensions[] = [
                'name' => $componentName,
                'type' => 'component'
            ];
        }
    }
    
    /**
     * Get list of loaded extensions
     */
    public function getLoadedExtensions(): array
    {
        return $this->loadedExtensions;
    }
    
    /**
     * Check if conditional loading is enabled
     */
    public function isEnabled(): bool
    {
        return !empty($this->manifest['extensions']);
    }
    
    /**
     * Get loading statistics
     */
    public function getStats(): array
    {
        $totalExtensions = count($this->manifest['extensions'] ?? []);
        $loadedCount = count($this->loadedExtensions);
        
        return [
            'cms_type' => 'joomla',
            'total_extensions' => $totalExtensions,
            'loaded_extensions' => $loadedCount,
            'skipped_extensions' => $totalExtensions - $loadedCount,
            'extensions' => $this->loadedExtensions,
            'memory_saved_estimate' => ($totalExtensions - $loadedCount) * 3 // ~3MB per extension estimate
        ];
    }
    
    /**
     * Match route pattern
     */
    private function matchesRoute(string $uri, string $pattern): bool
    {
        // Normalize URIs
        $uri = rtrim($uri, '/');
        $pattern = rtrim($pattern, '/');
        
        // Exact match
        if ($uri === $pattern) {
            return true;
        }
        
        // Wildcard match
        if (str_contains($pattern, '*')) {
            $regex = str_replace('*', '.*', preg_quote($pattern, '#'));
            $regex = '#^' . $regex . '#';
            return (bool) preg_match($regex, $uri);
        }
        
        // Prefix match
        if (str_starts_with($uri, $pattern . '/')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Load extension manifest
     */
    private function loadManifest(): array
    {
        $manifestFile = $this->instanceDir . '/extension-manifest.json';
        
        if (!file_exists($manifestFile)) {
            return ['extensions' => [], 'cms_type' => 'joomla'];
        }
        
        $content = file_get_contents($manifestFile);
        $manifest = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse extension manifest: " . json_last_error_msg());
            return ['extensions' => [], 'cms_type' => 'joomla'];
        }
        
        return $manifest;
    }
    
    /**
     * Get manifest data
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }
}
