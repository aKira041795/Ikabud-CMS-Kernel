<?php

declare(strict_types=1);

function bakeshopDeliveriesListBranches(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT id, code, name, address, external_store_id, external_warehouse_id, is_active, created_at, updated_at
         FROM bakeshop_branches
         ORDER BY name ASC'
    );
}

function bakeshopDeliveriesFindBranchById(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    return bakeshopCatalogFetchOne(
        'SELECT id, code, name, address, external_store_id, external_warehouse_id, is_active, created_at, updated_at
         FROM bakeshop_branches
         WHERE id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopDeliveriesNormalizeSource(array $input): array
{
    $sourceType = strtolower(trim((string)($input['source_type'] ?? 'commissary')));
    if (!in_array($sourceType, ['commissary', 'other'], true)) {
        throw new InvalidArgumentException('source_type must be commissary or other.');
    }

    $sourceName = trim((string)($input['source_name'] ?? ''));
    if ($sourceType === 'other' && $sourceName === '') {
        throw new InvalidArgumentException('source_name is required when source_type is other.');
    }

    if ($sourceType === 'commissary') {
        $sourceName = '';
    }

    return [
        'source_type' => $sourceType,
        'source_name' => $sourceName !== '' ? $sourceName : null,
    ];
}

function bakeshopDeliveriesHasSourceColumns(): bool
{
    return bakeshopTableHasColumn('bakeshop_deliveries', 'source_type')
        && bakeshopTableHasColumn('bakeshop_deliveries', 'source_name');
}

function bakeshopDeliveriesSourceSelectSql(string $alias = 'd'): string
{
    if (bakeshopDeliveriesHasSourceColumns()) {
        return "{$alias}.source_type,\n            {$alias}.source_name,";
    }

    return "NULL AS source_type,\n            NULL AS source_name,";
}

function bakeshopDeliveriesCreateBranch(array $input): array
{
    $id = null;
    $existing = null;
    if (($input['id'] ?? null) !== null && (string)$input['id'] !== '') {
        $id = bakeshopCatalogRequirePositiveInt($input['id'], 'id');
        $existing = bakeshopDeliveriesFindBranchById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Branch not found.');
        }
    }

    $code = strtoupper(trim((string)($input['code'] ?? '')));
    $name = bakeshopCatalogRequireName($input['name'] ?? null);
    $address = trim((string)($input['address'] ?? ''));
    $isActive = bakeshopCatalogNormalizeActiveFlag($input['is_active'] ?? null, (int)($existing['is_active'] ?? 1));

    if ($code === '') {
        throw new InvalidArgumentException('Code is required.');
    }

    if ($existing !== null) {
        $stmt = bakeshopDb()->prepare(
            'UPDATE bakeshop_branches
             SET code = :code,
                 name = :name,
                 address = :address,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':code' => $code,
            ':name' => $name,
            ':address' => $address !== '' ? $address : null,
            ':is_active' => $isActive,
        ]);

        $row = bakeshopDeliveriesFindBranchById($id) ?? [];

        bakeshopAudit(
            bakeshopResolveActiveMutationAction('bakeshop.branch', $existing, $row),
            $id,
            'bakeshop_branches',
            (string)$id,
            $existing,
            $row
        );

        return $row;
    }

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_branches (code, name, address, is_active)
         VALUES (:code, :name, :address, :is_active)'
    );
    $stmt->execute([
        ':code' => $code,
        ':name' => $name,
        ':address' => $address !== '' ? $address : null,
        ':is_active' => $isActive,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopDeliveriesFindBranchById($id);

    app()->events()->fire('bakeshop.branch.created', [
        'id' => $id,
        'code' => $code,
        'name' => $name,
    ]);

    bakeshopAudit('bakeshop.branch.created', $id, 'bakeshop_branches', (string)$id, null, $row);

    return $row ?? [];
}

function bakeshopBranchSetActive(int $id, mixed $value): array
{
    $existing = bakeshopDeliveriesFindBranchById($id);
    if ($existing === null) {
        throw new InvalidArgumentException('Branch not found.');
    }

    $isActive = bakeshopCatalogNormalizeActiveFlag($value, (int)($existing['is_active'] ?? 1));
    if ((int)($existing['is_active'] ?? 1) === $isActive) {
        return $existing;
    }

    $stmt = bakeshopDb()->prepare(
        'UPDATE bakeshop_branches
         SET is_active = :is_active,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $id,
        ':is_active' => $isActive,
    ]);

    $row = bakeshopDeliveriesFindBranchById($id) ?? [];
    bakeshopAudit(
        bakeshopResolveActiveMutationAction('bakeshop.branch', $existing, $row),
        $id,
        'bakeshop_branches',
        (string)$id,
        $existing,
        $row
    );

    return $row;
}

