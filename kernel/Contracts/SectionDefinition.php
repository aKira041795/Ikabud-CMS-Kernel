<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * A single customization section within a theme's definition.
 *
 * Describes a group of settings shown in the admin customizer UI.
 * The CMS uses this to generate the settings form, seed defaults,
 * and validate values.
 *
 * @package Ikabud\Kernel\Contracts
 */
final class SectionDefinition
{
    /**
     * @param string $id Section identifier (e.g., "header", "colors")
     * @param string $label Human-readable label (e.g., "Header Settings")
     * @param array<string, ControlDefinition> $controls The settings controls in this section
     * @param bool $isRegion Whether this section corresponds to a render region
     * @param array<string, mixed> $defaults Pre-computed defaults for this section
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $controls,
        public readonly bool $isRegion = false,
        public readonly array $defaults = [],
    ) {}
}
