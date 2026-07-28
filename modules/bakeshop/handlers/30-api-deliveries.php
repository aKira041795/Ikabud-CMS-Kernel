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

function bakeshopDeliveriesHasItemCostBasisColumn(): bool
{
    return bakeshopTableHasColumn('bakeshop_delivery_items', 'cost_basis');
}

function bakeshopDeliveriesHasCoverageDaysColumn(): bool
{
    return bakeshopTableHasColumn('bakeshop_deliveries', 'coverage_days');
}

function bakeshopDeliveriesSourceSelectSql(string $alias = 'd'): string
{
    if (bakeshopDeliveriesHasSourceColumns()) {
        return "{$alias}.source_type,\n            {$alias}.source_name,";
    }

    return "NULL AS source_type,\n            NULL AS source_name,";
}

function bakeshopDeliveriesCoverageDaysSelectSql(string $alias = 'd'): string
{
    if (bakeshopDeliveriesHasCoverageDaysColumn()) {
        return "{$alias}.coverage_days,";
    }

    return '1 AS coverage_days,';
}

function bakeshopDeliveriesItemCostBasisSelectSql(string $alias = 'di'): string
{
    if (bakeshopDeliveriesHasItemCostBasisColumn()) {
        return "{$alias}.cost_basis,";
    }

    return "NULL AS cost_basis,";
}

function bakeshopDeliveriesNormalizeCostBasis(mixed $value): ?string
{
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '' || $normalized === 'delivery_source') {
        return null;
    }

    if (!in_array($normalized, ['receipt', 'price_list', 'manual'], true)) {
        throw new InvalidArgumentException('cost_basis must be receipt, price_list, manual, or blank.');
    }

    return $normalized;
}

function bakeshopDeliveriesNormalizeCoverageDays(mixed $value): int
{
    if ($value === null || trim((string)$value) === '') {
        return 1;
    }

    $coverageDays = bakeshopCatalogRequirePositiveInt($value, 'coverage_days');
    if ($coverageDays > 31) {
        throw new InvalidArgumentException('coverage_days must be between 1 and 31.');
    }

    return $coverageDays;
}

function bakeshopDeliveriesCostBasisLabel(?string $value): string
{
    return match (strtolower(trim((string)$value))) {
        'receipt' => 'Receipt',
        'price_list' => 'Price List',
        'manual' => 'Manual',
        default => 'Delivery Source',
    };
}

function bakeshopDeliveriesSourceLabel(array $delivery): string
{
    $sourceType = strtolower(trim((string)($delivery['source_type'] ?? '')));
    $sourceName = trim((string)($delivery['source_name'] ?? ''));

    if ($sourceType === 'other') {
        return $sourceName !== '' ? ('Other - ' . $sourceName) : 'Other';
    }

    return 'Commissary';
}

function bakeshopDeliveriesFetchItemsByDeliveryIds(array $deliveryIds): array
{
    if ($deliveryIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($deliveryIds), '?'));
    $rows = bakeshopCatalogFetchAll(
        'SELECT
            di.delivery_id,
            di.id,
            di.ingredient_id,
            di.product_id,
            di.qty,
            di.unit_id,
            di.unit_cost,
            ' . bakeshopDeliveriesItemCostBasisSelectSql('di') . '
            i.name AS ingredient_name,
            u.code AS unit_code,
            p.name AS product_name
         FROM bakeshop_delivery_items di
         INNER JOIN bakeshop_ingredients i ON i.id = di.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = di.unit_id
         LEFT JOIN bakeshop_products p ON p.id = di.product_id
         WHERE di.delivery_id IN (' . $placeholders . ')
         ORDER BY di.delivery_id ASC, di.id ASC',
        array_values(array_map('intval', $deliveryIds))
    );

    $itemsByDeliveryId = [];
    foreach ($rows as $row) {
        $deliveryId = (int)($row['delivery_id'] ?? 0);
        $unitCost = ($row['unit_cost'] ?? null) !== null ? (float)$row['unit_cost'] : null;
        $qty = (float)($row['qty'] ?? 0);
        $costBasis = trim((string)($row['cost_basis'] ?? ''));
        $row['cost_basis'] = $costBasis !== '' ? $costBasis : null;
        $row['cost_basis_label'] = bakeshopDeliveriesCostBasisLabel($row['cost_basis']);
        $row['line_amount'] = $unitCost !== null ? round($qty * $unitCost, 2) : null;
        $row['line_amount_display'] = $row['line_amount'] !== null ? number_format((float)$row['line_amount'], 2, '.', '') : '—';
        $itemsByDeliveryId[$deliveryId][] = $row;
    }

    return $itemsByDeliveryId;
}

