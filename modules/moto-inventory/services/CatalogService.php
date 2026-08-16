<?php

declare(strict_types=1);

/**
 * Moto Inventory — CatalogService
 *
 * Brands and products: list/search, create, edit, archive/restore, trash,
 * and permanent delete. Every query is tenant-scoped; mutations require an
 * in-scope branch. Cost/privacy is enforced by the caller (handler) using
 * the permission context — this service always returns authoritative data.
 */
final class CatalogService
{
    /**
     * List brands for a tenant (optionally scoped to a branch's product counts).
     *
     * @param array $ctx   moto_ctx() array
     * @param array $filters include_trashed, include_archived
     * @return array{rows: array<int,array>, total: int}
     */
    public static function brands(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $where = ['b.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        $includeTrashed = !empty($filters['include_trashed']);
        $includeArchived = !empty($filters['include_archived']);
        if (!$includeTrashed) {
            $where[] = 'b.trashed = 0';
        }
        if (!$includeArchived && !$includeTrashed) {
            $where[] = 'b.archived = 0';
        }

        $whereSql = implode(' AND ', $where);
        $count = (int)$db->query(
            "SELECT COUNT(*) FROM moto_brands b WHERE {$whereSql}",
            $params
        )->fetchColumn();

        $stmt = $db->query(
            "SELECT b.id, b.name, b.archived, b.trashed,
                    (SELECT COUNT(*) FROM moto_products p WHERE p.brand_id = b.id AND p.tenant_id = :tid2 AND p.archived = 0) AS product_count
             FROM moto_brands b
             WHERE {$whereSql}
             ORDER BY b.name ASC",
            $params + [':tid2' => $tenantId]
        );

        return [
            'rows'  => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'total' => $count,
        ];
    }

    public static function brandByName(array $ctx, string $name): ?array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'SELECT * FROM moto_brands WHERE tenant_id = :tid AND name = :name LIMIT 1'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':name' => trim($name)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function brandById(array $ctx, int $brandId): ?array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'SELECT * FROM moto_brands WHERE tenant_id = :tid AND id = :id LIMIT 1'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Create a brand. Fails on duplicate within the tenant.
     */
    public static function createBrand(array $ctx, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Brand name is required');
        }
        if (mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Brand name is too long');
        }
        if (self::brandByName($ctx, $name) !== null) {
            throw new \InvalidArgumentException('Brand already exists');
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'INSERT INTO moto_brands (tenant_id, name) VALUES (:tid, :name)'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':name' => $name]);
        $id = (int)$db->lastInsertId();

        moto_audit($ctx, 'moto_inventory.brand.created', 'moto_brand', (string)$id, null, ['name' => $name]);

