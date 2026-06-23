<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Immutable value object carrying all context a row renderer needs.
 *
 * Consolidates the 14+ shared parameters across renderCompactRow,
 * renderCardGridRow, and renderTableRow to prevent parameter drift.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class RowRenderContext
{
    /**
     * @param array  $row            Single data row
     * @param array  $fields         Declared field names to display
     * @param array  $actions        Action IDs (e.g. ['view', 'edit'])
     * @param string $use            Style preset ('tailwind', 'bootstrap', 'legacy')
     * @param array  $actionUrls     URL template per action (e.g. ['view' => '/path/{id}'])
     * @param array  $actionMethods  HTTP method per action
     * @param array  $actionConfirm  Confirmation message per action
     * @param array  $actionShowIf   Condition expression per action
     * @param array  $actionLabels   Display label per action
     * @param array  $renderers      Field renderer map (field_name => renderer_string)
     * @param string $rowClick       Click handler attribute value
     * @param string $rowClickTarget Click target attribute value
     * @param string $userRole       Current user's role for action gating
     * @param array  $actionRoles    Required roles per action
     * @param bool   $hasBulk        Whether bulk checkboxes are enabled (table only)
     * @param array  $fieldContracts Field-level contracts (table only)
     */
    public function __construct(
        public readonly array $row,
        public readonly array $fields,
        public readonly array $actions,
        public readonly string $use,
        public readonly array $actionUrls,
        public readonly array $actionMethods,
        public readonly array $actionConfirm,
        public readonly array $actionShowIf,
        public readonly array $actionLabels,
        public readonly array $renderers,
        public readonly string $rowClick,
        public readonly string $rowClickTarget,
        public readonly string $userRole,
        public readonly array $actionRoles,
        public readonly bool $hasBulk = false,
        public readonly array $fieldContracts = [],
    ) {}
}
