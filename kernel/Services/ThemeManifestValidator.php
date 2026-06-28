<?php
/**
 * ThemeManifestValidator — Kernel-governed theme manifest schema and validation.
 *
 * Defines the canonical theme manifest schema and validates manifests at
 * load time. Each theme must declare its capabilities, compatibility,
 * slots, assets, and entity-view fallbacks.
 *
 * @package Ikabud\Kernel\Services
 */

namespace Ikabud\Kernel\Services;

class ThemeManifestValidator
{
    /** @var array<string, array> Canonical schema: key => [type, required, description] */
    private const SCHEMA = [
        'name' => ['type' => 'string', 'required' => true, 'min' => 1, 'description' => 'Theme machine name (e.g., "entity-native")'],
        'version' => ['type' => 'string', 'required' => true, 'pattern' => '/^\d+\.\d+\.\d+$/', 'description' => 'Semantic version'],
        'label' => ['type' => 'string', 'required' => true, 'min' => 1, 'description' => 'Human-readable theme name'],
        'description' => ['type' => 'string', 'required' => false, 'description' => 'Theme purpose summary'],
        'author' => ['type' => 'string', 'required' => false, 'description' => 'Author or organization'],
        'license' => ['type' => 'string', 'required' => false, 'description' => 'SPDX license identifier'],
        'kernel_os_compat' => ['type' => 'string', 'required' => false, 'pattern' => '/^\d+\.\d+(\.\d+)?$/', 'description' => 'Minimum Kernel OS version'],
        'disyl_compat' => ['type' => 'string', 'required' => false, 'pattern' => '/^\d+\.\d+(\.\d+)?$/', 'description' => 'Minimum DiSyL version'],
        'supported_surfaces' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'string', 'enum' => ['public', 'admin', 'print', 'email', 'export']], 'description' => 'Rendering surfaces the theme supports'],
        'supported_slots' => ['type' => 'array', 'required' => false, 'items' => ['type' => 'string'], 'description' => 'Theme slot identifiers rendered in shell'],
        'tokens' => ['type' => 'string', 'required' => false, 'description' => 'Path to tokens.json (relative to theme root)'],
        'shell' => ['type' => 'string', 'required' => false, 'description' => 'Path to primary shell template'],
        'sections' => ['type' => 'string', 'required' => false, 'description' => 'Directory for section templates'],
        'entity_views' => ['type' => 'string', 'required' => false, 'description' => 'Directory for entity-view templates'],
        'fallback_views' => ['type' => 'object', 'required' => false, 'properties' => [
            'card' => ['type' => 'string', 'required' => false],
            'table' => ['type' => 'string', 'required' => false],
            'detail' => ['type' => 'string', 'required' => false],
            'compact' => ['type' => 'string', 'required' => false],
        ], 'description' => 'Generic entity-view fallback templates for unknown entity types'],
        'component_variants' => ['type' => 'object', 'required' => false, 'description' => 'Theme-specific ikb_* component variant mappings'],
        'design_language' => ['type' => 'object', 'required' => false, 'description' => 'Design system metadata (type scale, color system, grid, icon set)'],
        'accessibility' => ['type' => 'object', 'required' => false, 'description' => 'Accessibility guarantees and supported features'],
        'browser_support' => ['type' => 'array', 'required' => false, 'description' => 'Targeted browsers'],
        'required_assets' => ['type' => 'object', 'required' => false, 'properties' => [
            'css' => ['type' => 'array', 'required' => false],
            'js' => ['type' => 'array', 'required' => false],
            'fonts' => ['type' => 'array', 'required' => false],
        ], 'description' => 'Assets always loaded by the theme'],
        'optional_assets' => ['type' => 'object', 'required' => false, 'properties' => [
            'css' => ['type' => 'array', 'required' => false],
            'js' => ['type' => 'array', 'required' => false],
        ], 'description' => 'Assets loaded only when needed (bridges)'],
    ];

    /** @var array<string> Standard governed slot names */
    private const STANDARD_SLOTS = [
        'site.before',
        'site.after',
        'header.before',
        'header.main',
        'header.after',
        'navigation.before',
        'navigation.after',
        'hero',
        'breadcrumbs',
        'content.before',
        'content',
        'content.after',
        'sidebar.primary',
        'sidebar.secondary',
        'footer.before',
        'footer.main',
        'footer.after',
        'modal.root',
        'drawer.root',
        'notifications',
    ];

    /**
     * Validate a theme manifest against the canonical schema.
     *
     * @param string $slug     Theme slug (directory name)
     * @param array  $manifest Parsed manifest data
     * @param string $themeDir Absolute path to theme directory
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(string $slug, array $manifest, string $themeDir = ''): array
    {
        $errors = [];
        $warnings = [];

        // 1. Schema validation
        $schemaResult = self::validateSchema($manifest);
        $errors = array_merge($errors, $schemaResult['errors']);
        $warnings = array_merge($warnings, $schemaResult['warnings']);

        // 2. File existence checks
        if ($themeDir !== '' && is_dir($themeDir)) {
            $fileResult = self::validateFiles($themeDir, $manifest);
            $errors = array_merge($errors, $fileResult['errors']);
            $warnings = array_merge($warnings, $fileResult['warnings']);

            // 3. Token validation
            $tokenResult = self::validateTokens($themeDir, $manifest);
            $warnings = array_merge($warnings, $tokenResult['warnings']);
        }

        // 4. Slot validation
        $slotResult = self::validateSlots($manifest);
        $warnings = array_merge($warnings, $slotResult['warnings']);

        // 5. Fallback view validation (check declaration even without theme dir)
        $fallbacks = $manifest['fallback_views'] ?? [];
        if (empty($fallbacks)) {
            $warnings[] = "No 'fallback_views' declared — unknown entity types will lack themed presentation";
        } elseif ($themeDir !== '' && is_dir($themeDir)) {
            // Only check file existence when theme dir is available
            $fallbackResult = self::validateFallbackViews($themeDir, $manifest);
            $warnings = array_merge($warnings, $fallbackResult['warnings']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate required keys and types against the schema.
     */
    private static function validateSchema(array $manifest): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::SCHEMA as $key => $rule) {
            $hasKey = array_key_exists($key, $manifest);

            if ($rule['required'] ?? false) {
                if (!$hasKey) {
                    $errors[] = "Missing required key: '{$key}' — {$rule['description']}";
                    continue;
                }
                if ($rule['type'] === 'string' && ($rule['min'] ?? 0) > 0 && trim((string)$manifest[$key]) === '') {
                    $errors[] = "Required key '{$key}' must not be empty";
                }
            }

            if (!$hasKey) {
                continue;
            }

            $value = $manifest[$key];
            $expectedType = $rule['type'] ?? 'string';

            // Type check
            if ($expectedType === 'array' && !is_array($value)) {
                $errors[] = "Key '{$key}' must be an array, got " . gettype($value);
            } elseif ($expectedType === 'object' && !is_array($value)) {
                $errors[] = "Key '{$key}' must be an object, got " . gettype($value);
            } elseif ($expectedType === 'string' && !is_string($value)) {
                $errors[] = "Key '{$key}' must be a string, got " . gettype($value);
            }

            // Pattern check for strings
            if (is_string($value) && !empty($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                $errors[] = "Key '{$key}' value '{$value}' does not match required pattern: {$rule['pattern']}";
            }

            // Enum check for array items
            if (is_array($value) && !empty($rule['items']['enum'])) {
                foreach ($value as $i => $item) {
                    if (!in_array($item, $rule['items']['enum'], true)) {
                        $warnings[] = "Key '{$key}'[{$i}] = '{$item}' is not a standard value (expected: " . implode(', ', $rule['items']['enum']) . ")";
                    }
                }
            }

            // Check nested properties for objects
            if (is_array($value) && !empty($rule['properties'])) {
                foreach ($rule['properties'] as $propKey => $propRule) {
                    if (($propRule['required'] ?? false) && !array_key_exists($propKey, $value)) {
                        $warnings[] = "Recommended key '{$key}.{$propKey}' is missing — {$propRule['description']}";
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validate that declared files exist on disk.
     */
    private static function validateFiles(string $themeDir, array $manifest): array
    {
        $errors = [];
        $warnings = [];

        // tokens.json
        if (!empty($manifest['tokens'])) {
            $tokensPath = $themeDir . '/' . ltrim((string)$manifest['tokens'], '/');
            if (!is_file($tokensPath)) {
                $warnings[] = "Declared tokens file '{$manifest['tokens']}' not found at {$tokensPath}";
            }
        }

        // Shell template
        if (!empty($manifest['shell'])) {
            $shellPath = $themeDir . '/' . ltrim((string)$manifest['shell'], '/');
            if (!is_file($shellPath)) {
                $warnings[] = "Declared shell template '{$manifest['shell']}' not found";
            }
        }

        // Sections directory
        if (!empty($manifest['sections'])) {
            $sectionsDir = $themeDir . '/' . ltrim((string)$manifest['sections'], '/');
            if (!is_dir($sectionsDir)) {
                $warnings[] = "Declared sections directory '{$manifest['sections']}' not found";
            }
        }

        // Entity views directory
        if (!empty($manifest['entity_views'])) {
            $evDir = $themeDir . '/' . ltrim((string)$manifest['entity_views'], '/');
            if (!is_dir($evDir)) {
                $warnings[] = "Declared entity_views directory '{$manifest['entity_views']}' not found";
            }
        }

        // Layouts directory
        $layoutsDir = $themeDir . '/layouts';
        if (!is_dir($layoutsDir)) {
            $warnings[] = "Standard 'layouts/' directory not found";
        }

        // Public templates directory
        $publicDir = $themeDir . '/public';
        if (!is_dir($publicDir)) {
            $warnings[] = "Standard 'public/' directory not found";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Validate token file structure.
     */
    private static function validateTokens(string $themeDir, array $manifest): array
    {
        $warnings = [];

        $tokensFile = !empty($manifest['tokens'])
            ? $themeDir . '/' . ltrim((string)$manifest['tokens'], '/')
            : $themeDir . '/tokens.json';

        if (!is_file($tokensFile)) {
            return ['warnings' => $warnings];
        }

        $tokens = kernelReadJsonFile($tokensFile);
        if (!is_array($tokens) || empty($tokens)) {
            $warnings[] = "Tokens file '{$tokensFile}' is empty or invalid";
            return ['warnings' => $warnings];
        }

        // Detect format: nested semantic (colors -> primary) or flat CSS vars (--color-primary)
        $isFlatCssVar = false;
        foreach (array_keys($tokens) as $key) {
            if (str_starts_with((string)$key, '--')) {
                $isFlatCssVar = true;
                break;
            }
        }

        if ($isFlatCssVar) {
            // Flat CSS var format: check for key prefixes
            $recommendedPrefixes = ['--color', '--font', '--spacing', '--radius'];
            foreach ($recommendedPrefixes as $prefix) {
                $found = false;
                foreach (array_keys($tokens) as $key) {
                    if (str_starts_with((string)$key, $prefix)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $warnings[] = "Tokens file missing recommended CSS variable prefix: '{$prefix}'";
                }
            }
        } else {
            // Nested semantic format: check for category keys
            $recommendedCategories = ['colors', 'typography', 'spacing', 'radius'];
            foreach ($recommendedCategories as $cat) {
                if (!isset($tokens[$cat])) {
                    $warnings[] = "Tokens file missing recommended category: '{$cat}'";
                }
            }

            // Color completeness check
            if (isset($tokens['colors'])) {
                $recommendedColors = ['primary', 'surface', 'text', 'border'];
                foreach ($recommendedColors as $color) {
                    if (!isset($tokens['colors'][$color]) && !isset($tokens['colors'][$color . '_primary'])) {
                        $warnings[] = "Tokens 'colors' missing recommended key: '{$color}'";
                    }
                }
            }
        }

        return ['warnings' => $warnings];
    }

    /**
     * Validate that declared slots are known standard slots.
     */
    private static function validateSlots(array $manifest): array
    {
        $warnings = [];

        $slots = $manifest['supported_slots'] ?? [];
        if (!is_array($slots) || empty($slots)) {
            return ['warnings' => $warnings];
        }

        foreach ($slots as $slot) {
            if (!in_array($slot, self::STANDARD_SLOTS, true)) {
                $warnings[] = "Slot '{$slot}' is not a standard governed slot (expected one of: "
                    . implode(', ', array_slice(self::STANDARD_SLOTS, 0, 8)) . ", ...)";
            }
        }

        return ['warnings' => $warnings];
    }

    /**
     * Validate that fallback entity view templates exist on disk.
     */
    private static function validateFallbackViews(string $themeDir, array $manifest): array
    {
        $warnings = [];

        $fallbacks = $manifest['fallback_views'] ?? [];
        if (!is_array($fallbacks) || empty($fallbacks)) {
            return ['warnings' => $warnings]; // declaration warning already emitted in validate()
        }

        foreach ($fallbacks as $view => $path) {
            $fullPath = $themeDir . '/' . ltrim((string)$path, '/');
            if (!is_file($fullPath)) {
                $warnings[] = "Fallback view '{$view}' declared at '{$path}' but file not found";
            }
        }

        return ['warnings' => $warnings];
    }

    /**
     * Get the canonical schema definition.
     */
    public static function getSchema(): array
    {
        return self::SCHEMA;
    }

    /**
     * Get the list of standard governed slot names.
     */
    public static function getStandardSlots(): array
    {
        return self::STANDARD_SLOTS;
    }

    /**
     * Get human-readable field descriptions for the schema.
     */
    public static function getFieldDescriptions(): array
    {
        $descriptions = [];
        foreach (self::SCHEMA as $key => $rule) {
            $label = $rule['required'] ? 'REQUIRED' : 'optional';
            $descriptions[$key] = "[{$label}] {$rule['description']}";
        }
        return $descriptions;
    }
}
