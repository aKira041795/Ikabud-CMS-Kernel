<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// CSV / Excel imports — Products, Recipes, Production
// All accept raw CSV text via `csv` field in JSON body or multipart file upload.
// ---------------------------------------------------------------------------

function bakeshopImportResolveRawCsv(): string
{
    // Try raw CSV text in JSON body first
    $raw = trim((string)(bakeshopInput('csv', '')));
    if ($raw !== '') {
        return $raw;
    }

    // Try uploaded file
    $file = kernelUploadedFile('csv_file');
    if ($file !== null && is_array($file) && ($file['tmp_name'] ?? '') !== '') {
        $raw = (string)file_get_contents((string)$file['tmp_name']);
        if ($raw === '') {
            throw new InvalidArgumentException('Uploaded CSV file is empty.');
        }
        return $raw;
    }

    throw new InvalidArgumentException('No CSV data provided. Send a `csv` text field or upload a `csv_file`.');
}

function bakeshopImportParseCsvFromInput(): array
{
    return bakeshopImportParseCsvString(bakeshopImportResolveRawCsv());
}

function bakeshopImportParseCsvString(string $raw): array
{
    // Normalize line endings
    $raw = str_replace("\r\n", "\n", $raw);
    $raw = str_replace("\r", "\n", $raw);
    $raw = trim($raw);

    if ($raw === '') {
        throw new InvalidArgumentException('CSV content is empty.');
    }

    $lines = explode("\n", $raw);
    if (count($lines) < 2) {
        throw new InvalidArgumentException('CSV must have a header row and at least one data row.');
    }

    // Parse header
    $header = str_getcsv(array_shift($lines), ',', '"', '\\');
    $header = array_map(static fn (string $col): string => strtolower(trim($col)), $header);
    if ($header === [] || ($header === [''])) {
        throw new InvalidArgumentException('CSV header row is empty.');
    }

    // Parse data rows
    $rows = [];
    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $cols = str_getcsv($line, ',', '"', '\\');
        $row = [];
        foreach ($header as $index => $colName) {
            $row[$colName] = isset($cols[$index]) ? trim($cols[$index]) : '';
        }

        // Skip completely empty rows
        if (array_filter($row, static fn (string $v): bool => $v !== '') === []) {
            continue;
        }

        $rows[] = $row;
    }

    if ($rows === []) {
        throw new InvalidArgumentException('No data rows found in CSV.');
    }

    return $rows;
}

function bakeshopImportResolveUnitByCodeOrId(string $codeOrId): int
{
    $codeOrId = trim($codeOrId);
    if ($codeOrId === '') {
        throw new InvalidArgumentException('Unit is required.');
    }

    if (ctype_digit($codeOrId)) {
        $id = (int)$codeOrId;
        bakeshopCatalogAssertRecordExists('bakeshop_units', $id);
        return $id;
    }

    $unit = bakeshopCatalogFetchOne(
        'SELECT id FROM bakeshop_units WHERE LOWER(code) = LOWER(:code) LIMIT 1',
        [':code' => $codeOrId]
    );

    if ($unit === null) {
        throw new InvalidArgumentException('Unit code not found: ' . $codeOrId);
    }

    return (int)$unit['id'];
}

function bakeshopImportResolveProductBySkuOrName(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // Try by SKU first
    $product = bakeshopCatalogFetchOne(
        'SELECT p.*, u.code AS default_yield_unit_code
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE p.sku = :sku LIMIT 1',
        [':sku' => $value]
    );

    if ($product !== null) {
        return $product;
    }

    // Try by name
    $product = bakeshopCatalogFetchOne(
        'SELECT p.*, u.code AS default_yield_unit_code
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE LOWER(p.name) = LOWER(:name) LIMIT 1',
        [':name' => $value]
    );

    return $product;
}

