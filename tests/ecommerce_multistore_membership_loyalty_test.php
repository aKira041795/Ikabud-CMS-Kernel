<?php

declare(strict_types=1);

/**
 * Tests for:
 *   - Milestone 1 (memberships & loyalty operational wiring)
 *   - Milestone 2 (multi-store data foundation)
 */

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

// Ensure migrations are applied
foreach ([
    __DIR__ . '/../modules/ecommerce/database/migrations/025_ec_phase6_product_options_and_loyalty.sql',
    __DIR__ . '/../modules/ecommerce/database/migrations/028_ec_multi_store_foundation.sql',
] as $migFile) {
    if (is_file($migFile)) {
        try { app()->db()->exec((string)file_get_contents($migFile)); } catch (\Throwable $e) {}
    }
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tMsl(string $label, bool $ok, string $detail = ''): void
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

// ── Customer fixture ──────────────────────────────────────────────────────

$customer = ecDb()->query(
    "SELECT id, email FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!is_array($customer) || (int)($customer['id'] ?? 0) < 1) {
    echo "SKIP — no active cms_users row available\n";
    exit(0);
}
$customerId    = (int)$customer['id'];
$customerEmail = (string)$customer['email'];

// ─────────────────────────────────────────────────────────────────────────
// §1  ecMembershipNormalizeRow — days_remaining field
// ─────────────────────────────────────────────────────────────────────────

echo "\n§1  Membership normalizer\n";

$rowActive = [
    'id' => 1, 'customer_id' => $customerId, 'customer_email' => $customerEmail,
    'membership_tier' => 'gold', 'status' => 'active', 'duration_days' => 365,
    'starts_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
    'ends_at'   => date('Y-m-d H:i:s', strtotime('+20 days')),
    'order_id'  => null,
];
$norm = ecMembershipNormalizeRow($rowActive);

tMsl('is_active = true when status=active and ends_at in future', (bool)($norm['is_active'] ?? false));
tMsl('days_remaining is set (not null)', $norm['days_remaining'] !== null);
tMsl('days_remaining is ~20 days', isset($norm['days_remaining']) && $norm['days_remaining'] >= 19 && $norm['days_remaining'] <= 21);

$rowExpired = array_merge($rowActive, ['ends_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
$normExp    = ecMembershipNormalizeRow($rowExpired);
tMsl('is_active = false when ends_at in past', !(bool)($normExp['is_active'] ?? true));
tMsl('days_remaining = 0 when already expired', ($normExp['days_remaining'] ?? -1) === 0);

$rowOpenEnded = array_merge($rowActive, ['ends_at' => null]);
$normOpen     = ecMembershipNormalizeRow($rowOpenEnded);
tMsl('days_remaining = null for open-ended membership', $normOpen['days_remaining'] === null);

// ─────────────────────────────────────────────────────────────────────────
// §2  Loyalty helpers — configurable rates
// ─────────────────────────────────────────────────────────────────────────

echo "\n§2  Configurable loyalty rates\n";

$earnRate     = ecLoyaltyEarnRatePerCurrencyUnit();
$ptsPerUnit   = ecLoyaltyPointsPerCurrencyUnit();
$minRedeem    = ecLoyaltyMinimumRedeemPoints();

tMsl('ecLoyaltyEarnRatePerCurrencyUnit returns int >= 1', is_int($earnRate) && $earnRate >= 1);
tMsl('ecLoyaltyPointsPerCurrencyUnit returns int >= 1', is_int($ptsPerUnit) && $ptsPerUnit >= 1);
tMsl('ecLoyaltyMinimumRedeemPoints returns int >= 0', is_int($minRedeem) && $minRedeem >= 0);

// ─────────────────────────────────────────────────────────────────────────
// §3  ecLoyaltyAdminAdjust — credit and debit
// ─────────────────────────────────────────────────────────────────────────

echo "\n§3  Loyalty admin adjust\n";

if (!ecLoyaltyStorageAvailable()) {
    echo "  SKIP — ec_loyalty_ledger table unavailable\n";
} else {
    $balanceBefore = ecCustomerLoyaltyPointsBalance($customerId);

    $creditResult = ecLoyaltyAdminAdjust($customerId, 500, 'Test credit', 0);
    tMsl('credit result ok', (bool)($creditResult['ok'] ?? false));

    $balanceAfterCredit = ecCustomerLoyaltyPointsBalance($customerId);
    tMsl('balance increased by 500 after credit', $balanceAfterCredit === $balanceBefore + 500,
        "before={$balanceBefore} after={$balanceAfterCredit}");

    $debitResult = ecLoyaltyAdminAdjust($customerId, -200, 'Test debit', 0);
    tMsl('debit result ok', (bool)($debitResult['ok'] ?? false));

    $balanceAfterDebit = ecCustomerLoyaltyPointsBalance($customerId);
    tMsl('balance decreased by 200 after debit', $balanceAfterDebit === $balanceBefore + 300,
        "expected=" . ($balanceBefore + 300) . " got={$balanceAfterDebit}");

    // Cleanup
    try {
        app()->db()->query(
            "DELETE FROM ec_loyalty_ledger WHERE customer_id = ? AND description LIKE 'Test %'",
            [$customerId]
        );
    } catch (\Throwable $e) {}

    $debitInvalid = ecLoyaltyAdminAdjust(0, 100, 'Bad customer', 0);
    tMsl('invalid customer id returns ok=false', !(bool)($debitInvalid['ok'] ?? true));

    $zeroPoints = ecLoyaltyAdminAdjust($customerId, 0, 'Zero points', 0);
    tMsl('zero points returns ok=false', !(bool)($zeroPoints['ok'] ?? true));
}

// ─────────────────────────────────────────────────────────────────────────
// §4  Multi-store — storage and helpers
// ─────────────────────────────────────────────────────────────────────────

echo "\n§4  Multi-store storage\n";

$storeAvailable = ecStoreStorageAvailable();
tMsl('ecStoreStorageAvailable() = true after migration', $storeAvailable);

if (!$storeAvailable) {
    echo "  SKIP rest of §4-§7 — ec_stores unavailable\n";
    goto summary;
}

// ─────────────────────────────────────────────────────────────────────────
// §5  Default store
// ─────────────────────────────────────────────────────────────────────────

echo "\n§5  Default store seed\n";

$default = ecStoreDefault();
tMsl('ecStoreDefault() returns non-null', $default !== null);
tMsl('default store has is_default = 1', (bool)($default['is_default'] ?? false));
tMsl('default store is_active = 1', (bool)($default['is_active'] ?? false));
tMsl('default store has code', isset($default['code']) && $default['code'] !== '');
tMsl('default store has slug', isset($default['slug']) && $default['slug'] !== '');

// ─────────────────────────────────────────────────────────────────────────
// §6  Store lookup helpers
// ─────────────────────────────────────────────────────────────────────────

echo "\n§6  Store lookup\n";

$storeId = (int)($default['id'] ?? 0);

$byId = ecStoreById($storeId);
tMsl('ecStoreById finds default store', is_array($byId) && (int)($byId['id'] ?? 0) === $storeId);

$bySlug = ecStoreBySlug((string)($default['slug'] ?? ''));
tMsl('ecStoreBySlug finds default store', is_array($bySlug) && (int)($bySlug['id'] ?? 0) === $storeId);

$notFound = ecStoreBySlug('__nonexistent_store_slug__');
tMsl('ecStoreBySlug returns null for unknown slug', $notFound === null);

$notFoundId = ecStoreById(0);
tMsl('ecStoreById returns null for id=0', $notFoundId === null);

// ─────────────────────────────────────────────────────────────────────────
// §7  Context resolution
// ─────────────────────────────────────────────────────────────────────────

echo "\n§7  Store context resolution\n";

// No ?store= param or header set — should resolve to default
unset($_GET['store'], $_SERVER['HTTP_X_STORE_SLUG']);
$ctx = ecStoreResolveContext();
tMsl('ecStoreResolveContext() returns default store (no param/header)', $ctx !== null && (int)($ctx['id'] ?? 0) === $storeId);

// Shared test state can leave additional active stores behind. The helper must
// still reflect the actual active-store count for the tenant.
$multiActive = ecStoreIsMultiStoreActive();
$activeStoreCount = (int)(ecDb()->query('SELECT COUNT(*) FROM ec_stores WHERE is_active = 1')->fetchColumn() ?: 0);
tMsl('ecStoreIsMultiStoreActive matches active store count', $multiActive === ($activeStoreCount > 1), (string)$activeStoreCount);

// ─────────────────────────────────────────────────────────────────────────
// §8  Product overrides
// ─────────────────────────────────────────────────────────────────────────

echo "\n§8  Product overrides\n";

// Insert a test override
$testProductId = 999999;
try {
    ecDb()->query(
        "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, price_override, sale_price_override)
         VALUES (?, ?, 1, 49.99, 39.99)
         ON DUPLICATE KEY UPDATE price_override = 49.99, sale_price_override = 39.99, is_visible = 1",
        [$storeId, $testProductId]
    );

    $override = ecStoreProductOverride($storeId, $testProductId);
    tMsl('ecStoreProductOverride() returns row', is_array($override));
    tMsl('price_override = 49.99', isset($override['price_override']) && (float)$override['price_override'] === 49.99);
    tMsl('sale_price_override = 39.99', isset($override['sale_price_override']) && (float)$override['sale_price_override'] === 39.99);

    // Apply overrides to a mock product array
    $mockProduct = ['id' => $testProductId, 'price' => 99.00, 'sale_price' => null, 'title' => 'Test'];
    $applied     = ecStoreApplyProductOverrides($mockProduct, $default);
    tMsl('ecStoreApplyProductOverrides returns non-null when visible', $applied !== null);
    tMsl('price overridden to 49.99', isset($applied['price']) && (float)$applied['price'] === 49.99);
    tMsl('sale_price overridden to 39.99', isset($applied['sale_price']) && (float)$applied['sale_price'] === 39.99);

    // Hidden product
    ecDb()->query(
        "UPDATE ec_store_product_overrides SET is_visible = 0 WHERE store_id = ? AND product_id = ?",
        [$storeId, $testProductId]
    );
    $hidden = ecStoreApplyProductOverrides($mockProduct, $default);
    tMsl('ecStoreApplyProductOverrides returns null when is_visible=0', $hidden === null);

} catch (\Throwable $e) {
    tMsl('product override insert/query', false, $e->getMessage());
} finally {
    try {
        ecDb()->query(
            "DELETE FROM ec_store_product_overrides WHERE store_id = ? AND product_id = ?",
            [$storeId, $testProductId]
        );
    } catch (\Throwable $e) {}
}

// No override row → product returned unchanged
$noOverrideProd = ['id' => 888888, 'price' => 75.00, 'sale_price' => null];
$unchanged      = ecStoreApplyProductOverrides($noOverrideProd, $default);
tMsl('product with no override returned unchanged', $unchanged !== null && (float)$unchanged['price'] === 75.00);

// ─────────────────────────────────────────────────────────────────────────
// §9  Inventory source
// ─────────────────────────────────────────────────────────────────────────

echo "\n§9  Inventory source\n";

$src = ecStoreInventorySource($storeId);
tMsl('ecStoreInventorySource returns null when none configured', $src === null);

$srcInvalid = ecStoreInventorySource(0);
tMsl('ecStoreInventorySource returns null for id=0', $srcInvalid === null);

// ─────────────────────────────────────────────────────────────────────────
// §10  ec_orders.store_id column exists
// ─────────────────────────────────────────────────────────────────────────

echo "\n§10  ec_orders.store_id column\n";

try {
    $storeIdColRows = ecDb()->query("SHOW COLUMNS FROM ec_orders LIKE 'store_id'")->fetchAll(PDO::FETCH_ASSOC);
    tMsl('ec_orders.store_id column present', count($storeIdColRows) === 1);
} catch (\Throwable $e) {
    tMsl('ec_orders.store_id column present', false, $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────

summary:
echo "\n";
if ($fail === 0) {
    echo "PASS  {$pass} assertions passed\n";
    exit(0);
}
echo "FAIL  {$pass} passed, {$fail} failed\n";
foreach ($errors as $e) {
    echo "  - {$e}\n";
}
exit(1);
