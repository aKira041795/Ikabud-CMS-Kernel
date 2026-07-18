<?php

declare(strict_types=1);

function bakeshopPageSupervisor(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'dashboard', 'branches', 'Bakeshop Operations', 'One operations workspace for branch setup, ingredients, products, deliveries, production, and usage. The sections below are grouped so non-technical staff can work in the order they actually do the day.');
    });
}

function bakeshopPageBranches(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'branches', 'branches', 'Branch Setup', 'Create and maintain the branch locations that every delivery, production run, and usage report will reference.');
    });
}

function bakeshopPageCatalog(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'catalog', 'catalog', 'Products', 'Set up ingredients first, then define finished products and their recipe lines in the same work area.');
    });
}

function bakeshopPageIngredients(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'ingredients', 'ingredients', 'Ingredients', 'Maintain the raw-material catalog separately from products so receiving and recipe setup stay clear.');
    });
}

function bakeshopPageDeliveries(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'deliveries', 'deliveries', 'Ingredient Deliveries', 'Receive ingredient stock by branch in one place so incoming materials are recorded cleanly before production.');
    });
}

function bakeshopPageProduction(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'production', 'production', 'Production Runs', 'Record what was produced, then review saved batches by branch, item, and time.');
    });
}

function bakeshopPageUsage(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        echo bakeshopRenderSupervisorWorkspace($user, 'usage', 'usage', 'Usage Summary', 'Review delivered versus consumed ingredients and refresh the usage summary before opening the printable report.');
    });
}

function bakeshopAuditHistoryNormalizeFilters(array $input): array
{
    $filters = [
        'branch_id' => null,
        'date_from' => null,
        'date_to' => null,
        'search' => trim((string)($input['search'] ?? '')),
        'limit' => max(10, min(100, (int)($input['limit'] ?? 25))),
        'offset' => max(0, (int)($input['offset'] ?? 0)),
    ];

    if (($input['branch_id'] ?? null) !== null && (string)$input['branch_id'] !== '') {
        $filters['branch_id'] = bakeshopCatalogRequirePositiveInt($input['branch_id'], 'branch_id');
        bakeshopCatalogAssertRecordExists('bakeshop_branches', $filters['branch_id']);
    }

    foreach (['date_from', 'date_to'] as $field) {
        $raw = trim((string)($input[$field] ?? ''));
        if ($raw === '') {
            continue;
        }

        $filters[$field] = (new DateTimeImmutable($raw))->format('Y-m-d');
    }

    if ($filters['date_from'] !== null && $filters['date_to'] !== null && $filters['date_from'] > $filters['date_to']) {
        throw new InvalidArgumentException('date_from cannot be after date_to.');
    }

    return $filters;
}

function bakeshopAuditHistoryActionLabel(string $action): string
{
    $map = [
        'bakeshop.branch.created' => 'Branch Created',
        'bakeshop.branch.archived' => 'Branch Archived',
        'bakeshop.branch.restored' => 'Branch Restored',
        'bakeshop.delivery.created' => 'Delivery Recorded',
        'bakeshop.delivery.deleted' => 'Delivery Deleted',
        'bakeshop.production.created' => 'Production Run Recorded',
        'bakeshop.production.updated' => 'Production Run Updated',
        'bakeshop.production.voided' => 'Production Run Voided',
        'bakeshop.settings.role_permissions.updated' => 'Access Settings Updated',
        'bakeshop.user.created' => 'Staff Account Created',
        'bakeshop.user.updated' => 'Staff Account Updated',
        'bakeshop.user.deactivated' => 'Staff Account Deactivated',
        'bakeshop.user.password_changed' => 'Staff Password Changed',
        'bakeshop.product_target.created' => 'Product Target Set',
        'bakeshop.product_target.updated' => 'Product Target Updated',
        'bakeshop.product_target.deleted' => 'Product Target Removed',
        'bakeshop.allocation.created' => 'Product Allocation Created',
        'bakeshop.allocation.deleted' => 'Product Allocation Removed',
        'bakeshop.adjustment.created' => 'Inventory Adjustment Created',
        'bakeshop.adjustment.deleted' => 'Inventory Adjustment Deleted',
        'bakeshop.product.deleted' => 'Product Deleted',
        'bakeshop.ingredient.deleted' => 'Ingredient Deleted',
        'bakeshop.recipe.deleted' => 'Recipe Line Deleted',
        'bakeshop.unit.created' => 'Unit Created',
    ];

    if (isset($map[$action])) {
        return $map[$action];
    }

    $label = preg_replace('/^bakeshop\./', '', $action);
    $label = str_replace(['.', '_'], ' ', (string)$label);
    return ucwords(trim($label));
}

