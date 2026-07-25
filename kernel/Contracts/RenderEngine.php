<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * RenderEngine — narrow rendering contract for kernel-level injection.
 *
 * Services type-hint this interface instead of calling app()->render().
 * Decouples template rendering from the App singleton and enables
 * testing rendering logic with a mock engine.
 *
 * Step 1 of the App decomposition roadmap.
 *
 * @package Ikabud\Kernel\Contracts
 */
interface RenderEngine
{
    /**
     * Render a DiSyL template with the given context data.
     *
     * @param string $template Template path (relative to templates/ root).
     * @param array  $context  Variables available in the template.
     * @return string Rendered HTML output.
     */
    public function render(string $template, array $context = []): string;

    /**
     * Build the base render context shared by all templates.
     * Includes user, navigation, theme settings, and GUI defaults.
     *
     * @param string $template The template being rendered (for nav active-state).
     * @return array Base context data.
     */
    public function buildRenderBaseContext(string $template = ''): array;
}
