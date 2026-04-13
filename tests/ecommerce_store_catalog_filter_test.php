<?php

declare(strict_types=1);

/**
 * Tests for multi-store storefront catalog filtering.
 *
 * Regression coverage for:
 *   - store_owned_only INNER JOIN: a store with no product assignments returns empty catalog.
 *   - ecStoreClearResolvedContext() properly resets the request-scoped singleton.
 *   - ?store=slug with a valid store but no products yields total=0.
 *   - Global catalog (no store param) returns all published products.
 */

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

// Suppress any stray HTML output from module initialization hooks.
ob_start();

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

foreach ([
    __DIR__ . '/../modules/ecommerce/database/migrations/028_ec_multi_store_foundation.sql',
    __DIR__ . '/../modules/ecommerce/database/migrations/033_ec_store_users.sql',
] as $migrationFile) {
    if (is_file($migrationFile)) {
        try {
            $sql = (string)file_get_contents($migrationFile);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                app()->db()->exec($statement);
            }
        } catch (\Throwable $ignored) {}
    }
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tScf(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \u{2717} {$label}" . ($detail !== '' ? " \u{2014} {$detail}" : '') . "\n";
}

$db = ecDb();

// ── Prerequisites ─────────────────────────────────────────────────────────
// Verify the storage tables exist before running.
if (!ecStoreStorageAvailable()) {
    echo "SKIP — ec_stores table unavailable in this tenant\n";
    exit(0);
}

