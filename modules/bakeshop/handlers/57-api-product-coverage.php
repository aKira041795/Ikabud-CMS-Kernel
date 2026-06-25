<?php

declare(strict_types=1);

// ──────────────────────────────────────────────
//  Allocation CRUD
// ──────────────────────────────────────────────

function bakeshopAllocationSelectColumns(): string
{
    return 'a.id,
            a.branch_id,
            a.product_id,
            a.allocated_date,
            a.days_worth,
            a.notes,
            a.created_by,
            a.created_at,
            a.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.name AS product_name';
}

function bakeshopAllocationList(array $filters = []): array
{
    $where = [];
    $bindings = [];

    if (($filters['branch_id'] ?? null) !== null) {
        $where[] = 'a.branch_id = :branch_id';
        $bindings[':branch_id'] = $filters['branch_id'];
    }
    if (($filters['product_id'] ?? null) !== null) {
        $where[] = 'a.product_id = :product_id';
        $bindings[':product_id'] = $filters['product_id'];
    }
    if (($filters['from_date'] ?? null) !== null) {
        $where[] = 'a.allocated_date >= :from_date';
        $bindings[':from_date'] = $filters['from_date'];
    }
    if (($filters['to_date'] ?? null) !== null) {
        $where[] = 'a.allocated_date <= :to_date';
        $bindings[':to_date'] = $filters['to_date'];
    }

    $sql = 'SELECT ' . bakeshopAllocationSelectColumns() . '
            FROM bakeshop_product_allocations a
            INNER JOIN bakeshop_branches b ON b.id = a.branch_id
            INNER JOIN bakeshop_products p ON p.id = a.product_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY a.allocated_date DESC, a.id DESC';

    return bakeshopCatalogFetchAll($sql, $bindings);
}

function bakeshopAllocationCreate(array $input): array
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    $productId = bakeshopCatalogRequirePositiveInt($input['product_id'] ?? null, 'product_id');

    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);
    bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);

    $allocatedDateRaw = trim((string)($input['allocated_date'] ?? ''));
    if ($allocatedDateRaw === '') {
        throw new InvalidArgumentException('allocated_date is required.');
    }
    $allocatedDate = (new DateTimeImmutable($allocatedDateRaw))->format('Y-m-d');

    $daysWorthRaw = $input['days_worth'] ?? null;
    if ($daysWorthRaw === null || (string)$daysWorthRaw === '') {
        throw new InvalidArgumentException('days_worth is required.');
    }
    if (!is_numeric($daysWorthRaw) || (float)$daysWorthRaw <= 0) {
        throw new InvalidArgumentException('days_worth must be a positive number.');
    }
    $daysWorth = number_format((float)$daysWorthRaw, 4, '.', '');

    $notes = trim((string)($input['notes'] ?? ''));
    $createdBy = trim((string)($input['created_by'] ?? ''));

    $db = bakeshopDb();
    $stmt = $db->prepare(
        'INSERT INTO bakeshop_product_allocations (branch_id, product_id, allocated_date, days_worth, notes, created_by)
         VALUES (:branch_id, :product_id, :allocated_date, :days_worth, :notes, :created_by)'
    );
    $stmt->execute([
        ':branch_id' => $branchId,
        ':product_id' => $productId,
        ':allocated_date' => $allocatedDate,
        ':days_worth' => $daysWorth,
        ':notes' => $notes !== '' ? $notes : null,
        ':created_by' => $createdBy !== '' ? $createdBy : null,
    ]);

    $id = (int)$db->lastInsertId();

    return bakeshopCatalogFetchOne(
        'SELECT ' . bakeshopAllocationSelectColumns() . '
         FROM bakeshop_product_allocations a
         INNER JOIN bakeshop_branches b ON b.id = a.branch_id
         INNER JOIN bakeshop_products p ON p.id = a.product_id
         WHERE a.id = :id LIMIT 1',
        [':id' => $id]
    ) ?? [];
}

