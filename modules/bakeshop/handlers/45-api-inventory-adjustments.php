<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Inventory Adjustments — CRUD for waste, spoilage, stocktake, transfers
// ---------------------------------------------------------------------------

function bakeshopAdjustmentSelectColumns(): string
{
    return 'a.id,
            a.branch_id,
            a.ingredient_id,
            a.adjustment_date,
            a.qty,
            a.unit_id,
            a.adjustment_type,
            a.reference,
            a.notes,
            a.status,
            a.version,
            a.created_by,
            a.created_at,
            a.updated_at,
            b.code AS branch_code,
            b.name AS branch_name,
            i.name AS ingredient_name,
            u.code AS unit_code,
            u.dimension AS unit_dimension';
}

function bakeshopAdjustmentList(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT ' . bakeshopAdjustmentSelectColumns() . '
         FROM bakeshop_inventory_adjustments a
         INNER JOIN bakeshop_branches b ON b.id = a.branch_id
         INNER JOIN bakeshop_ingredients i ON i.id = a.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = a.unit_id
         ORDER BY a.adjustment_date DESC, a.id DESC
         LIMIT 500'
    );
}

function bakeshopAdjustmentFindById(int $id): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT ' . bakeshopAdjustmentSelectColumns() . '
         FROM bakeshop_inventory_adjustments a
         INNER JOIN bakeshop_branches b ON b.id = a.branch_id
         INNER JOIN bakeshop_ingredients i ON i.id = a.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = a.unit_id
         WHERE a.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopAdjustmentSupportedTypes(): array
{
    return ['waste', 'spoilage', 'stocktake', 'transfer_in', 'transfer_out', 'other'];
}

function bakeshopAdjustmentCreate(array $input): array
{
    $branchId = bakeshopCatalogRequirePositiveInt($input['branch_id'] ?? null, 'branch_id');
    $ingredientId = bakeshopCatalogRequirePositiveInt($input['ingredient_id'] ?? null, 'ingredient_id');
    $unitId = bakeshopCatalogRequirePositiveInt($input['unit_id'] ?? null, 'unit_id');

    bakeshopCatalogAssertRecordExists('bakeshop_branches', $branchId);
    bakeshopCatalogAssertRecordExists('bakeshop_ingredients', $ingredientId);
    bakeshopCatalogAssertRecordExists('bakeshop_units', $unitId);
    bakeshopAssertIngredientUnitCompatible($ingredientId, $unitId, 'unit_id');

    $rawQty = $input['qty'] ?? null;
    if ($rawQty === null || (string)$rawQty === '') {
        throw new InvalidArgumentException('qty is required.');
    }
    if (!is_numeric($rawQty)) {
        throw new InvalidArgumentException('qty must be numeric.');
    }
    $qty = number_format((float)$rawQty, 4, '.', '');
    if ((float)$qty === 0.0) {
        throw new InvalidArgumentException('qty must not be zero.');
    }

    $adjustmentDateRaw = trim((string)($input['adjustment_date'] ?? ''));
    if ($adjustmentDateRaw === '') {
        throw new InvalidArgumentException('adjustment_date is required.');
    }
    $adjustmentDate = (new DateTimeImmutable($adjustmentDateRaw))->format('Y-m-d H:i:s');

    $adjustmentType = strtolower(trim((string)($input['adjustment_type'] ?? 'other')));
    if (!in_array($adjustmentType, bakeshopAdjustmentSupportedTypes(), true)) {
        throw new InvalidArgumentException('adjustment_type must be one of: ' . implode(', ', bakeshopAdjustmentSupportedTypes()) . '.');
    }

    $reference = trim((string)($input['reference'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $createdBy = trim((string)($input['created_by'] ?? ''));

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_inventory_adjustments (branch_id, ingredient_id, adjustment_date, qty, unit_id, adjustment_type, reference, notes, created_by)
         VALUES (:branch_id, :ingredient_id, :adjustment_date, :qty, :unit_id, :adjustment_type, :reference, :notes, :created_by)'
    );
    $stmt->execute([
        ':branch_id' => $branchId,
        ':ingredient_id' => $ingredientId,
        ':adjustment_date' => $adjustmentDate,
        ':qty' => $qty,
        ':unit_id' => $unitId,
        ':adjustment_type' => $adjustmentType,
        ':reference' => $reference !== '' ? $reference : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':created_by' => $createdBy !== '' ? $createdBy : null,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopAdjustmentFindById($id);

    app()->events()->fire('bakeshop.adjustment.created', [
        'id' => $id,
        'branch_id' => $branchId,
        'ingredient_id' => $ingredientId,
        'adjustment_type' => $adjustmentType,
        'qty' => (float)$qty,
    ]);

    bakeshopAudit('bakeshop.adjustment.created', $branchId, 'bakeshop_inventory_adjustments', (string)$id, null, $row);

    return $row ?? [];
}

function bakeshopAdjustmentDelete(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $row = bakeshopAdjustmentFindById($id);
    if ($row === null) {
        throw new InvalidArgumentException('Adjustment not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_inventory_adjustments WHERE id = :id');
    $stmt->execute([':id' => $id]);

    bakeshopAudit('bakeshop.adjustment.deleted', (int)($row['branch_id'] ?? 0) ?: null, 'bakeshop_inventory_adjustments', (string)$id, $row, null);

    return $row;
}

// -- API handlers -----------------------------------------------------------

function bakeshopApiAdjustmentsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopAdjustmentList()]);
    });
}

function bakeshopApiAdjustmentsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $user = bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $item = bakeshopAdjustmentCreate($input);

        $adjId = (int)($item['id'] ?? 0);
        if ($adjId > 0) {
            require_once __DIR__ . '/../Services/InventoryLedgerService.php';
            require_once __DIR__ . '/../Services/InventoryAdjustmentService.php';
            try {
                $svc = new BakeshopInventoryAdjustmentService();
                $item = $svc->post($adjId, 1, (int)($user['id'] ?? 0));
            } catch (\RuntimeException $e) {
                write_log('bakeshop.adjustment.post_failed', 'warning', [
                    'adjustment_id' => $adjId,
                    'error' => $e->getMessage(),
                ]);
                $message = strtolower($e->getMessage());
                $status = str_contains($message, 'stale version')
                    || str_contains($message, 'concurrent')
                    || str_contains($message, 'insufficient stock')
                    || str_contains($message, 'cannot post adjustment')
                    ? 409
                    : 500;
                bakeshopJsonError($e->getMessage(), $status);
                return;
            }
        }

        bakeshopJsonMutationOk(['item' => $item], ['usage', 'adjustments'], 201);
    });
}

function bakeshopApiAdjustmentsDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        $user = bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $adjId = (int)($input['id'] ?? 0);
        $expectedVersion = isset($input['expected_version']) ? (int)$input['expected_version'] : 1;

        require_once __DIR__ . '/../Services/InventoryLedgerService.php';
        require_once __DIR__ . '/../Services/InventoryAdjustmentService.php';
        try {
            $svc = new BakeshopInventoryAdjustmentService();
            $item = $svc->void($adjId, 'Deleted by user', $expectedVersion, (int)($user['id'] ?? 0));
            bakeshopJsonMutationOk(['item' => $item], ['usage', 'adjustments']);
        } catch (\RuntimeException $e) {
            bakeshopJsonError($e->getMessage(), 409);
        }
    });
}