function bakeshopImportResolveIngredientBySkuOrName(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // Try by SKU first
    $ingredient = bakeshopCatalogFetchOne(
        'SELECT i.*, u.code AS default_unit_code, u.dimension AS unit_dimension
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         WHERE i.sku = :sku LIMIT 1',
        [':sku' => $value]
    );

    if ($ingredient !== null) {
        return $ingredient;
    }

    // Try by name
    $ingredient = bakeshopCatalogFetchOne(
        'SELECT i.*, u.code AS default_unit_code, u.dimension AS unit_dimension
         FROM bakeshop_ingredients i
         INNER JOIN bakeshop_units u ON u.id = i.default_unit_id
         WHERE LOWER(i.name) = LOWER(:name) LIMIT 1',
        [':name' => $value]
    );

    return $ingredient;
}

function bakeshopImportResolveBranchByCodeOrId(string $value): ?array
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (ctype_digit($value)) {
        return bakeshopDeliveriesFindBranchById((int)$value);
    }

    return bakeshopCatalogFetchOne(
        'SELECT id, code, name, is_active FROM bakeshop_branches WHERE LOWER(code) = LOWER(:code) LIMIT 1',
        [':code' => $value]
    );
}

// ===========================================================================
// 1. PRODUCT IMPORT
// ===========================================================================

function bakeshopImportProductsFromCsv(string $rawCsv): array
{
    $rows = bakeshopImportParseCsvString($rawCsv);

    $created = 0;
    $updated = 0;
    $errors = [];
    $items = [];

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2; // 1-based, skip header
        try {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('name is required.');
            }

            $sku = trim((string)($row['sku'] ?? ''));
            $category = trim((string)($row['category'] ?? ''));

            $yieldQtyRaw = $row['default_yield_qty'] ?? $row['yield_qty'] ?? '1';
            if (!is_numeric($yieldQtyRaw) || (float)$yieldQtyRaw <= 0) {
                throw new InvalidArgumentException('default_yield_qty must be a positive number.');
            }

            $defaultYieldUnitId = null;
            $unitCodeOrId = trim((string)($row['default_yield_unit_code'] ?? $row['default_yield_unit_id'] ?? $row['yield_unit'] ?? ''));
            if ($unitCodeOrId !== '') {
                $defaultYieldUnitId = bakeshopImportResolveUnitByCodeOrId($unitCodeOrId);
            }

            $isActiveRaw = trim((string)($row['is_active'] ?? '1'));
            $isActive = ($isActiveRaw === '0' || strcasecmp($isActiveRaw, 'false') === 0 || strcasecmp($isActiveRaw, 'no') === 0) ? 0 : 1;

            // Check if product exists by SKU
            $existing = null;
            if ($sku !== '') {
                $existing = bakeshopCatalogFetchOne(
                    'SELECT id FROM bakeshop_products WHERE sku = :sku LIMIT 1',
                    [':sku' => $sku]
                );
            }

            $productInput = [
                'name' => $name,
                'sku' => $sku,
                'category' => $category !== '' ? $category : null,
                'default_yield_qty' => number_format((float)$yieldQtyRaw, 4, '.', ''),
                'default_yield_unit_id' => $defaultYieldUnitId,
                'is_active' => $isActive,
            ];

            if ($existing !== null) {
                $productInput['id'] = (int)$existing['id'];
            }

            $saved = bakeshopCatalogSaveProduct($productInput);
            $items[] = $saved;

            if ($existing !== null) {
                $updated++;
            } else {
                $created++;
            }
        } catch (Throwable $e) {
            $errors[] = [
                'row' => $rowNum,
                'message' => $e->getMessage(),
                'data' => $row,
            ];
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'error_count' => count($errors),
        'total_rows' => count($rows),
        'items' => $items,
        'errors' => $errors,
    ];
}

// ===========================================================================
// 2. RECIPE IMPORT (by product)
// ===========================================================================

