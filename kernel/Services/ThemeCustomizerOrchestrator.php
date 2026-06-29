<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;
use Ikabud\Kernel\Contracts\ThemeCustomizerDefinition;
use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\Contracts\ThemeCustomizationSubmission;
use Ikabud\Kernel\Contracts\ThemeValidationResult;

/**
 * Theme Customizer Orchestrator — Kernel OS governed.
 *
 * Central orchestrator for the theme-owned customizer system.
 * Provides a single unified pipeline for ALL themes:
 *
 *   resolve() → validate definition → build context → render regions
 *
 * Architecture rules enforced:
 *   1. Theme provider MUST implement ThemeCustomizerProvider interface
 *   2. Theme provider MUST NOT query the database
 *   3. Theme provider MUST return template paths, not HTML
 *   4. All persisted settings go through CMS persistence layer
 *   5. Invalid theme customizers fail at activation, not silently at runtime
 *
 * @package Ikabud\Kernel\Services
 */
class ThemeCustomizerOrchestrator
{
    /** @var ThemeCustomizerProvider|null Cached resolved provider */
    private static ?ThemeCustomizerProvider $resolvedProvider = null;

    /** @var array<string, bool> Validation cache */
    private static array $validationCache = [];

    /** @var bool Whether the current provider failed validation */
    private static bool $activationFailed = false;

    /**
     * Resolve the active theme's customizer provider.
     *
     * Returns:
     *   - Theme's custom provider (if owns: true, class valid)
     *   - DeclarativeThemeCustomizerProvider (if owns: true, no class needed)
     *   - LegacyCmsCustomizerAdapter (if no customizer block or owns: false)
     *
     * @return ThemeCustomizerProvider|null Null only if no theme is active
     */
    public static function resolve(): ?ThemeCustomizerProvider
    {
        if (self::$resolvedProvider !== null) {
            return self::$resolvedProvider;
        }

        if (!function_exists('cmsActiveThemeManifest') || !function_exists('cmsActiveTheme')) {
            return null;
        }

        $manifest = cmsActiveThemeManifest();
        $slug = cmsActiveTheme();
        $themePath = self::activeThemePath();

        if (!$slug || empty($manifest)) {
            return null;
        }

        $owns = $manifest['customizer']['owns'] ?? false;

        // Path 1: Theme owns its customizer
        if ($owns) {
            $provider = self::resolveOwnedProvider($slug, $manifest, $themePath);
            if ($provider !== null) {
                // Validate at activation time — fail closed
                if (!self::validateProvider($provider, $slug, $themePath)) {
                    self::$activationFailed = true;
                    write_log("[ThemeCustomizer] Activation rejected for '{$slug}' — provider failed validation", 'error');
                    return null;
                }
                self::$resolvedProvider = $provider;
                return $provider;
            }
            // owns: true but could not resolve — ACTIVATION FAILED
            self::$activationFailed = true;
            write_log("[ThemeCustomizer] Activation rejected for '{$slug}' — owns:true but no valid provider", 'error');
            return null;
        }

        // Path 2: Legacy theme — wrap in adapter
        $adapter = new LegacyCmsCustomizerAdapter($slug);
        self::$resolvedProvider = $adapter;
        return $adapter;
    }

    /**
     * Resolve a theme-owned customizer provider.
     * Returns null if the provider cannot be resolved (activation fails).
     */
    private static function resolveOwnedProvider(
        string $slug,
        array $manifest,
        ?string $themePath,
    ): ?ThemeCustomizerProvider {
        $className = (string)($manifest['customizer']['class'] ?? '');

        if ($className !== '' && class_exists($className)) {
            try {
                $instance = new $className();
                if ($instance instanceof ThemeCustomizerProvider) {
                    return $instance;
                }
            } catch (\Throwable $e) {
                // Class found but instantiation failed
                return null;
            }
        }

        // No custom class or invalid — use declarative provider
        if ($themePath !== null && is_dir($themePath)) {
            return new DeclarativeThemeCustomizerProvider($slug, $themePath);
        }

        return null;
    }

