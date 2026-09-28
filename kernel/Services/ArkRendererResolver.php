<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\DiSyL\ComponentRegistry;
use Ikabud\Kernel\DiSyL\TemplateEngine;

/**
 * Resolves an exact entity-view ID through a theme renderer-registry.json.
 *
 * This service is deliberately opt-in. It does not participate in the default
 * ikb_entity_list or ikb_entity_detail dispatch paths.
 */
final class ArkRendererResolver
{
    private string $themesPath;
    private ?TemplateEngine $templateEngine;

    public function __construct(string $themesPath, ?TemplateEngine $templateEngine = null)
    {
        $this->themesPath = rtrim($themesPath, '/');
        $this->templateEngine = $templateEngine;
    }

    /**
     * @return array{view_id:string,theme_slug:string,renderer:string,type:string,target:string,path:?string}|null
     */
    public function resolve(string $viewId, ?string $themeSlug = null): ?array
    {
        $viewId = trim($viewId);
        if (!preg_match('/^entity\.(?:list|detail)\.[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $viewId)) {
            return null;
        }

        $themeSlug = $this->resolveThemeSlug($themeSlug);
        if ($themeSlug === null || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $themeSlug) !== 1) {
            return null;
        }

        $themePath = $this->themePath($themeSlug);
        if ($themePath === null) {
            return null;
        }

        $registryPath = $themePath . '/renderer-registry.json';
        if (!is_file($registryPath)) {
            return null;
        }

        $contents = @file_get_contents($registryPath);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        try {
            $registry = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($registry) || !is_array($registry['renderers'] ?? null)) {
            return null;
        }

        // Exact match only: renderer selection never falls back to "*".
        $definition = $registry['renderers'][$viewId] ?? null;
        if (!is_array($definition)) {
            return null;
        }

        $templateValue = $definition['template'] ?? '';
        $componentValue = $definition['renders_as_component'] ?? '';
        if (!is_string($templateValue) || !is_string($componentValue)) {
            return null;
        }

        $template = trim($templateValue);
        if ($template !== '') {
            $resolved = $this->resolveTemplateTarget($themePath, $template);
            if ($resolved === null) {
                return null;
            }

            return [
                'view_id' => $viewId,
                'theme_slug' => $themeSlug,
                'renderer' => $this->rendererName($template, $resolved['target']),
                'type' => 'template',
                'target' => $resolved['target'],
                'path' => $resolved['path'],
            ];
        }

        $component = trim($componentValue);
        if ($component === ''
            || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $component) !== 1
            || !ComponentRegistry::has($component)
        ) {
            return null;
        }

        return [
            'view_id' => $viewId,
            'theme_slug' => $themeSlug,
            'renderer' => $component,
            'type' => 'component',
            'target' => $component,
            'path' => null,
        ];
    }

    /**
     * Render only when an exact, executable selection exists.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $viewId, array $context = [], ?string $themeSlug = null): ?string
    {
        $selection = $this->resolve($viewId, $themeSlug);
        if ($selection === null || $this->templateEngine === null) {
            return null;
        }

        try {
            if ($selection['type'] === 'template' && $selection['path'] !== null) {
                // Theme views include their own partials by theme-relative name
                // (e.g. block renderer templates). Root the include resolution at
                // the theme directory this view was resolved from, so the
                // decrypted tenant-scoped theme decision is preserved instead of
                // re-resolving through a global active-theme alias.
                $themePath = $this->themePath($selection['theme_slug']);
                if ($themePath !== null) {
                    return $this->templateEngine->renderWithin($selection['path'], $context, $themePath);
                }

                return $this->templateEngine->render($selection['path'], $context);
            }

            if ($selection['type'] === 'component') {
                return $this->templateEngine->renderString('{' . $selection['target'] . ' /}', $context);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function resolveThemeSlug(?string $themeSlug): ?string
    {
        if ($themeSlug === null) {
            return null;
        }

        $themeSlug = trim($themeSlug);
        return $themeSlug !== '' ? $themeSlug : null;
    }

    private function themePath(string $themeSlug): ?string
    {
        $path = $this->themesPath . '/' . $themeSlug;
        if (!is_dir($path)) {
            return null;
        }

        $realThemesPath = realpath($this->themesPath);
        $realThemePath = realpath($path);
        if ($realThemesPath === false || $realThemePath === false) {
            return null;
        }

        $prefix = rtrim($realThemesPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realThemePath . DIRECTORY_SEPARATOR, $prefix)) {
            return null;
        }

        return $realThemePath;
    }

    /**
     * @return array{target:string,path:string}|null
     */
    private function resolveTemplateTarget(string $themePath, string $target): ?array
    {
        if (str_starts_with($target, 'ark.blocks.')) {
            $alias = substr($target, strlen('ark.blocks.'));
            if ($alias === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $alias) !== 1) {
                return null;
            }
            $relative = 'public/blocks/' . $alias . '.block.disyl';
        } elseif (str_starts_with($target, 'ark.layouts.')) {
            $alias = substr($target, strlen('ark.layouts.'));
            if ($alias === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $alias) !== 1) {
                return null;
            }
            $relative = 'public/' . $alias . '.disyl';
        } else {
            $relative = ltrim($target, '/');
        }

        $path = realpath($themePath . '/' . $relative);
        if ($path === false || !is_file($path)) {
            return null;
        }

        $prefix = rtrim($themePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        return ['target' => $relative, 'path' => $path];
    }

    private function rendererName(string $declaredTarget, string $resolvedTarget): string
    {
        if (str_starts_with($declaredTarget, 'ark.blocks.') || str_starts_with($declaredTarget, 'ark.layouts.')) {
            return (string)substr($declaredTarget, strrpos($declaredTarget, '.') + 1);
        }

        $name = pathinfo($resolvedTarget, PATHINFO_FILENAME);
        return str_ends_with($name, '.block') ? substr($name, 0, -6) : $name;
    }
}
