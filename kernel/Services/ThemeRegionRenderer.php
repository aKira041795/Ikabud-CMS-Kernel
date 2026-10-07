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

        $sectionWidgets = $context->widgetsFor($region);
        $mainWidgets = $region === 'header'
            ? self::headerWidgetsAt($sectionWidgets, 'header')
            : $sectionWidgets;
        $topbarWidgets = $region === 'header'
            ? self::headerWidgetsAt($sectionWidgets, 'topbar')
            : [];

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
            // Persisted customizer widgets for this region, plus their CMS-rendered
            // HTML. The HTML is produced by the CMS's own widget renderers (which
            // escape their own output), so templates emit it with |raw.
            'widgets' => $context->widgets,
            'section_widgets' => $sectionWidgets,
            // Keep the established key as the main/header-row collection. A missing location is
            // deliberately "header" so existing persisted widgets do not move.
            'widgets_html' => self::renderWidgets($mainWidgets, $region, $region),
            'widgets_topbar_html' => self::renderWidgets($topbarWidgets, $region, 'topbar'),
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

    /**
     * @param array<int, array<string, mixed>> $widgets
     * @return array<int, array<string, mixed>>
     */
    private static function headerWidgetsAt(array $widgets, string $location): array
    {
        return array_values(array_filter(
            $widgets,
            static function (mixed $widget) use ($location): bool {
                if (!is_array($widget)) {
                    return $location === 'header';
                }
                return ($widget['location'] ?? 'header') === $location;
            }
        ));
    }

    /**
     * Pre-render a region's configured widgets to HTML.
     *
     * The CMS owns every widget renderer; the kernel must not re-implement them
     * or their escaping. This dispatches through the same
     * cmsBuilderWidgetRenderers() map the public builder uses, mirroring that
     * pipeline's default-prop/style resolution. When the CMS helper is absent
     * (a bare theme render), no widgets are emitted and the theme still renders.
     *
     * @param array<int, array<string, mixed>> $widgets
     */
    private static function renderWidgets(array $widgets, string $region, ?string $location = null): string
    {
        if ($widgets === [] || !function_exists('cmsBuilderWidgetRenderers')) {
            return '';
        }

        $renderers = cmsBuilderWidgetRenderers();
        if (!is_array($renderers)) {
            return '';
        }

        $html = '';
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }

            $type = trim((string)($widget['type'] ?? ''));
            // Customizer vocabulary predates the builder names for these equivalent widgets.
            $rendererType = match ($type) {
                'custom_html' => 'text',
                'cta_button' => 'button',
                default => $type,
            };
            $renderer = $rendererType !== '' ? ($renderers[$rendererType] ?? null) : null;
            if (!is_callable($renderer)) {
                continue;
            }

            $props = is_array($widget['props'] ?? null) ? $widget['props'] : [];
            $props = self::normalizeWidgetProps($props, $type);
            if (function_exists('cmsBuilderMergeDefaults')) {
                $props = cmsBuilderMergeDefaults($props, $type);
            }

            $style = is_array($widget['style'] ?? null) ? $widget['style'] : [];
            $location ??= $region;
            $horizontal = $region === 'header' || $location === 'topbar';
            if (function_exists('cmsBuilderDefaultStyle')) {
                $defaultStyle = cmsBuilderDefaultStyle($rendererType);
                // These shared types have card defaults for vertical regions. A horizontal band
                // supplies its own compact presentation; persisted per-widget styles still win.
                if ($horizontal && in_array($type, ['contact_info', 'opening_hours', 'nav_menu', 'social_links'], true)) {
                    $defaultStyle = [];
                } else {
                    $hasExplicitWidth = array_key_exists('width', $style)
                        && $style['width'] !== null
                        && $style['width'] !== '';
                    if ($region === 'header' && !$hasExplicitWidth) {
                        unset($defaultStyle['width']);
                    }
                }
                $style = array_merge(
                    $defaultStyle,
                    array_filter($style, static fn ($value): bool => $value !== null && $value !== '')
                );
            }

            $attrs = [
                'class' => 'ark-region-widget ark-region-widget--' . preg_replace('/[^a-z0-9_-]/i', '-', $type),
                'data-widget-type' => $type,
            ];
            if (!empty($widget['id'])) {
                $attrs['data-widget-id'] = (string)$widget['id'];
            }

            $rendererContext = [
                'region' => $region,
                'location' => $location,
                'orientation' => $horizontal ? 'horizontal' : 'vertical',
                'presentation' => $horizontal ? 'compact-inline' : 'card',
            ];

            try {
                $html .= (string)$renderer($props, $style, $attrs, '', $widget, $rendererContext);
            } catch (\Throwable $e) {
                // A single malformed widget must not take down the whole region.
            }
        }

        return $html;
    }

    /**
     * Bridge the Customizer's widget contract onto the page-builder's.
     *
     * The Customizer writes widget props in snake_case (`menu_id`) and its own previews read
     * them that way, while the builder renderers this method dispatches to read camelCase
     * (`menuId`). Without this, a saved Navigation widget resolves menu 0 and silently renders
     * "Select a menu to display here." even though the menu is set and populated.
     *
     * Aliases are added, never renamed, and an explicit camelCase value always wins - so props a
     * renderer already reads in either spelling keep working.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function normalizeWidgetProps(array $props, string $type): array
    {
        foreach ($props as $key => $value) {
            if (!is_string($key) || !str_contains($key, '_') || in_array($key, ['font_size', 'font_weight'], true)) {
                continue;
            }
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            if ($camel !== $key && !array_key_exists($camel, $props)) {
                $props[$camel] = $value;
            }
        }

        // Semantic aliases that cannot be represented by snake_case -> camelCase conversion.
        if (array_key_exists('button_label', $props) && !array_key_exists('buttonText', $props)) {
            $props['buttonText'] = $props['button_label'];
        }
        if ($type === 'opening_hours' && array_key_exists('icon', $props) && !array_key_exists('showIcon', $props)) {
            $props['showIcon'] = !empty($props['icon']);
        }
        if (array_key_exists('new_tab', $props) && !array_key_exists('target', $props) && !empty($props['new_tab'])) {
            $props['target'] = '_blank';
        }

        return $props;
    }
}
