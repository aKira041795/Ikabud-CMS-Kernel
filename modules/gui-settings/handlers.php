<?php

declare(strict_types=1);

// Load helpers
require_once __DIR__ . '/helpers.php';

// ─── Admin Settings Page ──────────────────────────────────────────────────

function handleGuiSettings(array $params = []): void
{
    $ctx = guiSettingsCtx();
    $user = $ctx->requireAnyRole('admin');

    $settings = readGuiSettings();
    $defaults = guiSettingsDefaults();

    // Build font presets for the dropdown
    $fontPresets = [
        ['label' => 'Inter (Default)', 'family' => "Inter, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'],
        ['label' => 'Poppins', 'family' => "Poppins, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap'],
        ['label' => 'Roboto', 'family' => "Roboto, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'],
        ['label' => 'Open Sans', 'family' => "Open Sans, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap'],
        ['label' => 'Nunito', 'family' => "Nunito, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap'],
        ['label' => 'Lato', 'family' => "Lato, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap'],
        ['label' => 'DM Sans', 'family' => "DM Sans, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap'],
        ['label' => 'Plus Jakarta Sans', 'family' => "Plus Jakarta Sans, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap'],
        ['label' => 'Source Sans 3', 'family' => "Source Sans 3, system-ui, sans-serif", 'url' => 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&display=swap'],
        ['label' => 'System UI', 'family' => "system-ui, -apple-system, sans-serif", 'url' => ''],
    ];

    // Color presets (themes)
    $colorPresets = [
        [
            'label' => 'Default Blue',
            'colors' => ['color_primary' => '#2563eb', 'color_primary_hover' => '#1d4ed8', 'color_primary_light' => '#dbeafe', 'color_header_bg' => '#1e293b', 'color_header_accent' => '#60a5fa'],
        ],
        [
            'label' => 'Emerald Green',
            'colors' => ['color_primary' => '#059669', 'color_primary_hover' => '#047857', 'color_primary_light' => '#d1fae5', 'color_header_bg' => '#064e3b', 'color_header_accent' => '#6ee7b7'],
        ],
        [
            'label' => 'Royal Purple',
            'colors' => ['color_primary' => '#7c3aed', 'color_primary_hover' => '#6d28d9', 'color_primary_light' => '#ede9fe', 'color_header_bg' => '#2e1065', 'color_header_accent' => '#c4b5fd'],
        ],
        [
            'label' => 'Warm Orange',
            'colors' => ['color_primary' => '#ea580c', 'color_primary_hover' => '#c2410c', 'color_primary_light' => '#ffedd5', 'color_header_bg' => '#431407', 'color_header_accent' => '#fdba74'],
        ],
        [
            'label' => 'Rose Pink',
            'colors' => ['color_primary' => '#e11d48', 'color_primary_hover' => '#be123c', 'color_primary_light' => '#ffe4e6', 'color_header_bg' => '#4c0519', 'color_header_accent' => '#fda4af'],
        ],
        [
            'label' => 'Teal',
            'colors' => ['color_primary' => '#0d9488', 'color_primary_hover' => '#0f766e', 'color_primary_light' => '#ccfbf1', 'color_header_bg' => '#134e4a', 'color_header_accent' => '#5eead4'],
        ],
        [
            'label' => 'Dark Mode',
            'colors' => ['color_bg' => '#0f172a', 'color_surface' => '#1e293b', 'color_border' => '#334155', 'color_text' => '#e2e8f0', 'color_text_muted' => '#94a3b8', 'color_text_light' => '#64748b', 'color_primary' => '#3b82f6', 'color_primary_hover' => '#2563eb', 'color_primary_light' => '#1e3a5f', 'color_header_bg' => '#020617', 'color_header_accent' => '#60a5fa'],
        ],
    ];

    // GUI Settings is a kernel companion rendered inside the kernel-admin
    // shell. Merge the kernel-admin layout context so navigation stays within
    // the same htmx-boost layout (avoids cross-layout head/Alpine breakage).
    $kernelAdminCtx = function_exists('kernelAdminContext')
        ? kernelAdminContext($user, 'gui-settings')
        : [];

    echo guiSettingsRender('modules/gui-settings/settings.disyl', array_merge(
        $kernelAdminCtx,
        [
            'page_title'    => 'GUI Settings',
            'settings'      => $settings,
            'defaults'      => $defaults,
            'setting_keys'  => array_keys($defaults),
            'font_presets'  => $fontPresets,
            'color_presets' => $colorPresets,
        ]
    ));
}

// ─── API: Save Settings ───────────────────────────────────────────────────

function apiSaveGuiSettings(array $params = []): void
{
    header('Content-Type: application/json');

    $user = guiSettingsUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    $input = guiSettingsInput();
    $defaults = guiSettingsDefaults();
    $allowed = array_keys($defaults);

    // Only save known keys
    $settings = [];
    foreach ($allowed as $key) {
        if (isset($input[$key])) {
            $settings[$key] = trim((string)$input[$key]);
        }
    }

    // Merge with existing (so partial saves work)
    $current = readGuiSettings();
    $merged = array_merge($current, $settings);

    // Validate hex color fields — reject values that are not valid hex colors
    foreach ($merged as $k => $v) {
        if (strpos($k, 'color_') !== 0) {
            continue;
        }
        $v = trim((string)$v);
        if ($v === '') {
            continue;
        }
        // Allow #RGB, #RRGGBB, #RRGGBBAA
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v)) {
            // Fall back to current or default value
            $merged[$k] = (string)($current[$k] ?? ($defaults[$k] ?? ''));
        }
    }

    // Normalize common CSS length fields (auto-fix typos like "15x" -> "15px")
    foreach (['font_size_base', 'font_size_small', 'font_size_h1', 'font_size_h2', 'font_size_nav', 'border_radius', 'header_height', 'nav_height', 'max_width'] as $k) {
        if (!isset($merged[$k])) {
            continue;
        }
        $val = trim((string) $merged[$k]);
        if ($val === '') {
            continue;
        }
        // Pure numbers -> px
        if (preg_match('/^\d+(?:\.\d+)?$/', $val)) {
            $merged[$k] = $val . 'px';
            continue;
        }
        // Common typo: trailing 'x' instead of 'px'
        if (preg_match('/^(\d+(?:\.\d+)?)x$/i', $val, $m)) {
            $merged[$k] = $m[1] . 'px';
            continue;
        }
        // Allow valid css lengths: px, rem, em, %, vh, vw
        if (!preg_match('/^\d+(?:\.\d+)?(px|rem|em|%|vh|vw)$/i', $val)) {
            // Fall back to current/default if invalid
            $defaults = guiSettingsDefaults();
            $merged[$k] = (string) ($current[$k] ?? ($defaults[$k] ?? ''));
        }
    }

    // Derive app_name_accent and app_name_rest from app_name if not explicitly set
    if (isset($input['app_name']) && !isset($input['app_name_accent'])) {
        $parts = explode(' ', trim($input['app_name']), 2);
        $merged['app_name_accent'] = $parts[0];
        $merged['app_name_rest'] = $parts[1] ?? '';
    }

    saveGuiSettings($merged);

    // Clear template cache so changes take effect
    $cacheDir = STORAGE_PATH . '/cache/disyl';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    kernelDeletePath($f);
                }
            }
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ─── API: Reset to Defaults ──────────────────────────────────────────────

function apiResetGuiSettings(array $params = []): void
{
    header('Content-Type: application/json');

    $user = guiSettingsUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Admin only']);
        exit;
    }

    saveGuiSettings(guiSettingsDefaults());

    // Clear template cache
    $cacheDir = STORAGE_PATH . '/cache/disyl';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                if (is_file($f)) {
                    kernelDeletePath($f);
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'settings' => guiSettingsDefaults()]);
    exit;
}
