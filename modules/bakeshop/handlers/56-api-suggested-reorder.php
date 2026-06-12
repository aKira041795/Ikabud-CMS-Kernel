<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Suggested Reorder — computes reorder quantities from usage data and par levels
// ---------------------------------------------------------------------------

function bakeshopSuggestedReorderNormalizeFilters(array $input): array
{
    $filters = [
        'branch_id' => null,
        'horizon_days' => null,
    ];

    if (($input['branch_id'] ?? null) !== null && (string)$input['branch_id'] !== '') {
        $filters['branch_id'] = bakeshopCatalogRequirePositiveInt($input['branch_id'], 'branch_id');
        bakeshopCatalogAssertRecordExists('bakeshop_branches', $filters['branch_id']);
    }

    $horizonRaw = $input['horizon_days'] ?? null;
    if ($horizonRaw !== null && (string)$horizonRaw !== '') {
        if (!is_numeric($horizonRaw)) {
            throw new InvalidArgumentException('horizon_days must be numeric.');
        }
        $horizonDays = (int)$horizonRaw;
        if ($horizonDays < 1 || $horizonDays > 31) {
            throw new InvalidArgumentException('horizon_days must be between 1 and 31.');
        }
        $filters['horizon_days'] = $horizonDays;
    }

    return $filters;
}

function bakeshopSuggestedReorderHorizonDays(?int $inputHorizon): int
{
    if ($inputHorizon !== null && $inputHorizon > 0) {
        return $inputHorizon;
    }

    $settings = bakeshopSettings();
    $raw = $settings['suggested_reorder_days'] ?? null;
    if ($raw === null || trim((string)$raw) === '') {
        return 7;
    }

    return max(1, min(31, (int)$raw));
}

function bakeshopSuggestedReorderDecimalPlaces(): int
{
    return bakeshopUsageDecimalPlaces();
}

function bakeshopIngredientParLevelBase(?array $ingredient): float
{
    if ($ingredient === null) {
        return 0.0;
    }

    $parLevel = (float)($ingredient['par_level'] ?? 0);
    if ($parLevel <= 0) {
        return 0.0;
    }

    $parUnitId = (int)($ingredient['par_level_unit_id'] ?? 0);
    if ($parUnitId <= 0) {
        // If no par unit specified, assume the par_level is in the ingredient's default unit
        $defaultUnitFactor = (float)($ingredient['default_unit_factor_to_base'] ?? 1);
        return $parLevel * $defaultUnitFactor;
    }

    // Look up the par unit's factor_to_base
    $parUnit = bakeshopCatalogFetchOne(
        'SELECT factor_to_base FROM bakeshop_units WHERE id = :id LIMIT 1',
        [':id' => $parUnitId]
    );

    $factor = $parUnit !== null ? (float)($parUnit['factor_to_base'] ?? 1) : 1.0;
    return $parLevel * $factor;
}

