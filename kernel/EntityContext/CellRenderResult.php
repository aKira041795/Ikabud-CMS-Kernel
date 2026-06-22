<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Immutable value object for the output of a cell renderer.
 *
 * Separates safe HTML from plain text, export values, and accessibility
 * labels so the rendering pipeline can choose the right representation
 * for each output target (HTML, CSV, PDF, screen reader, etc.).
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class CellRenderResult
{
    /**
     * @param string      $html        Safe HTML representation (escaped by the renderer)
     * @param string      $text        Plain-text representation (for CSV, text exports, fallback)
     * @param mixed       $exportValue Raw export value (for CSV/JSON export — null = use $text)
     * @param string|null $ariaLabel   Accessibility label (for screen readers)
     */
    public function __construct(
        public readonly string $html = '',
        public readonly string $text = '',
        public readonly mixed $exportValue = null,
        public readonly ?string $ariaLabel = null,
    ) {}
}
