<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a boolean value as Yes/No badge.
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class BooleanCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $is = $context->value && (string)$context->value !== '0';
        $label = $is ? 'Yes' : 'No';
        $class = $is ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';

        return new CellRenderResult(
            html: "<span class=\"inline-flex px-2 py-0.5 rounded-full text-xs font-medium {$class}\">{$label}</span>",
            text: $label,
            exportValue: $is,
            ariaLabel: $label,
        );
    }
}
