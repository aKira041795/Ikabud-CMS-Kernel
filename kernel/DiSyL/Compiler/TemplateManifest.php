<?php
/**
 * DiSyL Template Manifest — JSON index of compiled template metadata.
 *
 * Captures variable usage, component references, and extends relationships
 * during the interpretative compile pipeline. Used for tooling, type
 * introspection, and language server integration.
 *
 * @package Ikabud\Kernel\DiSyL\Compiler
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Compiler;

class TemplateManifest
{
    /** @var array<string, array> Loaded manifests: template path => manifest */
    private static array $manifests = [];

    /** @var string|null Directory for persistent manifest storage */
    private static ?string $storageDir = null;

    /**
     * Set the storage directory for manifest files.
     */
    public static function setStorageDir(string $dir): void
    {
        self::$storageDir = rtrim($dir, '/');
        if (!is_dir(self::$storageDir)) {
            @mkdir(self::$storageDir, 0775, true);
        }
    }

    /**
     * Build a manifest from a compiled template source.
     *
     * Called during the compile() pipeline after all processing is done.
     *
     * @param string $templatePath Relative template path
     * @param string $compiledOutput The fully compiled PHP/HTML output
     * @param array $context The render context used during compilation
     * @return array The built manifest
     */
    public static function build(string $templatePath, string $compiledOutput, array $context): array
    {
        // Extract variable names from context keys that were accessed
        $variables = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/^[a-zA-Z_]\w*$/', $key)) {
                $variables[] = $key;
            }
        }

        // Extract component references from compiled output
        $components = [];
        if (preg_match_all('/data-ikb-component="([^"]+)"/', $compiledOutput, $compMatches)) {
            $components = array_merge($components, $compMatches[1]);
        }
        if (preg_match_all('/data-state="([^"]+)"/', $compiledOutput, $stateMatches)) {
            $components = array_merge($components, array_map(fn($n) => "state:{$n}", $stateMatches[1]));
        }
        if (preg_match_all('/data-island="([^"]+)"/', $compiledOutput, $islandMatches)) {
            $components = array_merge($components, array_map(fn($n) => "island:{$n}", $islandMatches[1]));
        }

        // Extract extends relationship
        $extends = '';
        if (preg_match('/data-template-extends="([^"]+)"/', $compiledOutput, $extMatches)) {
            $extends = $extMatches[1];
        }

        // Extract refers-to (entity views) from compiled output
        $refersTo = [];
        if (preg_match_all('/data-entity-view="([^"]+)"/', $compiledOutput, $evMatches)) {
            $refersTo = $evMatches[1];
        }

        $manifest = [
            'template' => $templatePath,
            'variables' => array_values(array_unique($variables)),
            'components' => array_values(array_unique($components)),
            'extends' => $extends,
            'refers_to' => $refersTo,
            'compiled_at' => time(),
            'bytes' => strlen($compiledOutput),
        ];

        // Store in-memory
        self::$manifests[$templatePath] = $manifest;

        // Persist if storage is configured
        if (self::$storageDir !== null) {
            $safeName = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $templatePath);
            $path = self::$storageDir . '/' . $safeName . '.manifest.json';
            file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT));
        }

        return $manifest;
    }

    /**
     * Get the manifest for a template.
     */
    public static function get(string $templatePath): ?array
    {
        // Check in-memory cache
        if (isset(self::$manifests[$templatePath])) {
            return self::$manifests[$templatePath];
        }

        // Try loading from persistent storage
        if (self::$storageDir !== null) {
            $safeName = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $templatePath);
            $path = self::$storageDir . '/' . $safeName . '.manifest.json';
            if (file_exists($path)) {
                $manifest = json_decode(file_get_contents($path), true);
                if (is_array($manifest)) {
                    self::$manifests[$templatePath] = $manifest;
                    return $manifest;
                }
            }
        }

        return null;
    }

    /**
     * Get all loaded manifests.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (self::$storageDir !== null && is_dir(self::$storageDir)) {
            $files = glob(self::$storageDir . '/*.manifest.json');
            if ($files) {
                foreach ($files as $file) {
                    $data = json_decode(file_get_contents($file), true);
                    if (is_array($data) && isset($data['template'])) {
                        self::$manifests[$data['template']] = $data;
                    }
                }
            }
        }
        return self::$manifests;
    }

    /**
     * Search manifests by variable name.
     *
     * @param string $variableName
     * @return array<string, array> Templates that use this variable
     */
    public static function findByVariable(string $variableName): array
    {
        $results = [];
        foreach (self::all() as $path => $manifest) {
            if (in_array($variableName, $manifest['variables'] ?? [], true)) {
                $results[$path] = $manifest;
            }
        }
        return $results;
    }

    /**
     * Search manifests by component name.
     *
     * @param string $componentName
     * @return array<string, array> Templates that use this component
     */
    public static function findByComponent(string $componentName): array
    {
        $results = [];
        foreach (self::all() as $path => $manifest) {
            foreach ($manifest['components'] ?? [] as $comp) {
                if ($comp === $componentName || str_ends_with($comp, ":{$componentName}")) {
                    $results[$path] = $manifest;
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * Clear all manifests.
     */
    public static function clear(): void
    {
        self::$manifests = [];
        if (self::$storageDir !== null && is_dir(self::$storageDir)) {
            $files = glob(self::$storageDir . '/*.manifest.json');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }
    }
}
