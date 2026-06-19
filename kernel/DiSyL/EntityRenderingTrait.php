<?php

declare(strict_types=1);

/**
 * DiSyL EntityRenderingTrait
 *
 * Extracted from TemplateEngine to keep the entity view rendering pipeline
 * in a focused, testable unit.  All 18 methods are private and self-contained;
 * they reference only each other and global helpers (app(), write_log(),
 * csrf_token()).
 *
 * ## Methods inventory
 *
 * Styling:
 *   entityStyle()         — CSS class resolver (tailwind / bootstrap / legacy)
 *
 * Entity List:
 *   renderEntityList()    — ikb_entity_list entry point
 *   renderEntityListRows()— dispatch rows by view mode + search/bulk chrome
 *   renderEntitySearchBar()— Alpine.js search input
 *   renderEntityBulkBar() — floating bulk action bar
 *   renderCompactRow()    — compact / default row (supports row-click)
 *   renderCardGridRow()   — card grid row (supports row-click)
 *   renderTableHeader()   — <thead> from field list (+ checkbox column for bulk)
 *   renderTableRow()      — <tr> with renderers (+ checkbox, row-click)
 *
 * Cell Renderers:
 *   renderCell()          — dispatcher (badge/money/datetime/boolean/string)
 *   renderCellBadge()     — colored pill
 *   renderCellMoney()     — currency format (₱)
 *   renderCellDateTime()  — time / date / full
 *   renderCellBoolean()   — Yes/No badge
 *
 * Actions:
 *   renderRowActions()    — view/edit/delete/approve/etc. with POST+CSRF + auth filtering
 *   evaluateRowCondition()— simple == / != on row fields (static)
 *
 * Entity Detail:
 *   renderEntityDetail()  — ikb_entity_detail entry point
 *   renderDetailFields()  — definition list
 *
 * Utilities:
 *   renderWithRowContext()— {field} variable substitution
 *   renderRowClickAttrs() — onclick + cursor for row-click navigation
 *   entityErrorState()    — error banner
 *
 * @package Ikabud\Kernel\DiSyL
 */

namespace Ikabud\Kernel\DiSyL;

trait EntityRenderingTrait
{
    // ── Styling ────────────────────────────────────────────────────

