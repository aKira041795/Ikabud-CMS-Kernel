<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a location with place name and optional coordinates.
 *
 * Input value can be:
 *   - A string (place name only)
 *   - An array with 'name', 'latitude', 'longitude' keys
 *
 * Options:
 *   - show_coords: bool (default true)
 *   - coords_format: 'dms' | 'decimal' (default 'decimal')
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class LocationCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $showCoords = $context->options['show_coords'] ?? true;
        $value = $context->value;

        // Handle array input: ['name' => ..., 'latitude' => ..., 'longitude' => ...]
        if (is_array($value)) {
            $name = (string)($value['name'] ?? '');
            $lat = $value['latitude'] ?? null;
            $lng = $value['longitude'] ?? null;
        } else {
            $name = (string)$value;
            // Resolve lat/lng from row using field-specific keys.
            // location_in → latitude_in/longitude_in; location_out → latitude_out/longitude_out
            $field = $context->field;
            $latKey = $context->options['lat_field'] ?? (str_contains($field, 'out') ? 'latitude_out' : 'latitude');
            $lngKey = $context->options['lng_field'] ?? (str_contains($field, 'out') ? 'longitude_out' : 'longitude');
            // Fallback chain: exact key → latitude_in → latitude
            $lat = $context->row[$latKey] ?? $context->row['latitude_in'] ?? $context->row['latitude'] ?? null;
            $lng = $context->row[$lngKey] ?? $context->row['longitude_in'] ?? $context->row['longitude'] ?? null;
        }

        // Strip trailing coordinates in parentheses from display name
        // e.g. "Office (14.123,121.456)" → "Office"
        $displayName = preg_replace('/\s*\(-?\d+\.?\d*,-?\d+\.?\d*\)$/', '', $name);
        $displayName = $displayName !== '' ? htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') : '—';

        $coordsHtml = '';
        $coordsText = '';
        if ($showCoords && $lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
            $safeLat = htmlspecialchars((string)$lat, ENT_QUOTES, 'UTF-8');
            $safeLng = htmlspecialchars((string)$lng, ENT_QUOTES, 'UTF-8');
            $coordsHtml = "<span class=\"block text-blue-600 text-xs mt-0.5\">📍 {$safeLat},{$safeLng}</span>";
            $coordsText = " {$lat},{$lng}";
        }

        $html = "<span class=\"font-medium\">{$displayName}</span>{$coordsHtml}";

        return new CellRenderResult(
            html: $html,
            text: $name !== '' ? $name . $coordsText : '—',
            exportValue: $name,
            ariaLabel: $name !== '' ? "Location: {$name}{$coordsText}" : 'No location',
        );
    }
}
