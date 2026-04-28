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
    btSeedSql('seed sql omits recipe count comment', !str_contains($seedSql, 'recipes='), 'header mismatch');

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
    btSeedSql('seed sql does not import Julie\'s recipes', $seededRecipes === 0, (string)$seededRecipes);

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
        "SELECT p.id, p.sku, p.name, p.default_yield_qty, p.default_yield_unit_id, u.code AS default_yield_unit_code
         FROM bakeshop_products p
         LEFT JOIN bakeshop_units u ON u.id = p.default_yield_unit_id
         WHERE p.sku LIKE 'JBS-PRD-%'
         ORDER BY p.id ASC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    btSeedSql('sample seeded product exists', is_array($sampleProduct) && (int)($sampleProduct['id'] ?? 0) > 0, json_encode($sampleProduct, JSON_UNESCAPED_SLASHES));
    btSeedSql('sample seeded product keeps a yield unit', is_array($sampleProduct) && (string)($sampleProduct['default_yield_unit_code'] ?? '') !== '', json_encode($sampleProduct, JSON_UNESCAPED_SLASHES));
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