    /**
     * Resolve CSS classes for entity list styling based on the chosen framework preset.
     *
     * @param string $element Element key (e.g. 'wrapper', 'th', 'td', 'tr', 'action', 'actionWrapper')
     * @param string $context  Context key (e.g. 'table', 'compact', 'card_grid', or action name like 'view')
     * @param string $use      Framework preset: 'tailwind', 'bootstrap', 'legacy'
     */
    private function entityStyle(string $element, string $context, string $use = 'tailwind'): string
    {
        $presets = [
            // ── Tailwind ──────────────────────────────────────────────
            'tailwind' => [
                'wrapper' => [
                    'table'     => 'ikb-entity-list ikb-entity-list--table w-full overflow-x-auto',
                    'compact'   => 'ikb-entity-list ikb-entity-list--compact divide-y divide-gray-100',
                    'card_grid' => 'ikb-entity-list ikb-entity-list--grid grid gap-4 sm:grid-cols-2 lg:grid-cols-3',
                ],
                'thead'   => ['table' => 'bg-gray-50 border-b border-gray-200'],
                'th'      => ['table' => 'py-3 px-4 text-left font-semibold text-gray-600 text-xs uppercase tracking-wider whitespace-nowrap'],
                'tr'      => ['table' => 'border-b border-gray-100 hover:bg-gray-50/50 transition-colors'],
                'td'      => ['table' => 'py-3 px-4 text-gray-700 whitespace-nowrap'],
                'row'     => ['compact' => 'ikb-entity-row flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition'],
                'title'   => ['compact' => 'text-sm font-semibold text-gray-900', 'card_grid' => 'font-semibold text-gray-900'],
                'subtitle'=> ['compact' => 'text-sm text-gray-500', 'card_grid' => 'text-sm text-gray-500 mt-1'],
                'card'    => ['card_grid' => 'ikb-entity-card bg-white rounded-lg shadow border border-gray-100 overflow-hidden hover:shadow-md transition'],
                'actionWrapper' => ['actions' => 'flex items-center justify-end gap-2'],
                'action'  => [
                    'view'    => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-brand-700 bg-brand-50 hover:bg-brand-100',
                    'edit'    => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-gray-600 bg-gray-100 hover:bg-gray-200',
                    'delete'  => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-red-700 bg-red-50 hover:bg-red-100',
                    'approve' => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-green-700 bg-green-50 hover:bg-green-100',
                    'process' => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-blue-700 bg-blue-50 hover:bg-blue-100',
                    'cancel'  => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-orange-700 bg-orange-50 hover:bg-orange-100',
                ],
            ],
            // ── Bootstrap 5 ──────────────────────────────────────────
            'bootstrap' => [
                'wrapper' => [
                    'table'     => 'ikb-entity-list ikb-entity-list--table table-responsive',
                    'compact'   => 'ikb-entity-list ikb-entity-list--compact list-group',
                    'card_grid' => 'ikb-entity-list ikb-entity-list--grid row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3',
                ],
                'thead'   => ['table' => 'table-light'],
                'th'      => ['table' => 'px-3 py-2 text-muted small fw-semibold text-uppercase'],
                'tr'      => ['table' => ''],
                'td'      => ['table' => 'px-3 py-2 align-middle'],
                'row'     => ['compact' => 'ikb-entity-row list-group-item d-flex justify-content-between align-items-center px-3 py-2'],
                'title'   => ['compact' => 'small fw-semibold mb-0', 'card_grid' => 'fw-semibold'],
                'subtitle'=> ['compact' => 'small text-muted mb-0', 'card_grid' => 'small text-muted mt-1'],
                'card'    => ['card_grid' => 'ikb-entity-card card shadow-sm h-100'],
                'actionWrapper' => ['actions' => 'd-flex gap-1 justify-content-end'],
                'action'  => [
                    'view'    => 'ikb-row-action btn btn-sm btn-outline-primary',
                    'edit'    => 'ikb-row-action btn btn-sm btn-outline-secondary',
                    'delete'  => 'ikb-row-action btn btn-sm btn-outline-danger',
                    'approve' => 'ikb-row-action btn btn-sm btn-outline-success',
                    'process' => 'ikb-row-action btn btn-sm btn-outline-info',
                    'cancel'  => 'ikb-row-action btn btn-sm btn-outline-warning',
                ],
            ],
            // ── Legacy (no framework) ─────────────────────────────────
            'legacy' => [
                'wrapper' => [
                    'table'     => 'ikb-entity-list ikb-entity-list--table',
                    'compact'   => 'ikb-entity-list ikb-entity-list--compact',
                    'card_grid' => 'ikb-entity-list ikb-entity-list--grid',
                ],
                'thead'   => ['table' => ''],
                'th'      => ['table' => 'px-4 py-2 font-semibold text-gray-600'],
                'tr'      => ['table' => 'hover:bg-gray-50'],
                'td'      => ['table' => 'px-4 py-2 text-sm text-gray-700'],
                'row'     => ['compact' => 'ikb-entity-row'],
                'title'   => ['compact' => 'text-sm font-semibold text-gray-900', 'card_grid' => 'font-semibold text-gray-900'],
                'subtitle'=> ['compact' => 'text-sm text-gray-500', 'card_grid' => 'text-sm text-gray-500 mt-1'],
                'card'    => ['card_grid' => 'ikb-entity-card bg-white rounded-lg shadow border border-gray-100 overflow-hidden'],
                'actionWrapper' => ['actions' => 'flex items-center justify-end gap-1'],
                'action'  => [
                    'view'    => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100 transition',
                    'edit'    => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100 transition',
                    'delete'  => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-red-600 hover:bg-red-50 transition',
                    'approve' => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-green-600 hover:bg-green-50 transition',
                    'process' => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-blue-600 hover:bg-blue-50 transition',
                    'cancel'  => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-orange-600 hover:bg-orange-50 transition',
                ],
            ],
        ];

        // Default action style for unknown actions
        $defaultAction = match ($use) {
            'bootstrap' => 'ikb-row-action btn btn-sm btn-outline-secondary',
            'tailwind'  => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-gray-600 bg-gray-100 hover:bg-gray-200',
            default     => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100 transition',
        };

        $use = isset($presets[$use]) ? $use : 'legacy';
        return $presets[$use][$element][$context] ?? $presets[$use][$element]['table'] ?? $defaultAction;
    }

    // ── Entity List ─────────────────────────────────────────────────

