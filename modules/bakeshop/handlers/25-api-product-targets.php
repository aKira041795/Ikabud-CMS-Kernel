<?php

declare(strict_types=1);

function bakeshopProductTargetsFindById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return bakeshopCatalogFetchOne(
        'SELECT
            t.id,
            t.branch_id,
            t.product_id,
            t.daily_qty,
            t.unit_id,
            t.is_active,
            t.created_at,
            t.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.sku AS product_sku,
            p.name AS product_name,
            p.category AS product_category,
            p.default_yield_unit_id,
            pu.code AS product_default_yield_unit_code,
            u.code AS unit_code,
            u.name AS unit_name,
            u.dimension AS unit_dimension
         FROM bakeshop_branch_product_targets t
         INNER JOIN bakeshop_branches b ON b.id = t.branch_id
         INNER JOIN bakeshop_products p ON p.id = t.product_id
         LEFT JOIN bakeshop_units pu ON pu.id = p.default_yield_unit_id
         INNER JOIN bakeshop_units u ON u.id = t.unit_id
         WHERE t.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopProductTargetsFindByBranchProduct(int $branchId, int $productId): ?array
{
    if ($branchId <= 0 || $productId <= 0) {
        return null;
    }

    return bakeshopCatalogFetchOne(
        'SELECT
            t.id,
            t.branch_id,
            t.product_id,
            t.daily_qty,
            t.unit_id,
            t.is_active,
            t.created_at,
            t.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.sku AS product_sku,
            p.name AS product_name,
            p.category AS product_category,
            p.default_yield_unit_id,
            pu.code AS product_default_yield_unit_code,
            u.code AS unit_code,
            u.name AS unit_name,
            u.dimension AS unit_dimension
         FROM bakeshop_branch_product_targets t
         INNER JOIN bakeshop_branches b ON b.id = t.branch_id
         INNER JOIN bakeshop_products p ON p.id = t.product_id
         LEFT JOIN bakeshop_units pu ON pu.id = p.default_yield_unit_id
         INNER JOIN bakeshop_units u ON u.id = t.unit_id
         WHERE t.branch_id = :branch_id AND t.product_id = :product_id
         LIMIT 1',
        [
            ':branch_id' => $branchId,
            ':product_id' => $productId,
        ]
    );
}

function bakeshopProductTargetsList(array $input = []): array
{
    $where = [];
    $bindings = [];

    if (($input['branch_id'] ?? null) !== null && (string)$input['branch_id'] !== '') {
        $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'], 'branch_id');
        $where[] = 't.branch_id = :branch_id';
        $bindings[':branch_id'] = $branchId;
    }

    return bakeshopCatalogFetchAll(
        'SELECT
            t.id,
            t.branch_id,
            t.product_id,
            t.daily_qty,
            t.unit_id,
            t.is_active,
            t.created_at,
            t.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            p.sku AS product_sku,
            p.name AS product_name,
            p.category AS product_category,
            p.default_yield_unit_id,
            pu.code AS product_default_yield_unit_code,
            u.code AS unit_code,
            u.name AS unit_name,
            u.dimension AS unit_dimension
         FROM bakeshop_branch_product_targets t
         INNER JOIN bakeshop_branches b ON b.id = t.branch_id
         INNER JOIN bakeshop_products p ON p.id = t.product_id
         LEFT JOIN bakeshop_units pu ON pu.id = p.default_yield_unit_id
         INNER JOIN bakeshop_units u ON u.id = t.unit_id'
         . ($where === [] ? '' : (' WHERE ' . implode(' AND ', $where))) . '
         ORDER BY b.name ASC, p.name ASC',
        $bindings
    );
}

function bakeshopAssertProductTargetUnitCompatible(int $productId, int $unitId): void
{
    $product = bakeshopCatalogFindProductById($productId);
    if ($product === null) {
        throw new InvalidArgumentException('Product not found.');
    }

    $defaultYieldUnitId = (int)($product['default_yield_unit_id'] ?? 0);
    if ($defaultYieldUnitId <= 0) {
        throw new InvalidArgumentException('Product default_yield_unit_id must be configured before setting a branch target.');
    }

    bakeshopAssertUnitsShareDimension($defaultYieldUnitId, $unitId, 'unit_id', 'product default yield unit');
}

function bakeshopProductTargetsSave(array $input): array
{
    $existing = null;
    if (($input['id'] ?? null) !== null && (string)$input['id'] !== '') {
        $id = bakeshopCatalogRequirePositiveInt($input['id'], 'id');
        $existing = bakeshopProductTargetsFindById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Product target not found.');
        }
    }

    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? ($existing['branch_id'] ?? null), 'branch_id');
    $productId = bakeshopCatalogRequirePositiveInt($input['product_id'] ?? ($existing['product_id'] ?? null), 'product_id');
    $dailyQty = bakeshopCatalogRequirePositiveDecimal($input['daily_qty'] ?? ($existing['daily_qty'] ?? null), 'daily_qty');
    $isActive = bakeshopCatalogNormalizeActiveFlag($input['is_active'] ?? null, (int)($existing['is_active'] ?? 1));

    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);
    bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);

    $product = bakeshopCatalogFindProductById($productId);
    $unitId = ($input['unit_id'] ?? null) !== null && (string)$input['unit_id'] !== ''
        ? bakeshopCatalogRequirePositiveInt($input['unit_id'], 'unit_id')
        : (int)($existing['unit_id'] ?? ($product['default_yield_unit_id'] ?? 0));
    bakeshopCatalogAssertRecordExists('bakeshop_units', $unitId);
    bakeshopAssertProductTargetUnitCompatible($productId, $unitId);

    $duplicate = bakeshopProductTargetsFindByBranchProduct($branchId, $productId);
    if ($existing === null && $duplicate !== null) {
        $existing = $duplicate;
    }
    if ($existing !== null && $duplicate !== null && (int)($duplicate['id'] ?? 0) !== (int)($existing['id'] ?? 0)) {
        throw new InvalidArgumentException('A branch target already exists for this product. Update the existing target instead.');
    }

    if ($existing !== null) {
        $stmt = bakeshopDb()->prepare(
            'UPDATE bakeshop_branch_product_targets
             SET branch_id = :branch_id,
                 product_id = :product_id,
                 daily_qty = :daily_qty,
                 unit_id = :unit_id,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => (int)$existing['id'],
            ':branch_id' => $branchId,
            ':product_id' => $productId,
            ':daily_qty' => $dailyQty,
            ':unit_id' => $unitId,
            ':is_active' => $isActive,
        ]);

        $row = bakeshopProductTargetsFindById((int)$existing['id']) ?? [];
        bakeshopAudit('bakeshop.product_target.updated', $branchId, 'bakeshop_branch_product_targets', (string)($existing['id'] ?? ''), $existing, $row);

        return $row;
    }

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_branch_product_targets (branch_id, product_id, daily_qty, unit_id, is_active)
         VALUES (:branch_id, :product_id, :daily_qty, :unit_id, :is_active)'
    );
    $stmt->execute([
        ':branch_id' => $branchId,
        ':product_id' => $productId,
        ':daily_qty' => $dailyQty,
        ':unit_id' => $unitId,
        ':is_active' => $isActive,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopProductTargetsFindById($id) ?? [];
    bakeshopAudit('bakeshop.product_target.created', $branchId, 'bakeshop_branch_product_targets', (string)$id, null, $row);

    return $row;
}

function bakeshopProductTargetsDelete(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $row = bakeshopProductTargetsFindById($id);
    if ($row === null) {
        throw new InvalidArgumentException('Product target not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_branch_product_targets WHERE id = :id');
    $stmt->execute([':id' => $id]);

    bakeshopAudit('bakeshop.product_target.deleted', (int)($row['branch_id'] ?? 0) ?: null, 'bakeshop_branch_product_targets', (string)$id, $row, null);

    return $row;
}

function bakeshopApiProductTargetsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopProductTargetsList((array)bakeshopInput())]);
    });
}

function bakeshopApiProductTargetsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $input = (array)bakeshopInput();
        $isUpdate = ($input['id'] ?? null) !== null && (string)$input['id'] !== '';
        $item = bakeshopProductTargetsSave($input);
        bakeshopJsonMutationOk(['item' => $item], ['product-targets', 'production', 'dr-projection'], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiProductTargetsDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopProductTargetsDelete((array)bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['product-targets', 'production', 'dr-projection']);
    });
}