function bakeshopDeliveriesNormalizeItems(mixed $rawItems): array
{
    if (!is_array($rawItems) || $rawItems === []) {
        throw new InvalidArgumentException('At least one delivery item is required.');
    }

    $items = [];
    foreach ($rawItems as $index => $rawItem) {
        if (!is_array($rawItem)) {
            throw new InvalidArgumentException('Delivery item at index ' . $index . ' is invalid.');
        }

        $ingredientId = bakeshopCatalogRequirePositiveInt($rawItem['ingredient_id'] ?? null, 'ingredient_id');
        $unitId = bakeshopCatalogRequirePositiveInt($rawItem['unit_id'] ?? null, 'unit_id');
        $qty = bakeshopCatalogRequirePositiveDecimal($rawItem['qty'] ?? null, 'qty');
        $unitCost = null;

        if (($rawItem['unit_cost'] ?? null) !== null && (string)$rawItem['unit_cost'] !== '') {
            if (!is_numeric($rawItem['unit_cost'])) {
                throw new InvalidArgumentException('unit_cost must be numeric.');
            }

            $parsedCost = (float)$rawItem['unit_cost'];
            if ($parsedCost < 0) {
                throw new InvalidArgumentException('unit_cost cannot be negative.');
            }
            $unitCost = number_format($parsedCost, 4, '.', '');
        }

        bakeshopCatalogAssertRecordExists('bakeshop_ingredients', $ingredientId);
        bakeshopCatalogAssertRecordExists('bakeshop_units', $unitId);
        bakeshopAssertIngredientUnitCompatible($ingredientId, $unitId, 'unit_id');

        $items[] = [
            'ingredient_id' => $ingredientId,
            'unit_id' => $unitId,
            'qty' => $qty,
            'unit_cost' => $unitCost,
        ];
    }

    return $items;
}

function bakeshopDeliveriesList(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT
            d.id,
            d.branch_id,
            d.delivered_at,
            d.reference,
            ' . bakeshopDeliveriesSourceSelectSql('d') . '
            d.received_by,
            d.notes,
            d.created_at,
            d.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
                COALESCE(di_agg.item_count, 0) AS item_count,
                COALESCE(di_agg.total_quantity, 0) AS total_quantity
         FROM bakeshop_deliveries d
         INNER JOIN bakeshop_branches b ON b.id = d.branch_id
            LEFT JOIN (
                SELECT delivery_id, COUNT(id) AS item_count, COALESCE(SUM(qty), 0) AS total_quantity
                FROM bakeshop_delivery_items
                GROUP BY delivery_id
            ) di_agg ON di_agg.delivery_id = d.id
         ORDER BY d.delivered_at DESC, d.id DESC'
    );
}

