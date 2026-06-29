<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Immutable render context passed to theme DiSyL templates.
 *
 * This value object is built by the orchestrator from:
 *   - Theme definition (schema, defaults, tokens)
 *   - Persisted settings (from CMS settings repository)
 *   - Request context (site, navigation, entity, slots)
 *
 * The theme MUST NOT modify this context directly.
 * Any transformations MUST go through ThemeCustomizerProvider::transformContext().
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ThemeRenderContext
{
    /**
     * @param string $theme Theme machine name (e.g., "ark")
     * @param ThemeCustomizationScope $scope Current customization scope
     * @param array<string, array<string, mixed>> $settings Resolved settings per section
     * @param array<string, mixed> $tokens Resolved design tokens (merged defaults + overrides)
     * @param array{title: string, tagline: string, url: string, description?: string} $site Site metadata
     * @param array<string, array<array{href: string, label: string, children?: array}>> $navigation Navigation trees per location
     * @param array{entity?: array, route?: array, origin?: string, kind?: string} $entityContext Current entity context
     * @param array<string, array{href: string, label: string, html: string}> $slotContributions Pre-resolved slot contributions
     */
    public function __construct(
        public readonly string $theme,
        public readonly ThemeCustomizationScope $scope,
        public readonly array $settings,
        public readonly array $tokens,
        public readonly array $site,
        public readonly array $navigation,
        public readonly array $entityContext,
        public readonly array $slotContributions,
    ) {}

    /**
     * Get settings for a specific section.
     */
    public function settingsFor(string $section, mixed $default = null): array
    {
        return (array)($this->settings[$section] ?? $default ?? []);
    }

    /**
     * Get a specific setting value with dot-notation support.
     * E.g., $context->get('sidebar.width') or $context->get('header.sticky').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);
        if (count($parts) === 1) {
            return $this->tokens[$key] ?? $default;
        }
        [$section, $setting] = $parts;
        $sectionSettings = $this->settingsFor($section);
        return $sectionSettings[$setting] ?? $default;
    }

    /**
     * Check if a section has customizer settings.
     */
    public function hasSection(string $section): bool
    {
        return isset($this->settings[$section]) && !empty($this->settings[$section]);
    }
}
