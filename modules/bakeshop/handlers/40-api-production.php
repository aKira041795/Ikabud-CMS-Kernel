<?php

declare(strict_types=1);

function bakeshopProductionList(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT
            pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.created_at,
            pr.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.name AS product_name,
            p.default_yield_qty,
            u.code AS default_yield_unit_code,
            COUNT(pi.id) AS consumed_item_count,
            COALESCE(SUM(pi.qty_used), 0) AS total_consumed_qty
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_branches b ON b.id = pr.branch_id
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         LEFT JOIN bakeshop_production_items pi ON pi.run_id = pr.id
         GROUP BY
            pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.created_at,
            pr.updated_at,
            b.code,
            b.name,
            p.name,
            p.default_yield_qty,
            u.code
         ORDER BY pr.produced_at DESC, pr.id DESC'
    );
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
            pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.created_at,
            pr.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.name AS product_name,
            p.default_yield_qty,
            u.code AS default_yield_unit_code
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

function bakeshopProductionFindById(int $id): ?array
{
    $run = bakeshopCatalogFetchOne(
        'SELECT
            pr.id,
            pr.branch_id,
            pr.product_id,
            pr.produced_at,
            pr.qty_produced,
            pr.produced_by,
            pr.notes,
            pr.created_at,
            pr.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.name AS product_name,
            p.default_yield_qty,
            u.code AS default_yield_unit_code
         FROM bakeshop_production_runs pr
         INNER JOIN bakeshop_branches b ON b.id = pr.branch_id
         INNER JOIN bakeshop_products p ON p.id = pr.product_id
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE pr.id = :id LIMIT 1',
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

function bakeshopProductionDelete(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $run = bakeshopProductionFindById($id);
    if ($run === null) {
        throw new InvalidArgumentException('Production run not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_production_runs WHERE id = :id');
    $stmt->execute([':id' => $id]);

    bakeshopAudit(
        'bakeshop.production.deleted',
        (int)($run['branch_id'] ?? 0) ?: null,
        'bakeshop_production_runs',
        (string)$id,
        $run,
        null
    );

    return $run;
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
        $item = bakeshopProductionCreate(bakeshopInput());
        bakeshopJsonOk(['item' => $item], 201);
    });
}

function bakeshopApiProductionDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopProductionDelete(bakeshopInput());
        bakeshopJsonOk(['item' => $item]);
    });
}