function bakeshopAllocationDelete(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');

    $row = bakeshopCatalogFetchOne(
        'SELECT ' . bakeshopAllocationSelectColumns() . '
         FROM bakeshop_product_allocations a
         INNER JOIN bakeshop_branches b ON b.id = a.branch_id
         INNER JOIN bakeshop_products p ON p.id = a.product_id
         WHERE a.id = :id LIMIT 1',
        [':id' => $id]
    );

    if ($row === null) {
        throw new InvalidArgumentException('Allocation not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_product_allocations WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $row;
}

// ──────────────────────────────────────────────
//  Product Coverage Report
// ──────────────────────────────────────────────

function bakeshopProductCoverageNormalizeFilters(array $input): array
{
    $filters = [
        'branch_id' => null,
        'from_date' => null,
        'to_date' => null,
    ];

    $branchId = trim((string)($input['branch_id'] ?? ''));
    if ($branchId !== '') {
        $filters['branch_id'] = bakeshopCatalogRequirePositiveInt($branchId, 'branch_id');
        bakeshopCatalogAssertRecordExists('bakeshop_branches', $filters['branch_id']);
    }

    $fromRaw = trim((string)($input['from_date'] ?? ''));
    if ($fromRaw !== '') {
        $filters['from_date'] = (new DateTimeImmutable($fromRaw))->format('Y-m-d');
    }

    $toRaw = trim((string)($input['to_date'] ?? ''));
    if ($toRaw !== '') {
        $filters['to_date'] = (new DateTimeImmutable($toRaw))->format('Y-m-d');
    }

    if ($filters['from_date'] !== null && $filters['to_date'] !== null && $filters['from_date'] > $filters['to_date']) {
        throw new InvalidArgumentException('from_date cannot be after to_date.');
    }

    return $filters;
}

function bakeshopProductCoverageReport(array $input = []): array
{
    $filters = bakeshopProductCoverageNormalizeFilters($input);

    if ($filters['branch_id'] === null) {
        return [
            'branch_id' => null,
            'branch_name' => null,
            'from_date' => $filters['from_date'],
            'to_date' => $filters['to_date'],
            'products' => [],
            'summary' => [
                'total_products' => 0,
                'allocated' => 0,
                'not_allocated' => 0,
            ],
        ];
    }

    // Resolve branch name
    $branch = bakeshopCatalogFetchOne(
        'SELECT id, code, name FROM bakeshop_branches WHERE id = :id LIMIT 1',
        [':id' => $filters['branch_id']]
    );
    $branchName = $branch !== null
        ? (trim((string)($branch['code'] ?? '')) !== '' ? ($branch['code'] . ' - ' . $branch['name']) : ($branch['name'] ?? ''))
        : 'Unknown';

    // Default date range: current week (Mon-Sun)
    if ($filters['from_date'] === null) {
        $filters['from_date'] = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    }
    if ($filters['to_date'] === null) {
        $filters['to_date'] = (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
    }

    // 1. Sum allocated days_worth per product in this cycle
    $allocRows = bakeshopCatalogFetchAll(
        'SELECT
            a.product_id,
            p.name AS product_name,
            SUM(a.days_worth) AS total_days_allocated
         FROM bakeshop_product_allocations a
         INNER JOIN bakeshop_products p ON p.id = a.product_id
         WHERE a.branch_id = :branch_id
           AND a.allocated_date BETWEEN :from_date AND :to_date
         GROUP BY a.product_id, p.name
         ORDER BY p.name ASC',
        [
            ':branch_id' => $filters['branch_id'],
            ':from_date' => $filters['from_date'],
            ':to_date' => $filters['to_date'],
        ]
    );

    // 2. Sum production days_worth per product in the same cycle
    $prodDays = bakeshopCatalogFetchAll(
        'SELECT
            pr.product_id,
            COALESCE(SUM(pr.days_worth), 0) AS production_days,
            SUM(pr.qty_produced) AS total_qty_produced
         FROM bakeshop_production_runs pr
         WHERE pr.voided_at IS NULL
           AND pr.branch_id = :branch_id
           AND DATE(pr.produced_at) BETWEEN :from_date AND :to_date
         GROUP BY pr.product_id',
        [
            ':branch_id' => $filters['branch_id'],
            ':from_date' => $filters['from_date'],
            ':to_date' => $filters['to_date'],
        ]
    );

    $prodDaysByProduct = [];
    foreach ($prodDays as $row) {
        $prodDaysByProduct[(int)($row['product_id'] ?? 0)] = [
            'production_days' => (float)($row['production_days'] ?? 0),
            'total_qty_produced' => (float)($row['total_qty_produced'] ?? 0),
        ];
    }

    // 2b. Ledger: fetch allocations and production separately, merge in PHP
    $allocLedger = bakeshopCatalogFetchAll(
        'SELECT
            pa.id,
            pa.product_id,
            pa.allocated_date AS source_date,
            pa.days_worth,
            pa.notes,
            pa.created_at
         FROM bakeshop_product_allocations pa
         WHERE pa.branch_id = :ledger_branch
           AND pa.allocated_date BETWEEN :ledger_from AND :ledger_to
         ORDER BY pa.product_id, pa.allocated_date ASC, pa.created_at ASC',
        [
            ':ledger_branch' => $filters['branch_id'],
            ':ledger_from' => $filters['from_date'],
            ':ledger_to' => $filters['to_date'],
        ]
    );

    $prodLedger = bakeshopCatalogFetchAll(
        'SELECT
            pr.id,
            pr.product_id,
            DATE(pr.produced_at) AS source_date,
            pr.days_worth,
            pr.notes,
            pr.created_at
         FROM bakeshop_production_runs pr
         WHERE pr.voided_at IS NULL
           AND pr.branch_id = :ledger_branch2
           AND DATE(pr.produced_at) BETWEEN :ledger_from2 AND :ledger_to2
         ORDER BY pr.product_id, DATE(pr.produced_at) ASC, pr.created_at ASC',
        [
            ':ledger_branch2' => $filters['branch_id'],
            ':ledger_from2' => $filters['from_date'],
            ':ledger_to2' => $filters['to_date'],
        ]
    );

    $ledgerByProduct = [];

    // Process allocations (positive entries)
    foreach ($allocLedger as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $days = (float)($row['days_worth'] ?? 0);
        $ledgerByProduct[$productId][] = [
            'entry_id' => (int)($row['id'] ?? 0),
            'date' => (string)($row['source_date'] ?? ''),
            'type' => 'allocation',
            'type_label' => 'Allocation',
            'days' => $days,
            'days_display' => '+' . number_format($days, 1, '.', ''),
            'notes' => trim((string)($row['notes'] ?? '')),
            '_sort_date' => (string)($row['source_date'] ?? '') . '_' . ($row['created_at'] ?? ''),
        ];
    }

    // Process production (negative entries)
    foreach ($prodLedger as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $days = (float)($row['days_worth'] ?? 0);
        $entryDays = -1.0 * $days;
        $ledgerByProduct[$productId][] = [
            'entry_id' => (int)($row['id'] ?? 0),
            'date' => (string)($row['source_date'] ?? ''),
            'type' => 'production',
            'type_label' => 'Production',
            'days' => $entryDays,
            'days_display' => number_format($entryDays, 1, '.', ''),
            'notes' => trim((string)($row['notes'] ?? '')),
            '_sort_date' => (string)($row['source_date'] ?? '') . '_' . ($row['created_at'] ?? ''),
        ];
    }

    // Sort each product's ledger by date then created_at
    foreach ($ledgerByProduct as $pid => &$entries) {
        usort($entries, static fn (array $a, array $b): int => ($a['_sort_date'] ?? '') <=> ($b['_sort_date'] ?? ''));
    }
    unset($entries);

    // Compute running balance per product ledger
    foreach ($ledgerByProduct as $pid => &$entries) {
        $balance = 0.0;
        foreach ($entries as &$entry) {
            $balance = round($balance + $entry['days'], 4);
            $entry['balance'] = $balance;
            $entry['balance_display'] = ($balance >= 0 ? '+' : '') . number_format($balance, 1, '.', '');
            unset($entry['_sort_date']);
        }
        unset($entry);
    }
    unset($entries);

    // 3. Compute coverage per allocated product
    $products = [];
    $places = bakeshopUsageDecimalPlaces();

    foreach ($allocRows as $alloc) {
        $productId = (int)($alloc['product_id'] ?? 0);
        $totalDaysAllocated = (float)($alloc['total_days_allocated'] ?? 0);
        $productionInfo = $prodDaysByProduct[$productId] ?? ['production_days' => 0, 'total_qty_produced' => 0.0];
        $productionDays = $productionInfo['production_days'];
        $totalQtyProduced = $productionInfo['total_qty_produced'];

        $remaining = max(-99.0, round($totalDaysAllocated - $productionDays, 1));
        $remainingLabel = $remaining >= 0 ? '+' . number_format($remaining, 1, '.', '') : number_format($remaining, 1, '.', '');

        $status = 'balanced';
        if ($remaining >= 2.0) {
            $status = 'surplus';
        } elseif ($remaining <= -0.5) {
            $status = 'deficit';
        }

        $productLedger = $ledgerByProduct[$productId] ?? [];

        $products[] = [
            'product_id' => $productId,
            'product_name' => (string)($alloc['product_name'] ?? ''),
            'total_days_allocated' => $totalDaysAllocated,
            'days_allocated_label' => number_format($totalDaysAllocated, 1, '.', '') . ' days',
            'production_days' => $productionDays,
            'production_days_label' => number_format($productionDays, 1, '.', '') . ' days',
            'total_qty_produced' => number_format($totalQtyProduced, $places, '.', ''),
            'remaining_days' => $remaining,
            'remaining_label' => $remainingLabel,
            'status' => $status,
            'ledger' => $productLedger,
        ];
    }

    // Sort: deficit first, then surplus, then balanced
    $statusRank = ['deficit' => 0, 'surplus' => 1, 'balanced' => 2];
    usort($products, static function (array $a, array $b) use ($statusRank): int {
        return ($statusRank[$a['status']] ?? 9) <=> ($statusRank[$b['status']] ?? 9);
    });

    $totalAllocated = count($products);

    return [
        'branch_id' => $filters['branch_id'],
        'branch_name' => $branchName,
        'from_date' => $filters['from_date'],
        'to_date' => $filters['to_date'],
        'products' => $products,
        'summary' => [
            'total_products' => count($products),
            'allocated' => $totalAllocated,
            'surplus_count' => count(array_filter($products, static fn (array $p): bool => $p['status'] === 'surplus')),
            'deficit_count' => count(array_filter($products, static fn (array $p): bool => $p['status'] === 'deficit')),
            'balanced_count' => count(array_filter($products, static fn (array $p): bool => $p['status'] === 'balanced')),
        ],
    ];
}

// ──────────────────────────────────────────────
//  Page handler
// ──────────────────────────────────────────────

function bakeshopPageProductCoverage(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        $input = (array)bakeshopInput();

        $report = [];
        $errorMessage = '';
        $branches = bakeshopUsageBranchOptions();

        try {
            $report = bakeshopProductCoverageReport($input);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $filters = [
            'branch_id' => trim((string)($input['branch_id'] ?? '')),
            'from_date' => trim((string)($input['from_date'] ?? '')),
            'to_date' => trim((string)($input['to_date'] ?? '')),
        ];

        echo bakeshopRender('pages/product-coverage.disyl', bakeshopPageContext($user, 'coverage', [
            'page_title' => 'Product Coverage Report',
            'page_intro' => 'Track commissary product allocations against actual production. Allocated days come from commissary delivery records; production days are pulled from the baking log.',
            'report' => $report,
            'branches' => $branches,
            'filters' => $filters,
            'error_message' => $errorMessage,
        ]));
    });
}

function bakeshopPagePrintCoverage(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        $input = (array)bakeshopInput();
        $report = [];
        $errorMessage = '';

        try {
            $report = bakeshopProductCoverageReport($input);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $filters = [
            'branch_id' => trim((string)($input['branch_id'] ?? '')),
            'from_date' => trim((string)($input['from_date'] ?? '')),
            'to_date' => trim((string)($input['to_date'] ?? '')),
        ];

        echo bakeshopRender('pages/print-coverage.disyl', [
            'page_title' => 'Product Coverage Report',
            'base_url' => bakeshopBaseUrl(),
            'brand_settings' => bakeshopBrandSettings(),
            'report' => $report,
            'filters' => $filters,
            'error_message' => $errorMessage,
        ]);
    });
}

function bakeshopApiCoverageCsv(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        $input = (array)bakeshopInput();
        $report = bakeshopProductCoverageReport($input);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="coverage-report.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Branch', $report['branch_name'] ?? '']);
        fputcsv($out, ['Cycle', ($report['from_date'] ?? '') . ' to ' . ($report['to_date'] ?? '')]);
        fputcsv($out, []); // blank row
        fputcsv($out, ['Product', 'Allocated (days)', 'Produced (days)', 'Remaining (days)', 'Status']);

        foreach ($report['products'] ?? [] as $p) {
            fputcsv($out, [
                $p['product_name'] ?? '',
                $p['total_days_allocated'] ?? 0,
                $p['production_days'] ?? 0,
                $p['remaining_days'] ?? 'N/A',
                $p['status'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    });
}

function bakeshopLedgerBatchSave(array $input, array $user): void
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    $fromRaw = trim((string)($input['from_date'] ?? ''));
    $toRaw = trim((string)($input['to_date'] ?? ''));
    if ($fromRaw === '' || $toRaw === '') {
        throw new InvalidArgumentException('from_date and to_date are required.');
    }
    $fromDate = (new DateTimeImmutable($fromRaw))->format('Y-m-d');
    $toDate = (new DateTimeImmutable($toRaw))->format('Y-m-d');

    // Void all existing production runs in this week
    $existingRuns = bakeshopCatalogFetchAll(
        'SELECT id, product_id, DATE(produced_at) AS prod_date FROM bakeshop_production_runs
         WHERE voided_at IS NULL AND branch_id = :bid AND DATE(produced_at) BETWEEN :fd AND :td',
        [':bid' => $branchId, ':fd' => $fromDate, ':td' => $toDate]
    );
    foreach ($existingRuns as $run) {
        $stmt = bakeshopDb()->prepare('UPDATE bakeshop_production_runs SET voided_at = NOW(), voided_by = :vb, void_reason = :vr WHERE id = :id');
        $stmt->execute([
            ':id' => (int)$run['id'],
            ':vb' => $user['full_name'] ?? $user['username'] ?? '',
            ':vr' => 'Ledger batch replace',
        ]);
    }

    // Delete existing allocations in this week
    bakeshopDb()->execute(
        'DELETE FROM bakeshop_product_allocations WHERE branch_id = :bid AND allocated_date BETWEEN :fd AND :td',
        [':bid' => $branchId, ':fd' => $fromDate, ':td' => $toDate]
    );

    $createdBy = $user['full_name'] ?? $user['username'] ?? '';

    // Process allocations from form: alloc[product_id]=days_worth
    $allocInput = $input['alloc'] ?? null;
    if (is_array($allocInput)) {
        foreach ($allocInput as $pid => $dw) {
            $dw = trim((string)$dw);
            if ($dw === '' || (float)$dw <= 0) continue;
            bakeshopAllocationCreate([
                'branch_id' => $branchId,
                'product_id' => (int)$pid,
                'allocated_date' => $fromDate,
                'days_worth' => $dw,
                'created_by' => $createdBy,
            ]);
        }
    }

    // Process production from form: prod[product_id][date]=days_worth
    $prodInput = $input['prod'] ?? null;
    if (is_array($prodInput)) {
        foreach ($prodInput as $pid => $days) {
            if (!is_array($days)) continue;
            foreach ($days as $date => $dw) {
                $dw = trim((string)$dw);
                if ($dw === '' || (float)$dw <= 0) continue;
                bakeshopProductionCreate([
                    'branch_id' => $branchId,
                    'product_id' => (int)$pid,
                    'produced_at' => $date,
                    'days_worth' => $dw,
                    'qty_produced' => '1',
                    'produced_by' => $createdBy,
                ]);
            }
        }
    }
}

function bakeshopLedgerSingleSave(array $input, array $user): void
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    $cellType = trim((string)($input['cell_type'] ?? ''));
    $productId = bakeshopCatalogRequirePositiveInt($input['product_id'] ?? null, 'product_id');
    $val = trim((string)($input['value'] ?? ''));
    $createdBy = $user['full_name'] ?? $user['username'] ?? '';

    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);
    bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);

    if ($cellType === 'alloc') {
        // Replace allocation for this product+branch+week
        $fd = trim((string)($input['from_date'] ?? ''));
        $td = trim((string)($input['to_date'] ?? ''));
        if ($fd !== '' && $td !== '') {
            bakeshopDb()->execute(
                'DELETE FROM bakeshop_product_allocations WHERE branch_id = :bid AND product_id = :pid AND allocated_date BETWEEN :fd AND :td',
                [':bid' => $branchId, ':pid' => $productId, ':fd' => $fd, ':td' => $td]
            );
        }
        if ($val !== '' && (float)$val > 0) {
            bakeshopAllocationCreate([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'allocated_date' => $fd,
                'days_worth' => $val,
                'created_by' => $createdBy,
            ]);
        }
    } elseif ($cellType === 'prod') {
        $date = trim((string)($input['date'] ?? ''));
        if ($date === '') {
            throw new InvalidArgumentException('date is required for production cells.');
        }
        // Void any existing run for this product+branch+date
        $existingRun = bakeshopCatalogFetchOne(
            'SELECT id FROM bakeshop_production_runs WHERE voided_at IS NULL AND branch_id = :bid AND product_id = :pid AND DATE(produced_at) = :dt LIMIT 1',
            [':bid' => $branchId, ':pid' => $productId, ':dt' => $date]
        );
        if ($existingRun) {
            $stmt = bakeshopDb()->prepare('UPDATE bakeshop_production_runs SET voided_at = NOW(), voided_by = :vb, void_reason = :vr WHERE id = :id');
            $stmt->execute([':id' => (int)$existingRun['id'], ':vb' => $createdBy, ':vr' => 'Cell auto-save replace']);
        }
        if ($val !== '' && (float)$val > 0) {
            bakeshopProductionCreate([
                'branch_id' => $branchId,
                'product_id' => $productId,
                'produced_at' => $date,
                'days_worth' => $val,
                'qty_produced' => '1',
                'produced_by' => $createdBy,
            ]);
        }
    }
}

