<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a monetary value with currency symbol.
 *
 * Options:
 *   - decimals: number of decimal places (default 2)
 *   - currency: currency symbol (default ₱)
 *   - negative_class: CSS class for negative values (default 'text-red-600')
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class MoneyCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $decimals = (int)($context->options['decimals'] ?? 2);
        $currency = (string)($context->options['currency'] ?? '₱');
        $negativeClass = (string)($context->options['negative_class'] ?? 'text-red-600');

        $num = (float)$context->value;
        $formatted = $currency . number_format($num, $decimals);
        $class = $num < 0 ? $negativeClass : 'text-gray-900';
        $safe = htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');

        return new CellRenderResult(
            html: "<span class=\"{$class}\">{$safe}</span>",
            text: $formatted,
            exportValue: $num,
            ariaLabel: $formatted,
        );
    }
}