function bakeshopDeliveriesCreate(array $input): array
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);

    $deliveredAtRaw = trim((string)($input['delivered_at'] ?? ''));
    if ($deliveredAtRaw === '') {
        throw new InvalidArgumentException('delivered_at is required.');
    }

    $deliveredAt = new DateTimeImmutable($deliveredAtRaw);
    $reference = trim((string)($input['reference'] ?? ''));
    $source = bakeshopDeliveriesNormalizeSource($input);
    $receivedBy = trim((string)($input['received_by'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $items = bakeshopDeliveriesNormalizeItems($input['items'] ?? null);

    $db = bakeshopDb();
    $db->beginTransaction();

    try {
        if (bakeshopDeliveriesHasSourceColumns()) {
            $stmt = $db->prepare(
                'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, source_type, source_name, received_by, notes)
                 VALUES (:branch_id, :delivered_at, :reference, :source_type, :source_name, :received_by, :notes)'
            );
            $stmt->execute([
                ':branch_id' => $branchId,
                ':delivered_at' => $deliveredAt->format('Y-m-d H:i:s'),
                ':reference' => $reference !== '' ? $reference : null,
                ':source_type' => $source['source_type'],
                ':source_name' => $source['source_name'],
                ':received_by' => $receivedBy !== '' ? $receivedBy : null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, received_by, notes)
                 VALUES (:branch_id, :delivered_at, :reference, :received_by, :notes)'
            );
            $stmt->execute([
                ':branch_id' => $branchId,
                ':delivered_at' => $deliveredAt->format('Y-m-d H:i:s'),
                ':reference' => $reference !== '' ? $reference : null,
                ':received_by' => $receivedBy !== '' ? $receivedBy : null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
        }

        $deliveryId = (int)$db->lastInsertId();
        $itemStmt = $db->prepare(
            'INSERT INTO bakeshop_delivery_items (delivery_id, ingredient_id, qty, unit_id, unit_cost)
             VALUES (:delivery_id, :ingredient_id, :qty, :unit_id, :unit_cost)'
        );

        foreach ($items as $item) {
            $itemStmt->execute([
                ':delivery_id' => $deliveryId,
                ':ingredient_id' => $item['ingredient_id'],
                ':qty' => $item['qty'],
                ':unit_id' => $item['unit_id'],
                ':unit_cost' => $item['unit_cost'],
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    $delivery = bakeshopCatalogFetchOne(
        'SELECT
            d.id,
            d.branch_id,
            d.delivered_at,
            d.reference,
            ' . bakeshopDeliveriesSourceSelectSql('d') . '
            d.received_by,
            d.notes,
            d.created_at,
            d.updated_at,
            b.code AS branch_code,
            b.name AS branch_name
         FROM bakeshop_deliveries d
         INNER JOIN bakeshop_branches b ON b.id = d.branch_id
         WHERE d.id = :id LIMIT 1',
        [':id' => $deliveryId]
    ) ?? [];

    $delivery['items'] = bakeshopCatalogFetchAll(
        'SELECT
            di.id,
            di.ingredient_id,
            di.qty,
            di.unit_id,
            di.unit_cost,
            i.name AS ingredient_name,
            u.code AS unit_code
         FROM bakeshop_delivery_items di
         INNER JOIN bakeshop_ingredients i ON i.id = di.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = di.unit_id
         WHERE di.delivery_id = :delivery_id
         ORDER BY di.id ASC',
        [':delivery_id' => $deliveryId]
    );

    app()->events()->fire('bakeshop.delivery.created', [
        'id' => $deliveryId,
        'branch_id' => $branchId,
        'item_count' => count($items),
    ]);

    bakeshopAudit('bakeshop.delivery.created', $branchId, 'bakeshop_deliveries', (string)$deliveryId, null, $delivery);

    return $delivery;
}

function bakeshopDeliveriesFindById(int $id): ?array
{
    $delivery = bakeshopCatalogFetchOne(
        'SELECT
            d.id,
            d.branch_id,
            d.delivered_at,
            d.reference,
            ' . bakeshopDeliveriesSourceSelectSql('d') . '
            d.received_by,
            d.notes,
            d.created_at,
            d.updated_at,
            b.code AS branch_code,
            b.name AS branch_name
         FROM bakeshop_deliveries d
         INNER JOIN bakeshop_branches b ON b.id = d.branch_id
         WHERE d.id = :id LIMIT 1',
        [':id' => $id]
    );
    if ($delivery === null) {
        return null;
    }

    $delivery['items'] = bakeshopCatalogFetchAll(
        'SELECT
            di.id,
            di.ingredient_id,
            di.qty,
            di.unit_id,
            di.unit_cost,
            i.name AS ingredient_name,
            u.code AS unit_code
         FROM bakeshop_delivery_items di
         INNER JOIN bakeshop_ingredients i ON i.id = di.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = di.unit_id
         WHERE di.delivery_id = :delivery_id
         ORDER BY di.id ASC',
        [':delivery_id' => $id]
    );

    return $delivery;
}

function bakeshopDeliveriesDelete(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $delivery = bakeshopDeliveriesFindById($id);
    if ($delivery === null) {
        throw new InvalidArgumentException('Delivery not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_deliveries WHERE id = :id');
    $stmt->execute([':id' => $id]);

    bakeshopAudit(
        'bakeshop.delivery.deleted',
        (int)($delivery['branch_id'] ?? 0) ?: null,
        'bakeshop_deliveries',
        (string)$id,
        $delivery,
        null
    );

    return $delivery;
}

function bakeshopApiBranchesIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopDeliveriesListBranches()]);
    });
}

function bakeshopApiBranchesStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $isUpdate = ($input['id'] ?? null) !== null && (string)$input['id'] !== '';
        $item = bakeshopDeliveriesCreateBranch($input);
        bakeshopJsonOk(['item' => $item], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiBranchesStatusUpdate(array $params = []): void
{
    bakeshopResponseGuard(static function () use ($params): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $id = bakeshopCatalogRequirePositiveInt($params['id'] ?? null, 'id');
        $input = (array)bakeshopInput();
        $item = bakeshopBranchSetActive($id, $input['is_active'] ?? null);
        bakeshopJsonOk(['item' => $item]);
    });
}

function bakeshopApiDeliveriesIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopDeliveriesList()]);
    });
}

function bakeshopApiDeliveriesStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopDeliveriesCreate(bakeshopInput());
        bakeshopJsonOk(['item' => $item], 201);
    });
}

function bakeshopApiDeliveriesDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopDeliveriesDelete(bakeshopInput());
        bakeshopJsonOk(['item' => $item]);
    });
}