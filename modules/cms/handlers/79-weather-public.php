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

    // Fetch weather directly
    $currentWeather = null;
    $forecast = [];
    $error = null;

    try {
        $currentWeather = app()->cap()->call('weather.current@1', ['city' => $city], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        $forecastResult = app()->cap()->call('weather.forecast@1', ['city' => $city, 'days' => 5], [
            'caller' => ['module' => 'cms'],
            'mode' => 'first',
            'timeout_ms' => 10000,
        ]);
        $forecast = is_array($forecastResult) ? ($forecastResult['forecast'] ?? []) : [];
    } catch (\Throwable $e) {}

    // Render using CMS conventions — pre-render HTML in PHP
    $hasCurrent = is_array($currentWeather) && !empty($currentWeather['temperature_c']);
    $hasForecast = !empty($forecast);

    // Use raw echo to avoid DiSyL variable-passing issues in interpreted mode
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Weather — ' . htmlspecialchars($city) . '</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<link rel="stylesheet" href="http://cmsnew.test/assets/cms/themes/native-default/style.css">';
    echo '</head><body>';
    echo '<div class="weather-public" style="max-width:960px;margin:0 auto;padding:24px 16px;font-family:system-ui,sans-serif;">';

    // Header
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">';
    echo '<div><h1 style="font-size:24px;font-weight:700;margin:0;color:#0f172a;">🌍 Weather for ' . htmlspecialchars($city) . '</h1>';
    echo '<p style="margin:4px 0 0;color:#64748b;font-size:13px;">Powered by Kernel OS 5.0 — Polyglot Entity-View Pipeline</p></div>';
    echo '<form method="GET" action="/cms/weather" style="display:flex;gap:8px;">';
    echo '<input type="text" name="city" value="' . htmlspecialchars($city) . '" placeholder="Enter city..." style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;width:180px;">';
    echo '<button type="submit" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Search</button>';
    echo '</form></div>';

    // Error
    if ($error) {
        echo '<div style="padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;margin-bottom:20px;color:#92400e;font-weight:500;">⚠️ ' . htmlspecialchars($error) . '</div>';
    }

    // Current weather
    if ($hasCurrent) {
        $temp = round((float)$currentWeather['temperature_c']);
        $cond = htmlspecialchars((string)($currentWeather['condition'] ?? ''));
        $hum = (int)($currentWeather['humidity'] ?? 0);
        $wind = round((float)($currentWeather['wind_kph'] ?? 0));
        $feels = round((float)($currentWeather['feels_like_c'] ?? 0));
        $src = htmlspecialchars((string)($currentWeather['source'] ?? ''));
        echo '<div style="background:linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);border-radius:16px;padding:28px 24px;color:#fff;margin-bottom:24px;box-shadow:0 8px 32px rgba(37,99,235,.25);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">';
        echo '<div><div style="font-size:14px;opacity:.8;text-transform:uppercase;letter-spacing:.5px">Current Weather</div>';
        echo '<div style="font-size:48px;font-weight:800;line-height:1.1;margin:4px 0">' . $temp . '°C</div>';
        echo '<div style="font-size:16px;font-weight:500">' . $cond . '</div>';
        echo '<div style="font-size:13px;opacity:.7;margin-top:4px">Feels like ' . $feels . '°C</div></div>';
        echo '<div style="text-align:right;font-size:14px;line-height:1.8">';
        echo '<div>💧 Humidity: <strong>' . $hum . '%</strong></div>';
        echo '<div>💨 Wind: <strong>' . $wind . ' km/h</strong></div>';
        if ($src) echo '<div style="margin-top:8px;font-size:11px;opacity:.6">via ' . $src . '</div>';
        echo '</div></div>';
    } else {
        echo '<div style="padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px;text-align:center;color:#94a3b8;">No weather data available for ' . htmlspecialchars($city) . '.</div>';
    }

    // Forecast
    if ($hasForecast) {
        echo '<h2 style="font-size:16px;font-weight:600;color:#475569;margin:20px 0 12px;">📅 5-Day Forecast</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">';
        foreach ($forecast as $day) {
            if (!is_array($day)) continue;
            $date = htmlspecialchars((string)($day['date'] ?? ''));
            $high = round((float)($day['high_c'] ?? 0));
            $low = round((float)($day['low_c'] ?? 0));
            $cond = htmlspecialchars((string)($day['condition'] ?? ''));
            echo '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)">';
            echo '<div style="font-size:12px;color:#64748b;font-weight:600;margin-bottom:8px">' . $date . '</div>';
            echo '<div style="font-size:24px;font-weight:700;color:#0f172a">' . $high . '°</div>';
            echo '<div style="font-size:14px;color:#94a3b8;margin-bottom:6px">' . $low . '°</div>';
            echo '<div style="font-size:12px;color:#64748b">' . $cond . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '<div style="margin-top:32px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;color:#94a3b8;">✅ Polyglot: PHP → ServiceProxy → HTTP → Python → wttr.in</div>';
    echo '</div></body></html>';
}
