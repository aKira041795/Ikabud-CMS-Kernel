<?php
/**
 * Abstract Theme Generator
 * 
 * Base class with common functionality for all CMS generators.
 * Extend this class to create new CMS-specific generators.
 * 
 * @package IkabudKernel\ThemeGenerator
 * @version 1.0.0
 */

namespace IkabudKernel\ThemeGenerator;

abstract class AbstractThemeGenerator implements ThemeGeneratorInterface
{
    /**
     * Storage path for generated themes
     */
    protected string $storagePath;
    
    /**
     * Template path for stubs
     */
    protected string $templatePath;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->storagePath = dirname(__DIR__, 2) . '/storage/themes';
        $this->templatePath = __DIR__ . '/Templates/' . $this->getCmsId();
        
        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }
    
    /**
     * Generate theme slug from name
     * 
     * @param string $name Theme name
     * @return string URL-safe slug
     */
    protected function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'theme';
    }
    
    /**
     * Normalize configuration with defaults
     * 
     * @param array $config Raw configuration
     * @return array Normalized configuration
     */
    protected function normalizeConfig(array $config): array
    {
        $themeName = $config['themeName'] ?? 'My Theme';
        
        return [
            'themeName' => $themeName,
            'themeSlug' => $config['themeSlug'] ?? $this->generateSlug($themeName),
            'author' => $config['author'] ?? 'Ikabud Theme Builder',
            'authorUri' => $config['authorUri'] ?? '',
            'description' => $config['description'] ?? 'A DiSyL-powered theme',
            'version' => $config['version'] ?? '1.0.0',
            'license' => $config['license'] ?? 'GPL-2.0-or-later',
            'textDomain' => $config['textDomain'] ?? $this->generateSlug($themeName),
            'templates' => $config['templates'] ?? [],
            'options' => array_merge($this->getDefaultOptions(), $config['options'] ?? []),
        ];
    }
    
    /**
     * Get default options for this CMS
     * Override in subclasses for CMS-specific defaults
     * 
     * @return array Default options
     */
    protected function getDefaultOptions(): array
    {
        return [
            'includeCustomizer' => true,
            'includeWidgetAreas' => true,
            'menuLocations' => ['primary', 'footer'],
            'imageSizes' => [
                'hero' => [1920, 1080, true],
                'featured' => [800, 600, true],
                'thumbnail' => [400, 300, true],
            ],
        ];
    }
    
    /**
     * Create theme directory structure
     * 
     * @param string $basePath Base path for theme
     * @param array $directories Directories to create
     */
    protected function createDirectories(string $basePath, array $directories): void
    {
        foreach ($directories as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
    
    /**
     * Write file with content
     * 
     * @param string $path File path
     * @param string $content File content
     * @return bool Success
     */
    protected function writeFile(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($path, $content) !== false;
    }
    
    /**
     * Load template stub file
     * 
     * @param string $name Stub name (without extension)
     * @return string Stub content
     */
    protected function loadStub(string $name): string
    {
        $stubPath = $this->templatePath . '/' . $name . '.stub';
        
        if (file_exists($stubPath)) {
            return file_get_contents($stubPath);
        }
        
        // Fallback to common stubs
        $commonPath = __DIR__ . '/Templates/common/' . $name . '.stub';
        if (file_exists($commonPath)) {
            return file_get_contents($commonPath);
        }
        
        return '';
    }
    
    /**
     * Replace placeholders in template
     * 
     * @param string $template Template content
     * @param array $replacements Key-value replacements
     * @return string Processed template
     */
    protected function replacePlaceholders(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
    
    /**
     * Create ZIP archive of theme
     * 
     * @param string $themePath Theme directory path
     * @param string $zipPath Output ZIP path
     * @return bool Success
     */
    protected function createZipArchive(string $themePath, string $zipPath): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($themePath) + 1);
                $zip->addFile($filePath, basename($themePath) . '/' . $relativePath);
            }
        }
        
        return $zip->close();
    }
    
    /**
     * Validate theme configuration
     * 
     * @param array $config Theme configuration
     * @return array Validation result
     */
    public function validate(array $config): array
    {
        $errors = [];
        
        if (empty($config['themeName'])) {
            $errors[] = 'Theme name is required';
        }
        
        if (empty($config['templates'])) {
            $errors[] = 'At least one template is required';
        }
        
        // Check for required templates
        $requiredTemplates = array_filter(
            $this->getBaseTemplates(),
            fn($t) => $t['required'] ?? false
        );
        
        foreach ($requiredTemplates as $id => $template) {
            if (empty($config['templates'][$id])) {
                $errors[] = "Required template missing: {$template['name']}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Generate manifest.json for the theme
     * 
     * @param array $config Normalized configuration
     * @return string JSON content
     */
    protected function generateManifest(array $config): string
    {
        $manifest = [
            '$schema' => 'https://ikabud.com/schemas/theme-manifest-v1.json',
            'name' => $config['themeName'],
            'version' => $config['version'],
            'description' => $config['description'],
            'author' => $config['author'],
            'license' => $config['license'],
            'disyl_version' => '1.2.0',
            'cms_support' => [$this->getCmsId()],
            'components' => $this->generateComponentManifest($config),
            'customizer' => $this->generateCustomizerManifest($config),
        ];
        
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Generate component manifest entries
     * Override in subclasses for CMS-specific components
     * 
     * @param array $config Configuration
     * @return array Component manifest
     */
    protected function generateComponentManifest(array $config): array
    {
        return [];
    }
    
    /**
     * Generate customizer manifest entries
     * Override in subclasses for CMS-specific customizer
     * 
     * @param array $config Configuration
     * @return array Customizer manifest
     */
    protected function generateCustomizerManifest(array $config): array
    {
        return [];
    }
}
