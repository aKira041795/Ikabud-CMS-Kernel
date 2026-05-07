<?php

declare(strict_types=1);

function bakeshopCatalogFetchAll(string $sql, array $bindings = []): array
{
    $stmt = bakeshopDb()->prepare($sql);
    $stmt->execute($bindings);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bakeshopCatalogFetchOne(string $sql, array $bindings = []): ?array
{
    $stmt = bakeshopDb()->prepare($sql);
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function bakeshopCatalogRequirePositiveInt(mixed $value, string $field): int
{
    $parsed = (int)$value;
    if ($parsed <= 0) {
        throw new InvalidArgumentException($field . ' must be a positive integer.');
    }

    return $parsed;
}

function bakeshopCatalogRequirePositiveDecimal(mixed $value, string $field): string
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException($field . ' must be numeric.');
    }

    $number = (float)$value;
    if ($number <= 0) {
        throw new InvalidArgumentException($field . ' must be greater than zero.');
    }

    return number_format($number, 4, '.', '');
}

function bakeshopCatalogRequireName(mixed $value, string $field = 'name'): string
{
    $name = trim((string)$value);
    if ($name === '') {
        throw new InvalidArgumentException(ucfirst($field) . ' is required.');
    }

    return $name;
}

function bakeshopCatalogRequireUnitCode(mixed $value): string
{
    $code = trim((string)$value);
    if ($code === '') {
        throw new InvalidArgumentException('Code is required.');
    }
    if (mb_strlen($code) > 20) {
        throw new InvalidArgumentException('Code must not exceed 20 characters.');
    }

    return $code;
}

function bakeshopCatalogRequireUnitDimension(mixed $value): string
{
    $dimension = strtolower(trim((string)$value));
    $allowed = ['mass', 'volume', 'count'];
    if (!in_array($dimension, $allowed, true)) {
        throw new InvalidArgumentException('dimension must be one of: mass, volume, count.');
    }

    return $dimension;
}

function bakeshopCatalogNormalizeActiveFlag(mixed $value, int $default = 1): int
{
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 0 ? 0 : 1;
    }

    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return $default;
    }

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return 1;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return 0;
    }

    throw new InvalidArgumentException('is_active must be a boolean flag.');
}

function bakeshopCatalogFindProductById(int $id): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT p.*, u.code AS default_yield_unit_code
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE p.id = :id LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopCatalogFindIngredientById(int $id): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT i.*, u.code AS default_unit_code, u.name AS default_unit_name, u.dimension AS unit_dimension
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         WHERE i.id = :id LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopCatalogFindUnitById(int $id): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT id, code, name, dimension, base_unit_id, factor_to_base, sort_order, created_at, updated_at
         FROM bakeshop_units
         WHERE id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopCatalogUnitReferenceCount(int $unitId): int
{
    if ($unitId <= 0) {
        return 0;
    }

    $row = bakeshopCatalogFetchOne(
        'SELECT
            (SELECT COUNT(*) FROM bakeshop_ingredients WHERE default_unit_id = :unit_id) +
            (SELECT COUNT(*) FROM bakeshop_products WHERE default_yield_unit_id = :unit_id) +
            (SELECT COUNT(*) FROM bakeshop_product_recipe WHERE unit_id = :unit_id) +
            (SELECT COUNT(*) FROM bakeshop_delivery_items WHERE unit_id = :unit_id) +
            (SELECT COUNT(*) FROM bakeshop_production_items WHERE unit_id = :unit_id) +
            (SELECT COUNT(*) FROM bakeshop_units WHERE base_unit_id = :unit_id) AS reference_count',
        [':unit_id' => $unitId]
    );

    return max(0, (int)($row['reference_count'] ?? 0));
}

function bakeshopCatalogAssertRecordExists(string $table, int $id): void
{
    $allowed = [
        'bakeshop_units',
        'bakeshop_branches',
        'bakeshop_products',
        'bakeshop_ingredients',
    ];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported lookup table: ' . $table);
    }

    $stmt = bakeshopDb()->prepare(sprintf('SELECT id FROM `%s` WHERE id = :id LIMIT 1', $table));
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException($table . ' record not found.');
    }
}