function bakeshopPageProductLedger(array $params = []): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'POST') {
        bakeshopResponseGuard(static function (): void {
            bakeshopEnforceCsrf();
            $user = bakeshopCurrentUser('bakeshop.manage');
            $input = (array)bakeshopInput();
            $action = trim((string)($input['_action'] ?? 'batch_save'));

            if ($action === 'single_save') {
                try {
                    bakeshopLedgerSingleSave($input, $user);
                    bakeshopJsonOk(['saved' => true]);
                } catch (Throwable $e) {
                    bakeshopJsonError($e->getMessage());
                }
                return;
            }

            $error = '';
            try {
                bakeshopLedgerBatchSave($input, $user);
            } catch (Throwable $e) {
                $error = '&error=' . rawurlencode($e->getMessage());
            }
            $bid = rawurlencode((string)($input['branch_id'] ?? ''));
            $fd = rawurlencode((string)($input['from_date'] ?? ''));
            $td = rawurlencode((string)($input['to_date'] ?? ''));
            app()->redirect(bakeshopBaseUrl() . '/admin/bakeshop/ledger?branch_id=' . $bid . '&from_date=' . $fd . '&to_date=' . $td . $error);
        });
        return;
    }

    bakeshopResponseGuard(static function (): void {
        $user = bakeshopCurrentUser('bakeshop.read');
        $input = (array)bakeshopInput();

        $report = [];
        $errorMessage = '';
        $branches = bakeshopUsageBranchOptions();

        $errorRaw = trim((string)($input['error'] ?? ''));
        if ($errorRaw !== '') {
            $errorMessage = rawurldecode($errorRaw);
        }

        try {
            $report = bakeshopProductCoverageReport($input);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        $filters = [
            'branch_id' => trim((string)($input['branch_id'] ?? '')),
            'from_date' => trim((string)($input['from_date'] ?? '')),
            'to_date' => trim((string)($input['to_date'] ?? '')),
        ];

        if ($filters['branch_id'] === '' && $filters['from_date'] === '' && $filters['to_date'] === '') {
            $filters['from_date'] = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
            $filters['to_date'] = (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
        }

        // Build date columns for the week
        $dateCols = [];
        $dayLabels = [];
        $weekStart = $filters['from_date'] !== '' ? new DateTimeImmutable($filters['from_date']) : new DateTimeImmutable('monday this week');
        $weekEnd = $filters['to_date'] !== '' ? new DateTimeImmutable($filters['to_date']) : new DateTimeImmutable('sunday this week');
        $cursor = $weekStart;
        while ($cursor <= $weekEnd) {
            $dateCols[] = $cursor->format('Y-m-d');
            $dayLabels[] = $cursor->format('D j'); // e.g. "Mon 22"
            $cursor = $cursor->modify('+1 day');
        }

        // Build flat grid rows for DiSyL template
        $reportByPid = [];
        foreach (($report['products'] ?? []) as $p) {
            $reportByPid[(int)$p['product_id']] = $p;
        }

        $gridRows = [];
        $products = bakeshopCatalogListProducts();
        foreach ($products as $product) {
            $pid = (int)($product['id'] ?? 0);
            $rp = $reportByPid[$pid] ?? null;
            $row = [
                'id' => $pid,
                'name' => (string)($product['name'] ?? ''),
                'alloc_val' => $rp !== null ? (string)($rp['total_days_allocated'] ?? '') : '',
                'prod_total' => $rp !== null ? ((string)($rp['production_days_label'] ?? '0.0 days')) : '0.0 days',
                'remaining' => $rp !== null ? ((string)($rp['remaining_label'] ?? 'N/A')) : 'N/A',
                'status' => $rp !== null ? ((string)($rp['status'] ?? 'none')) : 'none',
            ];
            // Per-day values and field names
            $ledger = $rp !== null ? ($rp['ledger'] ?? []) : [];
            $dayProd = [];
            foreach ($ledger as $entry) {
                if ($entry['type'] === 'production') {
                    $dayProd[$entry['date']] = abs((float)($entry['days'] ?? 0));
                }
            }
            foreach ($dateCols as $i => $col) {
                $idx = 'day_' . $i;
                $row[$idx . '_val'] = isset($dayProd[$col]) ? (string)$dayProd[$col] : '';
                $row[$idx . '_name'] = 'prod[' . $pid . '][' . $col . ']';
            }
            $gridRows[] = $row;
        }

        echo bakeshopRender('pages/product-ledger.disyl', bakeshopPageContext($user, 'ledger', [
            'page_title' => 'Product Ledger',
            'page_intro' => 'Enter weekly allocation and daily production. Save to update the Coverage Summary report.',
            'branches' => $branches,
            'filters' => $filters,
            'date_cols' => $dateCols,
            'day_labels' => $dayLabels,
            'grid_rows' => $gridRows,
            'error_message' => $errorMessage,
        ]));
    });
}

// ──────────────────────────────────────────────
//  API handlers
// ──────────────────────────────────────────────

function bakeshopApiProductCoverage(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        $input = bakeshopInput();
        bakeshopJsonOk([
            'report' => bakeshopProductCoverageReport($input),
        ]);
    });
}

function bakeshopApiAllocationsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        $input = bakeshopInput();
        bakeshopJsonOk([
            'items' => bakeshopAllocationList($input),
        ]);
    });
}

function bakeshopApiAllocationsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopAllocationCreate(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['coverage'], 201);
    });
}

function bakeshopApiAllocationsDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopAllocationDelete(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['coverage']);
    });
}
