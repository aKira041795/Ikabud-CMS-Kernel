<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;
use Ikabud\Kernel\Contracts\ThemeCustomizerDefinition;
use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\SectionDefinition;
use Ikabud\Kernel\Contracts\ControlDefinition;

/**
 * Loads a theme's customizer definition from its declarative files.
 *
 * Reads customizer.schema.json, tokens.json, slots.json, and the
 * theme manifest to assemble a complete ThemeCustomizerDefinition.
 *
 * @package Ikabud\Kernel\Services
 */
class ThemeDefinitionLoader
{
    /** @var array<string, ThemeCustomizerDefinition> Loaded definition cache */
    private static array $cache = [];

    /**
     * Load a theme's customizer definition.
     *
     * @param string $themeSlug Theme machine name
     * @param string $themePath Absolute path to theme root directory
     * @return ThemeCustomizerDefinition|null Null if required files missing
     */
    public static function load(string $themeSlug, string $themePath): ?ThemeCustomizerDefinition
    {
        $cacheKey = $themeSlug . '_' . md5($themePath);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $manifestPath = $themePath . '/theme.manifest.json';
        $schemaPath = $themePath . '/customizer.schema.json';
        $tokensPath = $themePath . '/tokens.json';
        $slotsPath = $themePath . '/slots.json';

        $manifest = self::loadJson($manifestPath);
        $schema = self::loadJson($schemaPath);
        $tokens = self::loadJson($tokensPath);
        $slots = self::loadJson($slotsPath);

        if ($schema === null && $manifest === null) {
            return null;
        }

        $sections = self::parseSections($schema ?? [], $manifest);
        $regions = self::parseRegions($manifest);
        $parsedTokens = self::parseTokens($tokens ?? []);
        $parsedSlots = self::parseSlots($slots ?? [], $manifest);

        $definition = new ThemeCustomizerDefinition(
            sections: $sections,
            regions: $regions,
            tokens: $parsedTokens,
            slots: $parsedSlots,
        );

        self::$cache[$cacheKey] = $definition;
        return $definition;
    }

    /**
     * Parse customization sections from schema and manifest.
     *
     * @return array<string, SectionDefinition>
     */
    private static function parseSections(array $schema, ?array $manifest): array
    {
        $sections = [];
        $manifestSections = (array)($manifest['customizer']['sections'] ?? []);
        $schemaSections = (array)($schema['sections'] ?? []);
        $regionNames = self::regionNamesFromManifest($manifest);

        // Use schema as primary source, fall back to manifest section list
        $allSectionIds = !empty($schemaSections)
            ? array_keys($schemaSections)
            : $manifestSections;

        foreach ($allSectionIds as $id) {
            $sectionData = $schemaSections[$id] ?? [];
            $label = (string)($sectionData['label'] ?? self::defaultSectionLabel($id));
            $isRegion = in_array($id, $regionNames, true);

            $controls = [];
            $rawControls = (array)($sectionData['controls'] ?? []);
            foreach ($rawControls as $ctrlId => $ctrlData) {
                $controls[$ctrlId] = new ControlDefinition(
                    id: $ctrlId,
                    label: (string)($ctrlData['label'] ?? $ctrlId),
                    type: (string)($ctrlData['type'] ?? 'text'),
                    default: $ctrlData['default'] ?? null,
                    options: (array)($ctrlData['options'] ?? []),
                    constraints: (array)($ctrlData['constraints'] ?? []),
                    description: isset($ctrlData['description']) ? (string)$ctrlData['description'] : null,
                );
            }

            $defaults = [];
            foreach ($controls as $ctrlId => $control) {
                $defaults[$ctrlId] = $control->default;
            }

            $sections[$id] = new SectionDefinition(
                id: $id,
                label: $label,
                controls: $controls,
                isRegion: $isRegion,
                defaults: $defaults,
            );
        }

        return $sections;
    }

    /**
     * Parse render regions from manifest.
     * @return array<string, string> Region name → template path
     */
    private static function parseRegions(?array $manifest): array
    {
        $regions = [];
        $rawRegions = (array)($manifest['regions'] ?? []);
        foreach ($rawRegions as $name => $template) {
            if (is_string($template)) {
                $regions[$name] = $template;
            }
        }
        return $regions;
    }

    /**
     * @return array<string>
     */
    private static function regionNamesFromManifest(?array $manifest): array
    {
        $regions = (array)($manifest['regions'] ?? []);
        return array_keys($regions);
    }

    /**
     * Parse tokens from tokens.json into definition format.
     * @return array<string, array{type: string, default: mixed, description?: string}>
     */
    private static function parseTokens(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $key => $value) {
            if (is_array($value)) {
                // Already in definition format: {type, default, description}
                $result[$key] = $value;
            } else {
                // Simple key-value pair — infer type
                $type = match (true) {
                    is_bool($value) => 'boolean',
                    is_int($value) || is_float($value) => 'number',
                    str_starts_with((string)$value, '#') => 'color',
                    default => 'string',
                };
                $result[$key] = ['type' => $type, 'default' => $value];
            }
        }
        return $result;
    }

    /**
     * Parse slot definitions from slots.json or manifest.
     * @return array<string, array{label: string, accepts?: array, multiple?: bool}>
     */
    private static function parseSlots(array $slots, ?array $manifest): array
    {
        if (!empty($slots)) {
            return $slots;
        }

        // Fall back to manifest supported_slots
        $manifestSlots = (array)($manifest['supported_slots'] ?? []);
        $result = [];
        foreach ($manifestSlots as $slot) {
            $slotName = is_string($slot) ? $slot : (string)($slot['name'] ?? '');
            if ($slotName !== '') {
                $result[$slotName] = [
                    'label' => $slot['label'] ?? $slotName,
                    'accepts' => $slot['accepts'] ?? ['component'],
                    'multiple' => $slot['multiple'] ?? true,
                ];
            }
        }
        return $result;
    }

    private static function loadJson(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function defaultSectionLabel(string $id): string
    {
        return match ($id) {
            'header' => 'Header Settings',
            'footer' => 'Footer Settings',
            'sidebar' => 'Sidebar Settings',
            'colors' => 'Colors',
            'theme' => 'Theme Layout',
            'typography' => 'Typography',
            'layout' => 'Layout',
            default => ucfirst(str_replace('_', ' ', $id)),
        };
    }
}
