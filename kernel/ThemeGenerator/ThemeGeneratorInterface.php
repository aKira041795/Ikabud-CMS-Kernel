<?php
/**
 * Theme Generator Interface
 * 
 * Base interface for all CMS-specific theme generators.
 * Implement this interface to add support for new CMS platforms.
 * 
 * @package IkabudKernel\ThemeGenerator
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator;

interface ThemeGeneratorInterface
{
    /**
     * Get the CMS identifier
     * 
     * @return string CMS identifier (e.g., 'wordpress', 'joomla', 'drupal')
     */
    public function getCmsId(): string;
    
    /**
     * Get CMS display name
     * 
     * @return string Human-readable CMS name
     */
    public function getCmsName(): string;
    
    /**
     * Generate a complete theme package
     * 
     * @param array $config Theme configuration
     *   - themeName: string - Theme display name
     *   - themeSlug: string - Theme directory name (auto-generated if not provided)
     *   - author: string - Theme author
     *   - description: string - Theme description
     *   - version: string - Theme version
     *   - templates: array - DiSyL template content keyed by template name
     *   - options: array - CMS-specific options
     * 
     * @return array Generated theme data
     *   - theme: array - Theme metadata
     *   - files: array - Generated file paths and contents
     *   - downloadUrl: string|null - URL to download the theme package
     */
    public function generate(array $config): array;
    
    /**
     * Preview generated files without saving
     * 
     * @param array $config Theme configuration
     * @return array File contents keyed by relative path
     */
    public function preview(array $config): array;
    
    /**
     * Get base template stubs for this CMS
     * 
     * @return array Template definitions
     *   [
     *     'home' => ['name' => 'Homepage', 'required' => true, 'stub' => '...'],
     *     'single' => ['name' => 'Single Post', 'required' => true, 'stub' => '...'],
     *   ]
     */
    public function getBaseTemplates(): array;
    
    /**
     * Get base component stubs for this CMS
     * 
     * @return array Component definitions
     *   [
     *     'header' => ['name' => 'Header', 'required' => true, 'stub' => '...'],
     *     'footer' => ['name' => 'Footer', 'required' => true, 'stub' => '...'],
     *   ]
     */
    public function getBaseComponents(): array;
    
    /**
     * Get supported features for this CMS
     * 
     * @return array Feature list with descriptions
     *   [
     *     'customizer' => ['name' => 'Customizer API', 'description' => '...'],
     *     'widgets' => ['name' => 'Widget Areas', 'description' => '...'],
     *   ]
     */
    public function getSupportedFeatures(): array;
    
    /**
     * Validate theme configuration
     * 
     * @param array $config Theme configuration
     * @return array Validation result ['valid' => bool, 'errors' => array]
     */
    public function validate(array $config): array;
}
