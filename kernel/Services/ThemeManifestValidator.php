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

use Ikabud\Kernel\DiSyL\ComponentRegistry;

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
        'performance_budget' => ['type' => 'object', 'required' => false, 'properties' => [
            'css_kb' => ['type' => 'number', 'required' => false, 'description' => 'Per-file required CSS budget in kilobytes'],
            'js_kb' => ['type' => 'number', 'required' => false, 'description' => 'Per-file required JS budget in kilobytes'],
        ], 'description' => 'Optional theme-specific asset budget overrides for validation CLI'],
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

            // 3b. Optional ARK authority-layer contract validation
            $arkResult = self::validateArkContracts($themeDir, $manifest);
            $errors = array_merge($errors, $arkResult['errors']);
            $warnings = array_merge($warnings, $arkResult['warnings']);
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
        if (!empty($manifest['tokens']) && is_string($manifest['tokens'])) {
            $tokensPath = $themeDir . '/' . ltrim($manifest['tokens'], '/');
            if (!is_file($tokensPath)) {
                $warnings[] = "Declared tokens file '{$manifest['tokens']}' not found at {$tokensPath}";
            }
        }

        // Shell template
        if (!empty($manifest['shell']) && is_string($manifest['shell'])) {
            $shellPath = $themeDir . '/' . ltrim($manifest['shell'], '/');
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

        $tokensFile = (!empty($manifest['tokens']) && is_string($manifest['tokens']))
            ? $themeDir . '/' . ltrim($manifest['tokens'], '/')
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
     * Validate optional ARK authority-layer contract files when present.
     * These checks are additive and do not require non-ARK themes to adopt the files.
     *
     * @return array{errors:list<string>,warnings:list<string>}
     */
    private static function validateArkContracts(string $themeDir, array $manifest = []): array
    {
        $errors = [];
        $warnings = [];

        $rendererRegistryPath = $themeDir . '/renderer-registry.json';
        if (is_file($rendererRegistryPath)) {
            $rendererRegistry = kernelReadJsonFile($rendererRegistryPath);
            if (!is_array($rendererRegistry)) {
                $errors[] = "renderer-registry.json is not valid JSON";
            } elseif (!is_array($rendererRegistry['renderers'] ?? null) || $rendererRegistry['renderers'] === []) {
                $warnings[] = "renderer-registry.json exists but has no renderers declared";
            } else {
                $rendererResult = self::validateRendererRegistry($themeDir, $rendererRegistry);
                $errors = array_merge($errors, $rendererResult['errors']);
                $warnings = array_merge($warnings, $rendererResult['warnings']);
            }
        }

        $entityViewMapPath = $themeDir . '/entity-view-map.json';
        if (is_file($entityViewMapPath)) {
            $entityViewMap = kernelReadJsonFile($entityViewMapPath);
            if (!is_array($entityViewMap)) {
                $errors[] = "entity-view-map.json is not valid JSON";
            } elseif (!is_array($entityViewMap['entity_views'] ?? null) || $entityViewMap['entity_views'] === []) {
                $warnings[] = "entity-view-map.json exists but has no entity_views declared";
            } else {
                $entityViewResult = self::validateEntityViewMap($themeDir, $entityViewMap);
                $errors = array_merge($errors, $entityViewResult['errors']);
                $warnings = array_merge($warnings, $entityViewResult['warnings']);
            }
        }

        $blockRegistryPath = $themeDir . '/block-registry.json';
        $pageCompositionSchemaPath = $themeDir . '/page-composition.schema.json';
        if (is_file($pageCompositionSchemaPath)) {
            $pageCompositionSchema = kernelReadJsonFile($pageCompositionSchemaPath);
            if (!is_array($pageCompositionSchema)) {
                $errors[] = "page-composition.schema.json is not valid JSON";
            } else {
                $pageSchemaResult = self::validatePageCompositionSchema($themeDir, $pageCompositionSchema);
                $errors = array_merge($errors, $pageSchemaResult['errors']);
                $warnings = array_merge($warnings, $pageSchemaResult['warnings']);
            }
        } elseif (is_file($blockRegistryPath)) {
            $warnings[] = 'block-registry.json exists but page-composition.schema.json is missing';
        }

        $safetyPolicyPath = $themeDir . '/safety-policy.json';
        if (is_file($safetyPolicyPath)) {
            $safetyPolicy = kernelReadJsonFile($safetyPolicyPath);
            if (!is_array($safetyPolicy)) {
                $errors[] = "safety-policy.json is not valid JSON";
            } elseif (!is_array($safetyPolicy['policy'] ?? null) || $safetyPolicy['policy'] === []) {
                $warnings[] = "safety-policy.json exists but has no policy object";
            } else {
                $policyResult = self::validateThemeSafetyPolicy($themeDir, $manifest, $safetyPolicy);
                $errors = array_merge($errors, $policyResult['errors']);
                $warnings = array_merge($warnings, $policyResult['warnings']);
            }
        }

        $blockDefinitionsDir = $themeDir . '/block-definitions';
        if (is_file($blockRegistryPath)) {
            $blockRegistry = kernelReadJsonFile($blockRegistryPath);
            if (!is_array($blockRegistry)) {
                $errors[] = "block-registry.json is not valid JSON";
            } elseif (!is_array($blockRegistry['categories'] ?? null) || $blockRegistry['categories'] === []) {
                $warnings[] = "block-registry.json exists but has no categories declared";
            } else {
                if (!is_dir($blockDefinitionsDir)) {
                    $warnings[] = "block-registry.json exists but block-definitions/ directory is missing";
                } else {
                    $schemaPath = $blockDefinitionsDir . '/block-definition.schema.json';
                    $registeredBlocks = self::registeredBlockTypes($themeDir);
                    if (!is_file($schemaPath)) {
                        $warnings[] = "block-definitions/block-definition.schema.json is missing";
                    }

                    foreach ($blockRegistry['categories'] as $categoryName => $blockTypes) {
                        if (!is_array($blockTypes)) {
                            $warnings[] = "block-registry category '{$categoryName}' must be an array";
                            continue;
                        }

                        foreach ($blockTypes as $blockType) {
                            $blockType = trim((string)$blockType);
                            if ($blockType === '') {
                                continue;
                            }
                            $definitionPath = $blockDefinitionsDir . '/' . $categoryName . '/' . $blockType . '.json';
                            if (!is_file($definitionPath)) {
                                $warnings[] = "Block registry entry '{$categoryName}.{$blockType}' is missing definition file block-definitions/{$categoryName}/{$blockType}.json";
                                continue;
                            }

                            $definition = kernelReadJsonFile($definitionPath);
                            if (!is_array($definition)) {
                                $errors[] = "Block definition '{$categoryName}.{$blockType}' is not valid JSON";
                                continue;
                            }

                            foreach (['type', 'label', 'controls', 'renders_with'] as $requiredKey) {
                                if (!array_key_exists($requiredKey, $definition)) {
                                    $warnings[] = "Block definition '{$categoryName}.{$blockType}' is missing required key '{$requiredKey}'";
                                }
                            }

                            $renderTarget = trim((string)($definition['renders_with'] ?? ''));
                            if ($renderTarget !== '') {
                                $renderTargetResult = self::validateBlockDefinitionRenderTarget($themeDir, $categoryName, $blockType, $renderTarget);
                                $warnings = array_merge($warnings, $renderTargetResult['warnings']);
                            }

                            $relationshipResult = self::validateBlockDefinitionRelationships($categoryName, $blockType, $definition, $registeredBlocks);
                            $warnings = array_merge($warnings, $relationshipResult['warnings']);
                        }
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{errors:list<string>,warnings:list<string>}
     */
    private static function validateEntityViewMap(string $themeDir, array $entityViewMap): array
    {
        $errors = [];
        $warnings = [];
        $entityViews = is_array($entityViewMap['entity_views'] ?? null) ? $entityViewMap['entity_views'] : [];
        $registeredBlocks = self::registeredBlockTypes($themeDir);

        foreach ($entityViews as $entityType => $views) {
            $entityType = trim((string)$entityType);
            if ($entityType === '') {
                $warnings[] = 'entity-view-map.json contains an entity_views entry with an empty entity type';
                continue;
            }
            if (!is_array($views) || $views === []) {
                $warnings[] = "entity-view-map entity type '{$entityType}' must declare at least one view object";
                continue;
            }

            foreach ($views as $viewName => $definition) {
                $viewName = trim((string)$viewName);
                if ($viewName === '') {
                    $warnings[] = "entity-view-map entity type '{$entityType}' contains an empty view name";
                    continue;
                }
                if (!is_array($definition)) {
                    $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' must be an object";
                    continue;
                }

                $fields = $definition['fields'] ?? null;
                if (!is_array($fields) || $fields === []) {
                    $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' must declare a non-empty fields array";
                } else {
                    foreach ($fields as $index => $field) {
                        if (trim((string)$field) === '') {
                            $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' fields[{$index}] must be a non-empty string";
                        }
                    }
                }

                $actions = $definition['actions'] ?? null;
                if (!is_array($actions)) {
                    $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' actions must be an array";
                } else {
                    foreach ($actions as $index => $action) {
                        if (trim((string)$action) === '') {
                            $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' actions[{$index}] must be a non-empty string";
                        }
                    }
                }

                if (array_key_exists('block', $definition)) {
                    $blockType = trim((string)$definition['block']);
                    if ($blockType === '') {
                        $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' block must be a non-empty string when declared";
                    } elseif (!in_array($blockType, $registeredBlocks, true)) {
                        $warnings[] = "entity-view-map entry '{$entityType}.{$viewName}' references unknown block '{$blockType}'";
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return array{errors:list<string>,warnings:list<string>}
     */
    private static function validatePageCompositionSchema(string $themeDir, array $pageCompositionSchema): array
    {
        $errors = [];
        $warnings = [];
        $registeredBlocks = self::registeredBlockTypes($themeDir);

        if (trim((string)($pageCompositionSchema['version'] ?? '')) === '') {
            $warnings[] = 'page-composition.schema.json is missing a version string';
        }

        $documentEnvelope = is_array($pageCompositionSchema['document_envelope'] ?? null) ? $pageCompositionSchema['document_envelope'] : [];
        $envelopeRequiredKeys = array_values(array_filter(array_map('strval', is_array($documentEnvelope['required_keys'] ?? null) ? $documentEnvelope['required_keys'] : [])));
        foreach (['schema_version', 'document'] as $requiredKey) {
            if (!in_array($requiredKey, $envelopeRequiredKeys, true)) {
                $warnings[] = "page-composition.schema.json document_envelope.required_keys must include '{$requiredKey}'";
            }
        }

        $rootNode = is_array($pageCompositionSchema['root_node'] ?? null) ? $pageCompositionSchema['root_node'] : [];
        if (trim((string)($rootNode['type'] ?? '')) !== 'document') {
            $warnings[] = "page-composition.schema.json root_node.type should be 'document'";
        }
        if (trim((string)($rootNode['children_key'] ?? '')) !== 'children') {
            $warnings[] = "page-composition.schema.json root_node.children_key should be 'children'";
        }

        $rootRequiredKeys = array_values(array_filter(array_map('strval', is_array($rootNode['required_keys'] ?? null) ? $rootNode['required_keys'] : [])));
        foreach (['id', 'type', 'props', 'style', 'children', 'meta'] as $requiredKey) {
            if (!in_array($requiredKey, $rootRequiredKeys, true)) {
                $warnings[] = "page-composition.schema.json root_node.required_keys must include '{$requiredKey}'";
            }
        }

        $topLevelChildren = array_values(array_filter(array_map('strval', is_array($pageCompositionSchema['allowed_top_level_children'] ?? null) ? $pageCompositionSchema['allowed_top_level_children'] : [])));
        if ($topLevelChildren === []) {
            $warnings[] = 'page-composition.schema.json must declare allowed_top_level_children';
        } elseif ($registeredBlocks !== []) {
            foreach ($topLevelChildren as $nodeType) {
                if (!in_array($nodeType, $registeredBlocks, true)) {
                    $warnings[] = "page-composition.schema.json allowed_top_level_children references unknown ARK block type '{$nodeType}'";
                }
            }
        }

        $nodeContract = is_array($pageCompositionSchema['node_contract'] ?? null) ? $pageCompositionSchema['node_contract'] : [];
        $nodeContractRequiredKeys = array_values(array_filter(array_map('strval', is_array($nodeContract['required_keys'] ?? null) ? $nodeContract['required_keys'] : [])));
        foreach (['id', 'type', 'props', 'style', 'children', 'meta'] as $requiredKey) {
            if (!in_array($requiredKey, $nodeContractRequiredKeys, true)) {
                $warnings[] = "page-composition.schema.json node_contract.required_keys must include '{$requiredKey}'";
            }
        }
        foreach (['props_must_be_object', 'style_must_be_object', 'children_must_be_array', 'meta_must_be_object'] as $boolKey) {
            if (!array_key_exists($boolKey, $nodeContract)) {
                $warnings[] = "page-composition.schema.json node_contract is missing '{$boolKey}'";
            }
        }

        $compatibility = is_array($pageCompositionSchema['compatibility'] ?? null) ? $pageCompositionSchema['compatibility'] : [];
        if (trim((string)($compatibility['cms_builder_schema_version'] ?? '')) === '') {
            $warnings[] = 'page-composition.schema.json compatibility.cms_builder_schema_version is required';
        }
        if (trim((string)($compatibility['normalizer'] ?? '')) !== 'cmsBuilderNormalizeDocument') {
            $warnings[] = "page-composition.schema.json compatibility.normalizer should be 'cmsBuilderNormalizeDocument'";
        }
        if (trim((string)($compatibility['default_document_factory'] ?? '')) !== 'cmsBuilderDefaultDocument') {
            $warnings[] = "page-composition.schema.json compatibility.default_document_factory should be 'cmsBuilderDefaultDocument'";
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return array{errors:list<string>,warnings:list<string>}
     */
    private static function validateThemeSafetyPolicy(string $themeDir, array $manifest, array $safetyPolicy): array
    {
        $errors = [];
        $warnings = [];
        $policy = is_array($safetyPolicy['policy'] ?? null) ? $safetyPolicy['policy'] : [];
        $rawOutput = is_array($policy['raw_output'] ?? null) ? $policy['raw_output'] : [];
        $allowedRawKeys = array_values(array_filter(array_map('strval', is_array($rawOutput['allowed_keys'] ?? null) ? $rawOutput['allowed_keys'] : [])));
        $blockedPatterns = array_values(array_filter(array_map('strval', is_array($policy['blocked_patterns'] ?? null) ? $policy['blocked_patterns'] : [])));
        $templateFiles = self::collectThemeTemplateFiles($themeDir);
        $supportedSlots = array_values(array_map('strval', is_array($manifest['supported_slots'] ?? null) ? $manifest['supported_slots'] : []));
        $registeredComponents = self::registeredComponentNames();

        if ($allowedRawKeys === []) {
            $warnings[] = 'safety-policy.json policy.raw_output.allowed_keys is empty';
        }

        foreach ($templateFiles as $filePath) {
            $relativePath = self::relativeThemePath($themeDir, $filePath);
            $contents = @file_get_contents($filePath);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            if (preg_match_all('/\{[^\n\r}]*?([A-Za-z_][A-Za-z0-9_.]*)\|raw\b[^}]*\}/', $contents, $rawMatches, PREG_SET_ORDER)) {
                foreach ($rawMatches as $match) {
                    $rawExpression = (string)($match[1] ?? '');
                    if ($rawExpression === '') {
                        continue;
                    }
                    if (!self::isAllowedRawExpression($rawExpression, $allowedRawKeys)) {
                        $warnings[] = "{$relativePath} uses |raw with '{$rawExpression}', which is not allowlisted in safety-policy.json";
                    }
                }
            }

            foreach (self::blockedPatternMap() as $patternLabel => $patternRegex) {
                if (in_array($patternLabel, $blockedPatterns, true) && preg_match($patternRegex, $contents) === 1) {
                    $warnings[] = "{$relativePath} matches blocked safety pattern '{$patternLabel}'";
                }
            }

            if (preg_match('/\bonclick\s*=\s*/i', $contents) === 1) {
                $warnings[] = "{$relativePath} contains inline onclick handlers, which violate the ARK CSP policy";
            }

            if ($supportedSlots !== [] && preg_match_all('/\{ikb_slot\s+name="([^"]+)"/i', $contents, $slotMatches)) {
                foreach ($slotMatches[1] as $slotName) {
                    $slotName = trim((string)$slotName);
                    if ($slotName !== '' && !in_array($slotName, $supportedSlots, true)) {
                        $warnings[] = "{$relativePath} references slot '{$slotName}' that is not declared in supported_slots";
                    }
                }
            }

            if ($registeredComponents !== [] && preg_match_all('/\{(ikb_[a-zA-Z0-9_]+)/', $contents, $componentMatches)) {
                foreach (array_unique($componentMatches[1]) as $componentName) {
                    $componentName = trim((string)$componentName);
                    if ($componentName !== '' && $componentName !== 'ikb_slot' && !in_array($componentName, $registeredComponents, true)) {
                        $warnings[] = "{$relativePath} references unregistered component '{$componentName}'";
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return array{errors:list<string>,warnings:list<string>}
     */
    private static function validateRendererRegistry(string $themeDir, array $rendererRegistry): array
    {
        $errors = [];
        $warnings = [];
        $renderers = is_array($rendererRegistry['renderers'] ?? null) ? $rendererRegistry['renderers'] : [];
        $registeredComponents = self::registeredComponentNames();

        foreach ($renderers as $rendererName => $definition) {
            if (!is_array($definition)) {
                $errors[] = "renderer-registry entry '{$rendererName}' must be an object";
                continue;
            }

            $template = trim((string)($definition['template'] ?? ''));
            $component = trim((string)($definition['renders_as_component'] ?? ''));
            $controls = $definition['controls'] ?? null;
            $contextKeys = $definition['context_keys'] ?? null;

            if ($template === '' && $component === '') {
                $warnings[] = "renderer-registry entry '{$rendererName}' declares neither template nor renders_as_component";
            }

            if ($template !== '' && $component !== '') {
                $warnings[] = "renderer-registry entry '{$rendererName}' declares both template and renders_as_component; prefer one render target";
            }

            if ($template !== '') {
                $templatePath = $themeDir . '/' . ltrim($template, '/');
                if (!is_file($templatePath)) {
                    $warnings[] = "renderer-registry entry '{$rendererName}' points to missing template '{$template}'";
                } else {
                    $headerResult = self::validateRendererTemplateHeader($templatePath, $rendererName, is_array($contextKeys) ? $contextKeys : []);
                    $warnings = array_merge($warnings, $headerResult['warnings']);
                }
            }

            if ($component !== '' && $registeredComponents !== [] && !in_array($component, $registeredComponents, true)) {
                $warnings[] = "renderer-registry entry '{$rendererName}' references unregistered component '{$component}'";
            }

            if (!is_array($controls) || $controls === []) {
                $warnings[] = "renderer-registry entry '{$rendererName}' has no controls declared";
            } else {
                foreach ($controls as $control) {
                    if (trim((string)$control) === '') {
                        $warnings[] = "renderer-registry entry '{$rendererName}' contains an empty control name";
                        break;
                    }
                }
            }

            if (!is_array($contextKeys) || $contextKeys === []) {
                $warnings[] = "renderer-registry entry '{$rendererName}' has no context_keys declared";
            } else {
                foreach ($contextKeys as $contextKey) {
                    if (trim((string)$contextKey) === '') {
                        $warnings[] = "renderer-registry entry '{$rendererName}' contains an empty context_keys entry";
                        break;
                    }
                }
            }
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @param list<mixed> $requiredContextKeys
     * @return array{warnings:list<string>}
     */
    private static function validateRendererTemplateHeader(string $templatePath, string $rendererName, array $requiredContextKeys): array
    {
        $warnings = [];
        $headerSlice = @file_get_contents($templatePath, false, null, 0, 1200);
        if (!is_string($headerSlice) || $headerSlice === '') {
            return ['warnings' => $warnings];
        }

        if (preg_match('/Context:\s*([^#\r\n]+)/i', $headerSlice, $matches) !== 1) {
            $warnings[] = "renderer template '{$rendererName}' is missing a 'Context:' header declaration";
            return ['warnings' => $warnings];
        }

        $declared = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string)$matches[1])
        )));

        foreach ($requiredContextKeys as $contextKey) {
            $contextKey = trim((string)$contextKey);
            if ($contextKey === '') {
                continue;
            }
            if (!in_array($contextKey, $declared, true)) {
                $warnings[] = "renderer template '{$rendererName}' is missing declared context key '{$contextKey}' in its Context: header";
            }
        }

        return ['warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return array{warnings:list<string>}
     */
    private static function validateBlockDefinitionRenderTarget(string $themeDir, string $categoryName, string $blockType, string $renderTarget): array
    {
        $warnings = [];

        if (str_starts_with($renderTarget, 'ikb_')) {
            $registeredComponents = self::registeredComponentNames();
            if ($registeredComponents !== [] && !in_array($renderTarget, $registeredComponents, true)) {
                $warnings[] = "Block definition '{$categoryName}.{$blockType}' references unregistered component render target '{$renderTarget}'";
            }
            return ['warnings' => $warnings];
        }

        if (str_starts_with($renderTarget, 'ark.blocks.')) {
            $alias = substr($renderTarget, strlen('ark.blocks.'));
            $path = $themeDir . '/public/blocks/' . $alias . '.block.disyl';
            if (!is_file($path)) {
                $warnings[] = "Block definition '{$categoryName}.{$blockType}' references missing ARK block template '{$renderTarget}' (expected public/blocks/{$alias}.block.disyl)";
            }
            return ['warnings' => $warnings];
        }

        if (str_starts_with($renderTarget, 'ark.layouts.')) {
            $alias = substr($renderTarget, strlen('ark.layouts.'));
            $path = $themeDir . '/public/' . $alias . '.disyl';
            if (!is_file($path)) {
                $warnings[] = "Block definition '{$categoryName}.{$blockType}' references missing ARK layout template '{$renderTarget}' (expected public/{$alias}.disyl)";
            }
            return ['warnings' => $warnings];
        }

        $warnings[] = "Block definition '{$categoryName}.{$blockType}' uses unknown renders_with target '{$renderTarget}'";
        return ['warnings' => $warnings];
    }

    /**
     * @param list<string> $registeredBlocks
     * @return array{warnings:list<string>}
     */
    private static function validateBlockDefinitionRelationships(string $categoryName, string $blockType, array $definition, array $registeredBlocks): array
    {
        $warnings = [];
        $knownRelationshipTargets = array_values(array_unique(array_merge($registeredBlocks, self::knownBuilderNodeTypes())));

        foreach (['allowed_children', 'allowed_parents'] as $relationshipKey) {
            if (!array_key_exists($relationshipKey, $definition)) {
                continue;
            }

            $relationshipValue = $definition[$relationshipKey] ?? null;
            if (!is_array($relationshipValue)) {
                $warnings[] = "Block definition '{$categoryName}.{$blockType}' {$relationshipKey} must be an array when declared";
                continue;
            }

            foreach ($relationshipValue as $index => $relatedBlockType) {
                $relatedBlockType = trim((string)$relatedBlockType);
                if ($relatedBlockType === '') {
                    $warnings[] = "Block definition '{$categoryName}.{$blockType}' {$relationshipKey}[{$index}] must be a non-empty string";
                    continue;
                }
                if ($knownRelationshipTargets !== [] && !in_array($relatedBlockType, $knownRelationshipTargets, true)) {
                    $warnings[] = "Block definition '{$categoryName}.{$blockType}' {$relationshipKey} references unknown block type '{$relatedBlockType}'";
                }
            }
        }

        return ['warnings' => array_values(array_unique($warnings))];
    }

    /**
     * @return list<string>
     */
    private static function knownBuilderNodeTypes(): array
    {
        return [
            'document', 'section', 'columns', 'container', 'layout_container', 'row', 'column',
            'heading', 'text', 'button', 'image', 'video', 'icon', 'icon_box',
            'social_icons', 'list', 'testimonial', 'blockquote', 'image_box',
            'logo_grid', 'star_rating', 'call_to_action', 'pricing_table',
            'code_block', 'table', 'slideshow', 'gallery', 'map', 'tabs',
            'accordion', 'counter', 'progress', 'countdown', 'flip_box',
            'toggle', 'search_box', 'nav_menu', 'recent_posts', 'social_links', 'contact_info', 'categories', 'tag_cloud', 'archives', 'opening_hours',
            'form', 'spacer', 'divider', 'alert',
            'anchor', 'breadcrumbs', 'badge', 'stat_card', 'contact_card', 'posts_grid', 'products_grid', 'team_grid',
            'entity_view', 'entity_list', 'html_embed', 'audio', 'ai_block',
        ];
    }

    /**
     * @return list<string>
     */
    private static function registeredBlockTypes(string $themeDir): array
    {
        $blockRegistryPath = $themeDir . '/block-registry.json';
        if (!is_file($blockRegistryPath)) {
            return [];
        }

        $blockRegistry = kernelReadJsonFile($blockRegistryPath);
        $categories = is_array($blockRegistry['categories'] ?? null) ? $blockRegistry['categories'] : [];
        $registered = [];

        foreach ($categories as $blockTypes) {
            if (!is_array($blockTypes)) {
                continue;
            }
            foreach ($blockTypes as $blockType) {
                $blockType = trim((string)$blockType);
                if ($blockType !== '') {
                    $registered[$blockType] = true;
                }
            }
        }

        return array_keys($registered);
    }

    /**
     * @return list<string>
     */
    private static function collectThemeTemplateFiles(string $themeDir): array
    {
        $files = [];
        if (!is_dir($themeDir)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'disyl') {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }

        sort($files);
        return $files;
    }

    /**
     * @return list<string>
     */
    private static function registeredComponentNames(): array
    {
        if (!class_exists(ComponentRegistry::class) || !method_exists(ComponentRegistry::class, 'list')) {
            return [];
        }

        $components = ComponentRegistry::list();
        $names = [];
        foreach ($components as $component) {
            $name = trim((string)($component['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private static function relativeThemePath(string $themeDir, string $filePath): string
    {
        $prefix = rtrim($themeDir, '/') . '/';
        if (str_starts_with($filePath, $prefix)) {
            return substr($filePath, strlen($prefix));
        }

        return $filePath;
    }

    /**
     * @param list<string> $allowedRawKeys
     */
    private static function isAllowedRawExpression(string $expression, array $allowedRawKeys): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return false;
        }

        if (in_array($expression, $allowedRawKeys, true)) {
            return true;
        }

        $leaf = $expression;
        if (str_contains($expression, '.')) {
            $segments = explode('.', $expression);
            $leaf = (string)end($segments);
        }

        return in_array($leaf, $allowedRawKeys, true);
    }

    /**
     * @return array<string,string>
     */
    private static function blockedPatternMap(): array
    {
        return [
            'direct database queries' => '/\b(PDO|mysqli|cmsDb\s*\(|app\s*\(\)\s*->\s*db\s*\(|module\s*\(\)\s*->\s*db\s*\()/i',
            'php function invocation from templates' => '/\{[^}]*\b(exec|shell_exec|system|passthru|eval|assert)\s*\(/i',
            'session access' => '/\$_SESSION|\bsession_[a-z_]+\s*\(/i',
            'cookie writes' => '/\bsetcookie\s*\(/i',
            'filesystem access' => '/\b(file_get_contents|file_put_contents|fopen|fwrite|unlink|rename|mkdir|rmdir|scandir)\s*\(/i',
        ];
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