function bakeshopCatalogListUnits(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT id, code, name, dimension, base_unit_id, factor_to_base, sort_order
         FROM bakeshop_units
         ORDER BY dimension ASC, sort_order ASC, code ASC'
    );
}

function bakeshopCatalogListProducts(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT
            p.id,
            p.sku,
            p.name,
            p.category,
            p.default_yield_qty,
            p.default_yield_unit_id,
            p.is_active,
            p.created_at,
            p.updated_at,
            u.code AS default_yield_unit_code,
            COUNT(r.id) AS recipe_item_count,
                COALESCE(pr.production_reference_count, 0) AS production_reference_count,
            CASE
                     WHEN COALESCE(pr.production_reference_count, 0) = 0 THEN 1
                ELSE 0
            END AS can_delete
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         LEFT JOIN bakeshop_product_recipe r ON r.product_id = p.id
            LEFT JOIN (
                SELECT product_id, COUNT(*) AS production_reference_count
                FROM bakeshop_production_runs
                GROUP BY product_id
            ) pr ON pr.product_id = p.id
         GROUP BY
            p.id,
            p.sku,
            p.name,
            p.category,
            p.default_yield_qty,
            p.default_yield_unit_id,
            p.is_active,
            p.created_at,
            p.updated_at,
            u.code,
            pr.production_reference_count
         ORDER BY p.name ASC'
    );
}

function bakeshopCatalogListIngredients(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT
            i.id,
            i.sku,
            i.name,
            i.default_unit_id,
            i.is_active,
            i.created_at,
            i.updated_at,
            u.code AS default_unit_code,
            u.name AS default_unit_name,
            u.dimension AS unit_dimension,
            COALESCE(recipe_refs.recipe_reference_count, 0) AS recipe_reference_count,
            COALESCE(delivery_refs.delivery_reference_count, 0) AS delivery_reference_count,
            COALESCE(production_refs.production_reference_count, 0) AS production_reference_count,
            COALESCE(usage_refs.usage_reference_count, 0) AS usage_reference_count,
            CASE
                WHEN (
                    COALESCE(recipe_refs.recipe_reference_count, 0) +
                    COALESCE(delivery_refs.delivery_reference_count, 0) +
                    COALESCE(production_refs.production_reference_count, 0) +
                    COALESCE(usage_refs.usage_reference_count, 0)
                ) = 0 THEN 1
                ELSE 0
            END AS can_delete
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         LEFT JOIN (
            SELECT ingredient_id, COUNT(*) AS recipe_reference_count
            FROM bakeshop_product_recipe
            GROUP BY ingredient_id
         ) recipe_refs ON recipe_refs.ingredient_id = i.id
         LEFT JOIN (
            SELECT ingredient_id, COUNT(*) AS delivery_reference_count
            FROM bakeshop_delivery_items
            GROUP BY ingredient_id
         ) delivery_refs ON delivery_refs.ingredient_id = i.id
         LEFT JOIN (
            SELECT ingredient_id, COUNT(*) AS production_reference_count
            FROM bakeshop_production_items
            GROUP BY ingredient_id
         ) production_refs ON production_refs.ingredient_id = i.id
         LEFT JOIN (
            SELECT ingredient_id, COUNT(*) AS usage_reference_count
            FROM bakeshop_ingredient_usage
            GROUP BY ingredient_id
         ) usage_refs ON usage_refs.ingredient_id = i.id
         ORDER BY i.name ASC'
    );
}

function bakeshopCatalogNormalizeDeleteIds(mixed $value, string $field = 'ids'): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('Select at least one record to delete.');
    }

    $ids = [];
    foreach ($value as $candidate) {
        $id = bakeshopCatalogRequirePositiveInt($candidate, $field);
        $ids[$id] = $id;
    }

    if ($ids === []) {
        throw new InvalidArgumentException('Select at least one record to delete.');
    }

    return array_values($ids);
}

