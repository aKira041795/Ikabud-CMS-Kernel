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
        
        // Ensure storage directory exists with proper permissions
        $this->ensureDirectoryExists($this->storagePath);
        
        // Ensure kernel templates directory exists for shared assets
        $kernelTemplatesPath = dirname(__DIR__, 2) . '/kernel/templates';
        $this->ensureDirectoryExists($kernelTemplatesPath);
        
        // Create default disyl-components.css if missing
        $disylCssPath = $kernelTemplatesPath . '/disyl-components.css';
        if (!file_exists($disylCssPath)) {
            @file_put_contents($disylCssPath, $this->getDefaultDisylComponentsCss());
        }
    }
    
    /**
     * Ensure a directory exists with proper permissions
     * 
     * @param string $path Directory path
     * @return bool Success
     */
    protected function ensureDirectoryExists(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        
        // Try to create with 0755 first, then 0777 if that fails
        if (@mkdir($path, 0755, true)) {
            return true;
        }
        
        // Try with more permissive permissions
        if (@mkdir($path, 0777, true)) {
            return true;
        }
        
        // Log error but don't throw - allow graceful degradation
        error_log("ThemeGenerator: Failed to create directory: {$path}");
        return false;
    }
    
    /**
     * Get default DiSyL components CSS
     * 
     * @return string Default CSS content
     */
    protected function getDefaultDisylComponentsCss(): string
    {
        return <<<'CSS'
/**
 * DiSyL Components CSS
 * Default styles for DiSyL template components
 */

/* Section */
.ikb-section {
    position: relative;
    width: 100%;
}

.ikb-section--hero { padding: 4rem 0; }
.ikb-section--content { padding: 3rem 0; }
.ikb-section--features { padding: 4rem 0; }
.ikb-section--cta { padding: 3rem 0; }

/* Container */
.ikb-container {
    width: 100%;
    margin-left: auto;
    margin-right: auto;
    padding-left: 1rem;
    padding-right: 1rem;
}

.ikb-container--sm { max-width: 640px; }
.ikb-container--md { max-width: 768px; }
.ikb-container--lg { max-width: 1024px; }
.ikb-container--xl { max-width: 1280px; }
.ikb-container--full { max-width: 100%; }

/* Grid */
.ikb-grid {
    display: grid;
    gap: 1.5rem;
}

.ikb-grid--cols-2 { grid-template-columns: repeat(2, 1fr); }
.ikb-grid--cols-3 { grid-template-columns: repeat(3, 1fr); }
.ikb-grid--cols-4 { grid-template-columns: repeat(4, 1fr); }

/* Text */
.ikb-text { margin-bottom: 1rem; }
.ikb-text--center { text-align: center; }
.ikb-text--left { text-align: left; }
.ikb-text--right { text-align: right; }

/* Button */
.ikb-button {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    border-radius: 0.375rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
}

.ikb-button--primary {
    background-color: var(--color-primary, #3b82f6);
    color: white;
}

.ikb-button--secondary {
    background-color: var(--color-secondary, #6b7280);
    color: white;
}

/* Card */
.ikb-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.ikb-card__image { width: 100%; height: auto; }
.ikb-card__content { padding: 1.5rem; }
.ikb-card__title { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; }
.ikb-card__description { color: #6b7280; }

/* Responsive */
@media (max-width: 768px) {
    .ikb-grid--cols-2,
    .ikb-grid--cols-3,
    .ikb-grid--cols-4 {
        grid-template-columns: 1fr;
    }
}
CSS;
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
        // Ensure base path exists first
        $this->ensureDirectoryExists($basePath);
        
        foreach ($directories as $dir) {
            $path = $basePath . '/' . $dir;
            $this->ensureDirectoryExists($path);
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
        if (!$this->ensureDirectoryExists($dir)) {
            error_log("ThemeGenerator: Cannot create directory for file: {$path}");
            return false;
        }
        
        $result = @file_put_contents($path, $content);
        if ($result === false) {
            error_log("ThemeGenerator: Failed to write file: {$path}");
            return false;
        }
        
        return true;
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