function bakeshopDeliveriesAttachDerivedFields(array $delivery): array
{
    $items = array_values((array)($delivery['items'] ?? []));
    $delivery['items'] = $items;
    $delivery['source_label'] = bakeshopDeliveriesSourceLabel($delivery);
    $delivery['coverage_days'] = max(1, (int)($delivery['coverage_days'] ?? 1));

    $totalAmount = 0.0;
    $hasPricedLine = false;
    $costBasisLabels = [];
    foreach ($items as $item) {
        if (($item['line_amount'] ?? null) !== null) {
            $totalAmount += (float)$item['line_amount'];
            $hasPricedLine = true;
        }
        $costBasisLabels[] = (string)($item['cost_basis_label'] ?? bakeshopDeliveriesCostBasisLabel(null));
    }

    $costBasisLabels = array_values(array_unique(array_filter($costBasisLabels, static fn (string $label): bool => trim($label) !== '')));
    $delivery['cost_basis_summary'] = $costBasisLabels === []
        ? bakeshopDeliveriesCostBasisLabel(null)
        : (count($costBasisLabels) === 1 ? $costBasisLabels[0] : 'Mixed');
    $delivery['total_amount'] = $hasPricedLine ? round($totalAmount, 2) : null;
    $delivery['total_amount_display'] = $hasPricedLine ? number_format((float)$delivery['total_amount'], 2, '.', '') : '—';

    return $delivery;
}

function bakeshopDeliveriesHydrateCollection(array $deliveries): array
{
    $deliveryIds = array_values(array_filter(array_map(static fn (array $delivery): int => (int)($delivery['id'] ?? 0), $deliveries)));
    $itemsByDeliveryId = bakeshopDeliveriesFetchItemsByDeliveryIds($deliveryIds);

    foreach ($deliveries as &$delivery) {
        $deliveryId = (int)($delivery['id'] ?? 0);
        $delivery['items'] = $itemsByDeliveryId[$deliveryId] ?? [];
        $delivery = bakeshopDeliveriesAttachDerivedFields($delivery);
    }
    unset($delivery);

    return $deliveries;
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
    $hasItemCostBasisColumn = bakeshopDeliveriesHasItemCostBasisColumn();
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

        $productId = null;
        if (($rawItem['product_id'] ?? null) !== null && (string)$rawItem['product_id'] !== '') {
            $productId = bakeshopCatalogRequirePositiveInt($rawItem['product_id'], 'product_id');
            bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);
        }

        $items[] = [
            'ingredient_id' => $ingredientId,
            'product_id' => $productId,
            'unit_id' => $unitId,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'cost_basis' => $hasItemCostBasisColumn ? bakeshopDeliveriesNormalizeCostBasis($rawItem['cost_basis'] ?? null) : null,
        ];
    }

    return $items;
}

