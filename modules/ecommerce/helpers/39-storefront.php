<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Storefront Branding & Operating Hours (39-storefront.php)
// ─────────────────────────────────────────────────────────────────────────

// ── Operating Hours ───────────────────────────────────────────────────────

function ecStorefrontResolveStore(array|int|null $store = null): ?array
{
    if (is_array($store)) {
        return $store;
    }

    if (is_int($store) && $store > 0 && function_exists('ecStoreById')) {
        return ecStoreById($store);
    }

    return null;
}

function ecStorefrontStoreSettings(array|int|null $store = null): array
{
    $resolvedStore = ecStorefrontResolveStore($store);
    if (!is_array($resolvedStore) || !function_exists('ecStoreSettingsArray')) {
        return [];
    }

    return ecStoreSettingsArray($resolvedStore);
}

function ecStorefrontHasExplicitValue(mixed $value): bool
{
    if ($value === null) {
        return false;
    }

    if (is_string($value)) {
        return trim($value) !== '';
    }

    return true;
}

function ecStorefrontNormaliseTimeValue(mixed $value, string $default): string
{
    $resolved = trim((string)$value);
    if ($resolved === '') {
        return $default;
    }

    return preg_match('/^\d{2}:\d{2}$/', $resolved) ? $resolved : $default;
}

function ecStorefrontNormaliseHours(mixed $rawHours): array
{
    $parsed = [];
    if (is_string($rawHours)) {
        $rawHours = trim($rawHours);
        if ($rawHours !== '') {
            try {
                $decoded = json_decode($rawHours, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $parsed = $decoded;
                }
            } catch (\Throwable $e) {
                $parsed = [];
            }
        }
    } elseif (is_array($rawHours)) {
        $parsed = $rawHours;
    }

    $result = [];
    foreach (ecStoreHoursDayKeys() as $day) {
        $entry = is_array($parsed[$day] ?? null) ? $parsed[$day] : [];
        $result[$day] = [
            'open' => (bool)($entry['open'] ?? false),
            'from' => ecStorefrontNormaliseTimeValue($entry['from'] ?? null, '09:00'),
            'to' => ecStorefrontNormaliseTimeValue($entry['to'] ?? null, '17:00'),
        ];
    }

    return $result;
}

function ecStoreHoursConfigured(array|int|null $store = null): bool
{
    $settings = ecStorefrontStoreSettings($store);
    $hoursMode = trim((string)($settings['store_hours_mode'] ?? ''));
    if ($hoursMode === 'hide') {
        return false;
    }
    if ($hoursMode === 'custom') {
        return true;
    }

    return trim((string)ecSettings('store_hours', '')) !== '';
}

function ecStorefrontThemePalette(string $theme): ?array
{
    $palettes = [
        'indigo' => [
            '50'  => '#eef2ff',
            '100' => '#e0e7ff',
            '200' => '#c7d2fe',
            '500' => '#6366f1',
            '600' => '#4f46e5',
            '700' => '#4338ca',
        ],
        'emerald' => [
            '50'  => '#ecfdf5',
            '100' => '#d1fae5',
            '200' => '#a7f3d0',
            '500' => '#10b981',
            '600' => '#059669',
            '700' => '#047857',
        ],
        'rose' => [
            '50'  => '#fff1f2',
            '100' => '#ffe4e6',
            '200' => '#fecdd3',
            '500' => '#f43f5e',
            '600' => '#e11d48',
            '700' => '#be123c',
        ],
    ];

    return $palettes[$theme] ?? null;
}

/**
 * Day keys in canonical order (Sun–Sat aligns with PHP date('w')).
 */
function ecStoreHoursDayKeys(): array
{
    return ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
}

function ecStoreHoursDayLabels(): array
{
    return [
        'sun' => 'Sunday',
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
    ];
}

/**
 * Returns the parsed operating hours array.
 * Each entry: ['open' => bool, 'from' => '09:00', 'to' => '18:00']
 * Missing days fall back to sensible defaults (closed).
 */
