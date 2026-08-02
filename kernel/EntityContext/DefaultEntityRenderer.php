<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

use Ikabud\Kernel\EntityContext\Renderer\BadgeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\BooleanCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\DateTimeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\ImageCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\LocationCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\MoneyCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\TextCellRenderer;
use Ikabud\Kernel\EntityContext\RowRenderContext;

/**
 * Default entity renderer — the primary implementation of EntityRendererInterface.
 *
 * Replaces the private methods in EntityRenderingTrait with a composable,
 * testable service. Uses CellRendererRegistry for all cell rendering.
 *
 * Supports:
 *   - Sortable column headers with aria-sort (P4)
 *   - Pagination chrome with namespaced query params (P5)
 *   - Compile-once condition evaluator (P3)
 *   - Output target awareness (Gap 8)
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class DefaultEntityRenderer implements EntityRendererInterface
{
    private CellRendererRegistryInterface $cellRenderers;
    private EntityConditionEvaluator $conditionEvaluator;

    /** @var array<string, array<string, string>> CSS preset map */
    private array $stylePresets;

    /** @var array<string, array{ast: array, source: string}> Compiled action_show_if cache */
    private array $compiledConditions = [];

    public function __construct(?CellRendererRegistryInterface $registry = null, ?EntityConditionEvaluator $evaluator = null)
    {
        $this->cellRenderers = $registry ?? new CellRendererRegistry();
        $this->conditionEvaluator = $evaluator ?? new EntityConditionEvaluator();
        $this->registerBuiltinRenderers();
        $this->stylePresets = $this->buildStylePresets();
    }

    public function cellRenderers(): CellRendererRegistryInterface
    {
        return $this->cellRenderers;
    }

    // ── EntityRendererInterface ────────────────────────────────────

    public function renderList(array $rows, array $view, array $attrs, array $context = []): string
    {
        return $this->doRenderList($rows, $view, $attrs, $context);
    }

    /**
     * Render an entity list from a typed EntityListResult.
     *
     * Supports both total-based and cursor-based pagination.
     * Modules should prefer this when the resolver returns EntityListResult.
     */
    public function renderListFromResult(EntityListResult $result, array $view, array $attrs, array $context = []): string
    {
        $context['_total'] = $result->total ?? count($result->rows);
        if ($result->hasError()) {
            return $this->entityErrorState($result->error ?? 'Unknown error', (string)($attrs['class'] ?? ''));
        }
        return $this->doRenderList($result->rows, $view, $attrs, $context);
    }

    /**
     * Internal rendering logic shared by renderList and renderListFromResult.
     */
    private array $renderContext = [];

    private function doRenderList(array $rows, array $view, array $attrs, array $context = []): string
    {
        $this->renderContext = array_merge($attrs, $context, ['_view' => $view]);
        $this->beforeRenderList($attrs, $context);

        $use = (string)($attrs['use'] ?? 'tailwind');
        $class = (string)($attrs['class'] ?? '');
        $emptyMessage = (string)($attrs['empty'] ?? $view['empty_state'] ?? 'No records found.');
        $source = (string)($attrs['source'] ?? '');
        $listId = (string)($attrs['id'] ?? '');
        $queryState = $context['_queryState'] ?? ($listId !== '' ? $this->resolveQueryState($listId, $view) : null);

        $paginated = !empty($attrs['paginated']) && $attrs['paginated'] !== 'false';
        $sortable = !empty($attrs['sortable']) && $attrs['sortable'] !== 'false';
        $total = (int)($attrs['_total'] ?? $context['_total'] ?? count($rows));

        if (empty($rows)) {
            $emptyClass = $use === 'workbench' ? 'wb-empty-state wb-panel' : 'text-center py-8 text-gray-500';
            $result = '<div class="ikb-entity-list--empty ' . $emptyClass . ' ' . $this->entitySourceClass($source) . ' ' . $class . '">'
                . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</div>';
            return $this->afterRenderList($result, $attrs);
        }

        $fields = $view['fields'] ?? ['*'];
        $viewMode = $view['view'] ?? ($attrs['view'] ?? 'compact');
        $actions = $view['actions'] ?? [];
        $actionUrls = $view['action_urls'] ?? [];
        $actionMethods = $view['action_methods'] ?? [];
        $actionConfirm = $view['action_confirm'] ?? [];
        $actionShowIf = $view['action_show_if'] ?? [];
        $actionLabels = $view['action_labels'] ?? [];
        $renderers = $view['renderers'] ?? [];

        // Sortable field declarations: field_name => sort_key (DB column)
        $sortableFields = $view['sortable_fields'] ?? [];

        // Field-level contracts (editable, update_capability, allowed_values, etc.)
        $fieldContracts = $view['field_contracts'] ?? [];

        // Visible fields whitelist — declares which fields are safe for public display
        $visibleFields = $view['visible_fields'] ?? [];

        // Expand '*' to actual keys from first row, filtered by visible_fields whitelist
        if ($fields === ['*'] || $fields === '*') {
            $allKeys = array_keys($rows[0]);
            // If visible_fields is explicitly declared, intersect with it
            if (!empty($visibleFields)) {
                $fields = array_values(array_intersect($allKeys, $visibleFields));
            } else {
                // Fallback: only exclude underscore-prefixed internal keys
                $fields = array_values(array_filter($allKeys, fn(string $k): bool => !str_starts_with($k, '_')));
            }
        }

        // Validate declared fields exist in data
        $firstRowKeys = !empty($rows) ? array_keys($rows[0]) : [];
        $validFields = [];
        foreach ($fields as $field) {
            if ($field === '*') continue;
            if (in_array($field, $firstRowKeys, true)) {
                $validFields[] = $field;
            } elseif (function_exists('write_log')) {
                \write_log(
                    "DefaultEntityRenderer: field '{$field}' not found in data for '{$source}'. Available: " . implode(', ', $firstRowKeys),
                    'warning',
                    ['source' => $source, 'field' => $field, 'available' => $firstRowKeys]
                );
            }
        }
        if (!empty($validFields)) {
            $fields = $validFields;
        }

        $userRole = (string)($attrs['auth-role'] ?? $context['current_user_role'] ?? '');
        $actionRoles = $view['action_roles'] ?? [];
        $explicitRoles = isset($attrs['action-roles']) ? json_decode((string)$attrs['action-roles'], true) : null;
        if (is_array($explicitRoles)) {
            $actionRoles = $explicitRoles;
        }

        // Semantic role→field mapping from view contract (e.g. role="title", role="subtitle")
        $roleFields = $view['role_fields'] ?? [];

        $rowClick = (string)($attrs['row-click'] ?? '');
        $rowClickTarget = (string)($attrs['row-click-target'] ?? '');
        $search = !empty($attrs['search']) && $attrs['search'] !== 'false';
        $searchPlaceholder = (string)($attrs['search-placeholder'] ?? 'Search...');
        $bulkActions = isset($attrs['bulk-actions']) ? array_map('trim', explode(',', (string)$attrs['bulk-actions'])) : [];
        $bulkActionUrl = (string)($attrs['bulk-action-url'] ?? '');
        $hasBulk = !empty($bulkActions) && $bulkActionUrl !== '';
        $hasCustomSlot = !empty($attrs['_children']);

        $listId = $hasBulk ? 'ikb-entity-list-' . bin2hex(random_bytes(4)) : (string)($attrs['id'] ?? $source);
        // Use explicit id for query param namespacing if available
        $queryListId = (string)($attrs['id'] ?? $listId);

        $out = '';
        foreach ($rows as $row) {
            if ($hasCustomSlot) {
                $out .= $this->renderWithRowContext((string)$attrs['_children'], $row);
                continue;
            }

            $out .= match ($viewMode) {
                'card_grid' => $this->renderCardGridRow(new RowRenderContext(
                    row: $row, fields: $fields, actions: $actions, use: $use,
                    actionUrls: $actionUrls, actionMethods: $actionMethods, actionConfirm: $actionConfirm,
                    actionShowIf: $actionShowIf, actionLabels: $actionLabels, renderers: $renderers,
                    rowClick: $rowClick, rowClickTarget: $rowClickTarget, userRole: $userRole, actionRoles: $actionRoles,
                    roleFields: $roleFields,
                )),
                'table' => $this->renderTableRow(new RowRenderContext(
                    row: $row, fields: $fields, actions: $actions, use: $use,
                    actionUrls: $actionUrls, actionMethods: $actionMethods, actionConfirm: $actionConfirm,
                    actionShowIf: $actionShowIf, actionLabels: $actionLabels, renderers: $renderers,
                    rowClick: $rowClick, rowClickTarget: $rowClickTarget, userRole: $userRole, actionRoles: $actionRoles,
                    hasBulk: $hasBulk, fieldContracts: $fieldContracts, roleFields: $roleFields,
                )),
                default => $this->renderCompactRow(new RowRenderContext(
                    row: $row, fields: $fields, actions: $actions, use: $use,
                    actionUrls: $actionUrls, actionMethods: $actionMethods, actionConfirm: $actionConfirm,
                    actionShowIf: $actionShowIf, actionLabels: $actionLabels, renderers: $renderers,
                    rowClick: $rowClick, rowClickTarget: $rowClickTarget, userRole: $userRole, actionRoles: $actionRoles,
                    roleFields: $roleFields,
                )),
            };
        }

        $searchHtml = '';
        if ($search && !$hasCustomSlot) {
            $searchHtml = $this->renderEntitySearchBar($listId, $searchPlaceholder, $use);
        }

        $bulkHtml = '';
        if ($hasBulk) {
            $bulkHtml = $this->renderEntityBulkBar($bulkActions, $bulkActionUrl, $listId, $use);
        }

        $wrapperClass = $this->style('wrapper', $viewMode, $use);
        $entityClass = $this->entitySourceClass($source);
        $entityDataAttr = 'data-ikb-entity="' . htmlspecialchars($this->entityTypeFromSource($source), ENT_QUOTES, 'UTF-8') . '"';
        $sourceDataAttr = 'data-ikb-source="' . htmlspecialchars(str_replace('.', '-', $source), ENT_QUOTES, 'UTF-8') . '"';
        $viewDataAttr = 'data-ikb-view="' . htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') . '"';
        $listDataAttr = $listId !== '' ? ' data-ikb-list="' . htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') . '"' : '';
        $wbComponentAttr = 'data-wb-component="' . ($use === 'workbench' && $viewMode === 'table' ? 'responsive-table' : 'entity-list') . '"';
        $wbEntityAttr = 'data-wb-entity="' . htmlspecialchars($this->entityTypeFromSource($source), ENT_QUOTES, 'UTF-8') . '"';

        if ($viewMode === 'table' && !$hasCustomSlot) {
            $tableHeader = $this->renderTableHeader($fields, $actions, $use, $hasBulk, $sortable, $sortableFields, $queryState, $listId);
            $bulkCol = $hasBulk ? '<colgroup><col style="width:40px"></colgroup>' : '';
            $alpine = $search ? ' x-data="{ q:\'\' }"' : '';
            $out = '<div class="' . $wrapperClass . ' ' . $entityClass . ' ' . $class . '" ' . $entityDataAttr . ' ' . $wbComponentAttr . ' ' . $wbEntityAttr . ' ' . $sourceDataAttr . ' ' . $viewDataAttr . $listDataAttr . $alpine . '>'
                . $searchHtml . $bulkHtml
                . '<table class="' . ($use === 'workbench' ? 'wb-table wb-table--sticky' : 'w-full text-sm') . '">' . $bulkCol . $tableHeader . '<tbody>' . $out . '</tbody></table>'
                . ($paginated ? $this->renderPagination($total, $queryState) : '')
                . '</div>';
        } else {
            $styleAttr = isset($attrs['style']) ? ' style="' . htmlspecialchars((string)$attrs['style'], ENT_QUOTES, 'UTF-8') . '"' : '';
            $out = '<div class="' . $wrapperClass . ' ' . $entityClass . ' ' . $class . '" ' . $entityDataAttr . ' ' . $wbComponentAttr . ' ' . $wbEntityAttr . ' ' . $sourceDataAttr . ' ' . $viewDataAttr . $listDataAttr . $styleAttr . '>'
                . $searchHtml . $out . '</div>';
        }

        return $this->afterRenderList($out, $attrs);
    }

    /**
     * Lifecycle hook: called before rendering an entity list.
     * Override in subclasses/decorators for entity-specific setup.
     */
    protected function beforeRenderList(array $attrs, array $context): void {}

    /**
     * Lifecycle hook: called after rendering an entity list.
     * Override in subclasses/decorators for post-processing (e.g. wrapping).
     */
    protected function afterRenderList(string $html, array $attrs): string
    {
        return $html;
    }

    /**
     * Build a sort URL for a given field.
     */
    private function sortUrl(string $field, ?EntityQueryState $queryState, string $listId): string
    {
        if ($queryState === null) {
            return '?sort=' . urlencode($field) . '&dir=asc';
        }
        $newState = $queryState->withSort($field);
        $params = $newState->toQueryParams();
        return '?' . http_build_query($params);
    }

    public function renderDetail(array $entity, array $view, array $attrs, array $context = []): string
    {
        $class = (string)($attrs['class'] ?? '');
        $rawFields = $attrs['fields'] ?? ($view['fields'] ?? array_keys($entity));
        $fields = is_array($rawFields) ? $rawFields : array_map('trim', explode(',', (string)$rawFields));
        if ($fields === ['*'] || $fields === '*') {
            $fields = array_keys($entity);
            $fields = array_values(array_filter($fields, fn(string $k): bool => !str_starts_with($k, '_')));
        }

        $rows = '';
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field === '' || ($field === 'id' && count($fields) > 1)) {
                if (count($fields) > 1) continue;
            }
            $label = ucwords(str_replace('_', ' ', $field));
            $value = $entity[$field] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            }
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $rows .= <<<HTML
            <div class="ikb-entity-detail__field py-3 sm:grid sm:grid-cols-3 sm:gap-4">
                <dt class="text-sm font-medium text-gray-500">{$safeLabel}</dt>
                <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">{$safeValue}</dd>
            </div>
            HTML;
        }

        $entityClass = $this->entitySourceClass($attrs['source'] ?? '');
        return <<<HTML
        <div class="ikb-entity-detail divide-y divide-gray-100 px-4 py-2 {$entityClass} {$class}">
            {$rows}
        </div>
        HTML;
    }

    // ── Cell rendering ─────────────────────────────────────────────

    /**
     * Render a single cell value using the registry.
     */
    public function renderCell(mixed $value, ?string $renderer, string $field, array $row, string $view = 'table', string $outputTarget = 'html'): string
    {
        if ($renderer === null || $renderer === '' || $renderer === 'string') {
            $str = (string)$value;
            return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
        }

        $parts = explode(':', $renderer, 2);
        $type = $parts[0];
        $arg = $parts[1] ?? '';

        $context = new CellRenderContext(
            value: $value,
            field: $field,
            row: $row,
            fieldContract: ['renderer' => $renderer],
            view: $view,
            outputTarget: $outputTarget,
            options: ['arg' => $arg],
        );

        return $this->renderCellWithContext($context);
    }

    /**
     * Render a cell with inline editing support.
     *
     * When the field contract declares editable=true, wraps the cell output
     * in an Alpine.js ikbInlineEdit component that allows click-to-edit.
     */
    public function renderCellEditable(mixed $value, ?string $renderer, string $field, array $row, array $fieldContract = [], string $view = 'table'): string
    {
        $cellHtml = $this->renderCell($value, $renderer, $field, $row, $view);

        $editable = $fieldContract['editable'] ?? false;
        if (!$editable || $editable === 'false') {
            return $cellHtml;
        }

        $entityId = $row['id'] ?? $row['entity_id'] ?? 0;
        $updateCapability = $fieldContract['update_capability'] ?? '';
        $allowedValues = $fieldContract['allowed_values'] ?? [];
        $version = $row['_version'] ?? $row['version'] ?? null;

        if ($entityId === 0 || $updateCapability === '') {
            return $cellHtml;
        }

        // Build Alpine.js config
        $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $config = json_encode([
            'entityId' => (int)$entityId,
            'field' => $field,
            'value' => $value,
            'displayHtml' => $cellHtml,
            'capability' => $updateCapability,
            'allowedValues' => $allowedValues,
            'version' => $version !== null ? (int)$version : null,
            'renderer' => $renderer,
            'rowData' => $row,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $safeConfig = htmlspecialchars($config, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div x-data="ikbInlineEdit({$safeConfig})" class="inline">
            <template x-if="!editing">
                <span @click="startEdit" class="cursor-pointer hover:ring-2 hover:ring-brand-300 rounded inline-block"
                      :aria-label="'Click to edit ' + field" role="button" tabindex="0"
                      @keydown.enter="startEdit">
                    <span x-html="displayHtml">{$cellHtml}</span>
                </span>
            </template>
            <template x-if="editing">
                <div class="flex items-center gap-1">
                    <select x-show="isSelect" x-model="newValue" @change="save" @click.stop
                            @keydown.escape="cancel"
                            class="text-sm border border-brand-300 rounded px-2 py-1 bg-white focus:ring-2 focus:ring-brand-500"
                            :aria-label="'Edit ' + field">
                        <template x-for="opt in allowedValues" :key="opt">
                            <option :value="opt" x-text="opt" :selected="opt === value"></option>
                        </template>
                    </select>
                    <input x-show="!isSelect" type="text" x-model="newValue"
                           @blur="save" @keydown.enter="save" @keydown.escape="cancel"
                           class="text-sm border border-brand-300 rounded px-2 py-1 w-full focus:ring-2 focus:ring-brand-500"
                           :aria-label="'Edit ' + field">
                    <button @click="cancel" class="text-gray-400 hover:text-gray-600 p-1 text-lg leading-none" title="Cancel" aria-label="Cancel editing">&times;</button>
                </div>
            </template>
            <div x-show="saving" class="inline-block ml-1 text-xs text-gray-400" aria-busy="true">saving...</div>
            <div x-show="error" x-text="error" class="text-xs text-red-600 mt-1" role="alert" aria-live="polite"></div>
        </div>
        HTML;
    }

    /**
     * Render a cell from a fully-typed context.
     */
    public function renderCellWithContext(CellRenderContext $context): string
    {
        if ($this->cellRenderers->has($context->field)) {
            // Field-named renderer takes priority (e.g. 'status' with specific renderer)
            $renderer = $this->cellRenderers->get($context->field);
            return $renderer->render($context)->html;
        }

        // Parse type from renderer string in field contract
        $rendererStr = $context->fieldContract['renderer'] ?? '';
        if ($rendererStr === '' || $rendererStr === 'string') {
            return htmlspecialchars((string)$context->value, ENT_QUOTES, 'UTF-8');
        }

        $parts = explode(':', $rendererStr, 2);
        $type = $parts[0];

        if ($this->cellRenderers->has($type)) {
            $renderer = $this->cellRenderers->get($type);
            // Pass the arg as an option
            if (!empty($parts[1] ?? '')) {
                $context = new CellRenderContext(
                    value: $context->value,
                    field: $context->field,
                    row: $context->row,
                    fieldContract: $context->fieldContract,
                    view: $context->view,
                    outputTarget: $context->outputTarget,
                    options: array_merge($context->options, ['arg' => $parts[1]]),
                );
            }
            return $renderer->render($context)->html;
        }

        // Fallback: plain text
        return htmlspecialchars((string)$context->value, ENT_QUOTES, 'UTF-8');
    }

    // ── Row rendering ──────────────────────────────────────────────

    private function renderCompactRow(RowRenderContext $ctx): string
    {
        $rowClass = $this->style('row', 'compact', $ctx->use);
        $titleClass = $this->style('title', 'compact', $ctx->use);
        $subClass = $this->style('subtitle', 'compact', $ctx->use);
        $titleField = $ctx->fields[0] ?? 'id';
        $subField = $ctx->fields[1] ?? null;
        $title = htmlspecialchars((string)($ctx->row[$titleField] ?? $titleField), ENT_QUOTES, 'UTF-8');
        $subRaw = $subField ? (string)($ctx->row[$subField] ?? '') : '';
        $viewContract = is_array($this->renderContext['_view'] ?? null) ? $this->renderContext['_view'] : [];
        $excerptLength = (int)($this->renderContext['excerpt-length'] ?? $this->renderContext['excerpt_length'] ?? $viewContract['excerpt_length'] ?? 0);
        if ($excerptLength > 0 && $subRaw !== '') {
            $subRaw = mb_strlen($subRaw) > $excerptLength ? mb_substr($subRaw, 0, max(0, $excerptLength - 1)) . '...' : $subRaw;
        }
        $sub = htmlspecialchars($subRaw, ENT_QUOTES, 'UTF-8');

        $actionHtml = $this->renderRowActions($ctx);
        $subHtml = $sub !== '' ? "<p class=\"{$subClass}\">{$sub}</p>" : '';
        $clickAttrs = $this->renderRowClickAttrs($ctx->row, $ctx->rowClick, $ctx->rowClickTarget);

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

    private function renderCardGridRow(RowRenderContext $ctx): string
    {
        $cardClass = $this->style('card', 'card_grid', $ctx->use);
        $titleClass = $this->style('title', 'card_grid', $ctx->use);
        $subClass = $this->style('subtitle', 'card_grid', $ctx->use);

        // Use semantic role annotations from view contract if available,
        // fall back to positional fields (index 0 = title, index 1 = subtitle).
        $titleField = $ctx->roleFields['title'] ?? ($ctx->fields[0] ?? 'name');
        $subField = $ctx->roleFields['subtitle'] ?? ($ctx->fields[1] ?? null);
        $imageField = $ctx->roleFields['image'] ?? (in_array('image', $ctx->fields, true) ? 'image' : (in_array('thumbnail', $ctx->fields, true) ? 'thumbnail' : null));
        $title = htmlspecialchars((string)($ctx->row[$titleField] ?? ''), ENT_QUOTES, 'UTF-8');
        $subRaw = $subField ? (string)($ctx->row[$subField] ?? '') : '';
        $viewContract = is_array($this->renderContext['_view'] ?? null) ? $this->renderContext['_view'] : [];
        $excerptLength = (int)($this->renderContext['excerptLength'] ?? $this->renderContext['excerpt-length'] ?? $this->renderContext['excerpt_length'] ?? $viewContract['excerpt_length'] ?? 0);
        if ($excerptLength > 0 && $subRaw !== '') {
            $subRaw = mb_strlen($subRaw) > $excerptLength ? mb_substr($subRaw, 0, max(0, $excerptLength - 1)) . '...' : $subRaw;
        }
        $sub = htmlspecialchars($subRaw, ENT_QUOTES, 'UTF-8');

        $imageHtml = '';
        if ($imageField && !empty($ctx->row[$imageField])) {
            $imgSrc = htmlspecialchars((string)$ctx->row[$imageField], ENT_QUOTES, 'UTF-8');
            $imageHtml = "<img src=\"{$imgSrc}\" alt=\"{$title}\" class=\"w-full h-40 object-cover rounded-t-lg\" loading=\"lazy\">";
        }

        $actionHtml = $this->renderRowActions($ctx);
        $subHtml = $sub !== '' ? "<p class=\"{$subClass}\">{$sub}</p>" : '';
        $detailHtml = '';
        foreach ($ctx->fields as $field) {
            if ($field === $titleField || $field === $subField || $field === $imageField || $field === 'id') {
                continue;
            }
            if (!array_key_exists($field, $ctx->row)) {
                continue;
            }
            $renderer = $ctx->renderers[$field] ?? null;
            $detailHtml .= '<div class="mt-2 text-sm text-gray-700 ikb-card-field ikb-card-field--' . htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') . '">'
                . $this->renderCell($ctx->row[$field], is_string($renderer) ? $renderer : null, (string)$field, $ctx->row, 'card_grid')
                . '</div>';
        }
        $clickAttrs = $this->renderRowClickAttrs($ctx->row, $ctx->rowClick, $ctx->rowClickTarget);

        return <<<HTML
        <div class="{$cardClass}{$clickAttrs['class']}"{$clickAttrs['attrs']}>
            {$imageHtml}
            <div class="p-4">
                <h3 class="{$titleClass}">{$title}</h3>
                {$subHtml}
                {$detailHtml}
                <div class="mt-3 flex gap-2">{$actionHtml}</div>
            </div>
        </div>
        HTML;
    }

    private function renderTableHeader(array $fields, array $actions, string $use, bool $hasBulk = false, bool $sortable = false, array $sortableFields = [], ?EntityQueryState $queryState = null, string $listId = ''): string
    {
        $thClass = $this->style('th', 'table', $use);
        $theadClass = $this->style('thead', 'table', $use);
        $cells = '';
        if ($hasBulk) {
            $cells .= '<th scope="col" class="' . $thClass . '" style="width:40px"><input type="checkbox" class="ikb-bulk-select-all" onclick="document.querySelectorAll(\'.ikb-bulk-row\').forEach(cb => cb.checked = this.checked); document.getElementById(\'ikb-bulk-bar\').classList.toggle(\'hidden\', !this.checked)"></th>';
        }
        foreach ($fields as $field) {
            if ($field === '*') continue;
            $label = htmlspecialchars(ucfirst(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8');

            if ($sortable && (empty($sortableFields) || isset($sortableFields[$field]))) {
                $currentSort = $queryState?->sort;
                $currentDir = $queryState?->direction ?? 'desc';
                $isActive = $currentSort === $field;
                $ariaSort = $isActive ? ' aria-sort="' . ($currentDir === 'asc' ? 'ascending' : 'descending') . '"' : '';
                $arrow = $isActive ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
                $sortUrl = $this->sortUrl($field, $queryState, $listId);
                $cells .= '<th scope="col" class="' . $thClass . '"' . $ariaSort . '>'
                    . '<a href="' . htmlspecialchars($sortUrl, ENT_QUOTES, 'UTF-8') . '" class="flex items-center gap-1 hover:text-brand-700 no-underline">'
                    . $label . $arrow
                    . '</a></th>';
            } else {
                $cells .= '<th scope="col" class="' . $thClass . '">' . $label . '</th>';
            }
        }
        if (!empty($actions)) {
            $cells .= '<th scope="col" class="' . $thClass . ' text-right">Actions</th>';
        }
        return '<thead><tr class="' . $theadClass . '">' . $cells . '</tr></thead>';
    }

    private function renderTableRow(RowRenderContext $ctx): string
    {
        $tdClass = $this->style('td', 'table', $ctx->use);
        $trClass = $this->style('tr', 'table', $ctx->use);
        $clickAttrs = $this->renderRowClickAttrs($ctx->row, $ctx->rowClick, $ctx->rowClickTarget);
        $cells = '';
        if ($ctx->hasBulk) {
            $rowId = htmlspecialchars((string)($ctx->row['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $cells .= '<td class="' . $tdClass . '" data-label=""><input type="checkbox" name="ids[]" value="' . $rowId . '" class="ikb-bulk-row"></td>';
        }
        foreach ($ctx->fields as $field) {
            if ($field === '*') continue;
            $rawValue = $ctx->row[$field] ?? '';
            $renderer = $ctx->renderers[$field] ?? null;
            $fc = $ctx->fieldContracts[$field] ?? [];
            $editable = !empty($ctx->fieldContracts[$field]['editable']);
            $label = htmlspecialchars(ucwords(str_replace('_', ' ', $field)), ENT_QUOTES, 'UTF-8');
            if ($editable) {
                $cells .= '<td class="' . $tdClass . '" data-label="' . $label . '">' . $this->renderCellEditable($rawValue, $renderer, $field, $ctx->row, $fc) . '</td>';
            } else {
                $cells .= '<td class="' . $tdClass . '" data-label="' . $label . '">' . $this->renderCell($rawValue, $renderer, $field, $ctx->row) . '</td>';
            }
        }

        $actionHtml = $this->renderRowActions($ctx);
        if ($actionHtml !== '') {
            $cells .= '<td class="' . $tdClass . ' text-right whitespace-nowrap" data-label="Actions">' . $actionHtml . '</td>';
        }

        return '<tr class="' . $trClass . $clickAttrs['class'] . '"' . $clickAttrs['attrs'] . '>' . $cells . '</tr>';
    }

    // ── Actions ────────────────────────────────────────────────────

    private function renderRowActions(RowRenderContext $ctx): string
    {
        if (empty($ctx->actions)) return '';

        $id = $ctx->row['id'] ?? '';
        $actionWrapperClass = $this->style('actionWrapper', 'actions', $ctx->use);
        $html = '';

        foreach ($ctx->actions as $action) {
            $action = trim($action);
            if ($action === '') continue;

            if ($ctx->userRole !== '' && isset($ctx->actionRoles[$action])) {
                $allowedRoles = is_array($ctx->actionRoles[$action]) ? $ctx->actionRoles[$action] : [$ctx->actionRoles[$action]];
                if (!in_array($ctx->userRole, $allowedRoles, true)) continue;
            }

            if (isset($ctx->actionShowIf[$action]) && $ctx->actionShowIf[$action] !== '') {
                $condition = $ctx->actionShowIf[$action];
                // Compile once, cache for repeated row evaluation
                $cacheKey = $action . '::' . md5($condition);
                if (!isset($this->compiledConditions[$cacheKey])) {
                    try {
                        $this->compiledConditions[$cacheKey] = $this->conditionEvaluator->compile($condition);
                    } catch (\InvalidArgumentException $e) {
                        // If new parser fails, fall back to legacy regex
                        $this->compiledConditions[$cacheKey] = null;
                    }
                }
                $compiled = $this->compiledConditions[$cacheKey];
                if ($compiled !== null) {
                    if (!$this->conditionEvaluator->evaluate($compiled, $ctx->row)) continue;
                } else {
                    // Legacy regex fallback — condition uses old format
                    if (function_exists('write_log')) {
                        \write_log(
                            "DefaultEntityRenderer: legacy action_show_if condition '{$condition}' for action '{$action}' — update to new expression syntax for better performance",
                            'info',
                            ['action' => $action, 'condition' => $condition, 'source' => 'entity_renderer']
                        );
                    }
                    if (!$this->evaluateRowConditionLegacy($ctx->row, $condition)) continue;
                }
            }

            $label = $ctx->actionLabels[$action] ?? ucfirst($action);
            $actionClass = $this->style('action', $action, $ctx->use);
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $safeId = htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8');

            $href = isset($ctx->actionUrls[$action])
                ? $this->renderWithRowContext($ctx->actionUrls[$action], $ctx->row, $this->renderContext)
                : "?id={$safeId}&amp;action={$action}";

            $method = strtolower((string)($ctx->actionMethods[$action] ?? 'get'));

            if ($method === 'post') {
                $confirmMsg = $ctx->actionConfirm[$action] ?? '';
                $onSubmit = $confirmMsg !== ''
                    ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirmMsg), ENT_QUOTES, 'UTF-8') . ')"'
                    : '';
                $csrfInput = '';
                if (function_exists('csrf_token')) {
                    $csrfValue = htmlspecialchars((string)\csrf_token(), ENT_QUOTES, 'UTF-8');
                    $csrfInput = '<input type="hidden" name="_token" value="' . $csrfValue . '">';
                }
                if (function_exists('entity_csrf_token')) {
                    $moduleCsrf = htmlspecialchars((string)\entity_csrf_token(), ENT_QUOTES, 'UTF-8');
                    if ($moduleCsrf !== '') {
                        $csrfInput = '<input type="hidden" name="_token" value="' . $moduleCsrf . '">';
                    }
                }

                $hiddenInputs = '<input type="hidden" name="id" value="' . $safeId . '">';
                foreach ($ctx->row as $key => $value) {
                    if ($key === 'id' || !is_scalar($value)) continue;
                    $safeKey = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
                    $safeVal = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                    $hiddenInputs .= '<input type="hidden" name="' . $safeKey . '" value="' . $safeVal . '">';
                }

                $html .= '<form method="post" action="' . $href . '" class="inline"' . $onSubmit . '>'
                    . $hiddenInputs . $csrfInput
                    . '<button type="submit" class="' . $actionClass . '">' . $safeLabel . '</button></form>';
            } else {
                $onClick = '';
                if (isset($ctx->actionConfirm[$action]) && $ctx->actionConfirm[$action] !== '') {
                    $onClick = ' onclick="return confirm(' . htmlspecialchars(json_encode($ctx->actionConfirm[$action]), ENT_QUOTES, 'UTF-8') . ')"';
                }
                $html .= '<a href="' . $href . '" class="' . $actionClass . '"' . $onClick . '>' . $safeLabel . '</a>';
            }
        }

        return '<div class="' . $actionWrapperClass . '">' . $html . '</div>';
    }

    /**
     * Legacy regex-based condition evaluator (fallback).
     */
    private function evaluateRowConditionLegacy(array $row, string $condition): bool
    {
        if (preg_match('/^(\w+)\s*(==|!=)\s*"([^"]*)"$/', trim($condition), $m)) {
            $field = $m[1];
            $op = $m[2];
            $value = $m[3];
            $rowValue = (string)($row[$field] ?? '');
            return $op === '==' ? $rowValue === $value : $rowValue !== $value;
        }
        return true;
    }

    // ── Pagination ─────────────────────────────────────────────────

    /**
     * Render pagination controls for an entity list.
     */
    private function renderPagination(int $total, ?EntityQueryState $queryState): string
    {
        if ($queryState === null) {
            return '';
        }

        // Cursor-based pagination
        if ($queryState->isCursorBased()) {
            return $this->renderCursorPagination($queryState);
        }

        // Total-based pagination
        if ($total <= $queryState->limit) {
            return '';
        }

        $totalPages = $queryState->totalPages($total);
        $currentPage = $queryState->page;
        $prefix = $queryState->listId !== '' ? $queryState->listId . '_' : '';

        if ($totalPages <= 1) {
            return '';
        }

        $html = '<div class="ikb-entity-pagination flex items-center justify-between px-4 py-3 bg-white border-t border-gray-200 sm:px-6">';
        $html .= '<div class="flex-1 text-sm text-gray-700">Showing <span class="font-medium">' . (($currentPage - 1) * $queryState->limit + 1) . '</span>–<span class="font-medium">' . min($currentPage * $queryState->limit, $total) . '</span> of <span class="font-medium">' . $total . '</span></div>';
        $html .= '<div class="flex items-center gap-1">';

        // Prev button
        if ($currentPage > 1) {
            $prevUrl = $this->pageUrl($currentPage - 1, $queryState);
            $html .= '<a href="' . $prevUrl . '" class="px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">← Prev</a>';
        } else {
            $html .= '<span class="px-3 py-1 text-sm font-medium text-gray-300 bg-gray-50 border border-gray-200 rounded-md cursor-default">← Prev</span>';
        }

        // Page numbers
        $rangeStart = max(1, $currentPage - 2);
        $rangeEnd = min($totalPages, $currentPage + 2);
        if ($rangeStart > 1) {
            $html .= $this->pageLink(1, $queryState);
            if ($rangeStart > 2) {
                $html .= '<span class="px-2 text-gray-400">...</span>';
            }
        }
        for ($i = $rangeStart; $i <= $rangeEnd; $i++) {
            if ($i === $currentPage) {
                $html .= '<span class="px-3 py-1 text-sm font-medium text-brand-700 bg-brand-50 border border-brand-200 rounded-md">' . $i . '</span>';
            } else {
                $html .= $this->pageLink($i, $queryState);
            }
        }
        if ($rangeEnd < $totalPages) {
            if ($rangeEnd < $totalPages - 1) {
                $html .= '<span class="px-2 text-gray-400">...</span>';
            }
            $html .= $this->pageLink($totalPages, $queryState);
        }

        // Next button
        if ($currentPage < $totalPages) {
            $nextUrl = $this->pageUrl($currentPage + 1, $queryState);
            $html .= '<a href="' . $nextUrl . '" class="px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Next →</a>';
        } else {
            $html .= '<span class="px-3 py-1 text-sm font-medium text-gray-300 bg-gray-50 border border-gray-200 rounded-md cursor-default">Next →</span>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function pageLink(int $page, EntityQueryState $queryState): string
    {
        $url = $this->pageUrl($page, $queryState);
        return '<a href="' . $url . '" class="px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">' . $page . '</a>';
    }

    private function pageUrl(int $page, EntityQueryState $queryState): string
    {
        $newState = $queryState->withPage($page);
        $params = $newState->toQueryParams();
        return '?' . http_build_query($params);
    }

    /**
     * Render cursor-based pagination (next/previous with opaque cursors).
     */
    private function renderCursorPagination(EntityQueryState $queryState): string
    {
        $listId = $queryState->listId;
        $prefix = $listId !== '' ? $listId . '_' : '';

        $html = '<div class="ikb-entity-pagination flex items-center justify-between px-4 py-3 bg-white border-t border-gray-200 sm:px-6">';
        $html .= '<div class="flex-1 text-sm text-gray-700">Cursor-based navigation</div>';
        $html .= '<div class="flex items-center gap-2">';

        // Previous button — only if we have a cursor (means we're past page 1 or have a prev cursor)
        if ($queryState->prevCursor !== null || $queryState->cursor !== null) {
            if ($queryState->prevCursor !== null) {
                $params = $this->cursorParams($listId, $queryState->prevCursor, 'prev', $queryState->sort, $queryState->direction);
                $html .= '<a href="?' . http_build_query($params) . '" class="px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">← Prev</a>';
            }
        } else {
            $html .= '<span class="px-3 py-1 text-sm font-medium text-gray-300 bg-gray-50 border border-gray-200 rounded-md cursor-default">← Prev</span>';
        }

        // Next button
        if ($queryState->hasMore || $queryState->cursor !== null) {
            // First page (no cursor yet) — use the sort field value from the last row as cursor
            // When we have a cursor, use it for the next page
            $nextCursor = $queryState->cursor ?? '';
            if ($nextCursor !== '') {
                $params = $this->cursorParams($listId, $nextCursor, 'next', $queryState->sort, $queryState->direction);
                $html .= '<a href="?' . http_build_query($params) . '" class="px-3 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">Next →</a>';
            }
        } else {
            $html .= '<span class="px-3 py-1 text-sm font-medium text-gray-300 bg-gray-50 border border-gray-200 rounded-md cursor-default">Next →</span>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Build cursor navigation params preserving sort state.
     */
    private function cursorParams(string $listId, string $cursor, string $direction, ?string $sort, string $sortDir): array
    {
        $prefix = $listId !== '' ? $listId . '_' : '';
        $params = [];
        $params[$prefix . 'cursor'] = $cursor;
        if ($direction === 'prev') {
            $params[$prefix . 'prev'] = '1';
        }
        if ($sort !== null) {
            $params[$prefix . 'sort'] = $sort;
            $params[$prefix . 'dir'] = $sortDir;
        }
        return $params;
    }

    /**
     * Resolve EntityQueryState from view contract defaults + request.
     */
    private function resolveQueryState(string $listId, array $view): EntityQueryState
    {
        $resolver = new EntityQueryStateResolver();
        $resolved = $resolver->resolve($listId, $_GET, [
            'limit' => $view['limit'] ?? EntityQueryStateResolver::DEFAULT_LIMIT,
            'sort' => $view['sort']['field'] ?? '',
            'direction' => $view['sort']['direction'] ?? 'desc',
        ]);

        // Validate sort field against view contract allowlist
        if ($resolved->sort !== null && function_exists('app')) {
            $app = \app();
            if ($app !== null && method_exists($app, 'entityViews')) {
                $entityType = $view['entity_type'] ?? '';
                $viewName = $view['view'] ?? 'compact';
                if ($entityType !== '') {
                    $validated = $app->entityViews()->validateSort($entityType, $viewName, $resolved->sort, $resolved->direction);
                    return new EntityQueryState(
                        page: $resolved->page,
                        limit: $resolved->limit,
                        sort: $validated['field'],
                        direction: $validated['direction'],
                        filters: $resolved->filters,
                        listId: $resolved->listId,
                    );
                }
            }
        }

        return $resolved;
    }

    // ── Utilities ──────────────────────────────────────────────────

    private function renderWithRowContext(string $template, array $row, array $fallbackContext = []): string
    {
        $result = $template;
        // Collect all placeholders from the template
        preg_match_all('/\{(\w+)\}/', $result, $placeholders);
        $allValues = array_merge($fallbackContext, $row);

        foreach ($placeholders[1] as $key) {
            $value = $row[$key] ?? $fallbackContext[$key] ?? null;
            if ($value === null || $value === '' || $value === false) {
                // Placeholder not resolvable — replace with empty string to avoid
                // rendering literal "{key}" in the output (e.g. {base_url} when
                // the template context doesn't include it).
                $result = str_replace('{' . $key . '}', '', $result);
            } else {
                $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                $result = str_replace('{' . $key . '}', $safeValue, $result);
            }
        }
        // Collapse remaining double slashes (except protocol://)
        $result = preg_replace('#(?<!:)//+#', '/', $result);
        return $result;
    }

    private function renderRowClickAttrs(array $row, string $rowClick, string $target): array
    {
        if ($rowClick === '') {
            return ['attrs' => '', 'class' => ''];
        }
        $url = $rowClick;
        // First pass: check if all required placeholders have non-empty values
        preg_match_all('/\{(\w+)\}/', $url, $placeholders);
        foreach ($placeholders[1] as $key) {
            $value = $row[$key] ?? null;
            if ($value === null || $value === '' || $value === false) {
                return ['attrs' => '', 'class' => ''];
            }
        }
        // Second pass: substitute all values
        foreach ($row as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $url = str_replace('{' . $key . '}', urlencode((string)$value), $url);
            }
        }
        // Collapse any stray double slashes (except protocol://)
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        $interactiveGuard = "if(event.target.closest('a,button,input,select,textarea,[role=\"button\"],[data-wb-stop-row-click]')) return; ";
        $safeJsUrl = htmlspecialchars(json_encode($url), ENT_QUOTES, 'UTF-8');
        if ($target !== '') {
            $safeJsTarget = htmlspecialchars(json_encode($target), ENT_QUOTES, 'UTF-8');
            $attrs = ' onclick="' . htmlspecialchars($interactiveGuard, ENT_QUOTES, 'UTF-8') . 'window.open(' . $safeJsUrl . ',' . $safeJsTarget . ')" style="cursor:pointer"';
        } else {
            $attrs = ' onclick="' . htmlspecialchars($interactiveGuard, ENT_QUOTES, 'UTF-8') . 'window.location.href=' . $safeJsUrl . '" style="cursor:pointer"';
        }

        return [
            'attrs' => $attrs,
            'class' => ' cursor-pointer hover:bg-blue-50/30',
        ];
    }

    private function renderEntitySearchBar(string $listId, string $placeholder, string $use): string
    {
        $safePlaceholder = htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8');
        $listSelector = $listId !== '' ? '#' . $listId . ' ' : '';
        $inputClass = $use === 'workbench'
            ? 'wb-input'
            : 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
        return <<<HTML
        <div class="ikb-entity-search mb-3">
            <input type="text" x-model="q" placeholder="{$safePlaceholder}"
                class="{$inputClass}" aria-label="{$safePlaceholder}"
                @input="document.querySelectorAll('{$listSelector}tbody tr, {$listSelector}.ikb-entity-row, {$listSelector}.ikb-entity-card').forEach(el => {
                    const visible = !q || el.textContent.toLowerCase().includes(q.toLowerCase());
                    el.style.display = visible ? '' : 'none';
                })">
        </div>
        HTML;
    }

    private function renderEntityBulkBar(array $bulkActions, string $bulkActionUrl, string $listId, string $use): string
    {
        $csrfInput = '';
        if (function_exists('csrf_token')) {
            $csrfValue = htmlspecialchars((string)\csrf_token(), ENT_QUOTES, 'UTF-8');
            $csrfInput = '<input type="hidden" name="_token" value="' . $csrfValue . '">';
        }
        $safeUrl = htmlspecialchars($bulkActionUrl, ENT_QUOTES, 'UTF-8');
        $buttons = '';
        foreach ($bulkActions as $ba) {
            $ba = trim($ba);
            if ($ba === '') continue;
            $label = htmlspecialchars(ucfirst($ba), ENT_QUOTES, 'UTF-8');
            $actionClass = $this->style('action', $ba, $use);
            $buttons .= '<button type="submit" name="bulk_action" value="' . $ba . '" class="' . $actionClass . '">' . $label . '</button>';
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

    // ── Style resolution ───────────────────────────────────────────

    public function style(string $element, string $context, string $use = 'tailwind'): string
    {
        $defaultAction = match ($use) {
            'bootstrap' => 'ikb-row-action btn btn-sm btn-outline-secondary',
            'workbench' => 'ikb-row-action wb-btn wb-btn--outline wb-btn--sm',
            'tailwind' => 'ikb-row-action inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors text-gray-600 bg-gray-100 hover:bg-gray-200',
            default => 'ikb-row-action inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-md text-gray-600 hover:bg-gray-100 transition',
        };

        $use = isset($this->stylePresets[$use]) ? $use : 'legacy';
        return $this->stylePresets[$use][$element][$context]
            ?? $this->stylePresets[$use][$element]['table']
            ?? $defaultAction;
    }

    /**
     * Normalized CSS class derived from source string.
     */
    public function entitySourceClass(string $source): string
    {
        if ($source === '') return '';

        [$entityType, $qualifier] = $this->parseSourceParts($source);
        $entityClass = 'ikb-entity-' . $this->normalizeCssIdentifier($entityType);
        $sourceClass = $qualifier !== ''
            ? ' ikb-source-' . $this->normalizeCssIdentifier(str_replace('.', '-', $source))
            : '';

        return $entityClass . $sourceClass;
    }

    /**
     * Render an error state for entity components.
     */
    public function entityErrorState(string $message, string $class = ''): string
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

    // ── Private helpers ────────────────────────────────────────────

    private function registerBuiltinRenderers(): void
    {
        $this->cellRenderers->register('text',     new TextCellRenderer(),     'kernel');
        $this->cellRenderers->register('badge',    new BadgeCellRenderer(),    'kernel');
        $this->cellRenderers->register('money',    new MoneyCellRenderer(),    'kernel');
        $this->cellRenderers->register('datetime', new DateTimeCellRenderer(), 'kernel');
        $this->cellRenderers->register('boolean',  new BooleanCellRenderer(),  'kernel');
        $this->cellRenderers->register('location', new LocationCellRenderer(), 'kernel');
        $this->cellRenderers->register('image',    new ImageCellRenderer(),    'kernel');
    }

    private function buildStylePresets(): array
    {
        return [
            'workbench' => [
                'wrapper' => [
                    'table'     => 'ikb-entity-list ikb-entity-list--table wb-panel overflow-x-auto',
                    'compact'   => 'ikb-entity-list ikb-entity-list--compact wb-panel',
                    'card_grid' => 'ikb-entity-list ikb-entity-list--grid grid gap-4 sm:grid-cols-2 lg:grid-cols-3',
                ],
                'thead'   => ['table' => ''],
                'th'      => ['table' => ''],
                'tr'      => ['table' => ''],
                'td'      => ['table' => ''],
                'row'     => ['compact' => 'ikb-entity-row wb-panel__body'],
                'title'   => ['compact' => 'wb-section-title', 'card_grid' => 'wb-section-title'],
                'subtitle'=> ['compact' => 'wb-text-muted', 'card_grid' => 'wb-text-muted'],
                'card'    => ['card_grid' => 'ikb-entity-card wb-panel'],
                'actionWrapper' => ['actions' => 'flex items-center justify-end gap-2'],
                'action'  => [
                    'view'    => 'ikb-row-action wb-btn wb-btn--outline wb-btn--sm',
                    'edit'    => 'ikb-row-action wb-btn wb-btn--outline wb-btn--sm',
                    'delete'  => 'ikb-row-action wb-btn wb-btn--danger wb-btn--sm',
                    'approve' => 'ikb-row-action wb-btn wb-btn--success wb-btn--sm',
                    'process' => 'ikb-row-action wb-btn wb-btn--primary wb-btn--sm',
                    'cancel'  => 'ikb-row-action wb-btn wb-btn--outline wb-btn--sm',
                ],
            ],
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
    }

    private function parseSourceParts(string $source): array
    {
        $source = trim($source);
        $dot = strrpos($source, '.');
        if ($dot === false) {
            return [$source, ''];
        }
        return [substr($source, 0, $dot), substr($source, $dot + 1)];
    }

    private function entityTypeFromSource(string $source): string
    {
        [$type] = $this->parseSourceParts($source);
        return $type;
    }

    private function normalizeCssIdentifier(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        return trim($value, '-');
    }
}
