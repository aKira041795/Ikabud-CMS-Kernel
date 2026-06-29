<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Theme Customizer Definition — describes the complete customization surface.
 *
 * This value object is assembled from the theme's declarative files:
 *   - customizer.schema.json
 *   - tokens.json
 *   - slots.json
 *   - theme.manifest.json (regions block)
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ThemeCustomizerDefinition
{
    /**
     * @param array<string, SectionDefinition> $sections Customization sections (settings groups)
     * @param array<string, string> $regions Render regions → template paths
     * @param array<string, array{type: string, default: mixed, description?: string}> $tokens Design token definitions
     * @param array<string, array{label: string, accepts?: array, multiple?: bool}> $slots Governed slot definitions
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $regions,
        public readonly array $tokens,
        public readonly array $slots,
    ) {}

    /**
     * Get a section definition by name.
     */
    public function section(string $name): ?SectionDefinition
    {
        return $this->sections[$name] ?? null;
    }

    /**
     * Check if a section exists.
     */
    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Get all section names.
     * @return array<string>
     */
    public function sectionNames(): array
    {
        return array_keys($this->sections);
    }

    /**
     * Get all region names.
     * @return array<string>
     */
    public function regionNames(): array
    {
        return array_keys($this->regions);
    }

    /**
     * Check if a name is a render region (as opposed to a settings-only section).
     */
    public function isRegion(string $name): bool
    {
        return isset($this->regions[$name]);
    }

    /**
     * Get template path for a region.
     */
    public function templateForRegion(string $region): ?string
    {
        return $this->regions[$region] ?? null;
    }
}