function bakeshopSuggestedReorderCompute(array $input = []): array
{
    $filters = bakeshopSuggestedReorderNormalizeFilters($input);
    $horizonDays = bakeshopSuggestedReorderHorizonDays($filters['horizon_days']);
    $places = bakeshopSuggestedReorderDecimalPlaces();

    // Build WHERE clause for branch filter
    $branchWhere = '';
    $branchBindings = [];
    if ($filters['branch_id'] !== null) {
        $branchWhere = 'AND branch_id = :branch_id';
        $branchBindings[':branch_id'] = $filters['branch_id'];
    }

    // 1. Get ingredients with par levels set
    $ingredients = bakeshopCatalogFetchAll(
        'SELECT
            i.id,
            i.name,
            i.sku,
            i.default_unit_id,
            i.par_level,
            i.par_level_unit_id,
            u.code AS default_unit_code,
            u.dimension AS unit_dimension,
            u.factor_to_base AS default_unit_factor_to_base
            ' . bakeshopCatalogIngredientPackSelectSql('i', 'pu') . '
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         ' . bakeshopCatalogIngredientPackJoinSql('i', 'pu') . '
         WHERE i.par_level IS NOT NULL AND i.par_level > 0 AND i.is_active = 1
         ORDER BY i.name ASC'
    );

    if ($ingredients === []) {
        return [
            'horizon_days' => $horizonDays,
            'items' => [],
            'empty_reason' => 'No ingredients have par levels configured.',
        ];
    }

    // 2. Get on-hand inventory per branch per ingredient (cumulative variance up to now)
    $onHandWhere = '1=1';
    $onHandBindings = [];
    if ($filters['branch_id'] !== null) {
        $onHandWhere = 'branch_id = :branch_id';
        $onHandBindings[':branch_id'] = $filters['branch_id'];
    }

    $onHandRows = bakeshopCatalogFetchAll(
        'SELECT
            branch_id,
            branch_name,
            ingredient_id,
            ingredient_name,
            dimension,
            SUM(variance_qty_base) AS on_hand_qty_base,
            SUM(adjusted_qty_base) AS total_adjusted_qty_base
         FROM bakeshop_ingredient_usage
         WHERE ' . $onHandWhere . '
         GROUP BY branch_id, branch_name, ingredient_id, ingredient_name, dimension
         HAVING ABS(SUM(variance_qty_base)) > 0.0000001',
        $onHandBindings
    );

    // Index on-hand by branch_id:ingredient_id:dimension
    $onHandByKey = [];
    foreach ($onHandRows as $row) {
        $key = implode(':', [
            (int)($row['branch_id'] ?? 0),
            (int)($row['ingredient_id'] ?? 0),
            strtolower((string)($row['dimension'] ?? '')),
        ]);
        $onHandByKey[$key] = $row;
    }

    // 3. Get daily consumption rate from recent usage (last horizon_days * 2 to get good average)
    $lookbackDays = $horizonDays * 3;
    $consumptionRows = bakeshopCatalogFetchAll(
        'SELECT
            branch_id,
            ingredient_id,
            dimension,
            COUNT(DISTINCT period_date) AS active_days,
            SUM(consumed_qty_base) AS total_consumed_qty_base
         FROM bakeshop_ingredient_usage
         WHERE period_date >= DATE_SUB(CURDATE(), INTERVAL :lookback DAY)
           ' . $branchWhere . '
         GROUP BY branch_id, ingredient_id, dimension
         HAVING SUM(consumed_qty_base) > 0',
        array_merge([':lookback' => $lookbackDays], $branchBindings)
    );

    // Index consumption by branch_id:ingredient_id:dimension
    $consumptionByKey = [];
    foreach ($consumptionRows as $row) {
        $key = implode(':', [
            (int)($row['branch_id'] ?? 0),
            (int)($row['ingredient_id'] ?? 0),
            strtolower((string)($row['dimension'] ?? '')),
        ]);
        $consumptionByKey[$key] = $row;
    }

    // 4. Get all branches
    $branches = bakeshopDeliveriesListBranches();
    $branchesById = [];
    foreach ($branches as $branch) {
        $branchesById[(int)($branch['id'] ?? 0)] = $branch;
    }

    // 5. Compute suggested reorder per ingredient per branch
    $items = [];
    foreach ($ingredients as $ingredient) {
        $ingredientId = (int)($ingredient['id'] ?? 0);
        $dimension = strtolower((string)($ingredient['unit_dimension'] ?? ''));
        $parLevelBase = bakeshopIngredientParLevelBase($ingredient);

        foreach ($branches as $branch) {
            $branchId = (int)($branch['id'] ?? 0);
            if ($filters['branch_id'] !== null && $branchId !== $filters['branch_id']) {
                continue;
            }
            if ((int)($branch['is_active'] ?? 1) !== 1) {
                continue;
            }

            $key = implode(':', [$branchId, $ingredientId, $dimension]);
            $onHandRow = $onHandByKey[$key] ?? null;
            $consumptionRow = $consumptionByKey[$key] ?? null;

            $onHandBase = $onHandRow !== null ? (float)($onHandRow['on_hand_qty_base'] ?? 0) : 0.0;
            $totalConsumedBase = $consumptionRow !== null ? (float)($consumptionRow['total_consumed_qty_base'] ?? 0) : 0.0;
            $activeDays = $consumptionRow !== null ? max(1, (int)($consumptionRow['active_days'] ?? 0)) : 0;

            $dailyRateBase = $activeDays > 0 ? ($totalConsumedBase / $activeDays) : 0.0;
            $projectedUsage = $dailyRateBase * $horizonDays;
            $suggestedReorderBase = max(0.0, $parLevelBase - $onHandBase + $projectedUsage);
            $reorderUrgency = 'none';

            if ($parLevelBase <= 0) {
                continue; // skip if par not configured
            }

            if ($onHandBase <= 0) {
                $reorderUrgency = 'critical';
            } elseif ($onHandBase < ($projectedUsage * 1.5)) {
                $reorderUrgency = 'high';
            } elseif ($onHandBase < ($parLevelBase * 0.5)) {
                $reorderUrgency = 'medium';
            } elseif ($suggestedReorderBase > 0) {
                $reorderUrgency = 'low';
            }

            $defaultUnitFactor = (float)($ingredient['default_unit_factor_to_base'] ?? 1);
            $suggestedReorderQty = $defaultUnitFactor > 0 ? ($suggestedReorderBase / $defaultUnitFactor) : $suggestedReorderBase;

            $items[] = [
                'branch_id' => $branchId,
                'branch_code' => (string)($branch['code'] ?? ''),
                'branch_name' => (string)($branch['name'] ?? ''),
                'ingredient_id' => $ingredientId,
                'ingredient_name' => (string)($ingredient['name'] ?? ''),
                'ingredient_sku' => (string)($ingredient['sku'] ?? ''),
                'unit_code' => (string)($ingredient['default_unit_code'] ?? ''),
                'unit_dimension' => $dimension,
                'par_level_base' => number_format($parLevelBase, $places, '.', ''),
                'on_hand_qty_base' => number_format($onHandBase, $places, '.', ''),
                'daily_consumption_rate_base' => number_format($dailyRateBase, $places, '.', ''),
                'projected_usage_base' => number_format($projectedUsage, $places, '.', ''),
                'suggested_reorder_qty_base' => number_format($suggestedReorderBase, $places, '.', ''),
                'suggested_reorder_qty' => number_format($suggestedReorderQty, $places, '.', ''),
                'suggested_reorder_qty_display' => number_format($suggestedReorderQty, $places, '.', '') . ' ' . ($ingredient['default_unit_code'] ?? 'unit'),
                'per_pack_display' => bakeshopDrProjectionFormatPerPack($suggestedReorderBase, $ingredient, $places),
                'urgency' => $reorderUrgency,
                'has_on_hand_data' => $onHandRow !== null,
                'has_consumption_data' => $consumptionRow !== null,
                'active_consumption_days' => $activeDays,
            ];
        }
    }

    // Sort by urgency then ingredient name
    $urgencyRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4];
    usort($items, static function (array $a, array $b) use ($urgencyRank): int {
        $rankA = $urgencyRank[$a['urgency']] ?? 99;
        $rankB = $urgencyRank[$b['urgency']] ?? 99;
        return [$rankA, $a['ingredient_name'], $a['branch_name']]
            <=> [$rankB, $b['ingredient_name'], $b['branch_name']];
    });

    return [
        'horizon_days' => $horizonDays,
        'lookback_days' => $lookbackDays,
        'branch_count' => count($branches),
        'ingredient_count' => count($ingredients),
        'items' => $items,
        'summary' => [
            'critical' => count(array_filter($items, static fn (array $i): bool => $i['urgency'] === 'critical')),
            'high' => count(array_filter($items, static fn (array $i): bool => $i['urgency'] === 'high')),
            'medium' => count(array_filter($items, static fn (array $i): bool => $i['urgency'] === 'medium')),
            'low' => count(array_filter($items, static fn (array $i): bool => $i['urgency'] === 'low')),
            'none' => count(array_filter($items, static fn (array $i): bool => $i['urgency'] === 'none')),
        ],
    ];
}

// -- API handler ------------------------------------------------------------

function bakeshopApiSuggestedReorderIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        $input = bakeshopInput();
        bakeshopJsonOk([
            'report' => bakeshopSuggestedReorderCompute($input),
            'branches' => bakeshopUsageBranchOptions(),
        ]);
    });
}
