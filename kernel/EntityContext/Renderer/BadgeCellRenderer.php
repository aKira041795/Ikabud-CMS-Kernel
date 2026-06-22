<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a colored badge/pill.
 *
 * Supports static color map via $options['map']:
 *   ['computed' => ['label' => 'Computed', 'color' => 'amber'], ...]
 *
 * Or from field contract renderer arg (JSON string).
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class BadgeCellRenderer implements CellRendererInterface
{
    /** @var array<string, string> CSS classes per color name */
    private const COLORS = [
        'green'  => 'bg-green-100 text-green-700',
        'red'    => 'bg-red-100 text-red-700',
        'amber'  => 'bg-amber-100 text-amber-700',
        'blue'   => 'bg-blue-100 text-blue-700',
        'purple' => 'bg-purple-100 text-purple-700',
        'indigo' => 'bg-indigo-100 text-indigo-700',
        'orange' => 'bg-orange-100 text-orange-700',
        'gray'   => 'bg-gray-100 text-gray-500',
        'lime'   => 'bg-lime-100 text-lime-700',
        'teal'   => 'bg-teal-100 text-teal-700',
        'pink'   => 'bg-pink-100 text-pink-700',
        'cyan'   => 'bg-cyan-100 text-cyan-700',
    ];

    public function render(CellRenderContext $context): CellRenderResult
    {
        $str = (string)$context->value;
        $safe = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

        // Resolve color map from options or field contract renderer arg
        $colorMap = $context->options['map'] ?? [];
        $arg = $context->options['arg'] ?? '';

        // Try JSON arg (from renderer="badge:{...}" syntax)
        if (empty($colorMap) && $arg !== '') {
            $parsed = json_decode($arg, true);
            if (is_array($parsed)) {
                $colorMap = $parsed;
            }
        }

        if (!empty($colorMap) && isset($colorMap[$str])) {
            $entry = $colorMap[$str];
            if (is_string($entry) && str_contains($entry, '|')) {
                [$label, $color] = explode('|', $entry, 2);
            } else {
                $label = is_string($entry) ? $entry : $str;
                $color = 'gray';
            }
            $colorClass = self::COLORS[$color] ?? self::COLORS['gray'];
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $html = "<span class=\"inline-flex px-2 py-0.5 rounded-full text-xs font-medium {$colorClass}\">{$safeLabel}</span>";
            return new CellRenderResult(html: $html, text: $label, exportValue: $str, ariaLabel: $label);
        }

        // Default: truthy → Active/green, falsy → Inactive/gray
        $isActive = $context->value && $str !== '0' && $str !== '';
        if ($isActive) {
            return new CellRenderResult(
                html: '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>',
                text: 'Active',
                exportValue: $str,
                ariaLabel: 'Active',
            );
        }
        return new CellRenderResult(
            html: '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>',
            text: 'Inactive',
            exportValue: $str,
            ariaLabel: 'Inactive',
        );
    }
}