function bakeshopDeliveriesList(): array
{
    return bakeshopDeliveriesHydrateCollection(bakeshopCatalogFetchAll(
        'SELECT
            d.id,
            d.branch_id,
            d.delivered_at,
            d.reference,
            d.status,
            d.version,
            ' . bakeshopDeliveriesCoverageDaysSelectSql('d') . '
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
    ));
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
    $coverageDays = bakeshopDeliveriesNormalizeCoverageDays($input['coverage_days'] ?? null);
    $source = bakeshopDeliveriesNormalizeSource($input);
    $receivedBy = trim((string)($input['received_by'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $items = bakeshopDeliveriesNormalizeItems($input['items'] ?? null);

    $db = bakeshopDb();
    $db->beginTransaction();

    try {
        if (bakeshopDeliveriesHasSourceColumns()) {
            $stmt = $db->prepare(
                bakeshopDeliveriesHasCoverageDaysColumn()
                    ? 'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, coverage_days, source_type, source_name, received_by, notes)
                       VALUES (:branch_id, :delivered_at, :reference, :coverage_days, :source_type, :source_name, :received_by, :notes)'
                    : 'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, source_type, source_name, received_by, notes)
                       VALUES (:branch_id, :delivered_at, :reference, :source_type, :source_name, :received_by, :notes)'
            );
            $bindings = [
                ':branch_id' => $branchId,
                ':delivered_at' => $deliveredAt->format('Y-m-d H:i:s'),
                ':reference' => $reference !== '' ? $reference : null,
                ':source_type' => $source['source_type'],
                ':source_name' => $source['source_name'],
                ':received_by' => $receivedBy !== '' ? $receivedBy : null,
                ':notes' => $notes !== '' ? $notes : null,
            ];
            if (bakeshopDeliveriesHasCoverageDaysColumn()) {
                $bindings[':coverage_days'] = $coverageDays;
            }
            $stmt->execute($bindings);
        } else {
            $stmt = $db->prepare(
                bakeshopDeliveriesHasCoverageDaysColumn()
                    ? 'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, coverage_days, received_by, notes)
                       VALUES (:branch_id, :delivered_at, :reference, :coverage_days, :received_by, :notes)'
                    : 'INSERT INTO bakeshop_deliveries (branch_id, delivered_at, reference, received_by, notes)
                       VALUES (:branch_id, :delivered_at, :reference, :received_by, :notes)'
            );
            $bindings = [
                ':branch_id' => $branchId,
                ':delivered_at' => $deliveredAt->format('Y-m-d H:i:s'),
                ':reference' => $reference !== '' ? $reference : null,
                ':received_by' => $receivedBy !== '' ? $receivedBy : null,
                ':notes' => $notes !== '' ? $notes : null,
            ];
            if (bakeshopDeliveriesHasCoverageDaysColumn()) {
                $bindings[':coverage_days'] = $coverageDays;
            }
            $stmt->execute($bindings);
        }

        $deliveryId = (int)$db->lastInsertId();
        $hasItemCostBasisColumn = bakeshopDeliveriesHasItemCostBasisColumn();
        $itemStmt = $db->prepare(
            $hasItemCostBasisColumn
                ? 'INSERT INTO bakeshop_delivery_items (delivery_id, ingredient_id, product_id, qty, unit_id, unit_cost, cost_basis)
                   VALUES (:delivery_id, :ingredient_id, :product_id, :qty, :unit_id, :unit_cost, :cost_basis)'
                : 'INSERT INTO bakeshop_delivery_items (delivery_id, ingredient_id, product_id, qty, unit_id, unit_cost)
                   VALUES (:delivery_id, :ingredient_id, :product_id, :qty, :unit_id, :unit_cost)'
        );

        foreach ($items as $item) {
            $bindings = [
                ':delivery_id' => $deliveryId,
                ':ingredient_id' => $item['ingredient_id'],
                ':product_id' => $item['product_id'],
                ':qty' => $item['qty'],
                ':unit_id' => $item['unit_id'],
                ':unit_cost' => $item['unit_cost'],
            ];
            if ($hasItemCostBasisColumn) {
                $bindings[':cost_basis'] = $item['cost_basis'];
            }
            $itemStmt->execute($bindings);
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
            ' . bakeshopDeliveriesCoverageDaysSelectSql('d') . '
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

    $delivery['items'] = bakeshopDeliveriesFetchItemsByDeliveryIds([$deliveryId])[$deliveryId] ?? [];
    $delivery = bakeshopDeliveriesAttachDerivedFields($delivery);

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
            d.status,
            d.version,
            ' . bakeshopDeliveriesCoverageDaysSelectSql('d') . '
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

    $delivery['items'] = bakeshopDeliveriesFetchItemsByDeliveryIds([$id])[$id] ?? [];

    return bakeshopDeliveriesAttachDerivedFields($delivery);
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

function bakeshopDeliveriesDeleteBatch(array $input): array
{
    $ids = bakeshopCatalogNormalizeDeleteIds($input['ids'] ?? null);
    $db = bakeshopDb();
    $deleted = [];

    require_once __DIR__ . '/../Services/InventoryLedgerService.php';
    require_once __DIR__ . '/../Services/ReceivingService.php';
    $svc = new BakeshopReceivingService();

    $db->beginTransaction();
    try {
        foreach ($ids as $id) {
            $version = $svc->getVersion($id);
            try {
                $deleted[] = $svc->void($id, 'Batch delete', $version);
            } catch (\RuntimeException $e) {
                write_log('bakeshop.delivery.batch_void_failed', 'warning', [
                    'delivery_id' => $id, 'error' => $e->getMessage(),
                ]);
                // Fetch current state for the result
                $delivery = bakeshopDeliveriesFindById($id);
                $deleted[] = $delivery ?? ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'ids' => $ids,
        'items' => $deleted,
        'deleted_count' => count($deleted),
    ];
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
        bakeshopJsonMutationOk(['item' => $item], ['branches', 'deliveries', 'production', 'usage'], $isUpdate ? 200 : 201);
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
        bakeshopJsonMutationOk(['item' => $item], ['branches', 'deliveries', 'production', 'usage']);
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
        $user = bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $item = bakeshopDeliveriesCreate($input);

        // Record ledger movements for new deliveries.
        // If the ledger write fails, void the delivery to prevent
        // invisible draft records without ledger entries.
        if (!empty($item['items'])) {
            require_once __DIR__ . '/../Services/InventoryLedgerService.php';
            $deliveryId = (int)($item['id'] ?? 0);
            try {
                $ledger = new BakeshopInventoryLedgerService();
                $userId = (int)($user['id'] ?? 0);
                $ledger->recordDeliveryPosting($deliveryId, $item['items'], $userId);
            } catch (\RuntimeException $e) {
                write_log('bakeshop.delivery.ledger_failed', 'warning', [
                    'delivery_id' => $deliveryId, 'error' => $e->getMessage(),
                ]);
                try {
                    $db = bakeshopDb();
                    $db->prepare(
                        "UPDATE bakeshop_deliveries SET status = 'voided', void_reason = CONCAT('Ledger recording failed: ', LEFT(:reason, 200)) WHERE id = :id AND status = 'posted'"
                    )->execute([':id' => $deliveryId, ':reason' => $e->getMessage()]);
                } catch (\Throwable $voidErr) {
                    write_log('bakeshop.delivery.void_after_ledger_failure_failed', 'error', [
                        'delivery_id' => $deliveryId, 'error' => $voidErr->getMessage(),
                    ]);
                }
                bakeshopJsonError('Delivery created but ledger recording failed. The delivery has been voided. Check logs and retry.', 500);
                return;
            }
        }

        bakeshopJsonMutationOk(['item' => $item], ['deliveries', 'usage'], 201);
    });
}

function bakeshopApiDeliveriesDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $user = bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $deliveryId = (int)($input['id'] ?? 0);
        $expectedVersion = isset($input['expected_version']) ? (int)$input['expected_version'] : 1;

        require_once __DIR__ . '/../Services/InventoryLedgerService.php';
        require_once __DIR__ . '/../Services/ReceivingService.php';
        try {
            $svc = new BakeshopReceivingService();
            $item = $svc->void($deliveryId, 'Deleted by user', $expectedVersion, (int)($user['id'] ?? 0));
            bakeshopJsonMutationOk(['item' => $item], ['deliveries', 'usage']);
        } catch (\RuntimeException $e) {
            bakeshopJsonError($e->getMessage(), 409);
        }
    });
}

function bakeshopApiDeliveriesBatchDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $result = bakeshopDeliveriesDeleteBatch((array)bakeshopInput());
        bakeshopJsonMutationOk($result, ['deliveries', 'usage']);
    });
}