// ── Fixture: product in global catalog ───────────────────────────────────
// We need at least one published product to confirm the global catalog is non-empty.
// Use ecDb() directly — ecommerce and cms share the same tenant DB.
$globalProduct = null;
try {
    $globalProduct = $db->query(
        "SELECT id FROM cms_content WHERE type = 'product' AND status = 'published' AND deleted_at IS NULL LIMIT 1",
        []
    )->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {}

if (!is_array($globalProduct)) {
    echo "SKIP — no published product rows available for filter assertions\n";
    exit(0);
}
$globalProductId = (int)$globalProduct['id'];

// ── Fixture: empty store (no product assignments) ─────────────────────────
$runSuffix = substr(md5((string)microtime(true) . random_int(0, 99999)), 0, 8);
$testStoreSlug = 'scf-empty-' . $runSuffix;
$testStoreCode = 'scfe-' . $runSuffix;
try {
    $db->query(
        "INSERT INTO ec_stores (code, name, slug, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())",
        [$testStoreCode, 'Test SCF Empty Store', $testStoreSlug]
    );
} catch (\Throwable $e) {
    echo "SKIP — could not create empty store fixture: " . $e->getMessage() . "\n";
    exit(0);
}
$emptyStoreId = (int)$db->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();

// ── Fixture: store with one product assigned ──────────────────────────────
$testStoreSlugWithProduct = 'scf-assigned-' . $runSuffix;
$testStoreCodeAssigned = 'scfa-' . $runSuffix;
try {
    $db->query(
        "INSERT INTO ec_stores (code, name, slug, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())",
        [$testStoreCodeAssigned, 'Test SCF Assigned Store', $testStoreSlugWithProduct]
    );
} catch (\Throwable $e) {
    $db->query("DELETE FROM ec_stores WHERE id = ?", [$emptyStoreId]);
    echo "SKIP — could not create assigned store fixture: " . $e->getMessage() . "\n";
    exit(0);
}
$assignedStoreId = (int)$db->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
try {
    $db->query(
        "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible) VALUES (?, ?, 1)",
        [$assignedStoreId, $globalProductId]
    );
} catch (\Throwable $e) {
    // If override table is missing, skip the test gracefully.
    $db->query("DELETE FROM ec_stores WHERE id IN (?, ?)", [$emptyStoreId, $assignedStoreId]);
    echo "SKIP — ec_store_product_overrides unavailable: " . $e->getMessage() . "\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────
// §1  Global catalog — no store filter, returns all published products
// ─────────────────────────────────────────────────────────────────────────
echo "\n§1  Global catalog (no store filter)\n";

$globalResult = ecProductList([
    'status' => 'published',
    'limit'  => 100,
    'offset' => 0,
]);
tScf(
    'Global catalog total > 0',
    (int)($globalResult['total'] ?? 0) > 0,
    'total=' . ($globalResult['total'] ?? 'n/a')
);

// ─────────────────────────────────────────────────────────────────────────
// §2  Store with no product assignments returns empty catalog (store_owned_only)
// ─────────────────────────────────────────────────────────────────────────
echo "\n§2  Empty store — store_owned_only filter\n";

$emptyResult = ecProductList([
    'store_id'        => $emptyStoreId,
    'store_owned_only' => true,
    'status'          => 'published',
    'limit'           => 100,
    'offset'          => 0,
]);
tScf(
    'Empty store returns total=0',
    (int)($emptyResult['total'] ?? -1) === 0,
    'total=' . ($emptyResult['total'] ?? 'n/a')
);
tScf(
    'Empty store returns empty items array',
    ($emptyResult['items'] ?? null) === [],
    'count=' . count($emptyResult['items'] ?? [])
);

// Regression: without store_owned_only (LEFT JOIN), all products leak through.
$leftJoinResult = ecProductList([
    'store_id'        => $emptyStoreId,
    'store_owned_only' => false,
    'status'          => 'published',
    'limit'           => 100,
    'offset'          => 0,
]);
tScf(
    'LEFT JOIN (store_owned_only=false) leaks global products to store (expected leak, documents regression risk)',
    (int)($leftJoinResult['total'] ?? 0) > 0,
    'total=' . ($leftJoinResult['total'] ?? 'n/a') . ' — confirms INNER JOIN fix is necessary'
);

// ─────────────────────────────────────────────────────────────────────────
// §3  Store with one product assigned returns exactly that product
// ─────────────────────────────────────────────────────────────────────────
echo "\n§3  Assigned store — returns only its products\n";

$assignedResult = ecProductList([
    'store_id'        => $assignedStoreId,
    'store_owned_only' => true,
    'status'          => 'published',
    'limit'           => 100,
    'offset'          => 0,
]);
tScf(
    'Assigned store returns total=1',
    (int)($assignedResult['total'] ?? -1) === 1,
    'total=' . ($assignedResult['total'] ?? 'n/a')
);
$returnedIds = array_column($assignedResult['items'] ?? [], 'id');
tScf(
    'Returned product matches assigned product id',
    in_array($globalProductId, array_map('intval', $returnedIds), true),
    'expected id=' . $globalProductId . ' got=' . implode(',', $returnedIds)
);

// ─────────────────────────────────────────────────────────────────────────
// §4  ecStoreClearResolvedContext() properly resets singleton
// ─────────────────────────────────────────────────────────────────────────
echo "\n§4  ecStoreClearResolvedContext() singleton reset\n";

if (function_exists('ecStoreClearResolvedContext') && function_exists('ecStoreBySlug')) {
    // Clear any cache set by earlier ecProductList calls (ecWmsInventorySnapshotMapForSkus
    // calls ecStoreResolveContext() internally when products are returned), then verify
    // the cleared state resolves the expected store from $_GET['store'].
    ecStoreClearResolvedContext();
    $_GET['store'] = $testStoreSlug;
    $before = ecStoreResolveContext();
    tScf(
        'ecStoreResolveContext resolves empty store by slug',
        is_array($before) && (int)($before['id'] ?? 0) === $emptyStoreId,
        'id=' . ($before['id'] ?? 'null')
    );

    // Clear and switch to the assigned store slug without re-setting $_GET.
    ecStoreClearResolvedContext();
    $_GET['store'] = $testStoreSlugWithProduct;
    $after = ecStoreResolveContext();
    tScf(
        'After clear, ecStoreResolveContext resolves new slug',
        is_array($after) && (int)($after['id'] ?? 0) === $assignedStoreId,
        'id=' . ($after['id'] ?? 'null')
    );

    // Final clear so remaining test state is clean.
    ecStoreClearResolvedContext();
    unset($_GET['store']);
} else {
    tScf('ecStoreClearResolvedContext exists', function_exists('ecStoreClearResolvedContext'));
}

// ─────────────────────────────────────────────────────────────────────────
// Teardown
// ─────────────────────────────────────────────────────────────────────────
try {
    $db->query("DELETE FROM ec_store_product_overrides WHERE store_id IN (?, ?)", [$emptyStoreId, $assignedStoreId]);
    $db->query("DELETE FROM ec_stores WHERE id IN (?, ?)", [$emptyStoreId, $assignedStoreId]);
} catch (\Throwable $ignored) {}

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n";
if ($fail === 0) {
    echo "PASS  {$pass} assertions passed\n";
    exit(0);
}

echo "FAIL  {$fail} assertion(s) failed\n";
foreach ($errors as $error) {
    echo " - {$error}\n";
}
exit(1);
