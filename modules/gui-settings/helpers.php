<?php

declare(strict_types=1);

/**
 * GUI Settings helpers — loaded by handlers.php and available to the kernel.
 * Tenant-aware settings are stored through the module settings registry.
 * The legacy JSON file remains a read-only fallback for older installs.
 */

function guiSettingsPath(): string
{
    return STORAGE_PATH . '/gui-settings.json';
}

function guiSettingsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('gui-settings');
    if (!$ctx) {
        throw new \RuntimeException('Module context unavailable');
    }

    return $ctx;
}

function guiSettingsUser(): ?array
{
    return guiSettingsCtx()->user();
}

function guiSettingsInput(): array
{
    $input = guiSettingsCtx()->input();
    return is_array($input) ? $input : [];
}

function guiSettingsRender(string $template, array $context = []): string
{
    return guiSettingsCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function guiSettingsNormalizeRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'GUI Settings',
        'settings' => [],
        'defaults' => [],
        'setting_keys' => [],
        'font_presets' => [],
        'color_presets' => [],
    ], ['page_title', 'settings', 'defaults', 'setting_keys', 'font_presets', 'color_presets'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('gui-settings.admin.settings', [
    'template' => 'modules/gui-settings/settings.disyl',
    'priority' => 20,
    'normalize' => 'guiSettingsNormalizeRenderContext',
    'log_event' => 'gui-settings.render_context.contract_mismatch',
]);

/**
 * Default GUI settings — used when no settings file exists.
 */
function guiSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['gui-settings'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

/**
 * Read current GUI settings (merged with defaults).
 *
 * Priority chain (later wins):
 *   1. Built-in defaults
 *   2. Legacy JSON file (storage/gui-settings.json) — survives module disable
 *   3. Module-settings registry (global + tenant overlay)
 */
function readGuiSettings(): array
{
    $defaults = guiSettingsDefaults();
    $guiKeys = array_keys($defaults);

    // Layer 1: legacy JSON file
    $path = guiSettingsPath();
    $fromFile = [];
    if (is_file($path)) {
        $decoded = kernelReadJsonFile($path);
        if (is_array($decoded)) {
            $fromFile = $decoded;
        }
    }

    // Layer 2: module-settings registry (includes tenant overlay)
    $fromRegistry = getModuleSettings('gui-settings');
    if (!is_array($fromRegistry)) {
        $fromRegistry = [];
    }
    // Only keep keys that are actual GUI settings, not lifecycle flags
    $guiFromRegistry = array_intersect_key($fromRegistry, array_flip($guiKeys));

    return array_merge($defaults, $fromFile, $guiFromRegistry);
}

/**
 * Save GUI settings.
 *
 * Writes to both the legacy JSON file (always works) and the module-settings
 * registry (may be blocked in tenant mode when no tenant is resolved). The
 * legacy file is the durable store; the registry is a best-effort overlay.
 */
function saveGuiSettings(array $settings): void
{
    // Always persist to legacy JSON file — survives tenant-mode blocks
    $path = guiSettingsPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Merge with existing so partial saves work
    $existing = [];
    if (is_file($path)) {
        $decoded = kernelReadJsonFile($path);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $merged = array_merge($existing, $settings);
    file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Best-effort: also write to module registry
    saveModuleSettings('gui-settings', $settings);
}

/**
 * Generate CSS variable overrides from current GUI settings.
 * Returns a <style> block string to inject into <head>.
 */
function guiCssOverrides(): string
{
    $s = readGuiSettings();
    $d = guiSettingsDefaults();

    $vars = [];
    $map = [
        'color_bg'            => '--bg',
        'color_surface'       => '--surface',
        'color_border'        => '--border',
        'color_text'          => '--text',
        'color_text_muted'    => '--text-muted',
        'color_text_light'    => '--text-light',
        'color_primary'       => '--primary',
        'color_primary_hover' => '--primary-hover',
        'color_primary_light' => '--primary-light',
        'color_success'       => '--success',
        'color_success_light' => '--success-light',
        'color_warning'       => '--warning',
        'color_warning_light' => '--warning-light',
        'color_danger'        => '--danger',
        'color_danger_light'  => '--danger-light',
        'color_header_bg'     => '--header-bg',
        'color_header_text'   => '--header-text',
        'color_header_accent' => '--header-accent',
        'border_radius'       => '--radius',
        'header_height'       => '--header-h',
        'nav_height'          => '--nav-h',
        'font_size_base'      => '--font-size-base',
        'font_size_small'     => '--font-size-small',
        'font_size_nav'       => '--font-size-nav',
        'font_size_h1'        => '--font-size-h1',
        'font_size_h2'        => '--font-size-h2',
        'max_width'           => '--max-width',
    ];

    foreach ($map as $key => $cssVar) {
        $val = $s[$key] ?? '';
        if ($val !== '' && ($val !== ($d[$key] ?? ''))) {
            $safeVal = (string) $val;
            $safeVal = strip_tags($safeVal);
            $safeVal = str_replace(["\r", "\n"], ' ', $safeVal);
            $safeVal = preg_replace('/\s{2,}/', ' ', $safeVal);
            $vars[] = $cssVar . ': ' . trim($safeVal) . ';';
        }
    }

    // Ensure font family always flows through CSS overrides when changed
    $fontFamily = (string) ($s['font_family'] ?? '');
    $defaultFontFamily = (string) ($d['font_family'] ?? '');
    if ($fontFamily !== '' && $fontFamily !== $defaultFontFamily) {
        $safeVal = strip_tags($fontFamily);
        $safeVal = str_replace(["\r", "\n"], ' ', $safeVal);
        $safeVal = preg_replace('/\s{2,}/', ' ', $safeVal);
        $vars[] = '--font-family: ' . trim($safeVal) . ';';
    }

    $css = '';
    if (!empty($vars)) {
        $css .= ':root { ' . implode(' ', $vars) . ' }';
    }

    // All overrides (font-family, header colors, max-width) flow through CSS custom
    // properties in the map above. No direct element rules needed.

    return $css;
}

/**
 * Get GUI context for templates.
 * Returns array with app_name parts, font_url, css_overrides.
 */
function getGuiContext(): array
{
    $s = readGuiSettings();
    return [
        'app_name'        => $s['app_name'] ?? 'Ikabud',
        'app_name_accent' => $s['app_name_accent'] ?? 'Ikabud',
        'app_name_rest'   => $s['app_name_rest'] ?? '',
        'font_url'        => $s['font_url'] ?? '',
        'css_overrides'   => guiCssOverrides(),
    ];
}

function gui_settings_capability_handlers(): array
{
    return ['gui_settings.apply@1' => 'gui_settings_cap_apply_1'];
}

function gui_settings_cap_apply_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $input = is_array($payload) ? $payload : [];
    $settings = is_array($input['settings'] ?? null) ? $input['settings'] : $input;
    $settings = array_intersect_key($settings, guiSettingsDefaults());
    if ($settings === []) {
        return ['ok' => false, 'error' => 'At least one recognized GUI setting is required.'];
    }

    saveGuiSettings($settings);
    return ['ok' => true, 'settings' => readGuiSettings()];
}

// ─── Kernel Hook Registration ─────────────────────────────────────────────
// Register with kernel.gui_context hook so the kernel never calls
// getGuiContext() or readGuiSettings() directly.

app()->hooks()->on('kernel.gui_context', function (array $defaults): array {
    // Merge full saved settings + generated overrides
    $fullSettings = readGuiSettings();
    $ctx = getGuiContext();
    return array_merge($defaults, $fullSettings, $ctx);
});
