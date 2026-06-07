<?php

declare(strict_types=1);

/**
 * CMS Weather admin page handler.
 *
 * Demonstrates polyglot entity-view integration:
 *   - Calls weather.current@1 and weather.forecast@1 via the capability bus
 *   - Capability bus dispatches to Python weather-service via ServiceProxy
 *   - Data rendered via DiSyL {ikb_entity_detail} and {ikb_entity_list} components
 */

function cmsAdminWeather(array $params = []): void
{
    $user = cmsRequireRole('administrator');

    $city = trim((string)($_GET['city'] ?? 'London'));
    if ($city === '') {
        $city = 'London';
    }

    // Fetch weather data through the entity-view pipeline
    $currentWeather = null;
    $forecast = null;
    $error = null;

    try {
        $currentWeather = app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
        ]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        $forecastResult = app()->cap()->call('weather.forecast@1', ['city' => $city, 'days' => 5], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
        ]);
        $forecast = $forecastResult['forecast'] ?? [];
    } catch (\Throwable $e) {
        // Non-critical — show what we have
    }

    echo cmsRender('modules/cms/admin/weather.disyl', array_merge(
        cmsAdminContext($user, 'weather', []),
        [
            'page_title' => 'Weather — Polyglot Demo',
            'city' => $city,
            'current' => $currentWeather,
            'forecast' => $forecast ?? [],
            'error' => $error,
            'source_label' => $currentWeather['source'] ?? 'unknown',
        ]
    ));
}
