<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Contract for a cell renderer used by the entity rendering pipeline.
 *
 * Each renderer receives a fully-typed context and returns a structured
 * result that separates safe HTML from plain text, export values, and
 * accessibility labels.
 *
 * @package Ikabud\Kernel\EntityContext
 */
interface CellRendererInterface
{
    /**
     * Render a cell value for the given context.
     */
    public function render(CellRenderContext $context): CellRenderResult;
}
