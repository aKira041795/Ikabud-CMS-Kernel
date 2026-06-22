<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders a plain text string with HTML escaping.
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class TextCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $str = (string)$context->value;
        return new CellRenderResult(
            html: htmlspecialchars($str, ENT_QUOTES, 'UTF-8'),
            text: $str,
            exportValue: $str,
        );
    }
}
