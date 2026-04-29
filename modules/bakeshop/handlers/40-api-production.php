<?php

declare(strict_types=1);

function bakeshopProductionSelectColumns(): string
{
    return 'pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.voided_at,
            pr.voided_by,
            pr.void_reason,
            pr.created_at,
            pr.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.name AS product_name,
            p.default_yield_qty,
            u.code AS default_yield_unit_code';
}

function bakeshopProductionGroupByColumns(): string
{
    return 'pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.voided_at,
            pr.voided_by,
            pr.void_reason,
            pr.created_at,
            pr.updated_at,
            b.code,
            b.name,
            p.name,
            p.default_yield_qty,
            u.code';
}

function bakeshopProductionList(): array
{
    $runs = bakeshopCatalogFetchAll(
        'SELECT
            ' . bakeshopProductionSelectColumns() . ',
            COUNT(pi.id) AS consumed_item_count,
            COALESCE(SUM(pi.qty_used), 0) AS total_consumed_qty
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_branches b ON b.id = pr.branch_id
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         LEFT JOIN bakeshop_production_items pi ON pi.run_id = pr.id
         WHERE pr.voided_at IS NULL
         GROUP BY
                ' . bakeshopProductionGroupByColumns() . '
         ORDER BY pr.produced_at DESC, pr.id DESC'
    );

    return bakeshopProductionHydrateCollection($runs);
}

function bakeshopProductionFetchItemsByRunIds(array $runIds): array
{
    if ($runIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($runIds), '?'));
    $rows = bakeshopCatalogFetchAll(
        'SELECT
            pi.run_id,
            pi.id,
            pi.ingredient_id,
            pi.qty_used,
            pi.unit_id,
            i.name AS ingredient_name,
            u.code AS unit_code
         FROM bakeshop_production_items pi
         INNER JOIN bakeshop_ingredients i ON i.id = pi.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = pi.unit_id
         WHERE pi.run_id IN (' . $placeholders . ')
         ORDER BY pi.run_id ASC, pi.id ASC',
        array_values(array_map('intval', $runIds))
    );

    $itemsByRunId = [];
    foreach ($rows as $row) {
        $itemsByRunId[(int)($row['run_id'] ?? 0)][] = $row;
    }

    return $itemsByRunId;
}

function bakeshopProductionHydrateCollection(array $runs): array
{
    $runIds = array_values(array_filter(array_map(static fn (array $run): int => (int)($run['id'] ?? 0), $runs)));
    $itemsByRunId = bakeshopProductionFetchItemsByRunIds($runIds);

    foreach ($runs as &$run) {
        $run['items'] = $itemsByRunId[(int)($run['id'] ?? 0)] ?? [];
    }
    unset($run);

    return $runs;
}

