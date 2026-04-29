<?php

declare(strict_types=1);

function bakeshopUsageBranchOptions(): array
{
    return bakeshopDeliveriesListBranches();
}

function bakeshopUsageResolveBranchLabel(array $filters, array $branches): ?string
{
    $branchId = (int)($filters['branch_id'] ?? 0);
    if ($branchId <= 0) {
        return null;
    }

    foreach ($branches as $branch) {
        if ((int)($branch['id'] ?? 0) !== $branchId) {
            continue;
        }

        $code = trim((string)($branch['code'] ?? ''));
        $name = trim((string)($branch['name'] ?? ''));
        if ($code !== '' && $name !== '') {
            return $code . ' - ' . $name;
        }
        if ($name !== '') {
            return $name;
        }
        if ($code !== '') {
            return $code;
        }
    }

    return (string)$branchId;
}

function bakeshopUsageBaseUnitCode(string $dimension): string
{
    return match (strtolower(trim($dimension))) {
        'mass' => 'kg',
        'volume' => 'L',
        'count' => 'pc',
        default => 'unit',
    };
}

function bakeshopUsageNormalizeFilters(array $input): array
{
    $filters = [
        'branch_id' => null,
        'from_date' => null,
        'to_date' => null,
    ];

    if (($input['branch_id'] ?? null) !== null && (string)$input['branch_id'] !== '') {
        $filters['branch_id'] = bakeshopCatalogRequirePositiveInt($input['branch_id'], 'branch_id');
        bakeshopCatalogAssertRecordExists('bakeshop_branches', $filters['branch_id']);
    }

    foreach (['from_date', 'to_date'] as $dateField) {
        $raw = trim((string)($input[$dateField] ?? ''));
        if ($raw === '') {
            continue;
        }

        $date = new DateTimeImmutable($raw);
        $filters[$dateField] = $date->format('Y-m-d');
    }

    if ($filters['from_date'] !== null && $filters['to_date'] !== null && $filters['from_date'] > $filters['to_date']) {
        throw new InvalidArgumentException('from_date cannot be after to_date.');
    }

    return $filters;
}

