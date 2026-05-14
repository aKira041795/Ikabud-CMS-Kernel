<?php

declare(strict_types=1);

function bakeshopDrProjectionNormalizeFilters(array $input): array
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);

    $fromDateRaw = trim((string)($input['from_date'] ?? ''));
    if ($fromDateRaw === '') {
        throw new InvalidArgumentException('from_date is required.');
    }

    $toDateRaw = trim((string)($input['to_date'] ?? ''));
    if ($toDateRaw === '') {
        throw new InvalidArgumentException('to_date is required.');
    }

    $fromDate = (new DateTimeImmutable($fromDateRaw))->format('Y-m-d');
    $toDate = (new DateTimeImmutable($toDateRaw))->format('Y-m-d');
    if ($fromDate > $toDate) {
        throw new InvalidArgumentException('from_date cannot be after to_date.');
    }

    $horizonDays = ($input['horizon_days'] ?? null) !== null && (string)($input['horizon_days'] ?? '') !== ''
        ? bakeshopCatalogRequirePositiveInt($input['horizon_days'], 'horizon_days')
        : 7;
    if ($horizonDays > 31) {
        throw new InvalidArgumentException('horizon_days must be between 1 and 31.');
    }

    return [
        'branch_id' => $branchId,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'horizon_days' => $horizonDays,
    ];
}

function bakeshopDrProjectionWindowDays(array $filters): int
{
    $fromDate = new DateTimeImmutable((string)$filters['from_date']);
    $toDate = new DateTimeImmutable((string)$filters['to_date']);

    return max(1, ((int)$fromDate->diff($toDate)->days) + 1);
}

function bakeshopDrProjectionDecimalPlaces(): int
{
    $settings = bakeshopSettings();
    return max(0, min(4, (int)($settings['usage_decimal_places'] ?? 2)));
}

function bakeshopDrProjectionFormatCompactNumber(float $value, int $places): string
{
    $formatted = number_format($value, $places, '.', '');
    if ($places <= 0) {
        return $formatted;
    }

    $trimmed = rtrim(rtrim($formatted, '0'), '.');
    return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
}

function bakeshopDrProjectionFormatPerPack(float $projectedQtyBase, array $ingredient, int $places): ?string
{
    $packLabel = trim((string)($ingredient['pack_label'] ?? ''));
    $packQty = (float)($ingredient['pack_qty'] ?? 0);
    $packUnitFactor = (float)($ingredient['pack_unit_factor_to_base'] ?? 0);
    $defaultUnitFactor = (float)($ingredient['default_unit_factor_to_base'] ?? 0);
    $defaultUnitCode = strtoupper(trim((string)($ingredient['default_unit_code'] ?? '')));

    if ($packLabel === '' || $packQty <= 0 || $packUnitFactor <= 0 || $defaultUnitFactor <= 0) {
        return null;
    }

    $packSizeBase = $packQty * $packUnitFactor;
    if ($packSizeBase <= 0) {
        return null;
    }

    $wholePacks = (int)floor(($projectedQtyBase / $packSizeBase) + 0.0000001);
    $remainderBase = $projectedQtyBase - ($wholePacks * $packSizeBase);
    if (abs($remainderBase) < 0.000001) {
        $remainderBase = 0.0;
    }

    $parts = [];
    if ($wholePacks > 0) {
        $parts[] = $wholePacks . ' ' . strtoupper($packLabel);
    }
    if ($remainderBase > 0) {
        $remainderQty = $remainderBase / $defaultUnitFactor;
        $parts[] = bakeshopDrProjectionFormatCompactNumber($remainderQty, $places) . ' ' . ($defaultUnitCode !== '' ? $defaultUnitCode : 'UNIT');
    }

    if ($parts === []) {
        return '0';
    }

    return implode(' + ', $parts);
}