function bakeshopImportRecipesFromCsv(string $rawCsv): array
{
    bakeshopRequireProductRecipesEnabled();

    $rows = bakeshopImportParseCsvString($rawCsv);

    $created = 0;
    $updated = 0;
    $errors = [];
    $items = [];

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2;
        try {
            // Resolve product
            $productValue = trim((string)($row['product_sku'] ?? $row['product_name'] ?? $row['product'] ?? ''));
            if ($productValue === '') {
                throw new InvalidArgumentException('product_sku or product_name is required.');
            }
            $product = bakeshopImportResolveProductBySkuOrName($productValue);
            if ($product === null) {
                throw new InvalidArgumentException('Product not found: ' . $productValue);
            }
            $productId = (int)$product['id'];

            // Resolve ingredient
            $ingredientValue = trim((string)($row['ingredient_sku'] ?? $row['ingredient_name'] ?? $row['ingredient'] ?? ''));
            if ($ingredientValue === '') {
                throw new InvalidArgumentException('ingredient_sku or ingredient_name is required.');
            }
            $ingredient = bakeshopImportResolveIngredientBySkuOrName($ingredientValue);
            if ($ingredient === null) {
                throw new InvalidArgumentException('Ingredient not found: ' . $ingredientValue);
            }
            $ingredientId = (int)$ingredient['id'];

            // Resolve unit (if not provided, use ingredient's default unit)
            $unitCodeOrId = trim((string)($row['unit_code'] ?? $row['unit_id'] ?? ''));
            if ($unitCodeOrId === '') {
                $unitId = (int)$ingredient['default_unit_id'];
            } else {
                $unitId = bakeshopImportResolveUnitByCodeOrId($unitCodeOrId);
            }

            // Validate qty
            $qtyRaw = $row['qty'] ?? null;
            if ($qtyRaw === null || trim((string)$qtyRaw) === '') {
                throw new InvalidArgumentException('qty is required.');
            }
            if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                throw new InvalidArgumentException('qty must be a positive number.');
            }
            $qty = number_format((float)$qtyRaw, 4, '.', '');

            $notes = trim((string)($row['notes'] ?? ''));

            // Check if recipe line already exists
            $existing = bakeshopCatalogFindRecipeLine($productId, $ingredientId, $unitId);

            $saved = bakeshopCatalogSaveRecipe([
                'product_id' => $productId,
                'ingredient_id' => $ingredientId,
                'unit_id' => $unitId,
                'qty' => $qty,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $items[] = array_merge($saved, [
                '_product_name' => $product['name'],
                '_ingredient_name' => $ingredient['name'],
            ]);

            if ($existing !== null) {
                $updated++;
            } else {
                $created++;
            }
        } catch (Throwable $e) {
            $errors[] = [
                'row' => $rowNum,
                'message' => $e->getMessage(),
                'data' => $row,
            ];
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'error_count' => count($errors),
        'total_rows' => count($rows),
        'items' => $items,
        'errors' => $errors,
    ];
}

// ===========================================================================
// 3. PRODUCTION IMPORT (per product)
// ===========================================================================

function bakeshopImportProductionFromCsv(string $rawCsv): array
{
    $rows = bakeshopImportParseCsvString($rawCsv);

    $created = 0;
    $errors = [];
    $items = [];

    foreach ($rows as $index => $row) {
        $rowNum = $index + 2;
        try {
            // Resolve branch
            $branchValue = trim((string)($row['branch_code'] ?? $row['branch_id'] ?? $row['branch'] ?? ''));
            if ($branchValue === '') {
                throw new InvalidArgumentException('branch_code or branch_id is required.');
            }
            $branch = bakeshopImportResolveBranchByCodeOrId($branchValue);
            if ($branch === null) {
                throw new InvalidArgumentException('Branch not found: ' . $branchValue);
            }
            $branchId = (int)$branch['id'];

            // Resolve product
            $productValue = trim((string)($row['product_sku'] ?? $row['product_name'] ?? $row['product'] ?? ''));
            if ($productValue === '') {
                throw new InvalidArgumentException('product_sku or product_name is required.');
            }
            $product = bakeshopImportResolveProductBySkuOrName($productValue);
            if ($product === null) {
                throw new InvalidArgumentException('Product not found: ' . $productValue);
            }
            $productId = (int)$product['id'];

            // Qty produced
            $qtyRaw = $row['qty_produced'] ?? $row['qty'] ?? null;
            if ($qtyRaw === null || trim((string)$qtyRaw) === '') {
                throw new InvalidArgumentException('qty_produced is required.');
            }
            if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                throw new InvalidArgumentException('qty_produced must be a positive number.');
            }
            $qtyProduced = number_format((float)$qtyRaw, 4, '.', '');

            // Produced at date
            $producedAtRaw = trim((string)($row['produced_at'] ?? $row['date'] ?? ''));
            if ($producedAtRaw === '') {
                throw new InvalidArgumentException('produced_at is required.');
            }

            $producedBy = trim((string)($row['produced_by'] ?? ''));
            $notes = trim((string)($row['notes'] ?? ''));
            $relaxGuards = filter_var($row['relax_guards'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $productionInput = [
                'branch_id' => $branchId,
                'product_id' => $productId,
                'qty_produced' => $qtyProduced,
                'produced_at' => $producedAtRaw,
                'produced_by' => $producedBy,
                'notes' => $notes,
                'relax_guards' => $relaxGuards,
            ];

            $saved = bakeshopProductionCreate($productionInput);
            $items[] = array_merge($saved, [
                '_branch_name' => $branch['name'] ?? '',
                '_product_name' => $product['name'] ?? '',
            ]);

            $created++;
        } catch (Throwable $e) {
            $errors[] = [
                'row' => $rowNum,
                'message' => $e->getMessage(),
                'data' => $row,
            ];
        }
    }

    return [
        'created' => $created,
        'error_count' => count($errors),
        'total_rows' => count($rows),
        'items' => $items,
        'errors' => $errors,
    ];
}

// ===========================================================================
// API Handlers
// ===========================================================================

function bakeshopApiProductsImport(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $rawCsv = bakeshopImportResolveRawCsv();
        bakeshopJsonOk(bakeshopImportProductsFromCsv($rawCsv));
    });
}

