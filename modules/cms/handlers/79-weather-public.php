<?php

declare(strict_types=1);

/**
 * CMS Weather — Public Polyglot Entity-View Page
 *
 * This handler proves DiSyL-agnostic rendering:
 *   - No cmsRender(), no cmsAdminContext(), no cmsRequireRole()
 *   - Uses app()->render() directly (kernel-level rendering)
 *   - Template uses {ikb_entity_detail} and {ikb_entity_list} components
 *
 * Public route: GET /cms/weather?city=Manila
 */

function cmsPublicWeather(array $params = []): void
{
    $city = trim((string)($_GET['city'] ?? 'London'));
    if ($city === '') {
        $city = 'London';
    }

    $error = null;

    // Prove the weather service is reachable (optional — entity components handle their own errors)
    try {
        $probe = app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    // Render via kernel-level template engine — no CMS wrapper needed
    // This proves DiSyL agnosticism: the template works with any module
    echo app()->render('modules/cms/public/weather-public.disyl', [
        'page_title' => 'Weather — Polyglot Demo',
        'city' => $city,
        'error' => $error,
    ]);
}