function ecStoreHours(array|int|null $store = null): array
{
    if (!ecStoreHoursConfigured($store)) {
        return [];
    }

    $settings = ecStorefrontStoreSettings($store);
    $hoursMode = trim((string)($settings['store_hours_mode'] ?? ''));

    if ($hoursMode === 'custom') {
        return ecStorefrontNormaliseHours($settings['store_hours'] ?? []);
    }

    return ecStorefrontNormaliseHours(ecSettings('store_hours', ''));
}

/**
 * Returns true when the store has configured hours and is currently open.
 * Falls back to true (open) when no hours have been configured.
 */
function ecStoreIsOpenNow(array|int|null $store = null): bool
{
    $settings = ecStorefrontStoreSettings($store);
    $hoursMode = trim((string)($settings['store_hours_mode'] ?? ''));
    if ($hoursMode === 'hide') {
        return true; // store explicitly hides hours → assume always open
    }
    $raw = $hoursMode === 'custom' ? ($settings['store_hours'] ?? []) : ecSettings('store_hours', '');
    if ((is_string($raw) && trim($raw) === '') || (is_array($raw) && $raw === [])) {
        return true; // no hours configured → assume always open
    }

    $hours = ecStoreHours($store);
    $dayIndex = (int)date('w'); // 0=Sun … 6=Sat
    $dayKey   = ecStoreHoursDayKeys()[$dayIndex];
    $day      = $hours[$dayKey] ?? ['open' => false];

    if (!(bool)($day['open'] ?? false)) {
        return false;
    }

    $now  = (int)date('Hi'); // e.g. 0930
    $from = (int)str_replace(':', '', $day['from'] ?? '0000');
    $to   = (int)str_replace(':', '', $day['to']   ?? '0000');

    return $now >= $from && $now < $to;
}

/**
 * Returns a flat array of today's human-readable hours, or null when closed.
 * ['label' => 'Monday', 'from' => '09:00', 'to' => '18:00', 'is_open' => true]
 */
function ecStoreTodayHours(array|int|null $store = null): array
{
    $hours   = ecStoreHours($store);
    if ($hours === []) {
        return [];
    }

    $labels  = ecStoreHoursDayLabels();
    $dayIndex = (int)date('w');
    $dayKey  = ecStoreHoursDayKeys()[$dayIndex];
    $day     = $hours[$dayKey];

    return [
        'label'   => $labels[$dayKey],
        'from'    => $day['from'],
        'to'      => $day['to'],
        'is_open' => $day['open'],
    ];
}

// ── Social Links ──────────────────────────────────────────────────────────

/**
 * Returns an array of configured social links with icon identifiers.
 * Only includes links that have a value set.
 */
function ecSocialLinks(array|int|null $store = null): array
{
    $settings = ecStorefrontStoreSettings($store);
    $mode = trim((string)($settings['social_links_mode'] ?? ''));
    if ($mode === 'hide') {
        return [];
    }

    $links = [];
    $platforms = [
        'facebook'  => ['label' => 'Facebook',  'icon' => 'facebook'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'instagram'],
        'tiktok'    => ['label' => 'TikTok',    'icon' => 'tiktok'],
        'twitter'   => ['label' => 'X / Twitter', 'icon' => 'twitter'],
        'youtube'   => ['label' => 'YouTube',   'icon' => 'youtube'],
    ];

    foreach ($platforms as $key => $meta) {
        $url = $mode === 'custom'
            ? trim((string)($settings['social_' . $key] ?? ''))
            : trim((string)ecSettings('social_' . $key, ''));
        if ($url !== '') {
            $links[] = array_merge($meta, ['url' => $url, 'key' => $key]);
        }
    }
    return $links;
}

// ── Theme ─────────────────────────────────────────────────────────────────

/**
 * Returns the active storefront theme slug.
 */
