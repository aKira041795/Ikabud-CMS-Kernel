<?php

declare(strict_types=1);

/** Daily Ledger — data-backed product categories and preservation oracle. */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-product-categories', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'capability.call');
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();
$migrationFile = $base . '/modules/daily-ledger/database/migrations/098_product_categories.sql';
$migration = (string)file_get_contents($migrationFile);
$moduleJson = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
$handlers = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');

// Applying the real, guarded migration is part of this integration oracle.
$migrationError = null;
try {
    $pdo = app()->dbForTenant(207);
    if (!$pdo instanceof PDO) throw new RuntimeException('tenant PDO unavailable');
    $pdo->exec($migration);
} catch (Throwable $e) {
    $migrationError = $e->getMessage();
}

$fixtureCode = 'pcat_fixture_99871';
$fixtureProduct = null;
$fixtureCategoryId = 0;
$cleanup = static function () use ($db, $fixtureCode, &$fixtureProduct): void {
    $exists = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_product_categories'")->fetchColumn();
    if ($exists === 0) return;
    if (is_array($fixtureProduct)) {
        $db->prepare('UPDATE dl_products SET category_id = :category_id, product_category = :product_category WHERE id = :id')
            ->execute([':category_id' => $fixtureProduct['category_id'], ':product_category' => $fixtureProduct['product_category'], ':id' => $fixtureProduct['id']]);
    }
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND entity_type = 'dl_product_categories' AND entity_id IN (SELECT CAST(id AS CHAR) FROM dl_product_categories WHERE code = :code)")
        ->execute([':code' => $fixtureCode]);
    $db->prepare('DELETE FROM dl_product_categories WHERE code = :code')->execute([':code' => $fixtureCode]);
};

$cleanup();
try {
    $h->section('migration and preservation');
    $tableExists = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dl_product_categories'")->fetchColumn();
    $seed = $db->query("SELECT code, `grouping` FROM dl_product_categories WHERE code IN ('bread','cake','other') ORDER BY FIELD(code,'bread','cake','other')")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $h->test('migration 098 is registered, applies, and creates the category table', $migrationError === null && str_contains($moduleJson, 'database/migrations/098_product_categories.sql') && $tableExists === 1, (string)$migrationError);
    $h->test('the exact legacy categories retain their approved groupings', $seed === ['bread' => 'bread', 'cake' => 'non_bread', 'other' => 'non_bread'], json_encode($seed));

    $nullCount = (int)$db->query('SELECT COUNT(*) FROM dl_products WHERE category_id IS NULL')->fetchColumn();
    // Plain comparison, deliberately WITHOUT CONVERT/COLLATE: the mirror column and the
    // category code must share a collation, or every future query that joins them dies with
    // MySQL 1267 (Illegal mix of collations). Converting here would hide a real schema defect.
    $mismatchCount = (int)$db->query('SELECT COUNT(*) FROM dl_products p LEFT JOIN dl_product_categories c ON c.id = p.category_id WHERE c.id IS NULL OR c.code <> p.product_category')->fetchColumn();
    $h->test('every product has a category_id and its mirror still means exactly the same thing', $nullCount === 0 && $mismatchCount === 0, "null={$nullCount} mismatch={$mismatchCount}");

    $legacyCounts = $db->query('SELECT product_category, COUNT(*) n FROM dl_products GROUP BY product_category ORDER BY product_category')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $newCounts = $db->query('SELECT c.code, COUNT(*) n FROM dl_products p INNER JOIN dl_product_categories c ON c.id = p.category_id GROUP BY c.code ORDER BY c.code')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $h->test('per-code counts equal the legacy mirror counts without magic numbers', $legacyCounts === $newCounts, json_encode(['legacy' => $legacyCounts, 'new' => $newCounts]));

    $ids = $db->query("SELECT c.code, MIN(p.id) id FROM dl_product_categories c INNER JOIN dl_products p ON p.category_id = c.id WHERE c.code IN ('bread','cake','other') GROUP BY c.code")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    // Bodega supplies what is NEITHER bread NOR cake (client rule 2026-11-13), so only 'other'
    // sources from Bodega; bread and cake both fall back to the caller's origin.
    $originOk = isset($ids['bread'], $ids['cake'], $ids['other'])
        && dl_supplyOriginForProduct((int)$ids['bread'], 'branch') === 'branch'
        && dl_supplyOriginForProduct((int)$ids['cake'], 'branch') === 'branch'
        && dl_supplyOriginForProduct((int)$ids['other'], 'branch') === 'bodega';
    $h->test('supply origin is Bodega only for other non-bread, and bread/cake fall back', $originOk, json_encode($ids));

    $h->section('new category whole chain');
    $db->prepare('INSERT INTO dl_product_categories (code,label,`grouping`,sort_order) VALUES (:code,:label,"non_bread",99871)')
        ->execute([':code' => $fixtureCode, ':label' => 'Fixture Category']);
    $fixtureCategoryId = (int)$db->lastInsertId();
    $fixtureProduct = $db->query('SELECT id, category_id, product_category FROM dl_products ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($fixtureProduct) {
        $db->prepare('UPDATE dl_products SET category_id = :category_id, product_category = :code WHERE id = :id')
            ->execute([':category_id' => $fixtureCategoryId, ':code' => $fixtureCode, ':id' => $fixtureProduct['id']]);
    }
    $filter = $db->prepare('SELECT COUNT(*) FROM dl_products p INNER JOIN dl_product_categories c ON c.id = p.category_id WHERE p.id = :product_id AND c.`grouping` = "non_bread"');
    $filter->execute([':product_id' => (int)($fixtureProduct['id'] ?? 0)]);
    $h->test('a new non-bread category filters as non_bread and sources from Bodega', (int)$filter->fetchColumn() === 1 && dl_supplyOriginForProduct((int)$fixtureProduct['id']) === 'bodega');

    $countStmt = $db->prepare('SELECT COUNT(*) FROM dl_products WHERE category_id = :category_id');
    $countStmt->execute([':category_id' => $fixtureCategoryId]);
    $usedCount = (int)$countStmt->fetchColumn();
    $deactivation = dl_updateProductCategoryCore($db, $fixtureCategoryId, 'Fixture Category', 'non_bread', 99871, 0);
    $h->test('deactivation is refused while products use the category and reports the count', $usedCount === 1 && ($deactivation['ok'] ?? true) === false && (int)($deactivation['status'] ?? 0) === 409 && (int)($deactivation['product_count'] ?? 0) === $usedCount && str_contains((string)($deactivation['error'] ?? ''), (string)$usedCount), json_encode($deactivation));

    $unknown = dl_validateProductCategoryInput(['category' => '__unknown_99871']);
    $h->test('unknown create/update categories are refused with 422 instead of defaulting', ($unknown['ok'] ?? true) === false && (int)($unknown['status'] ?? 0) === 422 && str_contains((string)($unknown['error'] ?? ''), '__unknown_99871'), json_encode($unknown));

    $h->test('migration contains the exact idempotent backfill', str_contains($migration, "UPDATE dl_products p\nINNER JOIN dl_product_categories c\n        ON c.code = CONVERT(p.product_category USING utf8mb4) COLLATE utf8mb4_unicode_ci\nSET p.category_id = c.id\nWHERE p.category_id IS NULL;"));
} finally {
    $cleanup();
}

$check = $db->prepare('SELECT COUNT(*) FROM dl_product_categories WHERE code = :code');
$check->execute([':code' => $fixtureCode]);
$h->test('fixture cleanup pin: private category is removed', (int)$check->fetchColumn() === 0);
$h->done();