function bakeshopUsageVisibleDateBounds(array $input = []): array
{
    $filters = bakeshopUsageNormalizeFilters($input);
    $where = [];
    $bindings = [];

    if ($filters['branch_id'] !== null) {
        $where[] = 'branch_id = :branch_id';
        $bindings[':branch_id'] = $filters['branch_id'];
    }

    if ($filters['from_date'] !== null) {
        $where[] = 'period_date >= :from_date';
        $bindings[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== null) {
        $where[] = 'period_date <= :to_date';
        $bindings[':to_date'] = $filters['to_date'];
    }

    $sql = 'SELECT MIN(period_date) AS from_date, MAX(period_date) AS to_date FROM bakeshop_ingredient_usage';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $row = bakeshopCatalogFetchOne($sql, $bindings) ?? [];

    return [
        'from_date' => trim((string)($row['from_date'] ?? '')) !== '' ? (string)$row['from_date'] : null,
        'to_date' => trim((string)($row['to_date'] ?? '')) !== '' ? (string)$row['to_date'] : null,
    ];
}

function bakeshopUsageReportRows(array $input = []): array
{
    $filters = bakeshopUsageNormalizeFilters($input);
    $where = [];
    $bindings = [];

    if ($filters['branch_id'] !== null) {
        $where[] = 'branch_id = :branch_id';
        $bindings[':branch_id'] = $filters['branch_id'];
    }

    if ($filters['from_date'] !== null) {
        $where[] = 'period_date >= :from_date';
        $bindings[':from_date'] = $filters['from_date'];
    }

    if ($filters['to_date'] !== null) {
        $where[] = 'period_date <= :to_date';
        $bindings[':to_date'] = $filters['to_date'];
    }

    $sql = 'SELECT
                branch_id,
                branch_name,
                ingredient_id,
                ingredient_name,
                dimension,
                period_date,
                delivered_qty_base,
                consumed_qty_base,
                variance_qty_base
            FROM bakeshop_ingredient_usage';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY branch_name ASC, period_date DESC, ingredient_name ASC LIMIT 500';

    return bakeshopCatalogFetchAll($sql, $bindings);
}

function bakeshopUsageTotals(array $rows): array
{
    $decimalPlaces = bakeshopUsageDecimalPlaces();
    $totals = [
        'delivered_qty_base' => 0.0,
        'consumed_qty_base' => 0.0,
        'variance_qty_base' => 0.0,
    ];

    foreach ($rows as $row) {
        $totals['delivered_qty_base'] += (float)($row['delivered_qty_base'] ?? 0);
        $totals['consumed_qty_base'] += (float)($row['consumed_qty_base'] ?? 0);
        $totals['variance_qty_base'] += (float)($row['variance_qty_base'] ?? 0);
    }

    return [
        'delivered_qty_base' => number_format($totals['delivered_qty_base'], $decimalPlaces, '.', ''),
        'consumed_qty_base' => number_format($totals['consumed_qty_base'], $decimalPlaces, '.', ''),
        'variance_qty_base' => number_format($totals['variance_qty_base'], $decimalPlaces, '.', ''),
    ];
}

function bakeshopUsageFormatRows(array $rows): array
{
    $decimalPlaces = bakeshopUsageDecimalPlaces();

    foreach ($rows as &$row) {
        foreach (['delivered_qty_base', 'consumed_qty_base', 'variance_qty_base'] as $field) {
            $row[$field] = number_format((float)($row[$field] ?? 0), $decimalPlaces, '.', '');
        }
    }
    unset($row);

    return $rows;
}

function bakeshopPrintSummaryRows(array $input = []): array
{
    $filters = bakeshopUsageNormalizeFilters($input);
    $rowsByKey = [];

    $mergeRows = static function (array $sourceRows, string $valueField) use (&$rowsByKey): void {
        foreach ($sourceRows as $row) {
            $key = implode(':', [
                (int)($row['branch_id'] ?? 0),
                (int)($row['ingredient_id'] ?? 0),
                strtolower((string)($row['dimension'] ?? '')),
            ]);

            if (!isset($rowsByKey[$key])) {
                $rowsByKey[$key] = [
                    'branch_id' => (int)($row['branch_id'] ?? 0),
                    'branch_name' => (string)($row['branch_name'] ?? ''),
                    'ingredient_id' => (int)($row['ingredient_id'] ?? 0),
                    'ingredient_name' => (string)($row['ingredient_name'] ?? ''),
                    'dimension' => (string)($row['dimension'] ?? ''),
                    'unit_code' => bakeshopUsageBaseUnitCode((string)($row['dimension'] ?? '')),
                    'beginning_balance' => 0.0,
                    'total_delivery' => 0.0,
                    'total_usage' => 0.0,
                    'remaining_balance' => 0.0,
                    'supplier_label' => '—',
                ];
            }

            if (($row['branch_name'] ?? '') !== '') {
                $rowsByKey[$key]['branch_name'] = (string)$row['branch_name'];
            }
            if (($row['ingredient_name'] ?? '') !== '') {
                $rowsByKey[$key]['ingredient_name'] = (string)$row['ingredient_name'];
            }
            $rowsByKey[$key][$valueField] = (float)($row[$valueField] ?? 0);
        }
    };

    if ($filters['from_date'] !== null) {
        $openingWhere = ['period_date < :from_date'];
        $openingBindings = [':from_date' => $filters['from_date']];
        if ($filters['branch_id'] !== null) {
            $openingWhere[] = 'branch_id = :branch_id';
            $openingBindings[':branch_id'] = $filters['branch_id'];
        }

        $mergeRows(bakeshopCatalogFetchAll(
            'SELECT
                branch_id,
                branch_name,
                ingredient_id,
                ingredient_name,
                dimension,
                SUM(variance_qty_base) AS beginning_balance
             FROM bakeshop_ingredient_usage
             WHERE ' . implode(' AND ', $openingWhere) . '
             GROUP BY branch_id, branch_name, ingredient_id, ingredient_name, dimension',
            $openingBindings
        ), 'beginning_balance');
    }

    $periodWhere = [];
    $periodBindings = [];
    if ($filters['branch_id'] !== null) {
        $periodWhere[] = 'branch_id = :branch_id';
        $periodBindings[':branch_id'] = $filters['branch_id'];
    }
    if ($filters['from_date'] !== null) {
        $periodWhere[] = 'period_date >= :from_date';
        $periodBindings[':from_date'] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null) {
        $periodWhere[] = 'period_date <= :to_date';
        $periodBindings[':to_date'] = $filters['to_date'];
    }

    $periodSql = 'SELECT
            branch_id,
            branch_name,
            ingredient_id,
            ingredient_name,
            dimension,
            SUM(delivered_qty_base) AS total_delivery,
            SUM(consumed_qty_base) AS total_usage
         FROM bakeshop_ingredient_usage';
    if ($periodWhere !== []) {
        $periodSql .= ' WHERE ' . implode(' AND ', $periodWhere);
    }
    $periodSql .= ' GROUP BY branch_id, branch_name, ingredient_id, ingredient_name, dimension';
    $periodRows = bakeshopCatalogFetchAll($periodSql, $periodBindings);
    $mergeRows($periodRows, 'total_delivery');
    foreach ($periodRows as $row) {
        $key = implode(':', [
            (int)($row['branch_id'] ?? 0),
            (int)($row['ingredient_id'] ?? 0),
            strtolower((string)($row['dimension'] ?? '')),
        ]);
        if (isset($rowsByKey[$key])) {
            $rowsByKey[$key]['total_usage'] = (float)($row['total_usage'] ?? 0);
        }
    }

    $balanceWhere = [];
    $balanceBindings = [];
    if ($filters['branch_id'] !== null) {
        $balanceWhere[] = 'branch_id = :branch_id';
        $balanceBindings[':branch_id'] = $filters['branch_id'];
    }
    if ($filters['to_date'] !== null) {
        $balanceWhere[] = 'period_date <= :to_date';
        $balanceBindings[':to_date'] = $filters['to_date'];
    }

    $balanceSql = 'SELECT
            branch_id,
            branch_name,
            ingredient_id,
            ingredient_name,
            dimension,
            SUM(variance_qty_base) AS remaining_balance
         FROM bakeshop_ingredient_usage';
    if ($balanceWhere !== []) {
        $balanceSql .= ' WHERE ' . implode(' AND ', $balanceWhere);
    }
    $balanceSql .= ' GROUP BY branch_id, branch_name, ingredient_id, ingredient_name, dimension';
    $mergeRows(bakeshopCatalogFetchAll($balanceSql, $balanceBindings), 'remaining_balance');

    $hasDeliverySourceColumns = bakeshopTableHasColumn('bakeshop_deliveries', 'source_type')
        && bakeshopTableHasColumn('bakeshop_deliveries', 'source_name');

    if (!$hasDeliverySourceColumns) {
        foreach ($rowsByKey as &$row) {
            if ((float)($row['total_delivery'] ?? 0) > 0.0000001) {
                $row['supplier_label'] = 'Not recorded';
            }
        }
        unset($row);
    } else {
        $sourceWhere = [];
        $sourceBindings = [];
        if ($filters['branch_id'] !== null) {
            $sourceWhere[] = 'd.branch_id = :branch_id';
            $sourceBindings[':branch_id'] = $filters['branch_id'];
        }
        if ($filters['from_date'] !== null) {
            $sourceWhere[] = 'DATE(d.delivered_at) >= :from_date';
            $sourceBindings[':from_date'] = $filters['from_date'];
        }
        if ($filters['to_date'] !== null) {
            $sourceWhere[] = 'DATE(d.delivered_at) <= :to_date';
            $sourceBindings[':to_date'] = $filters['to_date'];
        }

        $sourceSql = <<<'SQL'
SELECT
    d.branch_id,
    b.name AS branch_name,
    di.ingredient_id,
    i.name AS ingredient_name,
    u.dimension AS dimension,
    GROUP_CONCAT(DISTINCT (
        CASE
            WHEN d.source_type = 'other' THEN
                CASE
                    WHEN TRIM(COALESCE(d.source_name, '')) <> '' THEN CONCAT('Other - ', TRIM(d.source_name))
                    ELSE 'Other'
                END
            ELSE 'Commissary'
        END
    ) ORDER BY d.source_type ASC, TRIM(COALESCE(d.source_name, '')) ASC SEPARATOR ', ') AS supplier_label
FROM bakeshop_deliveries d
INNER JOIN bakeshop_delivery_items di ON di.delivery_id = d.id
INNER JOIN bakeshop_ingredients i ON i.id = di.ingredient_id
INNER JOIN bakeshop_units u ON u.id = di.unit_id
INNER JOIN bakeshop_branches b ON b.id = d.branch_id
SQL;
        if ($sourceWhere !== []) {
            $sourceSql .= ' WHERE ' . implode(' AND ', $sourceWhere);
        }
        $sourceSql .= ' GROUP BY d.branch_id, b.name, di.ingredient_id, i.name, u.dimension';

        foreach (bakeshopCatalogFetchAll($sourceSql, $sourceBindings) as $row) {
            $key = implode(':', [
                (int)($row['branch_id'] ?? 0),
                (int)($row['ingredient_id'] ?? 0),
                strtolower((string)($row['dimension'] ?? '')),
            ]);
            if (!isset($rowsByKey[$key])) {
                continue;
            }

            $rowsByKey[$key]['supplier_label'] = trim((string)($row['supplier_label'] ?? '')) !== ''
                ? (string)$row['supplier_label']
                : '—';
        }
    }

    $rows = array_values(array_filter($rowsByKey, static function (array $row): bool {
        return abs((float)$row['beginning_balance']) > 0.0000001
            || abs((float)$row['total_delivery']) > 0.0000001
            || abs((float)$row['total_usage']) > 0.0000001
            || abs((float)$row['remaining_balance']) > 0.0000001;
    }));

    usort($rows, static function (array $left, array $right): int {
        return [$left['branch_name'] ?? '', $left['ingredient_name'] ?? ''] <=> [$right['branch_name'] ?? '', $right['ingredient_name'] ?? ''];
    });

    return $rows;
}

function bakeshopPrintSummaryBranchGroups(array $input = []): array
{
    $rows = bakeshopPrintSummaryRows($input);
    $branches = bakeshopUsageBranchOptions();
    $decimalPlaces = bakeshopUsageDecimalPlaces();
    $groups = [];

    foreach ($rows as $row) {
        $branchId = (int)($row['branch_id'] ?? 0);
        if (!isset($groups[$branchId])) {
            $groups[$branchId] = [
                'branch_id' => $branchId,
                'branch_label' => bakeshopUsageResolveBranchLabel(['branch_id' => $branchId], $branches) ?? (string)($row['branch_name'] ?? 'Branch'),
                'rows' => [],
                'totals' => [
                    'beginning_balance' => 0.0,
                    'total_delivery' => 0.0,
                    'total_usage' => 0.0,
                    'remaining_balance' => 0.0,
                ],
            ];
        }

        foreach (['beginning_balance', 'total_delivery', 'total_usage', 'remaining_balance'] as $field) {
            $groups[$branchId]['totals'][$field] += (float)($row[$field] ?? 0);
        }

        $formattedRow = $row;
        foreach (['beginning_balance', 'total_delivery', 'total_usage', 'remaining_balance'] as $field) {
            $formattedRow[$field] = number_format((float)($row[$field] ?? 0), $decimalPlaces, '.', '');
        }
        $groups[$branchId]['rows'][] = $formattedRow;
    }

    foreach ($groups as &$group) {
        foreach (['beginning_balance', 'total_delivery', 'total_usage', 'remaining_balance'] as $field) {
            $group['totals'][$field] = number_format((float)($group['totals'][$field] ?? 0), $decimalPlaces, '.', '');
        }
    }
    unset($group);

    usort($groups, static fn (array $left, array $right): int => strcmp((string)($left['branch_label'] ?? ''), (string)($right['branch_label'] ?? '')));

    return $groups;
}

function bakeshopUsageFactualSummary(array $input = []): array
{
    $filters = bakeshopUsageNormalizeFilters($input);
    $decimalPlaces = bakeshopUsageDecimalPlaces();
    $summaryRows = bakeshopPrintSummaryRows($filters);

    $deliveredQtyBase = 0.0;
    $consumedQtyBase = 0.0;
    $inventoryOnHandQtyBase = 0.0;
    foreach ($summaryRows as $row) {
        $deliveredQtyBase += (float)($row['total_delivery'] ?? 0);
        $consumedQtyBase += (float)($row['total_usage'] ?? 0);
        $inventoryOnHandQtyBase += (float)($row['remaining_balance'] ?? 0);
    }

    $deliveryWhere = [];
    $deliveryBindings = [];
    if ($filters['branch_id'] !== null) {
        $deliveryWhere[] = 'd.branch_id = :branch_id';
        $deliveryBindings[':branch_id'] = $filters['branch_id'];
    }
    if ($filters['from_date'] !== null) {
        $deliveryWhere[] = 'DATE(d.delivered_at) >= :from_date';
        $deliveryBindings[':from_date'] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null) {
        $deliveryWhere[] = 'DATE(d.delivered_at) <= :to_date';
        $deliveryBindings[':to_date'] = $filters['to_date'];
    }

    $deliverySql = 'SELECT COUNT(*) AS aggregate_count
        FROM bakeshop_delivery_items di
        INNER JOIN bakeshop_deliveries d ON d.id = di.delivery_id';
    if ($deliveryWhere !== []) {
        $deliverySql .= ' WHERE ' . implode(' AND ', $deliveryWhere);
    }
    $deliveryCount = (int)((bakeshopCatalogFetchOne($deliverySql, $deliveryBindings)['aggregate_count'] ?? 0));

    $productionWhere = [];
    $productionBindings = [];
    if ($filters['branch_id'] !== null) {
        $productionWhere[] = 'branch_id = :branch_id';
        $productionBindings[':branch_id'] = $filters['branch_id'];
    }
    if ($filters['from_date'] !== null) {
        $productionWhere[] = 'DATE(produced_at) >= :from_date';
        $productionBindings[':from_date'] = $filters['from_date'];
    }
    if ($filters['to_date'] !== null) {
        $productionWhere[] = 'DATE(produced_at) <= :to_date';
        $productionBindings[':to_date'] = $filters['to_date'];
    }

    $productionSql = 'SELECT COUNT(*) AS aggregate_count FROM bakeshop_production_runs WHERE voided_at IS NULL';
    if ($productionWhere !== []) {
        $productionSql .= ' AND ' . implode(' AND ', $productionWhere);
    }
    $productionRunCount = (int)((bakeshopCatalogFetchOne($productionSql, $productionBindings)['aggregate_count'] ?? 0));

    return [
        'ingredient_count' => count($summaryRows),
        'delivery_item_count' => $deliveryCount,
        'production_run_count' => $productionRunCount,
        'delivered_qty_base' => round($deliveredQtyBase, $decimalPlaces),
        'consumed_qty_base' => round($consumedQtyBase, $decimalPlaces),
        'variance_qty_base' => round($deliveredQtyBase - $consumedQtyBase, $decimalPlaces),
        'inventory_on_hand_qty_base' => round($inventoryOnHandQtyBase, $decimalPlaces),
    ];
}

function bakeshopInventorySnapshotRows(array $input = []): array
{
    $filters = bakeshopUsageNormalizeFilters($input);
    $where = [];
    $bindings = [];
    $effectiveToDate = $filters['to_date'] ?? $filters['from_date'];

    if ($filters['branch_id'] !== null) {
        $where[] = 'branch_id = :branch_id';
        $bindings[':branch_id'] = $filters['branch_id'];
    }
    if ($effectiveToDate !== null) {
        $where[] = 'period_date <= :to_date';
        $bindings[':to_date'] = $effectiveToDate;
    }

    $sql = 'SELECT
                branch_id,
                branch_name,
                ingredient_id,
                ingredient_name,
                dimension,
                SUM(delivered_qty_base) AS delivered_qty_base,
                SUM(consumed_qty_base) AS consumed_qty_base,
                SUM(variance_qty_base) AS on_hand_qty_base
            FROM bakeshop_ingredient_usage';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY branch_id, branch_name, ingredient_id, ingredient_name, dimension
              HAVING ABS(SUM(delivered_qty_base)) > 0.0000001
                  OR ABS(SUM(consumed_qty_base)) > 0.0000001
                  OR ABS(SUM(variance_qty_base)) > 0.0000001
              ORDER BY branch_name ASC, ingredient_name ASC LIMIT 500';

    return bakeshopCatalogFetchAll($sql, $bindings);
}

function bakeshopInventorySnapshotTotals(array $rows): array
{
    $decimalPlaces = bakeshopUsageDecimalPlaces();
    $onHandTotal = 0.0;

    foreach ($rows as $row) {
        $onHandTotal += (float)($row['on_hand_qty_base'] ?? 0);
    }

    return [
        'on_hand_qty_base' => number_format($onHandTotal, $decimalPlaces, '.', ''),
        'item_count' => count($rows),
    ];
}

function bakeshopInventorySnapshotFormatRows(array $rows): array
{
    $decimalPlaces = bakeshopUsageDecimalPlaces();

    foreach ($rows as &$row) {
        foreach (['delivered_qty_base', 'consumed_qty_base', 'on_hand_qty_base'] as $field) {
            $row[$field] = number_format((float)($row[$field] ?? 0), $decimalPlaces, '.', '');
        }
    }
    unset($row);

    return $rows;
}

function bakeshopApiUsageIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');

        $filters = bakeshopUsageNormalizeFilters(bakeshopInput());
        $items = bakeshopUsageFormatRows(bakeshopUsageReportRows($filters));
        $inventoryItems = bakeshopInventorySnapshotFormatRows(bakeshopInventorySnapshotRows($filters));

        bakeshopJsonOk([
            'items' => $items,
            'filters' => $filters,
            'branches' => bakeshopUsageBranchOptions(),
            'totals' => bakeshopUsageTotals($items),
            'factual_summary' => bakeshopUsageFactualSummary($filters),
            'inventory' => [
                'items' => $inventoryItems,
                'totals' => bakeshopInventorySnapshotTotals($inventoryItems),
            ],
            'settings' => array_merge(bakeshopSettings(), [
                'usage_decimal_places' => bakeshopUsageDecimalPlaces(),
                'print_template' => bakeshopPrintTemplate(),
            ]),
        ]);
    });
}