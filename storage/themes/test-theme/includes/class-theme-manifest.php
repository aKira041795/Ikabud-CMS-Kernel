<?php
/**
 * Theme Manifest Loader
 * 
 * Loads and parses the theme manifest.json for component definitions
 * 
 * @package test-theme
 */

class TestTheme_Manifest {
    
    private static $instance = null;
    private $manifest = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_manifest();
    }
    
    private function load_manifest() {
        $manifest_path = get_template_directory() . '/manifest.json';
        
        if (file_exists($manifest_path)) {
            $content = file_get_contents($manifest_path);
            $this->manifest = json_decode($content, true);
        }
    }
    
    public function get($key = null) {
        if ($key === null) {
            return $this->manifest;
        }
        
        return $this->manifest[$key] ?? null;
    }
    
    public function get_component($name) {
        return $this->manifest['components'][$name] ?? null;
    }
    
    public function get_components() {
        return $this->manifest['components'] ?? array();
    }
}

// Initialize manifest
TestTheme_Manifest::get_instance();