    /**
     * Validate a theme customizer provider — called at activation time.
     * Failures prevent the provider from being used.
     */
    public static function validateProvider(
        ThemeCustomizerProvider $provider,
        string $slug,
        ?string $themePath,
    ): bool {
        $cacheKey = $slug . '_provider_v2';
        if (isset(self::$validationCache[$cacheKey])) {
            return self::$validationCache[$cacheKey];
        }

        $errors = [];

        // Rule 1: Slug must match
        if ($provider->slug() !== $slug) {
            $errors[] = "Provider slug '{$provider->slug()}' does not match theme slug '{$slug}'";
        }

        // Rule 2: Must return a valid definition
        $definition = $provider->definition();
        if (!$definition instanceof ThemeCustomizerDefinition) {
            $errors[] = 'definition() must return a ThemeCustomizerDefinition';
        }

        // Rule 3: Must have at least one section if schema exists
        $sections = $definition->sectionNames();
        if (empty($sections) && $themePath !== null && is_file($themePath . '/customizer.schema.json')) {
            $errors[] = 'customizer.schema.json exists but no sections were parsed';
        }

        // Rule 4: templateForRegion must point to valid files if regions declared
        $regionNames = $definition->regionNames();
        foreach ($regionNames as $region) {
            $template = $provider->templateForRegion($region);
            if ($template !== null && $themePath !== null) {
                $fullPath = $themePath . '/' . ltrim($template, '/');
                if (!is_file($fullPath)) {
                    $errors[] = "Region '{$region}' template not found: {$template}";
                }
            }
        }

        // Rule 5: Provider must not implement render* methods (they're deprecated)
        $ref = new \ReflectionClass($provider);
        foreach (['renderHeader', 'renderFooter', 'renderSidebar'] as $method) {
            if ($ref->hasMethod($method) && $ref->getMethod($method)->getDeclaringClass()->getName() === get_class($provider)) {
                write_log("[ThemeCustomizer] Warning for '{$slug}': {$method}() is deprecated, use templateForRegion() instead", 'warning');
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                write_log("[ThemeCustomizer] Activation rejection for '{$slug}': {$error}", 'error');
            }
            self::$validationCache[$cacheKey] = false;
            return false;
        }

        self::$validationCache[$cacheKey] = true;
        return true;
    }

    /**
     * Check if the theme's customizer activation failed.
     */
    public static function activationFailed(): bool
    {
        return self::$activationFailed;
    }

    /**
     * Build an immutable ThemeRenderContext from the active provider.
     *
     * The context contains pre-resolved settings, tokens, site metadata,
     * navigation trees, and entity context — NO database access from theme.
     *
     * @param string $scopeString Legacy scope string (e.g., "native_ark")
     * @param array $publicCtx Public render context from CMS
     * @param object|null $db Database connection (for CMS persistence layer only)
     * @return ThemeRenderContext|null Null if provider cannot be resolved
     */
    public static function buildContext(
        string $scopeString,
        array $publicCtx = [],
        ?object $db = null,
    ): ?ThemeRenderContext {
        $provider = self::resolve();
        if ($provider === null) {
            return null;
        }

        $scope = ThemeCustomizationScope::fromString($scopeString);

        // Build settings from persisted values merged with defaults
        $definition = $provider->definition();
        $settings = [];
        foreach ($definition->sectionNames() as $section) {
            $defaults = [];
            $sectionDef = $definition->section($section);
            if ($sectionDef !== null) {
                $defaults = $sectionDef->defaults;
            }

            // Try to load persisted settings from CMS
            $persisted = [];
            if ($db !== null && function_exists('cmsCustomizerGet')) {
                try {
                    $data = cmsCustomizerGet($db, $section, $scopeString);
                    $persisted = (array)($data['settings'] ?? []);
                } catch (\Throwable $e) {
                    // Persistence unavailable, use defaults only
                }
            }

            $settings[$section] = array_merge($defaults, $persisted);
        }

        // Build tokens — merge defaults with any color overrides
        $tokens = $definition->tokens;
        if (!empty($settings['colors'] ?? [])) {
            foreach ($settings['colors'] as $key => $value) {
                $tokenKey = '--color-' . str_replace('_', '-', $key);
                if (isset($tokens[$tokenKey])) {
                    $tokens[$tokenKey]['default'] = $value;
                }
            }
        }

        // Site metadata
        $site = [
            'title' => (string)($publicCtx['site_title'] ?? ($publicCtx['cms_settings']['site_title'] ?? 'Site')),
            'tagline' => (string)($publicCtx['site_tagline'] ?? ($publicCtx['cms_settings']['site_tagline'] ?? '')),
            'url' => (string)($publicCtx['base_url'] ?? (defined('BASE_URL') ? BASE_URL : '/')),
        ];

        // Navigation
        $navigation = [];
        if (!empty($publicCtx['primary_menu'])) {
            $navigation['primary'] = $publicCtx['primary_menu'];
        }
        if (!empty($publicCtx['footer_menu'])) {
            $navigation['footer'] = $publicCtx['footer_menu'];
        }

        // Entity context
        $entityContext = [
            'route' => $publicCtx['public_route_kind'] ?? 'generic',
            'origin' => $publicCtx['public_render_origin'] ?? 'cms',
            'kind' => $publicCtx['public_route_kind'] ?? 'generic',
            'presentation' => $publicCtx['public_presentation_mode'] ?? 'traditional',
        ];

        // Slot contributions (empty for now — populated by SlotRegistry)
        $slotContributions = [];

        $context = new ThemeRenderContext(
            theme: $scope->themeSlug,
            scope: $scope,
            settings: $settings,
            tokens: $tokens,
            site: $site,
            navigation: $navigation,
            entityContext: $entityContext,
            slotContributions: $slotContributions,
        );

        // Allow provider to transform context
        return $provider->transformContext($context);
    }

