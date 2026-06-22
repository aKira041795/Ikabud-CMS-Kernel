<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Immutable value object carrying all context a cell renderer needs.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class CellRenderContext
{
    /**
     * @param mixed       $value         Raw cell value from the data row
     * @param string      $field         Field name (e.g. 'status', 'price')
     * @param array       $row           Full data row (for cross-field context)
     * @param array       $fieldContract Field-level contract from the view declaration
     * @param string      $view          View mode (table, compact, card_grid, detailed)
     * @param string      $outputTarget  Output target: 'html', 'csv', 'pdf', 'text', 'export'
     * @param array       $options       Additional options (e.g. decimal places, color map)
     */
    public function __construct(
        public readonly mixed $value,
        public readonly string $field,
        public readonly array $row,
        public readonly array $fieldContract = [],
        public readonly string $view = 'table',
        public readonly string $outputTarget = 'html',
        public readonly array $options = [],
    ) {}
}
