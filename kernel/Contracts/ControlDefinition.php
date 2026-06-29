<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * A single control within a customization section.
 *
 * Maps directly to a form field in the admin customizer UI.
 * The CMS uses this to render the input, validate values, and
 * seed defaults.
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ControlDefinition
{
    /**
     * @param string $id Control identifier (e.g., "sticky", "layout")
     * @param string $label Human-readable label
     * @param string $type Input type: text, color, select, boolean, number, textarea, font, image
     * @param mixed $default Default value
     * @param array $options Select options when type=select
     * @param array{min?: int, max?: int, step?: int} $constraints Validation constraints
     * @param string|null $description Help text or description
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $type,
        public readonly mixed $default = null,
        public readonly array $options = [],
        public readonly array $constraints = [],
        public readonly ?string $description = null,
    ) {}
}
