<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a datetime value with configurable format.
 *
 * Options / renderer arg:
 *   'time'  → 14:30
 *   'date'  → Jun 19
 *   'full'  → Jun 19 14:30 (default)
 *   'iso'   → 2026-06-19T14:30:00
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class DateTimeCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $format = $context->options['format'] ?? $context->options['arg'] ?? 'full';

        $ts = is_numeric($context->value) ? (int)$context->value : strtotime((string)$context->value);
        if ($ts === false || $ts <= 0) {
            $raw = (string)$context->value;
            $safe = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
            return new CellRenderResult(html: $safe, text: $raw, exportValue: $raw);
        }

        $html = match ($format) {
            'time' => '<span class="font-mono text-xs">' . date('H:i', $ts) . '</span>',
            'date' => '<span class="text-xs">' . date('M d', $ts) . '</span>',
            'iso'  => '<span class="text-xs">' . date('Y-m-d\TH:i:s', $ts) . '</span>',
            default => '<span class="text-xs">' . date('M d H:i', $ts) . '</span>',
        };

        $text = match ($format) {
            'time' => date('H:i', $ts),
            'date' => date('M d', $ts),
            'iso'  => date('Y-m-d\TH:i:s', $ts),
            default => date('M d H:i', $ts),
        };

        return new CellRenderResult(
            html: $html,
            text: $text,
            exportValue: date('Y-m-d H:i:s', $ts),
            ariaLabel: date('F j, Y g:i A', $ts),
        );
    }
}