function bakeshopAuditHistoryEntityLabel(?string $entityType, ?string $entityId): string
{
    $map = [
        'module_settings' => 'Module Settings',
        'bakeshop_branches' => 'Branch',
        'bakeshop_products' => 'Product',
        'bakeshop_ingredients' => 'Ingredient',
        'bakeshop_product_recipe' => 'Recipe Line',
        'bakeshop_deliveries' => 'Delivery',
        'bakeshop_production_runs' => 'Production Run',
        'bakeshop_users' => 'Staff Account',
        'bakeshop_branch_product_targets' => 'Product Target',
        'bakeshop_inventory_adjustments' => 'Inventory Adjustment',
        'bakeshop_product_allocations' => 'Product Allocation',
        'bakeshop_units' => 'Unit',
    ];

    $label = $map[$entityType ?? ''] ?? 'Audit Event';
    $identifier = trim((string)$entityId);
    if ($identifier === '') {
        return $label;
    }

    return $label . ' #' . $identifier;
}

function bakeshopPrintSummaryScopeLabel(array $filters, array $branches, array $summaryGroups): string
{
    $branchLabel = bakeshopUsageResolveBranchLabel($filters, $branches);
    if ($branchLabel !== null) {
        return $branchLabel;
    }

    if (count($summaryGroups) === 1) {
        return (string)($summaryGroups[0]['branch_label'] ?? 'Branch');
    }

    if (count($summaryGroups) > 1) {
        return 'All branches with activity';
    }

    return 'No branch activity yet';
}

function bakeshopPrintSummaryFormatDate(?string $value): ?string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw))->format('M d, Y');
    } catch (Throwable $ignored) {
        return $raw;
    }
}

function bakeshopPrintSummaryTemplateLabel(string $template): string
{
    $normalized = trim(str_replace(['_', '-'], ' ', $template));
    if ($normalized === '') {
        return 'Standard template';
    }

    return ucwords($normalized) . ' template';
}

function bakeshopDrProjectionPrintQuantityDisplay(float $qtyBase, array $row, int $places): string
{
    $defaultUnitFactor = (float)($row['default_unit_factor_to_base'] ?? 0);
    $defaultUnitCode = strtoupper(trim((string)($row['default_unit_code'] ?? '')));
    $qty = $defaultUnitFactor > 0 ? ($qtyBase / $defaultUnitFactor) : $qtyBase;

    return trim(bakeshopDrProjectionFormatCompactNumber($qty, $places) . ' ' . ($defaultUnitCode !== '' ? $defaultUnitCode : 'UNIT'));
}

function bakeshopDrProjectionPrintViewModel(array $report): array
{
    $places = max(0, (int)($report['settings']['usage_decimal_places'] ?? bakeshopDrProjectionDecimalPlaces()));
    $ingredientRows = [];
    foreach ((array)($report['ingredients'] ?? []) as $row) {
        $ingredientRows[] = array_merge($row, [
            'observed_qty_display' => bakeshopDrProjectionPrintQuantityDisplay((float)($row['delivered_qty_base'] ?? 0), $row, $places),
            'daily_qty_display' => bakeshopDrProjectionPrintQuantityDisplay((float)($row['daily_qty_base'] ?? 0), $row, $places),
            'projected_qty_display_with_unit' => trim((string)($row['projected_qty_display'] ?? bakeshopDrProjectionFormatCompactNumber((float)($row['projected_qty'] ?? 0), $places)) . ' ' . strtoupper(trim((string)($row['default_unit_code'] ?? 'UNIT')))),
            'coverage_label' => max(1, (int)($row['observed_coverage_days'] ?? 0)) . ' day(s)',
        ]);
    }

    $productRows = [];
    foreach ((array)($report['products'] ?? []) as $row) {
        $productRows[] = array_merge($row, [
            'daily_target_display' => trim(bakeshopDrProjectionFormatCompactNumber((float)($row['daily_qty'] ?? 0), $places) . ' ' . strtoupper(trim((string)($row['unit_code'] ?? 'UNIT')))),
            'projected_qty_display_with_unit' => trim((string)($row['projected_qty_display'] ?? bakeshopDrProjectionFormatCompactNumber((float)($row['projected_qty'] ?? 0), $places)) . ' ' . strtoupper(trim((string)($row['unit_code'] ?? 'UNIT')))),
            'days_produced_label' => (int)($row['days_produced'] ?? 0) . ' / ' . (int)($row['window_days'] ?? 0),
            'missing_days_label' => max(0, (int)($row['missing_days'] ?? 0)) . ' day(s)',
        ]);
    }

    return [
        'ingredients' => $ingredientRows,
        'products' => $productRows,
        'usage_decimal_places' => $places,
    ];
}

