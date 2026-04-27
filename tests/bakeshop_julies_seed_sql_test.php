<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btSeedSql(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function btSeedSqlFindRow(array $rows, callable $predicate): ?array
{
    foreach ($rows as $row) {
        if ($predicate($row)) {
            return $row;
        }
    }

    return null;
}

function btSeedSqlExecStatements(PDO $db, string $sql): void
{
    $length = strlen($sql);
    $buffer = '';
    $delimiter = ';';
    $singleQuoted = false;
    $doubleQuoted = false;
    $lineComment = false;
    $blockComment = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
            }
            continue;
        }

        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }

        if (!$singleQuoted && !$doubleQuoted) {
            if ($char === '-' && $next === '-') {
                $lineComment = true;
                $index++;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
        }

        if ($char === "'" && !$doubleQuoted) {
            $escaped = $index > 0 && $sql[$index - 1] === '\\';
            if (!$escaped) {
                $singleQuoted = !$singleQuoted;
            }
        } elseif ($char === '"' && !$singleQuoted) {
            $escaped = $index > 0 && $sql[$index - 1] === '\\';
            if (!$escaped) {
                $doubleQuoted = !$doubleQuoted;
            }
        }

        if (!$singleQuoted && !$doubleQuoted && $char === $delimiter) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $db->exec($statement);
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $db->exec($statement);
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP JULIES SEED SQL TEST ===\n\n";

$seedPath = __DIR__ . '/../database/seeds/002_bakeshop_julies_bread_pastry.sql';
$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
$runner->migrate('bakeshop');

$seedSql = is_file($seedPath) ? (string)file_get_contents($seedPath) : '';
$validationBranchId = 0;
$deliveryId = 0;
$runId = 0;

try {
    btSeedSql('seed sql file exists', $seedSql !== '', $seedPath);
    btSeedSql('seed sql declares expected branch count comment', str_contains($seedSql, 'branches=10'), 'header mismatch');
    btSeedSql('seed sql declares expected product count comment', str_contains($seedSql, 'products=81'), 'header mismatch');
    btSeedSql('seed sql declares expected ingredient count comment', str_contains($seedSql, 'ingredients=30'), 'header mismatch');
    btSeedSql('seed sql declares expected recipe count comment', str_contains($seedSql, 'recipes=271'), 'header mismatch');

    $db->exec("DELETE FROM bakeshop_product_recipe WHERE notes LIKE 'Imported from Julie''s live bakery seed.%'");
    $db->exec("DELETE FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%'");
    $db->exec("DELETE FROM bakeshop_ingredients WHERE sku LIKE 'JBS-ING-%'");
    $db->exec("DELETE FROM bakeshop_branches WHERE code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')");
    $db->exec("DELETE FROM bakeshop_units WHERE code IN ('BOT', 'GAL', 'SACK')");

    btSeedSqlExecStatements($db, $seedSql);

    $seededBranches = (int)($db->query("SELECT COUNT(*) FROM bakeshop_branches WHERE external_store_id IS NOT NULL AND code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')")->fetchColumn() ?: 0);
    $seededProducts = (int)($db->query("SELECT COUNT(*) FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%'")->fetchColumn() ?: 0);
    $seededIngredients = (int)($db->query("SELECT COUNT(*) FROM bakeshop_ingredients WHERE sku LIKE 'JBS-ING-%'")->fetchColumn() ?: 0);
    $seededRecipes = (int)($db->query("SELECT COUNT(*) FROM bakeshop_product_recipe WHERE notes LIKE 'Imported from Julie''s live bakery seed.%'")->fetchColumn() ?: 0);
    btSeedSql('seed sql inserts Julie\'s branches', $seededBranches === 10, (string)$seededBranches);
    btSeedSql('seed sql inserts Julie\'s products', $seededProducts === 81, (string)$seededProducts);
    btSeedSql('seed sql inserts Julie\'s ingredients', $seededIngredients === 30, (string)$seededIngredients);
    btSeedSql('seed sql inserts Julie\'s recipes', $seededRecipes === 271, (string)$seededRecipes);

    $productsMissingYieldUnit = (int)($db->query("SELECT COUNT(*) FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%' AND default_yield_unit_id IS NULL")->fetchColumn() ?: 0);
    btSeedSql('seed sql assigns yield units to Julie\'s products', $productsMissingYieldUnit === 0, (string)$productsMissingYieldUnit);

    $sampleBranch = $db->query(
        "SELECT id, code, name, external_store_id
         FROM bakeshop_branches
         WHERE external_store_id IS NOT NULL AND code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')
         ORDER BY code ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    btSeedSql('sample seeded branch exists for setup flow', is_array($sampleBranch) && (int)($sampleBranch['id'] ?? 0) > 0, json_encode($sampleBranch, JSON_UNESCAPED_SLASHES));

    $sampleProduct = $db->query(
        "SELECT p.id, p.name, p.default_yield_qty, p.default_yield_unit_id, u.code AS default_yield_unit_code
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         INNER JOIN bakeshop_product_recipe r ON r.product_id = p.id
         WHERE p.sku LIKE 'JBS-PRD-%'
         GROUP BY p.id, p.name, p.default_yield_qty, p.default_yield_unit_id, u.code
         HAVING COUNT(r.id) BETWEEN 2 AND 6
         ORDER BY p.id ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($sampleProduct)) {
        throw new RuntimeException('Unable to find a seeded product with recipe lines.');
    }
    btSeedSql('sample seeded product with recipe exists', (int)($sampleProduct['id'] ?? 0) > 0, json_encode($sampleProduct, JSON_UNESCAPED_SLASHES));
    btSeedSql('sample seeded product uses piece yield units', (string)($sampleProduct['default_yield_unit_code'] ?? '') === 'pc', json_encode($sampleProduct, JSON_UNESCAPED_SLASHES));

    $sampleRecipeItems = $db->prepare(
        'SELECT r.ingredient_id, r.qty, r.unit_id, u.factor_to_base
         FROM bakeshop_product_recipe r
         INNER JOIN bakeshop_units u ON u.id = r.unit_id
         WHERE r.product_id = ?
         ORDER BY r.id ASC'
    );
    $sampleRecipeItems->execute([(int)$sampleProduct['id']]);
    $recipeItems = $sampleRecipeItems->fetchAll(PDO::FETCH_ASSOC);
    btSeedSql('seeded product recipe lines loaded', $recipeItems !== [], json_encode($recipeItems, JSON_UNESCAPED_SLASHES));

    $validationBranchId = (int)($sampleBranch['id'] ?? 0);

    $deliveryItems = [];
    foreach ($recipeItems as $item) {
        $deliveryItems[] = [
            'ingredient_id' => (int)$item['ingredient_id'],
            'unit_id' => (int)$item['unit_id'],
            'qty' => number_format(((float)$item['qty']) * 3, 4, '.', ''),
            'unit_cost' => null,
        ];
    }

    $delivery = bakeshopDeliveriesCreate([
        'branch_id' => $validationBranchId,
        'delivered_at' => '2026-04-27 08:00:00',
        'reference' => 'JSEEDSQL',
        'received_by' => 'Seed Validator',
        'notes' => 'Julie\'s seed SQL validation delivery',
        'items' => $deliveryItems,
    ]);
    $deliveryId = (int)($delivery['id'] ?? 0);
    btSeedSql('delivery created from seeded ingredients', $deliveryId > 0, json_encode($delivery, JSON_UNESCAPED_SLASHES));

    $run = bakeshopProductionCreate([
        'branch_id' => $validationBranchId,
        'product_id' => (int)$sampleProduct['id'],
        'produced_at' => '2026-04-27 10:00:00',
        'qty_produced' => max(1, (float)($sampleProduct['default_yield_qty'] ?? 1)),
        'produced_by' => 'Seed Validator',
        'notes' => 'Julie\'s seed SQL validation production',
    ]);
    $runId = (int)($run['id'] ?? 0);
    btSeedSql('production run created from seeded product', $runId > 0, json_encode($run, JSON_UNESCAPED_SLASHES));

    $usageRows = bakeshopUsageReportRows([
        'branch_id' => $validationBranchId,
        'from_date' => '2026-04-27',
        'to_date' => '2026-04-27',
    ]);
    $inventoryRows = bakeshopInventorySnapshotRows([
        'branch_id' => $validationBranchId,
    ]);
    btSeedSql('usage rows generated for seed validation flow', count($usageRows) >= count($recipeItems), json_encode($usageRows, JSON_UNESCAPED_SLASHES));
    btSeedSql('inventory snapshot rows generated for seed validation flow', count($inventoryRows) >= count($recipeItems), json_encode($inventoryRows, JSON_UNESCAPED_SLASHES));

    $firstItem = $recipeItems[0] ?? null;
    if (!is_array($firstItem)) {
        throw new RuntimeException('Seed validation recipe items are empty.');
    }

    $usageRow = btSeedSqlFindRow($usageRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === (int)$firstItem['ingredient_id']);
    $inventoryRow = btSeedSqlFindRow($inventoryRows, static fn (array $row): bool => (int)($row['ingredient_id'] ?? 0) === (int)$firstItem['ingredient_id']);
    $expectedDelivered = ((float)$firstItem['qty']) * 3 * ((float)$firstItem['factor_to_base']);
    $expectedConsumed = ((float)$firstItem['qty']) * ((float)$firstItem['factor_to_base']);
    btSeedSql('usage delivered qty matches seeded delivery after normalization', abs((float)($usageRow['delivered_qty_base'] ?? 0) - $expectedDelivered) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
    btSeedSql('usage consumed qty matches seeded recipe after normalization', abs((float)($usageRow['consumed_qty_base'] ?? 0) - $expectedConsumed) < 0.0001, json_encode($usageRow, JSON_UNESCAPED_SLASHES));
    btSeedSql('inventory snapshot reflects remaining seeded stock', abs((float)($inventoryRow['on_hand_qty_base'] ?? 0) - ($expectedDelivered - $expectedConsumed)) < 0.0001, json_encode($inventoryRow, JSON_UNESCAPED_SLASHES));
} finally {
    if ($runId > 0) {
        $db->prepare('DELETE FROM bakeshop_production_items WHERE run_id = ?')->execute([$runId]);
        $db->prepare('DELETE FROM bakeshop_production_runs WHERE id = ?')->execute([$runId]);
    }

    if ($deliveryId > 0) {
        $db->prepare('DELETE FROM bakeshop_delivery_items WHERE delivery_id = ?')->execute([$deliveryId]);
        $db->prepare('DELETE FROM bakeshop_deliveries WHERE id = ?')->execute([$deliveryId]);
    }

    $db->exec("DELETE FROM bakeshop_product_recipe WHERE notes LIKE 'Imported from Julie''s live bakery seed.%'");
    $db->exec("DELETE FROM bakeshop_products WHERE sku LIKE 'JBS-PRD-%'");
    $db->exec("DELETE FROM bakeshop_ingredients WHERE sku LIKE 'JBS-ING-%'");
    $db->exec("DELETE FROM bakeshop_branches WHERE code IN ('JB01', 'JES01', 'JL01', 'JMA01', 'JMIP01', 'JMN01', 'JP01', 'JPI01', 'JPO01', 'JTUR01')");
    $db->exec("DELETE FROM bakeshop_units WHERE code IN ('BOT', 'GAL', 'SACK')");
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btSeedSql('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btSeedSql('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);