function ecStorefrontTheme(array|int|null $store = null): string
{
    $settings = ecStorefrontStoreSettings($store);
    $theme = trim((string)($settings['storefront_theme'] ?? ''));
    if ($theme === '') {
        $theme = trim((string)ecSettings('storefront_theme', 'orange'));
    }
    $valid = ['orange', 'indigo', 'emerald', 'rose'];
    return in_array($theme, $valid, true) ? $theme : 'orange';
}

/**
 * Returns a <style> block that remaps the default orange palette to the
 * selected theme's Tailwind colour equivalent. Returns an empty string for
 * the default orange theme (no override needed).
 */
function ecStorefrontThemeCss(array|int|null $store = null): string
{
    $theme = ecStorefrontTheme($store);
    if ($theme === 'orange') {
        return '';
    }

    $p = ecStorefrontThemePalette($theme);
    if ($p === null) {
        return '';
    }

    return '<style>
/* Ecommerce storefront theme: ' . htmlspecialchars($theme, ENT_QUOTES) . ' */
body {
    --color-primary: ' . $p['600'] . ' !important;
    --color-link: ' . $p['600'] . ' !important;
    --color-light-bg: ' . $p['50'] . ' !important;
    --storefront-cta-bg: ' . $p['600'] . ' !important;
    --storefront-secondary-bg: ' . $p['50'] . ' !important;
    --storefront-secondary-text: ' . $p['700'] . ' !important;
    --storefront-badge-bg: ' . $p['100'] . ' !important;
    --storefront-badge-text: ' . $p['700'] . ' !important;
    --storefront-success-bg: ' . $p['100'] . ' !important;
    --storefront-success-text: ' . $p['700'] . ' !important;
    --storefront-warning-bg: ' . $p['50'] . ' !important;
    --storefront-warning-text: ' . $p['700'] . ' !important;
}
.bg-orange-50  { background-color: ' . $p['50']  . ' !important; }
.bg-orange-100 { background-color: ' . $p['100'] . ' !important; }
.bg-orange-200 { background-color: ' . $p['200'] . ' !important; }
.bg-orange-600 { background-color: ' . $p['600'] . ' !important; }
.bg-orange-700 { background-color: ' . $p['700'] . ' !important; }
.hover\:bg-orange-50:hover  { background-color: ' . $p['50']  . ' !important; }
.hover\:bg-orange-100:hover { background-color: ' . $p['100'] . ' !important; }
.hover\:bg-orange-700:hover { background-color: ' . $p['700'] . ' !important; }
.text-orange-600 { color: ' . $p['600'] . ' !important; }
.text-orange-700 { color: ' . $p['700'] . ' !important; }
.border-orange-500 { border-color: ' . $p['500'] . ' !important; }
.border-orange-100 { border-color: ' . $p['100'] . ' !important; }
.border-orange-200 { border-color: ' . $p['200'] . ' !important; }
.from-orange-50  { --tw-gradient-from: ' . $p['50']  . ' !important; }
.focus\:border-orange-500:focus { border-color: ' . $p['500'] . ' !important; }
</style>';
}

/**
 * Returns the operating hours as an ordered array suitable for template rendering.
 * Each entry includes 'key', 'label', 'open', 'from', 'to', 'today'.
 */
function ecStoreHoursSchedule(array|int|null $store = null): array
{
    $hours   = ecStoreHours($store);
    if ($hours === []) {
        return [];
    }

    $labels  = ecStoreHoursDayLabels();
    $dayIndex = (int)date('w'); // 0=Sun … 6=Sat
    $todayKey = ecStoreHoursDayKeys()[$dayIndex];

    $schedule = [];
    foreach (ecStoreHoursDayKeys() as $key) {
        $day = $hours[$key];
        $schedule[] = [
            'key'   => $key,
            'label' => $labels[$key],
            'open'  => $day['open'],
            'from'  => $day['from'],
            'to'    => $day['to'],
            'today' => $key === $todayKey,
        ];
    }
    return $schedule;
}