        return ['id' => $id, 'name' => $name];
    }

    public static function renameBrand(array $ctx, int $brandId, string $newName): array
    {
        $brand = self::brandById($ctx, $brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Brand not found');
        }
        $newName = trim($newName);
        if ($newName === '') {
            throw new \InvalidArgumentException('Brand name is required');
        }
        if ($newName !== $brand['name']) {
            $existing = self::brandByName($ctx, $newName);
            if ($existing !== null && (int)$existing['id'] !== $brandId) {
                throw new \InvalidArgumentException('Brand already exists');
            }
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $db->prepare('UPDATE moto_brands SET name = :name WHERE tenant_id = :tid AND id = :id')
            ->execute([':name' => $newName, ':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);

        moto_audit($ctx, 'moto_inventory.brand.renamed', 'moto_brand', (string)$brandId, ['name' => $brand['name']], ['name' => $newName]);

        return ['id' => $brandId, 'name' => $newName];
    }

    public static function setBrandArchived(array $ctx, int $brandId, bool $archived): array
    {
        $brand = self::brandById($ctx, $brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Brand not found');
        }
        $db = moto_db((int)$ctx['tenant_id']);
        $db->prepare('UPDATE moto_brands SET archived = :arch WHERE tenant_id = :tid AND id = :id')
            ->execute([':arch' => $archived ? 1 : 0, ':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);
        $db->prepare('UPDATE moto_products SET archived = :arch WHERE tenant_id = :tid AND brand_id = :id')
            ->execute([':arch' => $archived ? 1 : 0, ':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);

        moto_audit($ctx, $archived ? 'moto_inventory.brand.archived' : 'moto_inventory.brand.restored', 'moto_brand', (string)$brandId, ['name' => $brand['name']], ['archived' => $archived]);

        return ['id' => $brandId, 'archived' => $archived];
    }

    public static function setBrandTrashed(array $ctx, int $brandId, bool $trashed): array
    {
        $brand = self::brandById($ctx, $brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Brand not found');
        }
        $db = moto_db((int)$ctx['tenant_id']);
        $db->prepare('UPDATE moto_brands SET trashed = :tr, archived = :arch WHERE tenant_id = :tid AND id = :id')
            ->execute([':tr' => $trashed ? 1 : 0, ':arch' => $trashed ? 1 : 0, ':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);
        if ($trashed) {
            $db->prepare('UPDATE moto_products SET archived = 1 WHERE tenant_id = :tid AND brand_id = :id')
                ->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);
        }

        moto_audit($ctx, $trashed ? 'moto_inventory.brand.trashed' : 'moto_inventory.brand.restored_from_trash', 'moto_brand', (string)$brandId, ['name' => $brand['name']], ['trashed' => $trashed]);

        return ['id' => $brandId, 'trashed' => $trashed];
    }

    /**
     * Permanently purge a trashed brand and its products. Refuses when any
     * product has movements or sale items (history must not be orphaned).
     */
    public static function purgeBrand(array $ctx, int $brandId): array
    {
        $brand = self::brandById($ctx, $brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Brand not found');
        }
        if (!(int)$brand['trashed']) {
            throw new \InvalidArgumentException('Only trashed brands can be purged');
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM moto_stock_movements m
             JOIN moto_products p ON p.id = m.product_id
             WHERE p.tenant_id = :tid AND p.brand_id = :bid'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':bid' => $brandId]);
        $movementCount = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM moto_sale_items si
             JOIN moto_products p ON p.id = si.product_id
             WHERE p.tenant_id = :tid AND p.brand_id = :bid'
        );
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':bid' => $brandId]);
        $saleItemCount = (int)$stmt->fetchColumn();

        if ($movementCount > 0 || $saleItemCount > 0) {
            throw new \RuntimeException('Cannot purge a brand with movement or sale history');
        }

        $db->prepare('DELETE FROM moto_products WHERE tenant_id = :tid AND brand_id = :id')
            ->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);
        $db->prepare('DELETE FROM moto_brands WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $brandId]);

        moto_audit($ctx, 'moto_inventory.brand.purged', 'moto_brand', (string)$brandId, ['name' => $brand['name']], []);

        return ['id' => $brandId];
    }

    // ── Products ────────────────────────────────────────────────────

    /**
     * Paginated, filterable product search. Branch-scoped for constrained
     * users; view-all-branch users may pass branch_id or null (all).
     *
     * @return array{rows: array, total: int, page: int, per_page: int}
     */
    public static function products(array $ctx, array $filters = []): array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $tenantId = (int)$ctx['tenant_id'];

        $perPage = max(1, min(250, (int)($filters['per_page'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $where = ['p.tenant_id = :tid'];
        $params = [':tid' => $tenantId];

        // Branch scope (server-resolved).
        $branchId = $filters['branch_id'] ?? null;
        if ($branchId !== null && (int)$branchId > 0) {
            $where[] = 'p.branch_id = :bid';
            $params[':bid'] = (int)$branchId;
        }

        if (!empty($filters['brand_id']) && (int)$filters['brand_id'] > 0) {
            $where[] = 'p.brand_id = :brand';
            $params[':brand'] = (int)$filters['brand_id'];
        }

        // State filter: default active only.
        $state = (string)($filters['state'] ?? 'active');
        if ($state === 'archived') {
            $where[] = 'p.archived = 1';
        } elseif ($state === 'trashed') {
            $where[] = 'b.trashed = 1';
        } else {
            $where[] = 'p.archived = 0';
            if (empty($filters['include_trashed'])) {
                $where[] = 'b.trashed = 0';
            }
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.part_number LIKE :q OR p.description LIKE :q2)';
            $params[':q'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }

        // Low-stock filter.
        if (!empty($filters['low_stock'])) {
            $threshold = moto_qty($filters['low_stock'] ?? moto_inventory_settings()['low_stock_threshold'] ?? 5);
            $where[] = 'p.qty_on_hand <= :low';
            $params[':low'] = $threshold;
        }

        $whereSql = implode(' AND ', $where);

        $count = (int)$db->query(
            "SELECT COUNT(*) FROM moto_products p JOIN moto_brands b ON b.id = p.brand_id WHERE {$whereSql}",
            $params
        )->fetchColumn();

        $sort = (string)($filters['sort'] ?? 'part_number');
        $allowed = ['part_number', 'description', 'brand', 'qty_on_hand', 'price', 'updated_at'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'part_number';
        }
        $dir = strtoupper((string)($filters['dir'] ?? 'ASC'));
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            $dir = 'ASC';
        }

        $stmt = $db->query(
            "SELECT p.id, p.brand_id, p.part_number, p.description, p.code, p.cost, p.price, p.qty_on_hand,
                    p.extra, p.archived, p.created_at, p.updated_at,
                    p.branch_id, b.name AS brand, b.archived AS brand_archived, b.trashed AS brand_trashed
             FROM moto_products p
             JOIN moto_brands b ON b.id = p.brand_id
             WHERE {$whereSql}
             ORDER BY " . ($sort === 'brand' ? 'b.name' : 'p.' . $sort) . " {$dir}
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['extra'] = self::decodeJson($row['extra']);
        }
        unset($row);

        return [
            'rows'     => $rows,
            'total'    => $count,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => max(1, (int)ceil($count / $perPage)),
        ];
    }

    public static function productById(array $ctx, int $productId, ?int $branchId = null): ?array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $where = 'p.tenant_id = :tid AND p.id = :id';
        $params = [':tid' => (int)$ctx['tenant_id'], ':id' => $productId];
        if ($branchId !== null) {
            $where .= ' AND p.branch_id = :bid';
            $params[':bid'] = $branchId;
        }
        $stmt = $db->prepare(
            "SELECT p.*, b.name AS brand, b.archived AS brand_archived, b.trashed AS brand_trashed
             FROM moto_products p JOIN moto_brands b ON b.id = p.brand_id
             WHERE {$where} LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['extra'] = self::decodeJson($row['extra']);

        return $row;
    }

    public static function productByKey(array $ctx, int $branchId, int $brandId, string $partNumber): ?array
    {
        $db = moto_db((int)$ctx['tenant_id']);
        $stmt = $db->prepare(
            'SELECT * FROM moto_products
             WHERE tenant_id = :tid AND branch_id = :bid AND brand_id = :brand AND part_number = :part LIMIT 1'
        );
        $stmt->execute([
            ':tid'    => (int)$ctx['tenant_id'],
            ':bid'    => $branchId,
            ':brand'  => $brandId,
            ':part'   => trim($partNumber),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['extra'] = self::decodeJson($row['extra']);

        return $row;
    }

    /**
     * Create a product in a branch. Unique per (tenant, branch, brand, part).
     */
    public static function createProduct(array $ctx, int $branchId, array $input): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $brandId = (int)($input['brand_id'] ?? 0);
        $brand = self::brandById($ctx, $brandId);
        if ($brand === null) {
            throw new \InvalidArgumentException('Brand not found');
        }
        $partNumber = trim((string)($input['part_number'] ?? ''));
        if ($partNumber === '') {
            throw new \InvalidArgumentException('Part number is required');
        }
        if (mb_strlen($partNumber) > 191) {
            throw new \InvalidArgumentException('Part number is too long');
        }
        if (self::productByKey($ctx, $branchId, $brandId, $partNumber) !== null) {
            throw new \InvalidArgumentException('Product already exists in this branch');
        }

        $db = moto_db((int)$ctx['tenant_id']);
        $qty = moto_qty($input['qty'] ?? 0);
        $cost = moto_money_float($input['cost'] ?? 0);
        $price = moto_money_float($input['price'] ?? 0);
        $code = strtoupper(substr(trim((string)($input['code'] ?? '')), 0, 64));

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO moto_products
                    (tenant_id, branch_id, brand_id, part_number, description, code, cost, price, qty_on_hand, extra)
                 VALUES (:tid, :bid, :brand, :part, :desc, :code, :cost, :price, 0, :extra)'
            );
            $stmt->execute([
                ':tid'    => (int)$ctx['tenant_id'],
                ':bid'    => $branchId,
                ':brand'  => $brandId,
                ':part'   => $partNumber,
                ':desc'   => substr(trim((string)($input['description'] ?? '')), 0, 191),
                ':code'   => $code,
                ':cost'   => $cost,
                ':price'  => $price,
                ':extra'  => isset($input['extra']) && is_array($input['extra']) ? json_encode($input['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
            $productId = (int)$db->lastInsertId();

            // Seed movement for initial quantity so the ledger is authoritative.
            if ($qty != 0) {
                StockService::applyDelta(
                    $db, $ctx, $branchId, $productId, $qty,
                    StockService::TYPE_ADJUSTMENT,
                    'product_creation', null,
                    'Initial stock on product creation',
                    null,
                    true
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        moto_audit($ctx, 'moto_inventory.product.created', 'moto_product', (string)$productId, null, [
            'branch_id' => $branchId, 'brand' => $brand['name'], 'part_number' => $partNumber, 'qty' => $qty,
        ]);
        moto_emit_event('moto_inventory.product.created', [
            'tenant_id' => (int)$ctx['tenant_id'], 'branch_id' => $branchId, 'product_id' => $productId,
        ]);

        return ['id' => $productId];
    }

    /**
     * Update product fields. Stock quantity is NOT edited here — use
     * StockService::adjust (movement-ledger). Quantity in this method is
     * rejected so the ledger stays authoritative.
     */
    public static function updateProduct(array $ctx, int $productId, int $branchId, array $input): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $db = moto_db((int)$ctx['tenant_id']);
        $product = self::productById($ctx, $productId, $branchId);
        if ($product === null) {
            throw new \InvalidArgumentException('Product not found in branch');
        }

        if (array_key_exists('qty', $input) && $input['qty'] !== null && $input['qty'] !== '') {
            throw new \InvalidArgumentException('Stock quantity must be adjusted with a reason through stock adjustment');
        }

        $fields = [];
        $params = [':id' => $productId, ':tid' => (int)$ctx['tenant_id']];
        $before = [
            'description' => $product['description'],
            'code'        => $product['code'],
            'cost'        => $product['cost'],
            'price'       => $product['price'],
            'extra'       => $product['extra'],
        ];

        if (array_key_exists('description', $input)) {
            $fields[] = 'description = :desc';
            $params[':desc'] = substr(trim((string)$input['description']), 0, 191);
        }
        if (array_key_exists('code', $input)) {
            $fields[] = 'code = :code';
            $params[':code'] = strtoupper(substr(trim((string)$input['code']), 0, 64));
        }
        if (array_key_exists('cost', $input) && $input['cost'] !== null && $input['cost'] !== '') {
            $fields[] = 'cost = :cost';
            $params[':cost'] = moto_money_float($input['cost']);
        }
        if (array_key_exists('price', $input) && $input['price'] !== null && $input['price'] !== '') {
            $fields[] = 'price = :price';
            $params[':price'] = moto_money_float($input['price']);
        }
        if (array_key_exists('extra', $input) && is_array($input['extra'])) {
            $fields[] = 'extra = :extra';
            $params[':extra'] = json_encode($input['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($fields !== []) {
            $db->prepare('UPDATE moto_products SET ' . implode(', ', $fields) . ' WHERE tenant_id = :tid AND id = :id')
                ->execute($params);
        }

        moto_audit($ctx, 'moto_inventory.product.updated', 'moto_product', (string)$productId, $before, $input);
        moto_emit_event('moto_inventory.product.updated', [
            'tenant_id' => (int)$ctx['tenant_id'], 'branch_id' => $branchId, 'product_id' => $productId,
        ]);

        return ['id' => $productId];
    }

    public static function setProductArchived(array $ctx, int $productId, int $branchId, bool $archived): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $db = moto_db((int)$ctx['tenant_id']);
        $product = self::productById($ctx, $productId, $branchId);
        if ($product === null) {
            throw new \InvalidArgumentException('Product not found in branch');
        }
        $db->prepare('UPDATE moto_products SET archived = :arch WHERE tenant_id = :tid AND id = :id')
            ->execute([':arch' => $archived ? 1 : 0, ':tid' => (int)$ctx['tenant_id'], ':id' => $productId]);

        moto_audit($ctx, $archived ? 'moto_inventory.product.archived' : 'moto_inventory.product.restored', 'moto_product', (string)$productId, ['archived' => (int)$product['archived']], ['archived' => $archived]);
        moto_emit_event('moto_inventory.product.archived', [
            'tenant_id' => (int)$ctx['tenant_id'], 'branch_id' => $branchId, 'product_id' => $productId, 'archived' => $archived,
        ]);

        return ['id' => $productId, 'archived' => $archived];
    }

    /**
     * Permanently delete a product. Refuses when the product has any
     * movement or sale history (never orphan history).
     */
    public static function deleteProduct(array $ctx, int $productId, int $branchId): array
    {
        $branchId = moto_require_write_branch($ctx, $branchId);
        $db = moto_db((int)$ctx['tenant_id']);
        $product = self::productById($ctx, $productId, $branchId);
        if ($product === null) {
            throw new \InvalidArgumentException('Product not found in branch');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM moto_stock_movements WHERE tenant_id = :tid AND product_id = :id');
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $productId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete a product with stock movement history; archive it instead');
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM moto_sale_items WHERE tenant_id = :tid AND product_id = :id');
        $stmt->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $productId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete a product with sale history; archive it instead');
        }

        $db->prepare('DELETE FROM moto_products WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => (int)$ctx['tenant_id'], ':id' => $productId]);

        moto_audit($ctx, 'moto_inventory.product.deleted', 'moto_product', (string)$productId, ['part_number' => $product['part_number']], []);

        return ['id' => $productId];
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private static function decodeJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