function bakeshopDrProjectionIngredientRows(array $filters): array
{
    $coverageExpression = bakeshopDeliveriesHasCoverageDaysColumn() ? 'd.coverage_days' : '1';
    $rawRows = bakeshopCatalogFetchAll(
        'SELECT
            d.id AS delivery_id,
            di.id AS delivery_item_id,
            di.ingredient_id,
            i.name AS ingredient_name,
            i.default_unit_id,
            du.code AS default_unit_code,
            du.name AS default_unit_name,
            du.dimension AS unit_dimension,
            du.factor_to_base AS default_unit_factor_to_base
            ' . bakeshopCatalogIngredientPackSelectSql('i', 'pu') . '
            ,
            (di.qty * u.factor_to_base) AS delivered_qty_base,
            ' . $coverageExpression . ' AS observed_coverage_days
         FROM bakeshop_deliveries d
         INNER JOIN bakeshop_delivery_items di ON di.delivery_id = d.id
         INNER JOIN bakeshop_units u ON u.id = di.unit_id
         INNER JOIN bakeshop_ingredients i ON i.id = di.ingredient_id
         INNER JOIN bakeshop_units du ON du.id = i.default_unit_id
         ' . bakeshopCatalogIngredientPackJoinSql('i', 'pu') . '
         WHERE d.branch_id = :branch_id
           AND DATE(d.delivered_at) BETWEEN :from_date AND :to_date
         ORDER BY i.name ASC',
        [
            ':branch_id' => $filters['branch_id'],
            ':from_date' => $filters['from_date'],
            ':to_date' => $filters['to_date'],
        ]
    );

    $rows = [];
    foreach ($rawRows as $rawRow) {
        $ingredientId = (int)($rawRow['ingredient_id'] ?? 0);
        $deliveryId = (int)($rawRow['delivery_id'] ?? 0);
        $lineDeliveredQtyBase = (float)($rawRow['delivered_qty_base'] ?? 0);
        $lineCoverageDays = (int)($rawRow['observed_coverage_days'] ?? 0);
        if ($ingredientId <= 0 || $deliveryId <= 0) {
            continue;
        }

        if (!isset($rows[$ingredientId])) {
            $rawRow['delivered_qty_base'] = 0.0;
            $rawRow['observed_coverage_days'] = 0;
            $rawRow['_delivery_ids'] = [];
            $rows[$ingredientId] = $rawRow;
        }

        $rows[$ingredientId]['delivered_qty_base'] += $lineDeliveredQtyBase;
        if (!isset($rows[$ingredientId]['_delivery_ids'][$deliveryId])) {
            $rows[$ingredientId]['observed_coverage_days'] += $lineCoverageDays;
            $rows[$ingredientId]['_delivery_ids'][$deliveryId] = true;
        }
    }

    $rows = array_values($rows);
    $places = bakeshopDrProjectionDecimalPlaces();
    foreach ($rows as &$row) {
        unset($row['delivery_id'], $row['delivery_item_id'], $row['_delivery_ids']);
        $observedCoverageDays = max(1.0, (float)($row['observed_coverage_days'] ?? 0));
        $deliveredQtyBase = (float)($row['delivered_qty_base'] ?? 0);
        $dailyQtyBase = $deliveredQtyBase / $observedCoverageDays;
        $projectedQtyBase = $dailyQtyBase * (int)$filters['horizon_days'];
        $defaultUnitFactor = (float)($row['default_unit_factor_to_base'] ?? 0);
        $projectedQty = $defaultUnitFactor > 0 ? ($projectedQtyBase / $defaultUnitFactor) : $projectedQtyBase;

        $row['observed_coverage_days'] = (int)round($observedCoverageDays);
        $row['daily_qty_base'] = $dailyQtyBase;
        $row['projected_qty_base'] = $projectedQtyBase;
        $row['projected_qty'] = $projectedQty;
        $row['projected_qty_display'] = number_format($projectedQty, $places, '.', '');
        $row['per_pack_display'] = bakeshopDrProjectionFormatPerPack($projectedQtyBase, $row, $places);
    }
    unset($row);

    return $rows;
}

function bakeshopDrProjectionProductRows(array $filters): array
{
    $windowDays = bakeshopDrProjectionWindowDays($filters);
    $rows = bakeshopCatalogFetchAll(
        'SELECT
            t.id,
            t.branch_id,
            t.product_id,
            t.daily_qty,
            t.unit_id,
            t.is_active,
            p.name AS product_name,
            p.category AS product_category,
            u.code AS unit_code,
            u.name AS unit_name,
            COUNT(DISTINCT CASE WHEN pr.qty_produced > 0 THEN DATE(pr.produced_at) END) AS days_produced
         FROM bakeshop_branch_product_targets t
         INNER JOIN bakeshop_products p ON p.id = t.product_id
         INNER JOIN bakeshop_units u ON u.id = t.unit_id
         LEFT JOIN bakeshop_production_runs pr
             ON pr.branch_id = t.branch_id
            AND pr.product_id = t.product_id
            AND pr.voided_at IS NULL
            AND DATE(pr.produced_at) BETWEEN :from_date AND :to_date
         WHERE t.branch_id = :branch_id
           AND t.is_active = 1
         GROUP BY
            t.id,
            t.branch_id,
            t.product_id,
            t.daily_qty,
            t.unit_id,
            t.is_active,
            p.name,
            p.category,
            u.code,
            u.name
         ORDER BY p.name ASC',
        [
            ':branch_id' => $filters['branch_id'],
            ':from_date' => $filters['from_date'],
            ':to_date' => $filters['to_date'],
        ]
    );

    $places = bakeshopDrProjectionDecimalPlaces();
    foreach ($rows as &$row) {
        $daysProduced = min($windowDays, max(0, (int)($row['days_produced'] ?? 0)));
        $dailyQty = (float)($row['daily_qty'] ?? 0);
        $projectedQty = $windowDays > 0
            ? (($dailyQty * $daysProduced * (int)$filters['horizon_days']) / $windowDays)
            : 0.0;

        $row['days_produced'] = $daysProduced;
        $row['window_days'] = $windowDays;
        $row['missing_days'] = max(0, $windowDays - $daysProduced);
        $row['has_missing_days'] = $row['missing_days'] > 0;
        $row['projected_qty'] = $projectedQty;
        $row['projected_qty_display'] = number_format($projectedQty, $places, '.', '');
    }
    unset($row);

    return $rows;
}

function bakeshopDrProjectionReport(array $input): array
{
    $filters = bakeshopDrProjectionNormalizeFilters($input);
    $branch = bakeshopDeliveriesFindBranchById((int)$filters['branch_id']);

    return [
        'filters' => $filters,
        'window_days' => bakeshopDrProjectionWindowDays($filters),
        'branch' => $branch,
        'settings' => [
            'usage_decimal_places' => bakeshopDrProjectionDecimalPlaces(),
        ],
        'ingredients' => bakeshopDrProjectionIngredientRows($filters),
        'products' => bakeshopDrProjectionProductRows($filters),
    ];
}

function bakeshopApiDrProjectionIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['report' => bakeshopDrProjectionReport((array)bakeshopInput())]);
    });
}
