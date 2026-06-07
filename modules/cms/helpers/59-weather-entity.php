<?php

declare(strict_types=1);

/**
 * CMS Weather Entity Integration — bridges the polyglot weather service
 * into the CMS entity-view system.
 *
 * This proves the full Kernel OS pipeline:
 *   CMS → EntityViewResolver → CapabilityBus → ServiceProxy → HTTP → Python → wttr.in
 *
 * Proves all five Kernel OS + DiSyL pillars:
 *   1. Entity View works (ikb_entity_list + ikb_entity_detail components)
 *   2. DiSyL-agnostic rendering (works outside CMS admin context)
 *   3. Polyglot runtime (PHP capability bus → Python microservice → real API)
 *   4. Page Builder integrable (entity_view / entity_list widgets)
 *   5. CMS page displayable (public routes + template rendering)
 *
 * Registered capabilities:
 *   entity.list.weather_current@1   → current weather for a city (list)
 *   entity.list.weather_forecast@1  → forecast for a city (list)
 *   entity.list.weather@1           → auto-routes to current or forecast
 *   entity.get.weather@1            → single weather entity detail
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

    // Current weather — entity detail view (for ikb_entity_detail)
    $views->registerView('weather.current', 'detail', [
        'fields' => ['city', 'temperature_c', 'condition', 'humidity', 'wind_kph', 'feels_like_c', 'source'],
        'actions' => [],
        'limit' => 1,
        'empty_state' => 'No weather data available for this city.',
        'error_state' => 'Weather fetch failed.',
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

    // Forecast — card_grid view (for builder display)
    $views->registerView('weather.forecast', 'card_grid', [
        'fields' => ['date', 'high_c', 'low_c', 'condition'],
        'actions' => [],
        'limit' => 5,
        'sort' => ['field' => 'date', 'direction' => 'asc'],
        'empty_state' => 'No forecast data.',
    ], 'cms');

    // Generic weather entity — card view (used by entity_list source="weather")
    $views->registerView('weather', 'card', [
        'fields' => ['city', 'temperature_c', 'condition', 'humidity', 'wind_kph', 'feels_like_c', 'source'],
        'actions' => [],
        'limit' => 1,
        'empty_state' => 'Enter a city to see weather.',
        'error_state' => 'Weather service unavailable.',
    ], 'cms');
}

// ── Capability Handlers ──

/**
 * Handle entity.list.weather_current@1 — delegates to the Python weather service.
 */
function cms_cap_entity_list_weather_current(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $city = cmsWeatherResolveCity($payload);

    try {
        $result = \app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);

        if (is_array($result)) {
            return ['rows' => [$result], 'total' => 1];
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

/**
 * Handle entity.list.weather_forecast@1 — delegates to the Python weather service.
 */
function cms_cap_entity_list_weather_forecast(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $city = cmsWeatherResolveCity($payload);
    $days = cmsWeatherResolveDays($payload);

    try {
        $result = \app()->cap()->call('weather.forecast@1', ['city' => $city, 'days' => $days], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);

        if (is_array($result) && isset($result['forecast'])) {
            return ['rows' => $result['forecast'], 'total' => count($result['forecast'])];
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

/**
 * Handle entity.list.weather@1 — auto-routes to current or forecast based on qualifier.
 *
 * Called when EntityViewResolver resolves source="weather" or "weather.recent" etc.
 */
function cms_cap_entity_list_weather(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $qualifier = (string)($payload['qualifier'] ?? '');
    if (str_contains($qualifier, 'forecast')) {
        return cms_cap_entity_list_weather_forecast($payload, $capabilityId, $providerId);
    }
    return cms_cap_entity_list_weather_current($payload, $capabilityId, $providerId);
}

/**
 * Handle entity.get.weather@1 — resolves a single weather entity for ikb_entity_detail.
 *
 * The entity_id is interpreted as the city name (or "London" as default).
 */
function cms_cap_entity_get_weather(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
{
    $city = 'London';
    if (is_array($payload)) {
        if (!empty($payload['entity_id']) && is_string($payload['entity_id'])) {
            $city = $payload['entity_id'];
        } elseif (!empty($payload['id']) && is_string($payload['id'])) {
            $city = $payload['id'];
        } elseif (!empty($payload['filters']['city'])) {
            $city = (string)$payload['filters']['city'];
        } elseif (!empty($payload['city'])) {
            $city = (string)$payload['city'];
        }
    }

    try {
        $result = \app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);

        if (is_array($result)) {
            return $result;
        }
    } catch (\Throwable $e) {
        return null;
    }

    return null;
}

// ── Helpers ──

/**
 * Resolve city from payload filters, entity_id, or direct city key.
 */
function cmsWeatherResolveCity(mixed $payload): string
{
    $city = 'London';
    if (is_array($payload)) {
        if (!empty($payload['filters']['city']) && is_string($payload['filters']['city'])) {
            $city = $payload['filters']['city'];
        } elseif (!empty($payload['city']) && is_string($payload['city'])) {
            $city = $payload['city'];
        } elseif (!empty($payload['entity_id']) && is_string($payload['entity_id'])) {
            $city = $payload['entity_id'];
        }
    }
    return trim($city) !== '' ? trim($city) : 'London';
}

/**
 * Resolve forecast days from payload, clamped 1-7.
 */
function cmsWeatherResolveDays(mixed $payload): int
{
    $days = 3;
    if (is_array($payload)) {
        if (isset($payload['filters']['days'])) {
            $days = (int)$payload['filters']['days'];
        } elseif (isset($payload['days'])) {
            $days = (int)$payload['days'];
        } elseif (isset($payload['limit'])) {
            $days = (int)$payload['limit'];
        }
    }
    return max(1, min(7, $days));
}

// ── Register capability handlers ──
// Picked up by naming convention in module-manager.php:
//   cms_cap_entity_list_weather_current → entity.list.weather_current@1
//
// The EntityViewResolver and ikb_entity_detail/ikb_entity_list sanitize
// source strings by replacing dots with underscores, so we register handlers
// under BOTH the canonical name and the sanitized form:
//   source="weather.current" → entity.get.weather_current@1
//   source="weather"         → entity.get.weather@1

$GLOBALS['capability_handlers'] = array_merge(
    $GLOBALS['capability_handlers'] ?? [],
    [
        'entity.list.weather_current@1'  => 'cms_cap_entity_list_weather_current',
        'entity.list.weather_forecast@1' => 'cms_cap_entity_list_weather_forecast',
        'entity.list.weather@1'          => 'cms_cap_entity_list_weather',
        'entity.get.weather@1'           => 'cms_cap_entity_get_weather',
        'entity.get.weather_current@1'   => 'cms_cap_entity_get_weather',
    ]
);