    /**
     * Render an entity list from a source/view declaration.
     *
     * Attributes:
     *   source   — entity source string (e.g. "orders.recent", "products.featured")
     *   view     — view preset (compact, detailed, card_grid, table, admin_row)
     *   use      — CSS framework preset: "tailwind" (default), "bootstrap", "legacy"
     *   limit    — max rows (overrides view contract default)
     *   empty    — custom empty-state message
     *   actions  — comma-separated allowed action names
     *   header   — HTML or #blockName rendered above the list (for inline forms, filters)
     *   class    — additional CSS classes for the wrapper
     *
     * Actions support POST (with CSRF token + confirm dialog) via
     * builtinDefaults action_methods/action_confirm/action_urls.
     */
    private function renderEntityList(array $attrs, string $children, array $context): string
    {
        $source = (string)($attrs['source'] ?? '');
        $view = (string)($attrs['view'] ?? 'compact');
        $use = (string)($attrs['use'] ?? 'tailwind');
        $limit = isset($attrs['limit']) ? (int)$attrs['limit'] : null;
        $emptyMessage = (string)($attrs['empty'] ?? '');
        $actions = isset($attrs['actions']) ? array_map('trim', explode(',', (string)$attrs['actions'])) : null;
        $class = (string)($attrs['class'] ?? '');

        // v4.8: entity list enhancements
        $rowClick = (string)($attrs['row-click'] ?? '');
        $rowClickTarget = (string)($attrs['row-click-target'] ?? '');
        $search = !empty($attrs['search']) && $attrs['search'] !== 'false';
        $searchPlaceholder = (string)($attrs['search-placeholder'] ?? 'Search...');
        $bulkActions = isset($attrs['bulk-actions']) ? array_map('trim', explode(',', (string)$attrs['bulk-actions'])) : [];
        $bulkActionUrl = (string)($attrs['bulk-action-url'] ?? '');
        $userRole = (string)($attrs['auth-role'] ?? $context['current_user_role'] ?? '');

        if ($source === '') {
            return $this->entityErrorState('Missing source attribute on ikb_entity_list.', $class);
        }

        // Resolve data via the entity view resolver
        $overrides = [];
        if ($limit !== null) { $overrides['limit'] = $limit; }
        if ($actions !== null) { $overrides['actions'] = $actions; }

        $resolved = null;
        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolve($source, $view, $overrides);
            }
        } catch (\Throwable $e) {
            return $this->entityErrorState('Failed to resolve entity list: ' . $e->getMessage(), $class);
        }

        if ($resolved === null || !empty($resolved['error'])) {
            $errorMsg = $resolved['error'] ?? '';
            // For capability-not-found and similar infra errors, use the
            // caller-supplied empty message instead of leaking internals.
            if ($errorMsg !== '' && $emptyMessage !== '' && (
                str_contains($errorMsg, 'Capability not found') ||
                str_contains($errorMsg, 'Data source unavailable') ||
                str_contains($errorMsg, 'No view contract')
            )) {
                return "<div class=\"ikb-entity-list--empty text-center py-8 text-gray-500 {$class}\">" . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . "</div>";
            }
            return $this->entityErrorState(
                $errorMsg ?: 'Unable to load data.',
                $class
            );
        }

        $rows = $resolved['rows'] ?? [];
        $headerSlot = (string)($attrs['header'] ?? '');

        // Render header slot before the list (supports inline forms, filters, etc.)
        $headerHtml = '';
        if ($headerSlot !== '') {
            // header attribute can be raw HTML or a template block reference.
            // If it starts with '#', it references a named block in the parent template.
            // Otherwise it's rendered as-is.
            if (str_starts_with($headerSlot, '#')) {
                $blockName = substr($headerSlot, 1);
                $headerHtml = $context->getBlock($blockName) ?? '';
            } else {
                $headerHtml = $headerSlot;
            }
        }
        if ($headerHtml === '' && trim($children) !== '' && !str_contains(trim($children), '{')) {
            // If children is plain text (no Disyl variables), treat as header
            $headerHtml = $children;
            $children = '';
        }

        if (empty($rows)) {
            // Log when entity list resolves successfully but returns zero rows
            // (helps distinguish "no data" from "rendering failure")
            if (\function_exists('write_log') && ($resolved['total'] ?? 0) === 0) {
                \write_log("EntityViewResolver: zero rows for '{$source}.{$view}'", 'info', [
                    'source' => $source,
                    'view' => $view,
                ]);
            }
            $msg = $emptyMessage ?: $resolved['view']['empty_state'] ?? 'No records found.';
            return "<div class=\"ikb-entity-list--empty text-center py-8 text-gray-500 {$class}\">" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        // Render using the view's field definitions
        $contract = $resolved['view'] ?? [];
        $fields = is_array($contract['fields'] ?? null) ? $contract['fields'] : ['*'];
        $viewMode = $contract['view'] ?? $view;
        $viewActions = $contract['actions'] ?? [];
        $actionUrls = $contract['action_urls'] ?? [];
        $actionMethods = $contract['action_methods'] ?? [];
        $actionConfirm = $contract['action_confirm'] ?? [];
        $actionShowIf = $contract['action_show_if'] ?? [];
        $actionLabels = $contract['action_labels'] ?? [];
        $renderers = $contract['renderers'] ?? [];

        // Expand '*' fields to actual keys from the first row
        if ($fields === ['*'] || $fields === '*') {
            $firstRow = $rows[0] ?? [];
            $fields = array_values(array_filter(array_keys($firstRow), fn($k) => !str_starts_with($k, '_')));
        }

        // v4.8: resolve action roles from contract or explicit attribute
        $actionRoles = $contract['action_roles'] ?? [];
        $explicitRoles = isset($attrs['action-roles']) ? json_decode((string)$attrs['action-roles'], true) : null;
        if (is_array($explicitRoles)) { $actionRoles = $explicitRoles; }

        $listHtml = $this->renderEntityListRows($rows, $fields, $viewMode, $viewActions, $class, $children, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $renderers, $rowClick, $rowClickTarget, $userRole, $actionRoles, $search, $searchPlaceholder, $bulkActions, $bulkActionUrl);
        return $headerHtml . $listHtml;
    }

    /**
     * Render entity list rows based on view mode.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $fields
     * @param array<int, string> $actions
     */
    private function renderEntityListRows(array $rows, array $fields, string $viewMode, array $actions, string $class, string $children, string $use = 'tailwind', array $actionUrls = [], array $actionMethods = [], array $actionConfirm = [], array $actionShowIf = [], array $actionLabels = [], array $renderers = [], string $rowClick = '', string $rowClickTarget = '', string $userRole = '', array $actionRoles = [], bool $search = false, string $searchPlaceholder = 'Search...', array $bulkActions = [], string $bulkActionUrl = ''): string
    {
        $hasCustomSlot = trim($children) !== '';
        $hasBulk = !empty($bulkActions) && $bulkActionUrl !== '';
        $listId = $hasBulk ? 'ikb-entity-list-' . bin2hex(random_bytes(4)) : '';

        $out = '';
        foreach ($rows as $row) {
            if ($hasCustomSlot) {
                $out .= $this->renderWithRowContext($children, $row);
                continue;
            }

            $out .= match ($viewMode) {
                'card_grid' => $this->renderCardGridRow($row, $fields, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $rowClick, $rowClickTarget, $userRole, $actionRoles),
                'table' => $this->renderTableRow($row, $fields, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $renderers, $rowClick, $rowClickTarget, $userRole, $actionRoles, $hasBulk),
                'compact', 'default' => $this->renderCompactRow($row, $fields, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $rowClick, $rowClickTarget, $userRole, $actionRoles),
                default => $this->renderCompactRow($row, $fields, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $rowClick, $rowClickTarget, $userRole, $actionRoles),
            };
        }

        // Search bar (Alpine.js client-side filter)
        $searchHtml = '';
        if ($search && !$hasCustomSlot) {
            $searchHtml = $this->renderEntitySearchBar($listId, $searchPlaceholder);
        }

        // Bulk action bar
        $bulkHtml = '';
        if ($hasBulk) {
            $bulkHtml = $this->renderEntityBulkBar($bulkActions, $bulkActionUrl, $listId);
        }

        $wrapperClass = $this->entityStyle('wrapper', $viewMode, $use);

        if ($viewMode === 'table' && !$hasCustomSlot) {
            $tableHeader = $this->renderTableHeader($fields, $actions, $use, $hasBulk);
            $bulkCol = $hasBulk ? '<colgroup><col style="width:40px"></colgroup>' : '';
            $alpine = $search ? ' x-data="{ q:\'\' }"' : '';
            $out = "<div class=\"{$wrapperClass} {$class}\"{$alpine}>{$searchHtml}{$bulkHtml}<table class=\"w-full text-sm\">{$bulkCol}{$tableHeader}<tbody>{$out}</tbody></table></div>";
        } else {
            $out = "<div class=\"{$wrapperClass} {$class}\">{$searchHtml}{$out}</div>";
        }

        return $out;
    }

    /**
     * Compact row: one field per line, minimal chrome.
     */
    private function renderCompactRow(array $row, array $fields, array $actions, string $use = 'tailwind', array $actionUrls = [], array $actionMethods = [], array $actionConfirm = [], array $actionShowIf = [], array $actionLabels = [], string $rowClick = '', string $rowClickTarget = '', string $userRole = '', array $actionRoles = []): string
    {
        $rowClass = $this->entityStyle('row', 'compact', $use);
        $titleClass = $this->entityStyle('title', 'compact', $use);
        $subClass = $this->entityStyle('subtitle', 'compact', $use);
        $titleField = $fields[0] ?? 'id';
        $subField = $fields[1] ?? null;
        $title = htmlspecialchars((string)($row[$titleField] ?? $titleField), ENT_QUOTES, 'UTF-8');
        $sub = $subField ? htmlspecialchars((string)($row[$subField] ?? ''), ENT_QUOTES, 'UTF-8') : '';

        $actionHtml = $this->renderRowActions($row, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $userRole, $actionRoles);
        $subHtml = $sub !== '' ? "<p class=\"{$subClass}\">{$sub}</p>" : '';
        $clickAttrs = $this->renderRowClickAttrs($row, $rowClick, $rowClickTarget);

        return <<<HTML
        <div class="{$rowClass}{$clickAttrs['class']}"{$clickAttrs['attrs']}>
            <div class="min-w-0 flex-1">
                <p class="{$titleClass}">{$title}</p>
                {$subHtml}
            </div>
            {$actionHtml}
        </div>
        HTML;
    }

    /**
     * Card grid row: image + title + subtitle in a card.
     */
    private function renderCardGridRow(array $row, array $fields, array $actions, string $use = 'tailwind', array $actionUrls = [], array $actionMethods = [], array $actionConfirm = [], array $actionShowIf = [], array $actionLabels = [], string $rowClick = '', string $rowClickTarget = '', string $userRole = '', array $actionRoles = []): string
    {
        $cardClass = $this->entityStyle('card', 'card_grid', $use);
        $titleClass = $this->entityStyle('title', 'card_grid', $use);
        $subClass = $this->entityStyle('subtitle', 'card_grid', $use);

        $titleField = $fields[0] ?? 'name';
        $subField = $fields[1] ?? null;
        $imageField = in_array('image', $fields, true) ? 'image' : (in_array('thumbnail', $fields, true) ? 'thumbnail' : null);
        $title = htmlspecialchars((string)($row[$titleField] ?? ''), ENT_QUOTES, 'UTF-8');
        $sub = $subField ? htmlspecialchars((string)($row[$subField] ?? ''), ENT_QUOTES, 'UTF-8') : '';

        $imageHtml = '';
        if ($imageField && !empty($row[$imageField])) {
            $imgSrc = htmlspecialchars((string)$row[$imageField], ENT_QUOTES, 'UTF-8');
            $imageHtml = "<img src=\"{$imgSrc}\" alt=\"{$title}\" class=\"w-full h-40 object-cover rounded-t-lg\" loading=\"lazy\">";
        }

        $actionHtml = $this->renderRowActions($row, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $userRole, $actionRoles);
        $subHtml = $sub !== '' ? "<p class=\"{$subClass}\">{$sub}</p>" : '';
        $clickAttrs = $this->renderRowClickAttrs($row, $rowClick, $rowClickTarget);

        return <<<HTML
        <div class="{$cardClass}{$clickAttrs['class']}"{$clickAttrs['attrs']}>
            {$imageHtml}
            <div class="p-4">
                <h3 class="{$titleClass}">{$title}</h3>
                {$subHtml}
                <div class="mt-3 flex gap-2">{$actionHtml}</div>
            </div>
        </div>
        HTML;
    }

    /**
     * Render table header from field names.
     */
    private function renderTableHeader(array $fields, array $actions, string $use = 'tailwind', bool $hasBulk = false): string
    {
        $thClass = $this->entityStyle('th', 'table', $use);
        $theadClass = $this->entityStyle('thead', 'table', $use);
        $cells = '';
        if ($hasBulk) {
            $cells .= "<th class=\"{$thClass}\" style=\"width:40px\"><input type=\"checkbox\" class=\"ikb-bulk-select-all\" onclick=\"document.querySelectorAll('.ikb-bulk-row').forEach(cb => cb.checked = this.checked); document.getElementById('ikb-bulk-bar').classList.toggle('hidden', !this.checked)\"></th>";
        }
        foreach ($fields as $field) {
            if ($field === '*') { continue; }
            $label = htmlspecialchars(ucfirst(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8');
            $cells .= "<th class=\"{$thClass}\">{$label}</th>";
        }
        if (!empty($actions)) {
            $cells .= "<th class=\"{$thClass} text-right\">Actions</th>";
        }
        return "<thead><tr class=\"{$theadClass}\">{$cells}</tr></thead>";
    }

    /**
     * Table row: one row in a striped table.
     */
    private function renderTableRow(array $row, array $fields, array $actions, string $use = 'tailwind', array $actionUrls = [], array $actionMethods = [], array $actionConfirm = [], array $actionShowIf = [], array $actionLabels = [], array $renderers = [], string $rowClick = '', string $rowClickTarget = '', string $userRole = '', array $actionRoles = [], bool $hasBulk = false): string
    {
        $tdClass = $this->entityStyle('td', 'table', $use);
        $trClass = $this->entityStyle('tr', 'table', $use);
        $clickAttrs = $this->renderRowClickAttrs($row, $rowClick, $rowClickTarget);
        $cells = '';
        if ($hasBulk) {
            $rowId = htmlspecialchars((string)($row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $cells .= "<td class=\"{$tdClass}\"><input type=\"checkbox\" name=\"ids[]\" value=\"{$rowId}\" class=\"ikb-bulk-row\" onclick=\"var any=document.querySelectorAll('.ikb-bulk-row:checked').length>0;document.getElementById('ikb-bulk-bar').classList.toggle('hidden',!any)\"></td>";
        }
        foreach ($fields as $field) {
            if ($field === '*') { continue; }
            $rawValue = $row[$field] ?? '';
            $renderer = $renderers[$field] ?? null;
            $cells .= "<td class=\"{$tdClass}\">" . $this->renderCell($rawValue, $renderer) . "</td>";
        }

        $actionHtml = $this->renderRowActions($row, $actions, $use, $actionUrls, $actionMethods, $actionConfirm, $actionShowIf, $actionLabels, $userRole, $actionRoles);
        if ($actionHtml !== '') {
            $cells .= "<td class=\"{$tdClass} text-right whitespace-nowrap\">{$actionHtml}</td>";
        }

        return "<tr class=\"{$trClass}{$clickAttrs['class']}\"{$clickAttrs['attrs']}>{$cells}</tr>";
    }

    // ── Cell Renderers ──────────────────────────────────────────────

    /**
     * Render a single cell value with an optional renderer.
     *
     * Supported renderers:
     *   badge       — colored pill (green/red/gray based on truthy/falsy)
     *   badge:map   — badge with value→label mapping (JSON: {"1":"Active","0":"Inactive"})
     *   money       — currency format (₱1,234.56)
     *   datetime    — "2026-06-19 08:30:00" → "08:30" (time only) or "Jun 19" (date)
     *   boolean     — "1" → "Yes", "0" → "No"
     *   string      — plain text (default)
     */
    private function renderCell(mixed $value, ?string $renderer): string
    {
        $str = (string)$value;
        if ($renderer === null || $renderer === '' || $renderer === 'string') {
            return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
        }

        // Parse renderer: "badge" or "badge:map" or "money:2"
        $parts = explode(':', $renderer, 2);
        $type = $parts[0];
        $arg = $parts[1] ?? '';

        return match ($type) {
            'badge' => $this->renderCellBadge($value, $arg),
            'money' => $this->renderCellMoney($value, $arg),
            'datetime' => $this->renderCellDateTime($value, $arg),
            'boolean' => $this->renderCellBoolean($value),
            default => htmlspecialchars($str, ENT_QUOTES, 'UTF-8'),
        };
    }

    /**
     * Render a badge/pill. If arg is a JSON map, use it to lookup label + color.
     * Otherwise treat value as truthy/falsy for Active/Inactive green/gray.
     */
    private function renderCellBadge(mixed $value, string $arg): string
    {
        $str = (string)$value;
        $safe = htmlspecialchars($str, ENT_QUOTES, 'UTF-8');

        // Try JSON map: {"computed":"Computed|amber","approved":"Approved|green","paid":"Paid|blue"}
        if ($arg !== '') {
            $map = json_decode($arg, true);
            if (is_array($map) && isset($map[$str])) {
                $entry = $map[$str];
                if (is_string($entry) && str_contains($entry, '|')) {
                    [$label, $color] = explode('|', $entry, 2);
                } else {
                    $label = is_string($entry) ? $entry : $str;
                    $color = 'gray';
                }
                $colors = ['green' => 'bg-green-100 text-green-700', 'red' => 'bg-red-100 text-red-700',
                    'amber' => 'bg-amber-100 text-amber-700', 'blue' => 'bg-blue-100 text-blue-700',
                    'purple' => 'bg-purple-100 text-purple-700', 'gray' => 'bg-gray-100 text-gray-500'];
                $colorClass = $colors[$color] ?? $colors['gray'];
                $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                return "<span class=\"inline-flex px-2 py-0.5 rounded-full text-xs font-medium {$colorClass}\">{$safeLabel}</span>";
            }
        }

        // Default: truthy → Active/green, falsy → Inactive/gray
        $isActive = $value && $str !== '0' && $str !== '';
        if ($isActive) {
            return '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>';
        }
        return '<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Inactive</span>';
    }

    /**
     * Render a monetary value. Arg is decimal places (default 2).
     */
    private function renderCellMoney(mixed $value, string $arg): string
    {
        $decimals = is_numeric($arg) ? (int)$arg : 2;
        $num = (float)$value;
        $formatted = '₱' . number_format($num, $decimals);
        $class = $num < 0 ? 'text-red-600' : 'text-gray-900';
        $safe = htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8');
        return "<span class=\"{$class}\">{$safe}</span>";
    }

    /**
     * Render a datetime value. Arg: 'time' (H:i), 'date' (M d), 'full' (M d H:i), empty=full.
     */
    private function renderCellDateTime(mixed $value, string $arg): string
    {
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        if ($ts === false || $ts <= 0) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
        return match ($arg) {
            'time' => '<span class="font-mono text-xs">' . date('H:i', $ts) . '</span>',
            'date' => '<span class="text-xs">' . date('M d', $ts) . '</span>',
            default => '<span class="text-xs">' . date('M d H:i', $ts) . '</span>',
        };
    }

    /**
     * Render a boolean as Yes/No badge.
     */
    private function renderCellBoolean(mixed $value): string
    {
        $is = $value && (string)$value !== '0';
        $label = $is ? 'Yes' : 'No';
        $class = $is ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500';
        return "<span class=\"inline-flex px-2 py-0.5 rounded-full text-xs font-medium {$class}\">{$label}</span>";
    }

    // ── Actions ─────────────────────────────────────────────────────

    /**
     * Render action links for a row (view, edit, delete, etc.).
     */
    private function renderRowActions(array $row, array $actions, string $use = 'tailwind', array $actionUrls = [], array $actionMethods = [], array $actionConfirm = [], array $actionShowIf = [], array $actionLabels = [], string $userRole = '', array $actionRoles = []): string
    {
        if (empty($actions)) {
            return '';
        }

        $id = $row['id'] ?? '';
        $actionWrapperClass = $this->entityStyle('actionWrapper', 'actions', $use);
        $html = '';

        foreach ($actions as $action) {
            $action = trim($action);
            if ($action === '') { continue; }

            // Auth-aware filtering: skip actions the user's role doesn't permit
            if ($userRole !== '' && isset($actionRoles[$action])) {
                $allowedRoles = is_array($actionRoles[$action]) ? $actionRoles[$action] : [$actionRoles[$action]];
                if (!in_array($userRole, $allowedRoles, true)) {
                    continue;
                }
            }

            // Check action_show_if condition — skip if row doesn't match
            if (isset($actionShowIf[$action]) && $actionShowIf[$action] !== '') {
                $condition = $actionShowIf[$action];
                if (!self::evaluateRowCondition($row, $condition)) {
                    continue;
                }
            }

            $label = $actionLabels[$action] ?? ucfirst($action);
            $actionClass = $this->entityStyle('action', $action, $use);
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $safeId = htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8');

            // Resolve href from action_urls or fallback
            $href = isset($actionUrls[$action])
                ? str_replace('{id}', $safeId, $actionUrls[$action])
                : "?id={$safeId}&amp;action={$action}";

            $method = $actionMethods[$action] ?? 'get';

            if ($method === 'post') {
                // Render as inline POST form with CSRF token + confirmation
                $confirmMsg = $actionConfirm[$action] ?? '';
                $onSubmit = $confirmMsg !== ''
                    ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirmMsg), ENT_QUOTES, 'UTF-8') . ')"'
                    : '';
                // Include CSRF token if the app provides one
                $csrfInput = '';
                if (\function_exists('csrf_token')) {
                    $csrfValue = htmlspecialchars((string)\csrf_token(), ENT_QUOTES, 'UTF-8');
                    $csrfInput = "<input type=\"hidden\" name=\"_token\" value=\"{$csrfValue}\">";
                }
                $html .= "<form method=\"post\" action=\"{$href}\" class=\"inline\"{$onSubmit}>"
                      . "<input type=\"hidden\" name=\"id\" value=\"{$safeId}\">"
                      . $csrfInput
                      . "<button type=\"submit\" class=\"{$actionClass}\">{$safeLabel}</button>"
                      . "</form>";
            } else {
                // Render as link for GET actions
                $onClick = '';
                if (isset($actionConfirm[$action]) && $actionConfirm[$action] !== '') {
                    $onClick = ' onclick="return confirm(' . htmlspecialchars(json_encode($actionConfirm[$action]), ENT_QUOTES, 'UTF-8') . ')"';
                }
                $html .= "<a href=\"{$href}\" class=\"{$actionClass}\"{$onClick}>{$safeLabel}</a>";
            }
        }

        return "<div class=\"{$actionWrapperClass}\">{$html}</div>";
    }

    /**
     * Evaluate a simple row condition like status == "pending" or balance > 0.
     */
    private static function evaluateRowCondition(array $row, string $condition): bool
    {
        // Support: field == "value" or field != "value"
        if (preg_match('/^(\w+)\s*(==|!=)\s*"([^"]*)"$/', trim($condition), $m)) {
            $field = $m[1];
            $op = $m[2];
            $value = $m[3];
            $rowValue = (string)($row[$field] ?? '');
            return $op === '==' ? $rowValue === $value : $rowValue !== $value;
        }
        return true;
    }

    // ── Entity Detail ───────────────────────────────────────────────

    /**
     * Render an entity detail view for a single entity.
     *
     * Attributes:
     *   source   — entity type (e.g. "order", "product", "case")
     *   id       — entity ID
     *   view     — view preset (detailed, summary, admin)
     *   fields   — comma-separated fields to show (default: all from contract)
     *   class    — additional CSS classes
     */
    private function renderEntityDetail(array $attrs, string $children, array $context): string
    {
        $source = (string)($attrs['source'] ?? '');
        $entityId = (string)($attrs['id'] ?? $attrs['entity_id'] ?? '');
        $view = (string)($attrs['view'] ?? 'detailed');
        $class = (string)($attrs['class'] ?? '');
        $requestedFields = isset($attrs['fields']) ? array_map('trim', explode(',', (string)$attrs['fields'])) : null;

        if ($source === '') {
            return $this->entityErrorState('Missing source attribute on ikb_entity_detail.', $class);
        }
        if ($entityId === '') {
            return $this->entityErrorState('Missing id attribute on ikb_entity_detail.', $class);
        }

        // Resolve via EntityViewResolver (shared path with ikb_entity_list)
        $resolved = null;
        try {
            if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolveDetail($source, $entityId, $view);
            }
        } catch (\Throwable $e) {
            return $this->entityErrorState('Failed to resolve entity detail: ' . $e->getMessage(), $class);
        }

        if ($resolved === null || !empty($resolved['error'])) {
            $errorMsg = $resolved['error'] ?? 'Entity not found.';
            return $this->entityErrorState($errorMsg, $class);
        }

        $entity = $resolved['entity'] ?? null;
        if ($entity === null || empty($entity)) {
            return $this->entityErrorState('Entity not found.', $class);
        }

        // Get view contract for field visibility
        $viewContract = $resolved['view'] ?? [];
        $fields = $requestedFields ?? $viewContract['fields'] ?? array_keys($entity);
        if ($fields === ['*'] || $fields === '*') {
            $fields = array_keys($entity);
            $fields = array_values(array_filter($fields, fn($k) => !str_starts_with($k, '_')));
        }

        $hasCustomSlot = trim($children) !== '';

        if ($hasCustomSlot) {
            return "<div class=\"ikb-entity-detail {$class}\">"
                . $this->renderWithRowContext($children, $entity)
                . '</div>';
        }

        return $this->renderDetailFields($entity, $fields, $class);
    }

    /**
     * Render a detail view as a definition list.
     */
    private function renderDetailFields(array $entity, array $fields, string $class): string
    {
        $rows = '';
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '' || $field === 'id' && count($fields) > 1) {
                // Skip id in multi-field detail views unless it's the only field
                if (count($fields) > 1) { continue; }
            }
            $label = ucwords(str_replace('_', ' ', $field));
            $value = $entity[$field] ?? '';
            if (is_array($value)) { $value = json_encode($value, JSON_UNESCAPED_SLASHES); }
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $rows .= <<<HTML
            <div class="ikb-entity-detail__field py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm font-medium text-gray-500">{$safeLabel}</dt>
                <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">{$safeValue}</dd>
            </div>
            HTML;
        }

        return <<<HTML
        <div class="ikb-entity-detail divide-y divide-gray-100 {$class}">
            {$rows}
        </div>
        HTML;
    }

    // ── Utilities ───────────────────────────────────────────────────

    /**
     * Render children with a row context (for custom entity templates).
     */
    private function renderWithRowContext(string $template, array $row): string
    {
        // Simple variable binding: replace {field} patterns with row values
        $result = $template;
        foreach ($row as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                $result = str_replace('{' . $key . '}', $safeValue, $result);
            }
        }
        return $result;
    }

    // ── v4.8: Row click, search, bulk actions ─────────────────────────

    /**
     * Build onclick + cursor CSS for a row when row-click is configured.
     *
     * @param array $row Entity row data (for {field} substitution)
     * @param string $rowClick URL pattern e.g. "/admin/employees/{id}"
     * @param string $target Optional target e.g. "_blank"
     * @return array{attrs: string, class: string}
     */
    private function renderRowClickAttrs(array $row, string $rowClick, string $target = ''): array
    {
        if ($rowClick === '') {
            return ['attrs' => '', 'class' => ''];
        }
        $url = $rowClick;
        foreach ($row as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $url = str_replace('{' . $key . '}', urlencode((string)$value), $url);
            }
        }
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        // Same-tab navigation by default; new tab only with explicit target
        if ($target !== '') {
            $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
            $attrs = ' onclick="window.open(\'' . $safeUrl . '\',\'' . $safeTarget . '\')" style="cursor:pointer"';
        } else {
            $attrs = ' onclick="window.location.href=\'' . $safeUrl . '\'" style="cursor:pointer"';
        }

        return [
            'attrs' => $attrs,
            'class' => ' cursor-pointer hover:bg-blue-50/30',
        ];
    }

    /**
     * Render a client-side search bar using Alpine.js.
     * Filters rows by text content within the same container.
     */
    private function renderEntitySearchBar(string $listId, string $placeholder): string
    {
        $safePlaceholder = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
        $listSelector = $listId !== '' ? '#' . $listId . ' ' : '';
        return <<<HTML
        <div class="ikb-entity-search mb-3">
            <input type="text" x-model="q" placeholder="{$safePlaceholder}"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                @input="document.querySelectorAll('{$listSelector}tbody tr, {$listSelector}.ikb-entity-row, {$listSelector}.ikb-entity-card').forEach(el => {
                    const visible = !q || el.textContent.toLowerCase().includes(q.toLowerCase());
                    el.style.display = visible ? '' : 'none';
                })">
        </div>
        HTML;
    }

    /**
     * Render a floating bulk action bar (hidden until checkboxes are checked).
     */
    private function renderEntityBulkBar(array $bulkActions, string $bulkActionUrl, string $listId): string
    {
        $csrfInput = '';
        if (\function_exists('csrf_token')) {
            $csrfValue = htmlspecialchars((string)\csrf_token(), ENT_QUOTES, 'UTF-8');
            $csrfInput = "<input type=\"hidden\" name=\"_token\" value=\"{$csrfValue}\">";
        }
        $safeUrl = htmlspecialchars($bulkActionUrl, ENT_QUOTES, 'UTF-8');
        $buttons = '';
        foreach ($bulkActions as $ba) {
            $ba = trim($ba);
            if ($ba === '') { continue; }
            $label = htmlspecialchars(ucfirst($ba), ENT_QUOTES, 'UTF-8');
            $actionClass = $this->entityStyle('action', $ba, 'tailwind');
            $buttons .= "<button type=\"submit\" name=\"bulk_action\" value=\"{$ba}\" class=\"{$actionClass}\">{$label}</button>";
        }
        $barId = $listId !== '' ? 'ikb-bulk-bar-' . $listId : 'ikb-bulk-bar';
        return <<<HTML
        <div id="{$barId}" class="ikb-bulk-bar hidden sticky top-0 z-10 mb-3 p-3 bg-brand-50 border border-brand-200 rounded-lg flex items-center gap-3 shadow-sm">
            <span class="text-sm font-medium text-brand-800 ikb-bulk-count">0 selected</span>
            <div class="flex-1"></div>
            <form method="post" action="{$safeUrl}" class="flex items-center gap-2" onsubmit="var ids=[];document.querySelectorAll('.ikb-bulk-row:checked').forEach(cb=>ids.push(cb.value));var inp=document.createElement('input');inp.type='hidden';inp.name='ids';inp.value=ids.join(',');this.appendChild(inp)">
                {$csrfInput}
                {$buttons}
            </form>
        </div>
        HTML;
    }

    /**
     * Render an error state for entity components.
     */
    private function entityErrorState(string $message, string $class = ''): string
    {
        $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return <<<HTML
        <div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg {$class}">
            <div class="text-center">
                <svg class="mx-auto h-8 w-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>
                <p class="mt-2 text-sm text-red-600">{$safeMsg}</p>
            </div>
        </div>
        HTML;
    }
}
