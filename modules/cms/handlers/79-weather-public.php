<?php

declare(strict_types=1);

/**
 * CMS Weather — Public Polyglot Entity-View Page
 *
 * Proves the full Kernel OS polyglot pipeline:
 *   DiSyL {ikb_entity_detail} → EntityViewResolver → CapabilityBus → ServiceProxy → HTTP → Python → wttr.in
 */

function cmsPublicWeather(array $params = []): void
{
    $city = trim((string)($_GET['city'] ?? 'London'));
    if ($city === '') {
        $city = 'London';
    }

    $error = null;

    // Optional probe to surface service errors early
    try {
        app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    // Render via kernel template engine — DiSyL entity components handle the rest
    echo app()->render('modules/cms/public/weather-public.disyl', [
        'page_title' => 'Weather — Polyglot Demo',
        'city' => $city,
        'error' => $error,
    ]);
}
