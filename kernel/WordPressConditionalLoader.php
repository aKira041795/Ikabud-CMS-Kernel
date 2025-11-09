<?php
namespace IkabudKernel\Core;

/**
 * WordPress Conditional Loader
 * 
 * Loads WordPress plugins conditionally based on request context
 */
class WordPressConditionalLoader implements ConditionalLoaderInterface
{
    private string $instanceDir;
    private array $manifest;
    private array $loadedPlugins = [];
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
        return 'wordpress';
    }
    
    /**
     * Determine which plugins to load based on request
     */
    public function determineExtensions(string $requestUri, array $context = []): array
    {
        $this->context = $context;
        $pluginsToLoad = [];
        
        if (empty($this->manifest['plugins'])) {
            return [];
        }
        
        foreach ($this->manifest['plugins'] as $pluginFile => $config) {
            if ($this->shouldLoadPlugin($pluginFile, $config, $requestUri)) {
                $pluginsToLoad[] = [
                    'file' => $pluginFile,
                    'priority' => $config['priority'] ?? 10,
                    'config' => $config,
                    'type' => 'plugin'
                ];
            }
        }
        
        // Sort by priority (lower number = higher priority)
        usort($pluginsToLoad, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        return $pluginsToLoad;
    }
    
    /**
     * Check if plugin should load for this request
     */
    private function shouldLoadPlugin(string $pluginFile, array $config, string $requestUri): bool
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
        
        // Check post types (if querying specific post type)
        if (isset($loadOn['post_types']) && isset($this->context['post_type'])) {
            if (in_array($this->context['post_type'], $loadOn['post_types'])) {
                return true;
            }
        }
        
        // Check shortcodes (if content contains shortcode)
        if (isset($loadOn['shortcodes']) && isset($this->context['content'])) {
            foreach ($loadOn['shortcodes'] as $shortcode) {
                if (str_contains($this->context['content'], "[$shortcode")) {
                    return true;
                }
            }
        }
        
        // Check post meta (if post has specific meta)
        if (isset($loadOn['post_meta']) && isset($this->context['post_meta'])) {
            foreach ($loadOn['post_meta'] as $metaKey) {
                if (isset($this->context['post_meta'][$metaKey])) {
                    return true;
                }
            }
        }
        
        // Check admin context
        if (isset($loadOn['admin']) && $loadOn['admin'] === true) {
            if (str_contains($requestUri, '/wp-admin') || str_contains($requestUri, '/wp-login')) {
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
     * Load determined plugins
     */
    public function loadExtensions(array $extensions): void
    {
        if (empty($extensions)) {
            return;
        }
        
        foreach ($extensions as $extension) {
            if ($extension['type'] !== 'plugin') {
                continue;
            }
            
            $pluginPath = $this->instanceDir . '/wp-content/plugins/' . $extension['file'];
            
            if (file_exists($pluginPath)) {
                try {
                    // Register plugin path (if function exists)
                    if (function_exists('wp_register_plugin_realpath')) {
                        wp_register_plugin_realpath($pluginPath);
                    }
                    
                    // Load the plugin
                    include_once $pluginPath;
                    
                    // Track loaded plugin
                    $this->loadedPlugins[] = $extension['file'];
                    
                    // Fire plugin_loaded hook (if function exists)
                    if (function_exists('do_action')) {
                        do_action('plugin_loaded', $pluginPath);
                    }
                } catch (\Throwable $e) {
                    error_log("Failed to load WordPress plugin {$extension['file']}: " . $e->getMessage());
                }
            }
        }
        
        // Fire plugins_loaded hook after all plugins loaded
        if (function_exists('do_action')) {
            do_action('plugins_loaded');
        }
    }
    
    /**
     * Get list of loaded plugins
     */
    public function getLoadedExtensions(): array
    {
        return $this->loadedPlugins;
    }
    
    /**
     * Check if conditional loading is enabled
     */
    public function isEnabled(): bool
    {
        return !empty($this->manifest['plugins']);
    }
    
    /**
     * Get loading statistics
     */
    public function getStats(): array
    {
        $totalPlugins = count($this->manifest['plugins'] ?? []);
        $loadedCount = count($this->loadedPlugins);
        
        return [
            'cms_type' => 'wordpress',
            'total_extensions' => $totalPlugins,
            'loaded_extensions' => $loadedCount,
            'skipped_extensions' => $totalPlugins - $loadedCount,
            'extensions' => $this->loadedPlugins,
            'memory_saved_estimate' => ($totalPlugins - $loadedCount) * 5 // ~5MB per plugin estimate
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
        
        // Prefix match (e.g., /shop matches /shop/product-123)
        if (str_starts_with($uri, $pattern . '/')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Load plugin manifest
     */
    private function loadManifest(): array
    {
        $manifestFile = $this->instanceDir . '/plugin-manifest.json';
        
        if (!file_exists($manifestFile)) {
            return ['plugins' => [], 'themes' => [], 'cms_type' => 'wordpress'];
        }
        
        $content = file_get_contents($manifestFile);
        $manifest = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse plugin manifest: " . json_last_error_msg());
            return ['plugins' => [], 'themes' => [], 'cms_type' => 'wordpress'];
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