// ── Banner ────────────────────────────────────────────────────────────────

/**
 * Returns the banner config array, or null when the banner is disabled.
 */
function ecStoreBanner(array|int|null $store = null): ?array
{
    $settings = ecStorefrontStoreSettings($store);
    $mode = trim((string)($settings['store_banner_mode'] ?? ''));

    if ($mode === 'hide') {
        return null;
    }

    if ($mode === 'show') {
        $ctaUrl = trim((string)($settings['store_banner_cta_url'] ?? ''));
        return [
            'headline'  => trim((string)($settings['store_banner_headline'] ?? '')),
            'subtext'   => trim((string)($settings['store_banner_subtext'] ?? '')),
            'image_url' => trim((string)($settings['store_banner_image_url'] ?? '')),
            'cta_text'  => trim((string)($settings['store_banner_cta_text'] ?? 'Shop Now')),
            'cta_url'   => $ctaUrl ?: '/ecommerce/shop',
        ];
    }

    if (!(bool)ecSettings('store_banner_enabled', false)) {
        return null;
    }

    $ctaUrl = trim((string)ecSettings('store_banner_cta_url', ''));
    return [
        'headline'  => trim((string)ecSettings('store_banner_headline', '')),
        'subtext'   => trim((string)ecSettings('store_banner_subtext', '')),
        'image_url' => trim((string)ecSettings('store_banner_image_url', '')),
        'cta_text'  => trim((string)ecSettings('store_banner_cta_text', 'Shop Now')),
        'cta_url'   => $ctaUrl ?: '/ecommerce/shop',
    ];
}

function ecStorefrontRenderContext(array|int|null $store = null): array
{
    // In single-store mode, auto-resolve the default store so per-store settings
    // configured through store-admin are applied on the main shop/product pages too,
    // not only when accessed via ?store=X or /store/{slug}.
    // Multi-store mode intentionally keeps global settings when no store filter is active.
    if ($store === null
        && function_exists('ecIsMultiStoreEnabled')
        && !ecIsMultiStoreEnabled()
        && function_exists('ecStoreDefault')
    ) {
        $store = ecStoreDefault();
    }

    return [
        'store_banner' => ecStoreBanner($store) ?? [],
        'store_hours_schedule' => ecStoreHoursSchedule($store),
        'store_is_open' => ecStoreIsOpenNow($store),
        'social_links' => ecSocialLinks($store),
        'storefront_theme' => ecStorefrontTheme($store),
        'storefront_theme_css' => ecStorefrontThemeCss($store),
    ];
}

function ecStorefrontHoursFormSchedule(array $input = [], array|int|null $store = null): array
{
    $hoursInput = $input['setting_store_hours'] ?? null;
    if (is_array($hoursInput)) {
        $schedule = [];
        $labels = ecStoreHoursDayLabels();
        foreach (ecStoreHoursDayKeys() as $day) {
            $entry = is_array($hoursInput[$day] ?? null) ? $hoursInput[$day] : [];
            $schedule[] = [
                'key' => $day,
                'label' => $labels[$day],
                'open' => !empty($entry['open']),
                'from' => ecStorefrontNormaliseTimeValue($entry['from'] ?? null, '09:00'),
                'to' => ecStorefrontNormaliseTimeValue($entry['to'] ?? null, '17:00'),
                'today' => false,
            ];
        }
        return $schedule;
    }

    $schedule = ecStoreHoursSchedule($store);
    if ($schedule !== []) {
        return $schedule;
    }

    $defaults = [];
    foreach (ecStoreHoursDayKeys() as $day) {
        $defaults[] = [
            'key' => $day,
            'label' => ecStoreHoursDayLabels()[$day],
            'open' => false,
            'from' => '09:00',
            'to' => '17:00',
            'today' => false,
        ];
    }

    return $defaults;
}