function bakeshopApiRecipesImport(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $rawCsv = bakeshopImportResolveRawCsv();
        bakeshopJsonOk(bakeshopImportRecipesFromCsv($rawCsv));
    });
}

function bakeshopApiProductionImport(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopEnforceCsrf();
        bakeshopCurrentUser('bakeshop.manage');
        $rawCsv = bakeshopImportResolveRawCsv();
        bakeshopJsonOk(bakeshopImportProductionFromCsv($rawCsv));
    });
}

// ===========================================================================
// CSV Template Downloads
// ===========================================================================

function bakeshopSendCsvDownload(string $filename, string $csvContent): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $csvContent;
    exit;
}

function bakeshopCsvTemplateProducts(): string
{
    return implode("\n", [
        'sku,name,category,default_yield_qty,default_yield_unit_code,is_active',
        'BREAD-001,White Bread Loaf,Bread,10,kg,1',
        'PASTRY-001,Cheese Roll,Pastry,24,pc,1',
        'CAKE-001,Chocolate Cake,Cakes,1,pc,1',
    ]) . "\n";
}

function bakeshopCsvTemplateRecipes(): string
{
    return implode("\n", [
        'product_sku,ingredient_sku,qty,unit_code,notes',
        'BREAD-001,FLOUR-001,2.5,kg,Base flour',
        'BREAD-001,SUGAR-001,0.3,kg,Sweetener',
        'BREAD-001,YEAST-001,0.05,kg,Leavening',
    ]) . "\n";
}

function bakeshopCsvTemplateProduction(): string
{
    return implode("\n", [
        'branch_code,product_sku,qty_produced,produced_at,produced_by,notes',
        'BRANCH-A,BREAD-001,30,2026-06-12 08:00:00,Juan,Morning batch',
        'BRANCH-A,PASTRY-001,48,2026-06-12 09:00:00,Maria,Afternoon batch',
    ]) . "\n";
}

// -- API handlers (no CSRF needed for GET downloads) -----------------------

function bakeshopApiProductsImportTemplate(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopSendCsvDownload('bakeshop-products-template.csv', bakeshopCsvTemplateProducts());
    });
}

function bakeshopApiRecipesImportTemplate(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopSendCsvDownload('bakeshop-recipes-template.csv', bakeshopCsvTemplateRecipes());
    });
}

function bakeshopApiProductionImportTemplate(array $params = []): void
{
    bakeshopResponseGuard(static function (): void {
        bakeshopCurrentUser('bakeshop.read');
        bakeshopSendCsvDownload('bakeshop-production-template.csv', bakeshopCsvTemplateProduction());
    });
}
