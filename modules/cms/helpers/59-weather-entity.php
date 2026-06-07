<?php

declare(strict_types=1);

/**
 * CMS Weather Entity Integration — bridges the polyglot weather service
 * into the CMS entity-view system.
 *
 * This proves the full Kernel OS pipeline:
 *   CMS → EntityViewResolver → CapabilityBus → ServiceProxy → HTTP → Python → wttr.in
 *
 * Registered capabilities:
 *   entity.list.weather_current@1  → proxies to weather.current@1
 *   entity.list.weather_forecast@1 → proxies to weather.forecast@1
 */

// ── Entity View Contracts ──

if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
    $views = $app->entityViews();

    // Current weather — card view
    $views->registerView('weather.current', 'card', [
        'fields' => ['city', 'temperature_c', 'condition', 'humidity', 'wind_kph', 'feels_like_c', 'source'],
        'actions' => [],
        'limit' => 1,
        'sort' => ['field' => 'temperature_c', 'direction' => 'desc'],
        'empty_state' => 'Enter a city to see current weather.',
        'error_state' => 'Weather service unavailable. Is the Python service running on port 9002?',
        'exportable' => false,
    ], 'cms');

    // Current weather — detailed view
    $views->registerView('weather.current', 'detailed', [
        'fields' => ['city', 'temperature_c', 'condition', 'humidity', 'wind_kph', 'feels_like_c', 'source'],
        'actions' => [],
        'limit' => 1,
        'empty_state' => 'No weather data.',
        'error_state' => 'Weather data unavailable.',
    ], 'cms');

    // Forecast — list view
    $views->registerView('weather.forecast', 'list', [
        'fields' => ['date', 'high_c', 'low_c', 'condition'],
        'actions' => [],
        'limit' => 7,
        'sort' => ['field' => 'date', 'direction' => 'asc'],
        'empty_state' => 'No forecast data available.',
        'error_state' => 'Forecast service unavailable.',
    ], 'cms');

    // Forecast — compact view
    $views->registerView('weather.forecast', 'compact', [
        'fields' => ['date', 'high_c', 'low_c', 'condition'],
        'actions' => [],
        'limit' => 3,
        'sort' => ['field' => 'date', 'direction' => 'asc'],
        'empty_state' => 'No forecast.',
    ], 'cms');
}

// ── Capability Handlers (proxy weather.* → entity.list.*) ──

/**
 * Handle entity.list.weather_current@1 — delegates to the Python weather service.
 */
function cms_cap_entity_list_weather_current(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $city = 'London';
    if (is_array($payload) && isset($payload['filters']['city'])) {
        $city = (string)$payload['filters']['city'];
    } elseif (is_array($payload) && isset($payload['city'])) {
        $city = (string)$payload['city'];
    }

    try {
        $result = \app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
        ]);

        if (is_array($result)) {
            // Wrap in rows format expected by EntityViewResolver
            return [
                'rows' => [$result],
                'total' => 1,
            ];
        }
    } catch (\Throwable $e) {
        // Graceful fallback — EntityViewResolver will use error_state
        return null;
    }

    return null;
}

/**
 * Handle entity.list.weather_forecast@1 — delegates to the Python weather service.
 */
function cms_cap_entity_list_weather_forecast(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $city = 'London';
    $days = 3;
    if (is_array($payload)) {
        if (isset($payload['filters']['city'])) {
            $city = (string)$payload['filters']['city'];
        } elseif (isset($payload['city'])) {
            $city = (string)$payload['city'];
        }
        if (isset($payload['filters']['days'])) {
            $days = max(1, min(7, (int)$payload['filters']['days']));
        } elseif (isset($payload['days'])) {
            $days = max(1, min(7, (int)$payload['days']));
        }
    }

    try {
        $result = \app()->cap()->call('weather.forecast@1', ['city' => $city, 'days' => $days], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
        ]);

        if (is_array($result) && isset($result['forecast'])) {
            return [
                'rows' => $result['forecast'],
                'total' => count($result['forecast']),
            ];
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

// ── Register capability handlers with the module-manager ──
// These are picked up by the naming convention in module-manager.php:
//   cms_cap_entity_list_weather_current → entity.list.weather_current@1

$GLOBALS['capability_handlers'] = array_merge(
    $GLOBALS['capability_handlers'] ?? [],
    [
        'entity.list.weather_current@1'  => 'cms_cap_entity_list_weather_current',
        'entity.list.weather_forecast@1' => 'cms_cap_entity_list_weather_forecast',
        // Also register under the parsed entity_type name (resolver strips qualifier)
        'entity.list.weather@1'          => 'cms_cap_entity_list_weather_current',
    ]
);