function bakeshopCatalogDeleteProductsBatch(array $input): array
{
    $ids = bakeshopCatalogNormalizeDeleteIds($input['ids'] ?? null);
    $db = bakeshopDb();
    $deleted = [];

    $db->beginTransaction();
    try {
        foreach ($ids as $id) {
            $deleted[] = bakeshopCatalogDeleteProduct(['id' => $id]);
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

function bakeshopCatalogDeleteIngredientsBatch(array $input): array
{
    $ids = bakeshopCatalogNormalizeDeleteIds($input['ids'] ?? null);
    $db = bakeshopDb();
    $deleted = [];

    $db->beginTransaction();
    try {
        foreach ($ids as $id) {
            $deleted[] = bakeshopCatalogDeleteIngredient(['id' => $id]);
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

function bakeshopCatalogListRecipes(): array
{
    return bakeshopCatalogFetchAll(
        'SELECT
            r.id,
            r.product_id,
            r.ingredient_id,
            r.qty,
            r.unit_id,
            r.notes,
            r.created_at,
            r.updated_at,
            p.name AS product_name,
            i.name AS ingredient_name,
            u.code AS unit_code,
            u.dimension AS unit_dimension
         FROM bakeshop_product_recipe r
         INNER JOIN bakeshop_products p ON p.id = r.product_id
         INNER JOIN bakeshop_ingredients i ON i.id = r.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = r.unit_id
         ORDER BY p.name ASC, i.name ASC, u.sort_order ASC'
    );
}

function bakeshopCatalogFindRecipeLine(int $productId, int $ingredientId, int $unitId): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT
            r.id,
            r.product_id,
            r.ingredient_id,
            r.qty,
            r.unit_id,
            r.notes,
            r.created_at,
            r.updated_at,
            p.name AS product_name,
            i.name AS ingredient_name,
            u.code AS unit_code,
            u.dimension AS unit_dimension
         FROM bakeshop_product_recipe r
         INNER JOIN bakeshop_products p ON p.id = r.product_id
         INNER JOIN bakeshop_ingredients i ON i.id = r.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = r.unit_id
         WHERE r.product_id = :product_id AND r.ingredient_id = :ingredient_id AND r.unit_id = :unit_id
         LIMIT 1',
        [
            ':product_id' => $productId,
            ':ingredient_id' => $ingredientId,
            ':unit_id' => $unitId,
        ]
    );
}

function bakeshopCatalogFindRecipeById(int $id): ?array
{
    return bakeshopCatalogFetchOne(
        'SELECT
            r.id,
            r.product_id,
            r.ingredient_id,
            r.qty,
            r.unit_id,
            r.notes,
            r.created_at,
            r.updated_at,
            p.name AS product_name,
            i.name AS ingredient_name,
            u.code AS unit_code,
            u.dimension AS unit_dimension
         FROM bakeshop_product_recipe r
         INNER JOIN bakeshop_products p ON p.id = r.product_id
         INNER JOIN bakeshop_ingredients i ON i.id = r.ingredient_id
         INNER JOIN bakeshop_units u ON u.id = r.unit_id
         WHERE r.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function bakeshopCatalogSaveProduct(array $input): array
{
    $id = null;
    $existing = null;
    if (($input['id'] ?? null) !== null && (string)$input['id'] !== '') {
        $id = bakeshopCatalogRequirePositiveInt($input['id'], 'id');
        $existing = bakeshopCatalogFindProductById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Product not found.');
        }
    }

    $name = bakeshopCatalogRequireName($input['name'] ?? null);
    $sku = trim((string)($input['sku'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $defaultYieldQty = bakeshopCatalogRequirePositiveDecimal($input['default_yield_qty'] ?? 1, 'default_yield_qty');
    $isActive = bakeshopCatalogNormalizeActiveFlag($input['is_active'] ?? null, (int)($existing['is_active'] ?? 1));

    $defaultYieldUnitId = null;
    if (($input['default_yield_unit_id'] ?? null) !== null && (string)$input['default_yield_unit_id'] !== '') {
        $defaultYieldUnitId = bakeshopCatalogRequirePositiveInt($input['default_yield_unit_id'], 'default_yield_unit_id');
        bakeshopCatalogAssertRecordExists('bakeshop_units', $defaultYieldUnitId);
    }

    if ($existing !== null) {
        $stmt = bakeshopDb()->prepare(
            'UPDATE bakeshop_products
             SET sku = :sku,
                 name = :name,
                 category = :category,
                 default_yield_qty = :default_yield_qty,
                 default_yield_unit_id = :default_yield_unit_id,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':sku' => $sku !== '' ? $sku : null,
            ':name' => $name,
            ':category' => $category !== '' ? $category : null,
            ':default_yield_qty' => $defaultYieldQty,
            ':default_yield_unit_id' => $defaultYieldUnitId,
            ':is_active' => $isActive,
        ]);

        $row = bakeshopCatalogFindProductById($id) ?? [];
        bakeshopAudit(
            bakeshopResolveActiveMutationAction('bakeshop.product', $existing, $row),
            null,
            'bakeshop_products',
            (string)$id,
            $existing,
            $row
        );

        return $row;
    }

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_products (sku, name, category, default_yield_qty, default_yield_unit_id, is_active)
         VALUES (:sku, :name, :category, :default_yield_qty, :default_yield_unit_id, :is_active)'
    );
    $stmt->execute([
        ':sku' => $sku !== '' ? $sku : null,
        ':name' => $name,
        ':category' => $category !== '' ? $category : null,
        ':default_yield_qty' => $defaultYieldQty,
        ':default_yield_unit_id' => $defaultYieldUnitId,
        ':is_active' => $isActive,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopCatalogFindProductById($id);

    app()->events()->fire('bakeshop.product.created', [
        'id' => $id,
        'sku' => $sku,
        'name' => $name,
        'category' => $category,
    ]);

    bakeshopAudit('bakeshop.product.created', null, 'bakeshop_products', (string)$id, null, $row);

    return $row ?? [];
}

function bakeshopCatalogCreateProduct(array $input): array
{
    return bakeshopCatalogSaveProduct($input);
}

function bakeshopCatalogSaveUnit(array $input): array
{
    $id = null;
    $existing = null;
    if (($input['id'] ?? null) !== null && (string)$input['id'] !== '') {
        $id = bakeshopCatalogRequirePositiveInt($input['id'], 'id');
        $existing = bakeshopCatalogFindUnitById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Unit not found.');
        }
    }

    $code = bakeshopCatalogRequireUnitCode($input['code'] ?? null);
    $name = bakeshopCatalogRequireName($input['name'] ?? null);
    $dimension = bakeshopCatalogRequireUnitDimension($input['dimension'] ?? null);

    $factorRaw = $input['factor_to_base'] ?? 1;
    if (!is_numeric($factorRaw) || (float)$factorRaw <= 0) {
        throw new InvalidArgumentException('factor_to_base must be greater than zero.');
    }

    $factorToBase = number_format((float)$factorRaw, 6, '.', '');
    $existingCode = bakeshopCatalogFetchOne(
        'SELECT id FROM bakeshop_units WHERE LOWER(code) = LOWER(:code) AND (:id IS NULL OR id <> :id) LIMIT 1',
        [':code' => $code, ':id' => $id]
    );
    if ($existingCode !== null) {
        throw new InvalidArgumentException('Unit code already exists.');
    }

    if ($existing !== null) {
        $referenceCount = bakeshopCatalogUnitReferenceCount($id ?? 0);
        $dimensionChanged = (string)($existing['dimension'] ?? '') !== $dimension;
        $factorChanged = number_format((float)($existing['factor_to_base'] ?? 1), 6, '.', '') !== $factorToBase;
        if ($referenceCount > 0 && ($dimensionChanged || $factorChanged)) {
            throw new InvalidArgumentException('Units already used in ingredients, recipes, deliveries, production, or dependent conversions can only update code and name.');
        }
    }

    $baseUnitId = null;
    if (abs((float)$factorToBase - 1.0) > 0.0000005) {
        $bindings = [':dimension' => $dimension];
        $sql = 'SELECT id
             FROM bakeshop_units
             WHERE dimension = :dimension AND factor_to_base = 1.000000';
        if ($id !== null) {
            $sql .= ' AND id <> :current_id';
            $bindings[':current_id'] = $id;
        }
        $sql .= ' ORDER BY sort_order ASC, code ASC LIMIT 1';

        $baseUnit = bakeshopCatalogFetchOne(
            $sql,
            $bindings
        );
        if ($baseUnit === null) {
            throw new InvalidArgumentException('A base unit for this dimension must exist before adding conversion units.');
        }
        $baseUnitId = (int)($baseUnit['id'] ?? 0);
    }

    $sortOrder = (int)($existing['sort_order'] ?? 0);
    if ($existing === null || (string)($existing['dimension'] ?? '') !== $dimension) {
        $sortOrderRow = bakeshopCatalogFetchOne(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort_order FROM bakeshop_units WHERE dimension = :dimension',
            [':dimension' => $dimension]
        );
        $sortOrder = max(10, (int)($sortOrderRow['next_sort_order'] ?? 10));
    }

    if ($existing !== null) {
        $stmt = bakeshopDb()->prepare(
            'UPDATE bakeshop_units
             SET code = :code,
                 name = :name,
                 dimension = :dimension,
                 base_unit_id = :base_unit_id,
                 factor_to_base = :factor_to_base,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':code' => $code,
            ':name' => $name,
            ':dimension' => $dimension,
            ':base_unit_id' => $baseUnitId,
            ':factor_to_base' => $factorToBase,
            ':sort_order' => $sortOrder,
        ]);

        $row = bakeshopCatalogFindUnitById($id ?? 0) ?? [];
        bakeshopAudit(
            bakeshopResolveActiveMutationAction('bakeshop.unit', $existing, $row),
            null,
            'bakeshop_units',
            (string)$id,
            $existing,
            $row
        );

        return $row;
    }

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_units (code, name, dimension, base_unit_id, factor_to_base, sort_order)
         VALUES (:code, :name, :dimension, :base_unit_id, :factor_to_base, :sort_order)'
    );
    $stmt->execute([
        ':code' => $code,
        ':name' => $name,
        ':dimension' => $dimension,
        ':base_unit_id' => $baseUnitId,
        ':factor_to_base' => $factorToBase,
        ':sort_order' => $sortOrder,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopCatalogFindUnitById($id) ?? [];

    app()->events()->fire('bakeshop.unit.created', [
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'dimension' => $dimension,
    ]);

    bakeshopAudit('bakeshop.unit.created', null, 'bakeshop_units', (string)$id, null, $row);

    return $row;
}

function bakeshopCatalogSaveIngredient(array $input): array
{
    $id = null;
    $existing = null;
    if (($input['id'] ?? null) !== null && (string)$input['id'] !== '') {
        $id = bakeshopCatalogRequirePositiveInt($input['id'], 'id');
        $existing = bakeshopCatalogFindIngredientById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Ingredient not found.');
        }
    }

    $name = bakeshopCatalogRequireName($input['name'] ?? null);
    $sku = trim((string)($input['sku'] ?? ''));
    $defaultUnitId = bakeshopCatalogRequirePositiveInt($input['default_unit_id'] ?? null, 'default_unit_id');
    $isActive = bakeshopCatalogNormalizeActiveFlag($input['is_active'] ?? null, (int)($existing['is_active'] ?? 1));
    bakeshopCatalogAssertRecordExists('bakeshop_units', $defaultUnitId);

    if ($existing !== null) {
        $stmt = bakeshopDb()->prepare(
            'UPDATE bakeshop_ingredients
             SET sku = :sku,
                 name = :name,
                 default_unit_id = :default_unit_id,
                 is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':sku' => $sku !== '' ? $sku : null,
            ':name' => $name,
            ':default_unit_id' => $defaultUnitId,
            ':is_active' => $isActive,
        ]);

        $row = bakeshopCatalogFindIngredientById($id) ?? [];
        bakeshopAudit(
            bakeshopResolveActiveMutationAction('bakeshop.ingredient', $existing, $row),
            null,
            'bakeshop_ingredients',
            (string)$id,
            $existing,
            $row
        );

        return $row;
    }

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_ingredients (sku, name, default_unit_id, is_active)
         VALUES (:sku, :name, :default_unit_id, :is_active)'
    );
    $stmt->execute([
        ':sku' => $sku !== '' ? $sku : null,
        ':name' => $name,
        ':default_unit_id' => $defaultUnitId,
        ':is_active' => $isActive,
    ]);

    $id = (int)bakeshopDb()->lastInsertId();
    $row = bakeshopCatalogFindIngredientById($id);

    app()->events()->fire('bakeshop.ingredient.created', [
        'id' => $id,
        'sku' => $sku,
        'name' => $name,
        'default_unit_id' => $defaultUnitId,
    ]);

    bakeshopAudit('bakeshop.ingredient.created', null, 'bakeshop_ingredients', (string)$id, null, $row);

    return $row ?? [];
}

function bakeshopCatalogCreateIngredient(array $input): array
{
    return bakeshopCatalogSaveIngredient($input);
}

function bakeshopCatalogSaveRecipe(array $input): array
{
    $productId = bakeshopCatalogRequirePositiveInt($input['product_id'] ?? null, 'product_id');
    $ingredientId = bakeshopCatalogRequirePositiveInt($input['ingredient_id'] ?? null, 'ingredient_id');
    $unitId = bakeshopCatalogRequirePositiveInt($input['unit_id'] ?? null, 'unit_id');
    $qty = bakeshopCatalogRequirePositiveDecimal($input['qty'] ?? null, 'qty');
    $notes = trim((string)($input['notes'] ?? ''));

    bakeshopCatalogAssertRecordExists('bakeshop_products', $productId);
    bakeshopCatalogAssertRecordExists('bakeshop_ingredients', $ingredientId);
    bakeshopCatalogAssertRecordExists('bakeshop_units', $unitId);
    bakeshopAssertIngredientUnitCompatible($ingredientId, $unitId, 'unit_id');

    $existing = bakeshopCatalogFindRecipeLine($productId, $ingredientId, $unitId);

    $stmt = bakeshopDb()->prepare(
        'INSERT INTO bakeshop_product_recipe (product_id, ingredient_id, qty, unit_id, notes)
         VALUES (:product_id, :ingredient_id, :qty, :unit_id, :notes)
         ON DUPLICATE KEY UPDATE qty = VALUES(qty), notes = VALUES(notes), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':ingredient_id' => $ingredientId,
        ':qty' => $qty,
        ':unit_id' => $unitId,
        ':notes' => $notes !== '' ? $notes : null,
    ]);

    $row = bakeshopCatalogFindRecipeLine($productId, $ingredientId, $unitId);

    app()->events()->fire('bakeshop.recipe.saved', [
        'product_id' => $productId,
        'ingredient_id' => $ingredientId,
        'unit_id' => $unitId,
        'qty' => $qty,
    ]);

    bakeshopAudit(
        $existing === null ? 'bakeshop.recipe.created' : 'bakeshop.recipe.updated',
        null,
        'bakeshop_product_recipe',
        (string)($row['id'] ?? ''),
        $existing,
        $row
    );

    return $row ?? [];
}

function bakeshopCatalogDeleteRecipe(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $row = bakeshopCatalogFindRecipeById($id);
    if ($row === null) {
        throw new InvalidArgumentException('Recipe line not found.');
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_product_recipe WHERE id = :id');
    $stmt->execute([':id' => $id]);

    bakeshopAudit('bakeshop.recipe.deleted', null, 'bakeshop_product_recipe', (string)$id, $row, null);

    return $row;
}

function bakeshopCatalogSetProductActive(int $id, mixed $value): array
{
    $existing = bakeshopCatalogFindProductById($id);
    if ($existing === null) {
        throw new InvalidArgumentException('Product not found.');
    }

    $isActive = bakeshopCatalogNormalizeActiveFlag($value, (int)($existing['is_active'] ?? 1));
    if ((int)($existing['is_active'] ?? 1) === $isActive) {
        return $existing;
    }

    $stmt = bakeshopDb()->prepare(
        'UPDATE bakeshop_products
         SET is_active = :is_active,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $id,
        ':is_active' => $isActive,
    ]);

    $row = bakeshopCatalogFindProductById($id) ?? [];
    bakeshopAudit(
        bakeshopResolveActiveMutationAction('bakeshop.product', $existing, $row),
        null,
        'bakeshop_products',
        (string)$id,
        $existing,
        $row
    );

    return $row;
}

function bakeshopCatalogDeleteProduct(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $row = bakeshopCatalogFindProductById($id);
    if ($row === null) {
        throw new InvalidArgumentException('Product not found.');
    }

    $productionCount = (int)(bakeshopCatalogFetchOne(
        'SELECT COUNT(*) AS aggregate_count FROM bakeshop_production_runs WHERE product_id = :id',
        [':id' => $id]
    )['aggregate_count'] ?? 0);
    if ($productionCount > 0) {
        throw new InvalidArgumentException('Cannot delete product that already has production runs. Archive it instead.');
    }

    $recipeCount = (int)(bakeshopCatalogFetchOne(
        'SELECT COUNT(*) AS aggregate_count FROM bakeshop_product_recipe WHERE product_id = :id',
        [':id' => $id]
    )['aggregate_count'] ?? 0);

    $deleteRecipeStmt = bakeshopDb()->prepare('DELETE FROM bakeshop_product_recipe WHERE product_id = :id');
    $deleteRecipeStmt->execute([':id' => $id]);

    $deleteProductStmt = bakeshopDb()->prepare('DELETE FROM bakeshop_products WHERE id = :id');
    $deleteProductStmt->execute([':id' => $id]);

    app()->events()->fire('bakeshop.product.deleted', [
        'id' => $id,
        'recipe_count' => $recipeCount,
    ]);

    $deletedRow = $row;
    $deletedRow['deleted_recipe_count'] = $recipeCount;
    bakeshopAudit('bakeshop.product.deleted', null, 'bakeshop_products', (string)$id, $row, null);

    return $deletedRow;
}

function bakeshopCatalogSetIngredientActive(int $id, mixed $value): array
{
    $existing = bakeshopCatalogFindIngredientById($id);
    if ($existing === null) {
        throw new InvalidArgumentException('Ingredient not found.');
    }

    $isActive = bakeshopCatalogNormalizeActiveFlag($value, (int)($existing['is_active'] ?? 1));
    if ((int)($existing['is_active'] ?? 1) === $isActive) {
        return $existing;
    }

    $stmt = bakeshopDb()->prepare(
        'UPDATE bakeshop_ingredients
         SET is_active = :is_active,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $id,
        ':is_active' => $isActive,
    ]);

    $row = bakeshopCatalogFindIngredientById($id) ?? [];
    bakeshopAudit(
        bakeshopResolveActiveMutationAction('bakeshop.ingredient', $existing, $row),
        null,
        'bakeshop_ingredients',
        (string)$id,
        $existing,
        $row
    );

    return $row;
}

function bakeshopCatalogDeleteIngredient(array $input): array
{
    $id = bakeshopCatalogRequirePositiveInt($input['id'] ?? null, 'id');
    $row = bakeshopCatalogFindIngredientById($id);
    if ($row === null) {
        throw new InvalidArgumentException('Ingredient not found.');
    }

    $references = [
        'recipe lines' => 'SELECT COUNT(*) AS aggregate_count FROM bakeshop_product_recipe WHERE ingredient_id = :id',
        'deliveries' => 'SELECT COUNT(*) AS aggregate_count FROM bakeshop_delivery_items WHERE ingredient_id = :id',
        'production snapshots' => 'SELECT COUNT(*) AS aggregate_count FROM bakeshop_production_items WHERE ingredient_id = :id',
        'usage history' => 'SELECT COUNT(*) AS aggregate_count FROM bakeshop_ingredient_usage WHERE ingredient_id = :id',
    ];

    foreach ($references as $label => $sql) {
        $count = (int)(bakeshopCatalogFetchOne($sql, [':id' => $id])['aggregate_count'] ?? 0);
        if ($count > 0) {
            throw new InvalidArgumentException('Cannot delete ingredient that is still used in ' . $label . '.');
        }
    }

    $stmt = bakeshopDb()->prepare('DELETE FROM bakeshop_ingredients WHERE id = :id');
    $stmt->execute([':id' => $id]);

    app()->events()->fire('bakeshop.ingredient.deleted', [
        'id' => $id,
    ]);

    bakeshopAudit('bakeshop.ingredient.deleted', null, 'bakeshop_ingredients', (string)$id, $row, null);

    return $row;
}

function bakeshopApiHealth(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopJsonOk([
            'service' => 'bakeshop',
            'tenant_id' => app()->tenant()->current(),
            'time' => gmdate('c'),
        ]);
    });
}

function bakeshopApiUnitsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopCatalogListUnits()]);
    });
}

function bakeshopApiUnitsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $isUpdate = (bakeshopInput('id') ?? null) !== null && (string)bakeshopInput('id') !== '';
        $item = bakeshopCatalogSaveUnit(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['units', 'products', 'ingredients', 'recipes'], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiProductsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopCatalogListProducts()]);
    });
}

function bakeshopApiIngredientsIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopCatalogListIngredients()]);
    });
}

function bakeshopApiRecipesIndex(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopJsonOk(['items' => bakeshopCatalogListRecipes()]);
    });
}

function bakeshopApiProductsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $isUpdate = ($input['id'] ?? null) !== null && (string)$input['id'] !== '';
        $item = bakeshopCatalogSaveProduct($input);
        bakeshopJsonMutationOk(['item' => $item], ['products', 'recipes', 'production'], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiProductsStatusUpdate(array $params = []): void
{
    bakeshopResponseGuard(static function () use ($params): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $id = bakeshopCatalogRequirePositiveInt($params['id'] ?? null, 'id');
        $input = (array)bakeshopInput();
        $item = bakeshopCatalogSetProductActive($id, $input['is_active'] ?? null);
        bakeshopJsonMutationOk(['item' => $item], ['products', 'recipes', 'production']);
    });
}

function bakeshopApiIngredientsStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $input = bakeshopInput();
        $isUpdate = ($input['id'] ?? null) !== null && (string)$input['id'] !== '';
        $item = bakeshopCatalogSaveIngredient($input);
        bakeshopJsonMutationOk(['item' => $item], ['ingredients', 'recipes', 'deliveries', 'usage'], $isUpdate ? 200 : 201);
    });
}

function bakeshopApiIngredientsStatusUpdate(array $params = []): void
{
    bakeshopResponseGuard(static function () use ($params): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $id = bakeshopCatalogRequirePositiveInt($params['id'] ?? null, 'id');
        $input = (array)bakeshopInput();
        $item = bakeshopCatalogSetIngredientActive($id, $input['is_active'] ?? null);
        bakeshopJsonMutationOk(['item' => $item], ['ingredients', 'recipes', 'deliveries', 'usage']);
    });
}

function bakeshopApiRecipesStore(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopCatalogSaveRecipe(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['recipes', 'products']);
    });
}

function bakeshopApiRecipesDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopCatalogDeleteRecipe(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['recipes', 'products']);
    });
}

function bakeshopApiProductsDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopCatalogDeleteProduct(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['products', 'recipes', 'production']);
    });
}

function bakeshopApiProductsBatchDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $result = bakeshopCatalogDeleteProductsBatch((array)bakeshopInput());
        bakeshopJsonMutationOk($result, ['products', 'recipes', 'production']);
    });
}

function bakeshopApiIngredientsDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $item = bakeshopCatalogDeleteIngredient(bakeshopInput());
        bakeshopJsonMutationOk(['item' => $item], ['ingredients', 'recipes', 'deliveries', 'usage']);
    });
}

function bakeshopApiIngredientsBatchDelete(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $result = bakeshopCatalogDeleteIngredientsBatch((array)bakeshopInput());
        bakeshopJsonMutationOk($result, ['ingredients', 'recipes', 'deliveries', 'usage']);
    });
}