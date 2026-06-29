<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;

/**
 * Renders theme-owned DiSyL region templates with a safe render context.
 *
 * This service bridges the gap between the immutable ThemeRenderContext
 * and DiSyL template rendering. It selects the appropriate template
 * from the theme provider and passes the context as template variables.
 *
 * @package Ikabud\Kernel\Services
 */
class ThemeRegionRenderer
{
    /**
     * Render a region using the theme's DiSyL template.
     *
     * @param ThemeCustomizerProvider $provider The theme's customizer provider
     * @param string $region Region identifier (header, footer, sidebar, etc.)
     * @param ThemeRenderContext $context Immutable render context
     * @param string $themePath Absolute path to the theme root directory
     * @return string|null Rendered HTML or null if no template found
     */
    public static function render(
        ThemeCustomizerProvider $provider,
        string $region,
        ThemeRenderContext $context,
        string $themePath,
    ): ?string {
        $templatePath = $provider->templateForRegion($region);
        if ($templatePath === null) {
            return null;
        }

        // Resolve the template relative to the theme directory
        $fullPath = $themePath . '/' . ltrim($templatePath, '/');
        if (!is_file($fullPath)) {
            return null;
        }

        // Build a relative path for the CMS template engine
        // Templates are rendered from the theme's perspective
        $relativePath = '_cms_active_theme/' . ltrim($templatePath, '/');

        // Template variables available in DiSyL region templates
        $templateVars = [
            'region' => $region,
            'theme' => $context->theme,
            'settings' => $context->settings,
            'section_settings' => $context->settingsFor($region),
            'tokens' => $context->tokens,
            'site' => $context->site,
            'navigation' => $context->navigation,
            'entity_context' => $context->entityContext,
            'slot_contributions' => $context->slotContributions,
            'scope' => $context->scope->toLegacyString(),
            'scope_type' => $context->scope->scopeType,
        ];

        // Try to render via the CMS template engine
        if (function_exists('cmsRender')) {
            try {
                return cmsRender($relativePath, $templateVars);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Render all regions for a given context.
     *
     * @return array<string, string|null> Region name → rendered HTML (or null)
     */
    public static function renderAll(
        ThemeCustomizerProvider $provider,
        ThemeRenderContext $context,
        string $themePath,
    ): array {
        $results = [];
        $regionNames = $provider->definition()->regionNames();
        foreach ($regionNames as $region) {
            $results[$region] = self::render($provider, $region, $context, $themePath);
        }
        return $results;
    }
}