    /**
     * Render a region using the active theme's provider.
     *
     * This is the primary render dispatch method.
     * The CMS calls this instead of its own CMS render functions.
     *
     * @param string $region Region identifier (header, footer, sidebar)
     * @param object|null $db Database connection (for persistence layer only)
     * @param array $publicCtx Public render context
     * @return array{html: string, meta: array} Rendered output with metadata
     */
    public static function renderRegion(
        string $region,
        ?object $db,
        array $publicCtx = [],
    ): array {
        $provider = self::resolve();
        if ($provider === null) {
            return ['html' => '', 'meta' => ['fallback' => 'no_provider']];
        }

        $scopeString = function_exists('cmsCustomizerScopeFromPublicContext')
            ? cmsCustomizerScopeFromPublicContext($publicCtx)
            : ('native_' . $provider->slug());

        $context = self::buildContext($scopeString, $publicCtx, $db);
        if ($context === null) {
            return ['html' => '', 'meta' => ['fallback' => 'no_context']];
        }

        $themePath = self::activeThemePath();
        if ($themePath === null) {
            return ['html' => '', 'meta' => ['fallback' => 'no_theme_path']];
        }

        // Try rendering via DiSyL region template
        $rendered = ThemeRegionRenderer::render($provider, $region, $context, $themePath);

        if ($rendered !== null) {
            return ['html' => $rendered, 'meta' => ['source' => 'theme_region_template']];
        }

        // Fallback: if provider returned null, try legacy CMS render
        if ($provider instanceof LegacyCmsCustomizerAdapter) {
            return self::legacyRender($region, $db, $publicCtx);
        }

        // Theme provider returned null — no rendering available
        return ['html' => '', 'meta' => ['fallback' => 'no_template']];
    }

    /**
     * Fall back to legacy CMS customizer rendering.
     */
    private static function legacyRender(string $region, ?object $db, array $publicCtx): array
    {
        if ($db === null) {
            return ['html' => '', 'meta' => ['fallback' => 'no_db']];
        }

        return match ($region) {
            'header' => [
                'html' => function_exists('cmsRenderCustomizedHeader')
                    ? cmsRenderCustomizedHeader($db, $publicCtx) : '',
                'meta' => ['source' => 'legacy_cms'],
            ],
            'footer' => [
                'html' => function_exists('cmsRenderCustomizedFooter')
                    ? cmsRenderCustomizedFooter($db, $publicCtx) : '',
                'meta' => ['source' => 'legacy_cms'],
            ],
            'sidebar' => [
                'html' => function_exists('cmsRenderCustomizedSidebar')
                    ? ((cmsRenderCustomizedSidebar($db, $publicCtx))['html'] ?? '') : '',
                'meta' => ['source' => 'legacy_cms'],
            ],
            default => ['html' => '', 'meta' => ['fallback' => 'unknown_region']],
        };
    }

    /**
     * Get the active theme's absolute filesystem path.
     */
    private static function activeThemePath(): ?string
    {
        if (function_exists('cmsActiveTheme')) {
            $slug = cmsActiveTheme();
            if ($slug !== '') {
                $base = defined('CMS_THEMES_PATH') ? CMS_THEMES_PATH : (__DIR__ . '/../../storage/cms-themes');
                $path = rtrim($base, '/') . '/' . $slug;
                if (is_dir($path)) {
                    return realpath($path) ?: $path;
                }
            }
        }
        return null;
    }

    /**
     * Reset the orchestrator state (useful for testing).
     */
    public static function reset(): void
    {
        self::$resolvedProvider = null;
        self::$validationCache = [];
        self::$activationFailed = false;
    }

    /**
     * Get defaults for a settings section from the active provider.
     */
    public static function sectionDefaults(string $section): array
    {
        $provider = self::resolve();
        if ($provider === null) {
            return [];
        }
        $def = $provider->definition()->section($section);
        return $def !== null ? $def->defaults : [];
    }

    /**
     * Validate settings through the active provider.
     */
    public static function validateSettings(
        string $section,
        array $settings,
        ?string $scopeString = null,
    ): array {
        $provider = self::resolve();
        if ($provider === null) {
            return $settings;
        }

        $scope = ThemeCustomizationScope::fromString(
            $scopeString ?? 'native_' . $provider->slug()
        );
        $submission = new ThemeCustomizationSubmission(
            section: $section,
            values: $settings,
            scope: $scope,
        );

        $result = $provider->validate($submission);
        return $result->correctedValues;
    }
}
