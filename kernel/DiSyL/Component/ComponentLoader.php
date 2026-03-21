<?php
/**
 * DiSyL v4.0 Component Loader
 * 
 * Loads and caches single-file components from the filesystem.
 * 
 * @package Ikabud\Kernel\DiSyL\Component
 * @version 4.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

/**
 * Component loader and registry
 */
class ComponentLoader
{
    /** @var array<string, string> Component directories */
    private array $directories = [];
    
    /** @var array<string, SingleFileComponent> Loaded components */
    private array $components = [];
    
    /** @var array<string, string> Collected styles */
    private array $styles = [];
    
    /**
     * Add a component directory
     */
    public function addDirectory(string $path, string $namespace = ''): void
    {
        $this->directories[$namespace] = rtrim($path, '/');
    }
    
    /**
     * Load a component by name
     */
    public function load(string $name): ?SingleFileComponent
    {
        // Check cache
        if (isset($this->components[$name])) {
            return $this->components[$name];
        }
        
        // Find component file
        $path = $this->findComponent($name);
        if ($path === null) {
            return null;
        }
        
        // Load and parse
        $source = file_get_contents($path);
        $component = new SingleFileComponent($name, $source);
        
        // Cache
        $this->components[$name] = $component;
        
        // Collect styles
        if ($component->getStyle()) {
            $this->styles[$name] = $component->getStyle();
        }
        
        return $component;
    }
    
    /**
     * Check if component exists
     */
    public function has(string $name): bool
    {
        if (isset($this->components[$name])) {
            return true;
        }
        return $this->findComponent($name) !== null;
    }
    
    /**
     * Find component file path
     */
    private function findComponent(string $name): ?string
    {
        // Check for namespaced component (e.g., "ui/Button")
        $parts = explode('/', $name);
        $namespace = '';
        $componentName = $name;
        
        if (count($parts) > 1) {
            $namespace = $parts[0];
            $componentName = implode('/', array_slice($parts, 1));
        }
        
        // Search in directories
        foreach ($this->directories as $ns => $dir) {
            if ($namespace !== '' && $ns !== $namespace) {
                continue;
            }
            
            $searchName = $namespace !== '' ? $componentName : $name;
            
            // Try different file patterns
            $patterns = [
                "{$dir}/{$searchName}.disyl",
                "{$dir}/{$searchName}/index.disyl",
                "{$dir}/" . ucfirst($searchName) . ".disyl",
            ];
            
            foreach ($patterns as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get all collected styles
     */
    public function getStyles(): string
    {
        return implode("\n\n", $this->styles);
    }
    
    /**
     * Get style for specific component
     */
    public function getComponentStyle(string $name): ?string
    {
        return $this->styles[$name] ?? null;
    }
    
    /**
     * Clear component cache
     */
    public function clear(): void
    {
        $this->components = [];
        $this->styles = [];
    }
    
    /**
     * Get all loaded component names
     */
    public function getLoadedComponents(): array
    {
        return array_keys($this->components);
    }
    
    /**
     * Preload all components from directories
     */
    public function preloadAll(): int
    {
        $count = 0;
        
        foreach ($this->directories as $dir) {
            $files = glob($dir . '/*.disyl');
            foreach ($files as $file) {
                $name = basename($file, '.disyl');
                if ($this->load($name)) {
                    $count++;
                }
            }
            
            // Also check subdirectories
            $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
            foreach ($subdirs as $subdir) {
                $indexFile = $subdir . '/index.disyl';
                if (file_exists($indexFile)) {
                    $name = basename($subdir);
                    if ($this->load($name)) {
                        $count++;
                    }
                }
            }
        }
        
        return $count;
    }
}