function bakeshopAuditHistoryBuildUrl(string $path, array $params = []): string
{
    $params = array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');
    return $path . ($params === [] ? '' : ('?' . http_build_query($params)));
}

function bakeshopAuditHistoryEntityUrl(?string $entityType, ?string $entityId): ?string
{
    $entityId = trim((string)$entityId);

    return match ((string)$entityType) {
        'module_settings' => '/admin/bakeshop/settings',
        'bakeshop_branches' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/branches', ['focus_kind' => 'branch', 'focus_id' => $entityId]),
        'bakeshop_products' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/catalog', ['focus_kind' => 'product', 'focus_id' => $entityId]),
        'bakeshop_ingredients' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/ingredients', ['focus_kind' => 'ingredient', 'focus_id' => $entityId]),
        'bakeshop_product_recipe' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/catalog', ['focus_kind' => 'recipe', 'focus_id' => $entityId]),
        'bakeshop_deliveries' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/deliveries', ['focus_kind' => 'delivery', 'focus_id' => $entityId]),
        'bakeshop_production_runs' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/production', ['focus_kind' => 'production', 'focus_id' => $entityId]),
        'bakeshop_users' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/users', ['focus_user_id' => $entityId]),
        default => null,
    };
}

function bakeshopAuditHistoryBranchLabel(array $row): string
{
    $code = trim((string)($row['branch_code'] ?? ''));
    $name = trim((string)($row['branch_name'] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }
    if ($name !== '') {
        return $name;
    }
    if ($code !== '') {
        return $code;
    }

    $branchId = (int)($row['branch_id'] ?? 0);
    return $branchId > 0 ? 'Branch #' . $branchId : 'All Branches';
}

function bakeshopAuditHistoryBranchUrl(array $row): ?string
{
    $branchId = (int)($row['branch_id'] ?? 0);
    if ($branchId <= 0) {
        return null;
    }

    return bakeshopAuditHistoryBuildUrl('/admin/bakeshop/branches', [
        'focus_kind' => 'branch',
        'focus_id' => $branchId,
    ]);
}

function bakeshopAuditHistoryActorLabel(array $row): string
{
    $source = trim((string)($row['actor_source'] ?? ''));
    $bakeshopName = trim((string)($row['actor_bakeshop_name'] ?? ''));
    $bakeshopUsername = trim((string)($row['actor_bakeshop_username'] ?? ''));
    $kernelUsername = trim((string)($row['actor_kernel_username'] ?? ''));

    if ($source === 'bakeshop') {
        if ($bakeshopName !== '' && $bakeshopUsername !== '') {
            return $bakeshopName . ' (@' . $bakeshopUsername . ')';
        }
        if ($bakeshopUsername !== '') {
            return '@' . $bakeshopUsername;
        }

        $moduleUserId = (int)($row['actor_module_user_id'] ?? 0);
        if ($moduleUserId > 0) {
            return 'Bakeshop Staff #' . $moduleUserId;
        }
    }

    if ($kernelUsername !== '') {
        return '@' . $kernelUsername . ' (Kernel)';
    }

    $actorUserId = (int)($row['actor_user_id'] ?? 0);
    if ($actorUserId > 0) {
        return 'Kernel User #' . $actorUserId;
    }

    return 'System';
}

function bakeshopAuditHistoryActorUrl(array $row): ?string
{
    $source = trim((string)($row['actor_source'] ?? ''));
    $moduleUserId = (int)($row['actor_module_user_id'] ?? 0);

    if ($source === 'bakeshop' || $moduleUserId > 0) {
        return bakeshopAuditHistoryBuildUrl('/admin/bakeshop/users', [
            'focus_user_id' => $moduleUserId > 0 ? $moduleUserId : null,
        ]);
    }

    return null;
}

function bakeshopAuditHistorySummary(?array $oldData, ?array $newData): string
{
    if ($oldData === null && $newData !== null) {
        return 'Created a new record.';
    }

    if ($oldData !== null && $newData === null) {
        return 'Removed a record.';
    }

    if (is_array($oldData) && is_array($newData)) {
        $changed = [];
        $keys = array_values(array_unique(array_merge(array_keys($oldData), array_keys($newData))));
        foreach ($keys as $key) {
            if (($oldData[$key] ?? null) !== ($newData[$key] ?? null)) {
                $changed[] = (string)$key;
            }
        }

        if ($changed === []) {
            return 'Recorded activity.';
        }

        if (count($changed) === 1) {
            return 'Changed ' . str_replace('_', ' ', $changed[0]) . '.';
        }

        return 'Changed ' . count($changed) . ' fields.';
    }

    return 'Recorded activity.';
}

function bakeshopAuditHistoryList(array $input = []): array
{
    $filters = bakeshopAuditHistoryNormalizeFilters($input);
    $where = ['a.module = ?'];
    $bindings = ['bakeshop'];

    if ($filters['branch_id'] !== null) {
        $where[] = 'a.branch_id = ?';
        $bindings[] = $filters['branch_id'];
    }
    if ($filters['date_from'] !== null) {
        $where[] = 'a.created_at >= ?';
        $bindings[] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== null) {
        $where[] = 'a.created_at <= ?';
        $bindings[] = $filters['date_to'] . ' 23:59:59';
    }
    if ($filters['search'] !== '') {
        $where[] = '(a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ?)';
        $search = '%' . $filters['search'] . '%';
        $bindings[] = $search;
        $bindings[] = $search;
        $bindings[] = $search;
    }

    $variants = [
        [
            'actor_columns' => 'a.actor_user_id, a.actor_module_user_id, a.actor_source,',
            'actor_join' => 'LEFT JOIN bakeshop_users bu ON bu.id = a.actor_module_user_id',
        ],
        [
            'actor_columns' => 'a.actor_user_id, NULL AS actor_module_user_id, NULL AS actor_source,',
            'actor_join' => 'LEFT JOIN bakeshop_users bu ON 1 = 0',
        ],
    ];

    $items = null;
    $lastError = null;
    foreach ($variants as $variant) {
        $sql = 'SELECT
                    a.id,
                    a.branch_id,
                    a.action,
                    a.entity_type,
                    a.entity_id,
                    a.old_data,
                    a.new_data,
                    a.metadata_json,
                    a.created_at,
                    ' . $variant['actor_columns'] . '
                    COALESCE(b.code, "") AS branch_code,
                    COALESCE(b.name, "") AS branch_name,
                    NULL AS actor_kernel_username,
                    bu.username AS actor_bakeshop_username,
                    bu.full_name AS actor_bakeshop_name
                FROM audit_logs a
                ' . $variant['actor_join'] . '
                LEFT JOIN bakeshop_branches b ON b.id = a.branch_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY a.created_at DESC
                LIMIT ' . (int)$filters['limit'] . ' OFFSET ' . (int)$filters['offset'];

        try {
            $stmt = bakeshopDb()->prepare($sql);
            $stmt->execute($bindings);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $lastError = null;
            break;
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    if ($items === null) {
        throw $lastError ?? new RuntimeException('Unable to load bakeshop history.');
    }

    foreach ($items as &$item) {
        $item['old_data'] = !empty($item['old_data']) ? json_decode((string)$item['old_data'], true) : null;
        $item['new_data'] = !empty($item['new_data']) ? json_decode((string)$item['new_data'], true) : null;
        $item['metadata'] = !empty($item['metadata_json']) ? json_decode((string)$item['metadata_json'], true) : null;
        $item['action_label'] = bakeshopAuditHistoryActionLabel((string)($item['action'] ?? ''));
        $item['entity_label'] = bakeshopAuditHistoryEntityLabel($item['entity_type'] ?? null, $item['entity_id'] ?? null);
        $item['entity_url'] = bakeshopAuditHistoryEntityUrl($item['entity_type'] ?? null, $item['entity_id'] ?? null);
        $item['branch_label'] = bakeshopAuditHistoryBranchLabel($item);
        $item['branch_url'] = bakeshopAuditHistoryBranchUrl($item);
        $item['actor_label'] = bakeshopAuditHistoryActorLabel($item);
        $item['actor_url'] = bakeshopAuditHistoryActorUrl($item);
        $item['summary'] = bakeshopAuditHistorySummary($item['old_data'], $item['new_data']);
        unset($item['metadata_json']);
    }
    unset($item);

    $countStmt = bakeshopDb()->prepare('SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where));
    $countStmt->execute($bindings);
    $total = (int)$countStmt->fetchColumn();

    return [
        'items' => $items,
        'filters' => $filters,
        'total' => $total,
        'has_more' => ($filters['offset'] + $filters['limit']) < $total,
    ];
}

function bakeshopAuditHistoryPageUrl(array $filters, int $offset): string
{
    $query = [
        'branch_id' => $filters['branch_id'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'search' => $filters['search'],
        'limit' => $filters['limit'],
        'offset' => max(0, $offset),
    ];

    $query = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/admin/bakeshop/history' . ($query === [] ? '' : ('?' . http_build_query($query)));
}

function bakeshopPageHistory(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser(null, ['admin']);
        $history = bakeshopAuditHistoryList((array)bakeshopInput());
        $filters = $history['filters'];
        $workspaceScopeQuery = array_filter([
            'branch_id' => $filters['branch_id'],
            'from_date' => $filters['date_from'],
            'to_date' => $filters['date_to'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        echo bakeshopRender('pages/history.disyl', bakeshopPageContext($user, 'history', [
            'page_title' => 'Activity History',
            'page_intro' => 'Review tenant-specific staff, settings, delivery, production, and catalog activity inside the Bakeshop module.',
            'entries' => $history['items'],
            'history_total' => $history['total'],
            'history_has_more' => $history['has_more'],
            'history_filters' => $filters,
            'history_branches' => bakeshopUsageBranchOptions(),
            'history_previous_url' => (int)$filters['offset'] > 0 ? bakeshopAuditHistoryPageUrl($filters, (int)$filters['offset'] - (int)$filters['limit']) : null,
            'history_next_url' => $history['has_more'] ? bakeshopAuditHistoryPageUrl($filters, (int)$filters['offset'] + (int)$filters['limit']) : null,
            'workspace_scope_query' => $workspaceScopeQuery,
        ]));
    });
}

function bakeshopRenderSupervisorWorkspace(array $user, string $currentPage, string $initialTab, string $pageTitle, string $pageIntro): string
{
    $bootstrapOnboarding = bakeshopBootstrapOnboardingState();
    $isDashboard = $currentPage === 'dashboard';
    $focusedWorkspaceMode = !$isDashboard
        || strtolower(trim((string)bakeshopInput('view', ''))) === 'workspace';
    $showDashboardPanels = $isDashboard && !$focusedWorkspaceMode;
    $workspaceNavCurrentTab = (!$isDashboard || $focusedWorkspaceMode) ? $initialTab : null;
    $canManageWorkspace = bakeshopCanManageSettings($user);
    $summaryFilters = $isDashboard ? bakeshopUsageNormalizeFilters(bakeshopInput()) : bakeshopUsageNormalizeFilters([]);
    $summaryQuery = array_filter([
        'branch_id' => $summaryFilters['branch_id'],
        'from_date' => $summaryFilters['from_date'],
        'to_date' => $summaryFilters['to_date'],
    ], static fn (mixed $value): bool => $value !== null && $value !== '');
    $workspaceOverviewUrl = '/admin/bakeshop' . ($summaryQuery === [] ? '' : ('?' . http_build_query($summaryQuery)));
    $focusedWorkspaceQuery = array_merge(['view' => 'workspace'], $summaryQuery);
    $focusedWorkspaceBase = '/admin/bakeshop?' . http_build_query($focusedWorkspaceQuery);
    $workspaceTabBase = $focusedWorkspaceMode ? $focusedWorkspaceBase : $workspaceOverviewUrl;
    $workAreaMeta = [
        'branches' => [
            'title' => 'Branch Setup',
            'intro' => 'Create and maintain the branch locations that every delivery, production run, and usage report will reference.',
        ],
        'catalog' => [
            'title' => 'Products',
            'intro' => 'Define finished goods first, then maintain each product\'s recipe lines in the same work area.',
        ],
        'ingredients' => [
            'title' => 'Ingredients',
            'intro' => 'Maintain the raw-material catalog separately from products so receiving and recipe setup stay clear.',
        ],
        'deliveries' => [
            'title' => 'Ingredient Deliveries',
            'intro' => 'Receive ingredient stock by branch in one place so incoming materials are recorded cleanly before production.',
        ],
        'production' => [
            'title' => 'Baking Log',
            'intro' => 'Record what was baked, then review saved batches by branch, item, and time.',
        ],
        'usage' => [
            'title' => 'Usage Summary',
            'intro' => 'Review delivered versus consumed ingredients and refresh the usage summary before opening the printable report.',
        ],
    ];
    $workAreaLabels = [
        'branches' => 'Branches',
        'catalog' => 'Products',
        'ingredients' => 'Ingredients',
        'deliveries' => 'Ingredient Deliveries',
        'production' => 'Baking Log',
        'usage' => 'Usage Summary',
    ];
    $workAreaRoutes = [];
    $workspaceSubmenuRoutes = [];
    foreach ($workAreaLabels as $tabKey => $tabLabel) {
        $workAreaRoutes[$tabKey] = $workspaceTabBase . '#' . $tabKey;
        $workspaceSubmenuRoutes[$tabKey] = $focusedWorkspaceBase . '#' . $tabKey;
    }

    if ($isDashboard && $focusedWorkspaceMode) {
        $pageTitle = 'Focused Operations Workspace';
        $pageIntro = 'Choose one work area from the submenu and finish that slice before returning to the full workspace overview.';
    }

    $role = (string)($user['role'] ?? '');
    $rolePermissions = bakeshopRolePermissions();

    $statsStmt = bakeshopDb()->query(
        'SELECT
            (SELECT COUNT(*) FROM bakeshop_branches) AS branch_count,
            (SELECT COUNT(*) FROM bakeshop_products) AS product_count,
            (SELECT COUNT(*) FROM bakeshop_ingredients) AS ingredient_count,
            (SELECT COUNT(*) FROM bakeshop_product_recipe) AS recipe_count,
            (SELECT COUNT(*) FROM bakeshop_units) AS unit_count'
    );
    $stats = $statsStmt ? ($statsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $usageDecimalPlaces = bakeshopUsageDecimalPlaces();
    $summaryBranches = bakeshopUsageBranchOptions();
    $summaryGroups = $isDashboard ? bakeshopPrintSummaryBranchGroups($summaryFilters) : [];
    $summaryBounds = $isDashboard ? bakeshopUsageVisibleDateBounds($summaryFilters) : ['from_date' => null, 'to_date' => null];
    $summaryFactualSummary = $isDashboard ? bakeshopUsageFactualSummary($summaryFilters) : [];
    $summaryBranchLabel = bakeshopUsageResolveBranchLabel($summaryFilters, $summaryBranches);
    $summaryBranchScopeLabel = $summaryBranchLabel;
    if ($summaryBranchScopeLabel === null) {
        if (count($summaryGroups) === 1) {
            $summaryBranchScopeLabel = (string)($summaryGroups[0]['branch_label'] ?? 'Branch');
        } elseif (count($summaryGroups) > 1) {
            $summaryBranchScopeLabel = 'All branches with activity';
        } else {
            $summaryBranchScopeLabel = 'No branch activity yet';
        }
    }
    $summaryDisplayFromDate = $summaryFilters['from_date'] ?? $summaryBounds['from_date'];
    $summaryDisplayToDate = $summaryFilters['to_date'] ?? $summaryBounds['to_date'];

    return bakeshopRender('pages/supervisor.disyl', bakeshopPageContext($user, $currentPage, [
        'in_workspace' => true,
        'page_title' => $pageTitle,
        'page_intro' => $pageIntro,
        'initial_tab' => $initialTab,
        'focused_workspace_mode' => $focusedWorkspaceMode,
        'workspace_overview_url' => $workspaceOverviewUrl,
        'workspace_nav_current_tab' => $workspaceNavCurrentTab,
        'workspace_submenu_routes' => $workspaceSubmenuRoutes,
        'work_area_labels' => $workAreaLabels,
        'work_area_meta' => $workAreaMeta,
        'dashboard_visibility_style' => $showDashboardPanels ? '' : 'display:none;',
        'workspace_visibility_style' => '',
        'workspace_head_visibility_style' => '',
        'workspace_tabs_visibility_style' => '',
        'summary_visibility_style' => $showDashboardPanels ? '' : 'display:none;',
        'usage_decimal_places' => $usageDecimalPlaces,
        'usage_zero_value' => number_format(0, $usageDecimalPlaces, '.', ''),
        'default_dr_coverage_days' => bakeshopDefaultDrCoverageDays(),
        'product_recipe_status' => bakeshopProductRecipeStatus(),
        'product_recipes_enabled' => bakeshopProductRecipesEnabled(),
        'production_recipe_mode' => bakeshopProductionRecipeMode(),
        'production_requires_recipe' => bakeshopProductionRequiresRecipe(),
        'work_area_routes' => $workAreaRoutes,
        'stats' => [
            'branches' => (int)($stats['branch_count'] ?? 0),
            'products' => (int)($stats['product_count'] ?? 0),
            'ingredients' => (int)($stats['ingredient_count'] ?? 0),
            'recipes' => (int)($stats['recipe_count'] ?? 0),
            'units' => (int)($stats['unit_count'] ?? 0),
        ],
        'summary_report' => [
            'filters' => $summaryFilters,
            'branches' => $summaryBranches,
            'branch_label' => $summaryBranchLabel,
            'branch_scope_label' => $summaryBranchScopeLabel,
            'visible_from_date' => $summaryBounds['from_date'],
            'visible_to_date' => $summaryBounds['to_date'],
            'display_from_date' => $summaryDisplayFromDate,
            'display_to_date' => $summaryDisplayToDate,
            'groups' => $summaryGroups,
            'factual_summary' => $summaryFactualSummary,
            'print_template' => bakeshopPrintTemplate(),
            'dashboard_url' => '/admin/bakeshop',
            'print_url' => '/admin/bakeshop/print' . ($summaryQuery === [] ? '' : ('?' . http_build_query($summaryQuery))),
            'has_active_filters' => $summaryQuery !== [],
        ],
        'current_user_id' => (int)($user['id'] ?? 0),
        'can_manage_workspace' => $canManageWorkspace,
        'can_manage_users' => bakeshopCanManageUsers($user),
        'can_manage_settings' => $canManageWorkspace,
        'bootstrap_onboarding' => $bootstrapOnboarding,
        'is_bootstrap_user' => bakeshopIsBootstrapUser($user),
        'permission_matrix' => [
            'admin' => [
                'read' => in_array('bakeshop.read', $rolePermissions['admin'] ?? [], true),
                'manage' => in_array('bakeshop.manage', $rolePermissions['admin'] ?? [], true),
            ],
            'supervisor' => [
                'read' => in_array('bakeshop.read', $rolePermissions['supervisor'] ?? [], true),
                'manage' => in_array('bakeshop.manage', $rolePermissions['supervisor'] ?? [], true),
            ],
        ],
    ]));
}

function bakeshopPageSettings(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        $role = (string)($user['role'] ?? '');
        $rolePermissions = bakeshopRolePermissions();
        $unitsStmt = bakeshopDb()->query(
            'SELECT id, code, name, dimension, factor_to_base FROM bakeshop_units ORDER BY dimension ASC, sort_order ASC, code ASC'
        );
        $units = $unitsStmt ? ($unitsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $settings = bakeshopSettings();
        $brandSettings = bakeshopBrandSettings();
        $canManageSettings = bakeshopCanManageSettings($user);
        $dbRuntime = $canManageSettings ? app()->dbRuntimeSnapshot() : null;

        echo bakeshopRender('pages/settings.disyl', bakeshopPageContext($user, 'settings', [
            'page_title' => 'Bakeshop Settings',
            'page_intro' => 'Access rules, store branding, print defaults, and unit setup for the Bakeshop workspace.',
            'units' => $units,
            'settings' => [
                'store_name' => $brandSettings['store_name'],
                'store_description' => $brandSettings['store_description'],
                'store_logo_url' => $brandSettings['store_logo_url'],
                'usage_decimal_places' => bakeshopNormalizeUsageDecimalPlaces($settings['usage_decimal_places'] ?? null),
                'default_dr_coverage_days' => bakeshopNormalizeDefaultDrCoverageDays($settings['default_dr_coverage_days'] ?? null),
                'product_recipe_status' => bakeshopProductRecipeStatus(),
                'production_recipe_mode' => bakeshopProductionRecipeMode(),
                'print_template' => bakeshopNormalizePrintTemplate($settings['print_template'] ?? null),
            ],
            'permission_matrix' => [
                'admin' => [
                    'read' => in_array('bakeshop.read', $rolePermissions['admin'] ?? [], true),
                    'manage' => in_array('bakeshop.manage', $rolePermissions['admin'] ?? [], true),
                ],
                'supervisor' => [
                    'read' => in_array('bakeshop.read', $rolePermissions['supervisor'] ?? [], true),
                    'manage' => in_array('bakeshop.manage', $rolePermissions['supervisor'] ?? [], true),
                ],
            ],
            'db_runtime' => $dbRuntime,
            'can_manage_settings' => $canManageSettings,
        ]));
    });
}

function bakeshopPagePrintSummary(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');

        $filters = bakeshopUsageNormalizeFilters(bakeshopInput());
        $branches = bakeshopUsageBranchOptions();
        $branchFilterOptions = array_map(static function (array $branch) use ($filters): array {
            $branchId = (int)($branch['id'] ?? 0);
            $code = trim((string)($branch['code'] ?? ''));
            $name = trim((string)($branch['name'] ?? ''));
            $label = $code !== '' && $name !== ''
                ? ($code . ' - ' . $name)
                : ($name !== '' ? $name : ($code !== '' ? $code : ('Branch #' . $branchId)));

            return [
                'value' => (string)$branchId,
                'label' => $label,
                'selected' => $branchId > 0 && $branchId === (int)($filters['branch_id'] ?? 0),
            ];
        }, $branches);
        $supplierOptions = bakeshopUsageSupplierOptions($filters);
        $ingredientOptions = bakeshopPrintSummaryIngredientOptions($filters);
        $selectedSupplier = bakeshopUsageParseSupplierFilter($filters['supplier'] ?? null);
        $selectedIngredientOptions = array_values(array_filter($ingredientOptions, static fn (array $option): bool => !empty($option['selected'])));
        $summaryGroups = bakeshopPrintSummaryBranchGroups($filters);
        $factualSummary = bakeshopUsageFactualSummary($filters);
        $visibleBounds = bakeshopUsageVisibleDateBounds($filters);
        $usageDecimalPlaces = bakeshopUsageDecimalPlaces();
        $printTemplate = bakeshopPrintTemplate();

        echo bakeshopRender('pages/print-summary.disyl', [
            'page_title' => 'Printable Bakeshop Summary',
            'brand_settings' => bakeshopBrandSettings(),
            'filters' => $filters,
            'branch_filter_options' => $branchFilterOptions,
            'supplier_options' => $supplierOptions,
            'ingredient_options' => $ingredientOptions,
            'branch_scope_label' => bakeshopPrintSummaryScopeLabel($filters, $branches, $summaryGroups),
            'supplier_scope_label' => $selectedSupplier['label'] ?? 'All suppliers',
            'ingredient_scope_label' => $selectedIngredientOptions === []
                ? 'All ingredients'
                : (count($selectedIngredientOptions) === 1
                    ? (string)($selectedIngredientOptions[0]['label'] ?? '1 ingredient selected')
                    : (count($selectedIngredientOptions) . ' ingredients selected')),
            'branches' => $branches,
            'summary_groups' => $summaryGroups,
            'factual_summary' => $factualSummary,
            'display_from_date' => bakeshopPrintSummaryFormatDate($filters['from_date'] ?? $visibleBounds['from_date']),
            'display_to_date' => bakeshopPrintSummaryFormatDate($filters['to_date'] ?? $visibleBounds['to_date']),
            'usage_decimal_places' => $usageDecimalPlaces,
            'print_template_label' => bakeshopPrintSummaryTemplateLabel($printTemplate),
            'output_summary_label' => 'Rounded to ' . $usageDecimalPlaces . ' decimal place' . ($usageDecimalPlaces === 1 ? '' : 's'),
        ]);
    });
}

function bakeshopPagePrintDrProjection(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');

        $input = (array)bakeshopInput();
        $report = null;
        $viewModel = [
            'ingredients' => [],
            'products' => [],
            'usage_decimal_places' => bakeshopDrProjectionDecimalPlaces(),
        ];
        $errorMessage = '';

        try {
            $report = bakeshopDrProjectionReport($input);
            $report['settings'] = array_merge((array)($report['settings'] ?? []), [
                'usage_decimal_places' => bakeshopDrProjectionDecimalPlaces(),
            ]);
            $viewModel = bakeshopDrProjectionPrintViewModel($report);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $filters = is_array($report['filters'] ?? null)
            ? (array)$report['filters']
            : [
                'branch_id' => trim((string)($input['branch_id'] ?? '')),
                'from_date' => trim((string)($input['from_date'] ?? '')),
                'to_date' => trim((string)($input['to_date'] ?? '')),
                'horizon_days' => trim((string)($input['horizon_days'] ?? '7')),
            ];

        $workspaceQuery = array_filter([
            'branch_id' => $filters['branch_id'] ?? null,
            'from_date' => $filters['from_date'] ?? null,
            'to_date' => $filters['to_date'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        echo bakeshopRender('pages/print-dr-projection.disyl', [
            'page_title' => 'Printable DR Projection',
            'base_url' => bakeshopBaseUrl(),
            'brand_settings' => bakeshopBrandSettings(),
            'report' => $report,
            'filters' => $filters,
            'branch' => is_array($report['branch'] ?? null) ? (array)$report['branch'] : null,
            'display_from_date' => bakeshopPrintSummaryFormatDate((string)($filters['from_date'] ?? '')),
            'display_to_date' => bakeshopPrintSummaryFormatDate((string)($filters['to_date'] ?? '')),
            'horizon_days' => (string)($filters['horizon_days'] ?? '7'),
            'usage_decimal_places' => $viewModel['usage_decimal_places'],
            'ingredient_rows' => $viewModel['ingredients'],
            'product_rows' => $viewModel['products'],
            'error_message' => $errorMessage,
            'workspace_url' => bakeshopAuditHistoryBuildUrl('/admin/bakeshop/usage', $workspaceQuery),
            'print_template_label' => bakeshopPrintSummaryTemplateLabel(bakeshopPrintTemplate()),
            'output_summary_label' => 'Rounded to ' . $viewModel['usage_decimal_places'] . ' decimal place' . ((int)$viewModel['usage_decimal_places'] === 1 ? '' : 's'),
        ]);
    });
}