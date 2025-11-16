<?php
/**
 * Phoenix Manifest Parser
 * 
 * Parses and validates the theme manifest.json file
 * 
 * @package Phoenix
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Phoenix_Manifest {
    
    /**
     * Manifest data
     * @var array
     */
    private $manifest = null;
    
    /**
     * Manifest file path
     * @var string
     */
    private $manifest_path;
    
    /**
     * Singleton instance
     * @var Phoenix_Manifest
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->manifest_path = get_template_directory() . '/manifest.json';
        $this->load_manifest();
    }
    
    /**
     * Load manifest from file
     */
    private function load_manifest() {
        // Check if manifest exists
        if (!file_exists($this->manifest_path)) {
            error_log('Phoenix: manifest.json not found, using defaults');
            $this->manifest = $this->get_default_manifest();
            return;
        }
        
        // Read and decode manifest
        $json = file_get_contents($this->manifest_path);
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Phoenix: Invalid manifest.json - ' . json_last_error_msg());
            $this->manifest = $this->get_default_manifest();
            return;
        }
        
        // Validate and store
        $this->manifest = $this->validate_manifest($data);
    }
    
    /**
     * Validate manifest structure
     */
    private function validate_manifest($data) {
        // Ensure required keys exist
        $defaults = $this->get_default_manifest();
        
        return array_merge($defaults, array_filter([
            'name' => $data['name'] ?? $defaults['name'],
            'version' => $data['version'] ?? $defaults['version'],
            'components' => $data['components'] ?? [],
            'templates' => $data['templates'] ?? [],
            'widget_areas' => $data['widget_areas'] ?? [],
            'customizer' => $data['customizer'] ?? [],
            'menus' => $data['menus'] ?? [],
            'image_sizes' => $data['image_sizes'] ?? [],
        ]));
    }
    
    /**
     * Get default manifest (fallback)
     */
    private function get_default_manifest() {
        return [
            'name' => 'Phoenix Theme',
            'version' => '1.0.0',
            'components' => [],
            'templates' => [],
            'widget_areas' => [],
            'customizer' => [
                'sections' => []
            ],
            'menus' => [],
            'image_sizes' => [],
        ];
    }
    
    /**
     * Get full manifest
     */
    public function get_manifest() {
        return $this->manifest;
    }
    
    /**
     * Get components
     */
    public function get_components() {
        return $this->manifest['components'] ?? [];
    }
    
    /**
     * Get single component
     */
    public function get_component($component_id) {
        return $this->manifest['components'][$component_id] ?? null;
    }
    
    /**
     * Get component props
     */
    public function get_component_props($component_id) {
        $component = $this->get_component($component_id);
        return $component['props'] ?? [];
    }
    
    /**
     * Get customizer sections
     */
    public function get_customizer_sections() {
        return $this->manifest['customizer']['sections'] ?? [];
    }
    
    /**
     * Get widget areas
     */
    public function get_widget_areas() {
        return $this->manifest['widget_areas'] ?? [];
    }
    
    /**
     * Get menus
     */
    public function get_menus() {
        return $this->manifest['menus'] ?? [];
    }
    
    /**
     * Get image sizes
     */
    public function get_image_sizes() {
        return $this->manifest['image_sizes'] ?? [];
    }
    
    /**
     * Get all customizer-enabled props across all components
     */
    public function get_customizer_props() {
        $props = [];
        
        foreach ($this->get_components() as $component_id => $component) {
            if (!isset($component['props'])) {
                continue;
            }
            
            foreach ($component['props'] as $prop_id => $prop) {
                if (isset($prop['customizer']['enabled']) && $prop['customizer']['enabled']) {
                    $props["{$component_id}_{$prop_id}"] = array_merge($prop, [
                        'component_id' => $component_id,
                        'prop_id' => $prop_id,
                    ]);
                }
            }
        }
        
        return $props;
    }
}
