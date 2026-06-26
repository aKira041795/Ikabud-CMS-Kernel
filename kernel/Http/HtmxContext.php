<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * HTMX request context — detect HTMX headers and build responses.
 *
 * Extracted from App.php for testability and single responsibility.
 *
 * @package Ikabud\Kernel\Http
 */
final class HtmxContext
{
    /**
     * Check if the current request is an HTMX fragment request.
     *
     * HX-History-Restore-Request means HTMX needs the full page
     * (back/forward navigation with a cache miss). Treat as full-page.
     */
    public static function isRequest(): bool
    {
        if (!empty($_SERVER['HTTP_HX_HISTORY_RESTORE_REQUEST'])) {
            return false;
        }
        return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
    }

    /**
     * Check if current request is an HTMX boosted navigation (hx-boost).
     */
    public static function isBoosted(): bool
    {
        return !empty($_SERVER['HTTP_HX_BOOSTED']);
    }

    /**
     * Get HTMX request details as an associative array.
     *
     * @return array{request: string|false, trigger: string|null, trigger_name: string|null, target: string|null, current_url: string|null, boosted: bool}
     */
    public static function context(): array
    {
        return [
            'request' => $_SERVER['HTTP_HX_REQUEST'] ?? false,
            'trigger' => $_SERVER['HTTP_HX_TRIGGER'] ?? null,
            'trigger_name' => $_SERVER['HTTP_HX_TRIGGER_NAME'] ?? null,
            'target' => $_SERVER['HTTP_HX_TARGET'] ?? null,
            'current_url' => $_SERVER['HTTP_HX_CURRENT_URL'] ?? null,
            'boosted' => isset($_SERVER['HTTP_HX_BOOSTED']),
        ];
    }

    /**
     * Send HTMX response headers.
     *
     * @param array<string, string> $headers Key-value pairs (e.g. ['redirect' => '/new-url'])
     */
    public static function sendHeaders(array $headers = []): void
    {
        foreach ($headers as $key => $value) {
            $headerName = 'HX-' . ucfirst($key);
            header("{$headerName}: {$value}");
        }
    }
}