function bakeshopProductionCreate(array $input): array
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    $productId = bakeshopCatalogRequirePositiveInt($input['product_id'] ?? null, 'product_id');
    $qtyProduced = bakeshopCatalogRequirePositiveDecimal($input['qty_produced'] ?? null, 'qty_produced');

    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);
    bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);

    $producedAtRaw = trim((string)($input['produced_at'] ?? ''));
    if ($producedAtRaw === '') {
        throw new InvalidArgumentException('produced_at is required.');
    }

    $producedAt = new DateTimeImmutable($producedAtRaw);
    $producedBy = trim((string)($input['produced_by'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    $product = bakeshopCatalogFetchOne(
        'SELECT id, name, default_yield_qty, default_yield_unit_id FROM bakeshop_products WHERE id = :id LIMIT 1',
        [':id' => $productId]
    );
    if (!$product) {
        throw new InvalidArgumentException('Product not found.');
    }

    $defaultYieldQty = (float)($product['default_yield_qty'] ?? 0);
    if ($defaultYieldQty <= 0) {
        throw new InvalidArgumentException('Product default_yield_qty must be greater than zero before production can be recorded.');
    }

    $recipeItems = bakeshopCatalogFetchAll(
        'SELECT ingredient_id, qty, unit_id FROM bakeshop_product_recipe WHERE product_id = :product_id ORDER BY id ASC',
        [':product_id' => $productId]
    );
    if ($recipeItems === []) {
        throw new InvalidArgumentException('This product has no recipe lines yet.');
    }

    $scale = ((float)$qtyProduced) / $defaultYieldQty;
    $db = bakeshopDb();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'INSERT INTO bakeshop_production_runs (branch_id, product_id, produced_at, qty_produced, produced_by, notes)
             VALUES (:branch_id, :product_id, :produced_at, :qty_produced, :produced_by, :notes)'
        );
        $stmt->execute([
            ':branch_id' => $branchId,
            ':product_id' => $productId,
            ':produced_at' => $producedAt->format('Y-m-d H:i:s'),
            ':qty_produced' => $qtyProduced,
            ':produced_by' => $producedBy !== '' ? $producedBy : null,
            ':notes' => $notes !== '' ? $notes : null,
        ]);

        $runId = (int)$db->lastInsertId();
        $itemStmt = $db->prepare(
            'INSERT INTO bakeshop_production_items (run_id, ingredient_id, qty_used, unit_id)
             VALUES (:run_id, :ingredient_id, :qty_used, :unit_id)'
        );

        foreach ($recipeItems as $recipeItem) {
            $qtyUsed = number_format(((float)$recipeItem['qty']) * $scale, 4, '.', '');
            $itemStmt->execute([
                ':run_id' => $runId,
                ':ingredient_id' => (int)$recipeItem['ingredient_id'],
                ':qty_used' => $qtyUsed,
                ':unit_id' => (int)$recipeItem['unit_id'],
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $run = bakeshopCatalogFetchOne(
        'SELECT
            ' . bakeshopProductionSelectColumns() . '
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_branches b ON b.id = pr.branch_id
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE pr.id = :id LIMIT 1',
        [':id' => $runId]
    ) ?? [];

    $run['items'] = bakeshopCatalogFetchAll(
        'SELECT
            pi.id,
            pi.ingredient_id,
            pi.qty_used,
            pi.unit_id,
            i.name AS ingredient_name,
            u.code AS unit_code
         FROM bakeshop_production_items pi
         INNER JOIN bakeshop_ingredients i ON i.id = pi.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = pi.unit_id
         WHERE pi.run_id = :run_id
         ORDER BY pi.id ASC',
        [':run_id' => $runId]
    );

    app()->events()->fire('bakeshop.production.created', [
        'id' => $runId,
        'branch_id' => $branchId,
        'product_id' => $productId,
        'qty_produced' => (float)$qtyProduced,
        'item_count' => count($recipeItems),
    ]);

    bakeshopAudit('bakeshop.production.created', $branchId, 'bakeshop_production_runs', (string)$runId, null, $run);

    return $run;
}

function bakeshopProductionUpdate(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $run = bakeshopProductionFindById($id, true);
    if ($run === null) {
        throw new InvalidArgumentException('Production run not found.');
    }
    if (trim((string)($run['voided_at'] ?? '')) !== '') {
        throw new InvalidArgumentException('Voided production runs cannot be edited.');
    }

    $producedAtRaw = trim((string)($input['produced_at'] ?? ''));
    if ($producedAtRaw === '') {
        throw new InvalidArgumentException('produced_at is required.');
    }

    $producedAt = new DateTimeImmutable($producedAtRaw);
    $producedBy = trim((string)($input['produced_by'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));

    $stmt = bakeshopDb()->prepare(
        'UPDATE bakeshop_production_runs
         SET produced_at = :produced_at,
             produced_by = :produced_by,
             notes = :notes
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $id,
        ':produced_at' => $producedAt->format('Y-m-d H:i:s'),
        ':produced_by' => $producedBy !== '' ? $producedBy : null,
        ':notes' => $notes !== '' ? $notes : null,
    ]);

    $updatedRun = bakeshopProductionFindById($id, true) ?? $run;

    bakeshopAudit(
        'bakeshop.production.updated',
        (int)($run['branch_id'] ?? 0) ?: null,
        'bakeshop_production_runs',
        (string)$id,
        $run,
        $updatedRun
    );

    return $updatedRun;
}

function bakeshopProductionFindById(int $id, bool $includeVoided = false): ?array
{
    $where = 'pr.id = :id';
    if (!$includeVoided) {
        $where .= ' AND pr.voided_at IS NULL';
    }

    $run = bakeshopCatalogFetchOne(
        'SELECT
            ' . bakeshopProductionSelectColumns() . '
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_branches b ON b.id = pr.branch_id
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE ' . $where . ' LIMIT 1',
        [':id' => $id]
    );
    if ($run === null) {
        return null;
    }

    $run['items'] = bakeshopCatalogFetchAll(
        'SELECT
            pi.id,
            pi.ingredient_id,
            pi.qty_used,
            pi.unit_id,
            i.name AS ingredient_name,
            u.code AS unit_code
         FROM bakeshop_production_items pi
         INNER JOIN bakeshop_ingredients i ON i.id = pi.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = pi.unit_id
         WHERE pi.run_id = :run_id
         ORDER BY pi.id ASC',
        [':run_id' => $id]
    );

    return $run;
}

function bakeshopProductionVoid(array $input, array $actor = []): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $reason = trim((string)($input['void_reason'] ?? ''));
    if ($reason === '') {
        throw new InvalidArgumentException('void_reason is required.');
    }

    $run = bakeshopProductionFindById($id, true);
    if ($run === null) {
        throw new InvalidArgumentException('Production run not found.');
    }
    if (trim((string)($run['voided_at'] ?? '')) !== '') {
        throw new InvalidArgumentException('Production run has already been voided.');
    }

    $voidedBy = trim((string)($actor['full_name'] ?? $actor['username'] ?? ''));
    $voidedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = bakeshopDb()->prepare(
        'UPDATE bakeshop_production_runs
         SET voided_at = :voided_at,
             voided_by = :voided_by,
             void_reason = :void_reason
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $id,
        ':voided_at' => $voidedAt,
        ':voided_by' => $voidedBy !== '' ? $voidedBy : null,
        ':void_reason' => $reason,
    ]);

    $updatedRun = bakeshopProductionFindById($id, true) ?? $run;

    bakeshopAudit(
        'bakeshop.production.voided',
        (int)($run['branch_id'] ?? 0) ?: null,
        'bakeshop_production_runs',
        (string)$id,
        $run,
        $updatedRun
    );

    return $updatedRun;
}

function bakeshopApiProductionIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopProductionList()]);
    });
}

function bakeshopApiProductionStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $isUpdate = (($input['id'] ?? null) !== null && trim((string)$input['id']) !== '');
        $item = $isUpdate ? bakeshopProductionUpdate($input) : bakeshopProductionCreate($input);
        bakeshopJsonOk(['item' => $item], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiProductionVoid(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $user = bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopProductionVoid(bakeshopInput(), $user);
        bakeshopJsonOk(['item' => $item]);
    });
}

function bakeshopApiProductionDelete(array $params = []): void
{
    bakeshopApiProductionVoid($params);
}