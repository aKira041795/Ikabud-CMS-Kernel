<?php

declare(strict_types=1);

/**
 * Tests for Milestone 4 — Back-in-Stock Notifications
 * helpers/41-stock-notifications.php
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

// Apply migration
$migFile = __DIR__ . '/../modules/ecommerce/database/migrations/030_ec_stock_notifications.sql';
if (is_file($migFile)) {
    try { app()->db()->exec((string)file_get_contents($migFile)); } catch (\Throwable $e) {}
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tSn(string $label, bool $ok, string $detail = ''): void
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

// ─────────────────────────────────────────────────────────────────────────
// §1  Storage availability
// ─────────────────────────────────────────────────────────────────────────

echo "\n§1  Storage\n";
tSn('ecStockNotificationStorageAvailable() = true', ecStockNotificationStorageAvailable());

if (!ecStockNotificationStorageAvailable()) {
    echo "  SKIP rest — storage unavailable\n";
    goto summary;
}

// ─────────────────────────────────────────────────────────────────────────
// §2  Subscribe — happy path
// ─────────────────────────────────────────────────────────────────────────

echo "\n§2  Subscribe\n";

$testProductId = 444444;
$testEmail     = 'stocktest_' . substr(md5((string)microtime(true)), 0, 8) . '@example.com';

$r1 = ecStockNotificationSubscribe($testProductId, null, $testEmail);
tSn('subscribe returns ok=true', (bool)($r1['ok'] ?? false));
tSn('subscribe already_subscribed=false on first call', !(bool)($r1['already_subscribed'] ?? true));
tSn('subscribe error is empty on success', ($r1['error'] ?? '') === '');

// Check DB row exists
$count = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_stock_notifications WHERE product_id = ? AND customer_email = ? AND status = 'waiting'",
    [$testProductId, $testEmail]
)->fetchColumn();
tSn('row exists in DB with status=waiting', $count === 1);

// ─────────────────────────────────────────────────────────────────────────
// §3  Duplicate prevention
// ─────────────────────────────────────────────────────────────────────────

echo "\n§3  Duplicate prevention\n";

$r2 = ecStockNotificationSubscribe($testProductId, null, $testEmail);
tSn('second subscribe returns ok=true', (bool)($r2['ok'] ?? false));
tSn('second subscribe already_subscribed=true', (bool)($r2['already_subscribed'] ?? false));

$countAfterDup = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_stock_notifications WHERE product_id = ? AND customer_email = ? AND status = 'waiting'",
    [$testProductId, $testEmail]
)->fetchColumn();
tSn('still exactly one waiting row', $countAfterDup === 1);

// ─────────────────────────────────────────────────────────────────────────
// §4  Input validation
// ─────────────────────────────────────────────────────────────────────────

echo "\n§4  Validation\n";

$rBadEmail = ecStockNotificationSubscribe($testProductId, null, 'not-an-email');
tSn('invalid email returns ok=false', !(bool)($rBadEmail['ok'] ?? true));

$rBadProd = ecStockNotificationSubscribe(0, null, 'valid@example.com');
tSn('product_id=0 returns ok=false', !(bool)($rBadProd['ok'] ?? true));

$rEmptyEmail = ecStockNotificationSubscribe($testProductId, null, '');
tSn('empty email returns ok=false', !(bool)($rEmptyEmail['ok'] ?? true));

// ─────────────────────────────────────────────────────────────────────────
// §5  Variant-scoped subscriptions
// ─────────────────────────────────────────────────────────────────────────

echo "\n§5  Variant-scoped\n";

$variantId    = 555555;
$variantEmail = 'variant_' . substr(md5((string)microtime(true)), 0, 6) . '@example.com';

$rv = ecStockNotificationSubscribe($testProductId, $variantId, $variantEmail);
tSn('variant subscription ok', (bool)($rv['ok'] ?? false));

$countVariant = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_stock_notifications WHERE product_id = ? AND variant_id = ? AND customer_email = ? AND status = 'waiting'",
    [$testProductId, $variantId, $variantEmail]
)->fetchColumn();
tSn('variant row stored with correct variant_id', $countVariant === 1);

// Same email, same product, but different variant — should be independent
$countNoVariant = ecStockNotificationWaitersCount($testProductId, null);
tSn('waiters count for null variant is 1 (product-level only)', $countNoVariant === 1);

$countWithVariant = ecStockNotificationWaitersCount($testProductId, $variantId);
tSn('waiters count for specific variant is 1', $countWithVariant === 1);

// ─────────────────────────────────────────────────────────────────────────
// §6  ecStockNotificationWaiters
// ─────────────────────────────────────────────────────────────────────────

echo "\n§6  Waiters list\n";

$waiters = ecStockNotificationWaiters($testProductId);
tSn('ecStockNotificationWaiters returns array', is_array($waiters));
tSn('waiters count >= 1', count($waiters) >= 1);
tSn('first waiter has customer_email', isset($waiters[0]['customer_email']));

$waitersNone = ecStockNotificationWaiters(0);
tSn('product_id=0 returns empty array', empty($waitersNone));

// ─────────────────────────────────────────────────────────────────────────
// §7  ecStockNotificationCheckAndTrigger — no-op when prevQty > 0
// ─────────────────────────────────────────────────────────────────────────

echo "\n§7  Trigger logic\n";

// When previous qty is already > 0, trigger should not process anything
// (simulate by checking that status remains 'waiting' after a non-zero→nonzero transition)
ecStockNotificationCheckAndTrigger($testProductId, null, 5, 10); // prevQty=5, no back-in-stock
$stillWaiting = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_stock_notifications WHERE product_id = ? AND customer_email = ? AND status = 'waiting'",
    [$testProductId, $testEmail]
)->fetchColumn();
tSn('trigger with prevQty>0 does not mark as sent', $stillWaiting === 1);

// When prevQty <= 0 → newQty > 0, should process (but sendEmail not available in CLI — rows stay waiting)
// Check that function doesn't throw
$threw = false;
try {
    ecStockNotificationCheckAndTrigger($testProductId, null, 0, 5); // back in stock
} catch (\Throwable $e) {
    $threw = true;
}
tSn('trigger with prevQty=0 → newQty>0 does not throw', !$threw);

// ─────────────────────────────────────────────────────────────────────────
// §8  ecStockNotificationExpire
// ─────────────────────────────────────────────────────────────────────────

echo "\n§8  Expire\n";

// Insert a very old waiting row
$oldEmail = 'old_' . substr(md5((string)microtime(true)), 0, 6) . '@example.com';
ecDb()->query(
    "INSERT INTO ec_stock_notifications (product_id, variant_id, customer_email, customer_id, status, created_at)
     VALUES (?, NULL, ?, NULL, 'waiting', DATE_SUB(NOW(), INTERVAL 91 DAY))",
    [$testProductId, $oldEmail]
);

ecStockNotificationExpire(90);

$expiredStatus = (string)ecDb()->query(
    "SELECT status FROM ec_stock_notifications WHERE product_id = ? AND customer_email = ? LIMIT 1",
    [$testProductId, $oldEmail]
)->fetchColumn();
tSn('old waiting row marked expired', $expiredStatus === 'expired');

// Recent row not affected
$recentStatus = (string)ecDb()->query(
    "SELECT status FROM ec_stock_notifications WHERE product_id = ? AND customer_email = ? AND status = 'waiting' LIMIT 1",
    [$testProductId, $testEmail]
)->fetchColumn();
tSn('recent waiting row not expired', $recentStatus === 'waiting');

// ─────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────

try {
    ecDb()->query("DELETE FROM ec_stock_notifications WHERE product_id = ?", [$testProductId]);
} catch (\Throwable $e) {}

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
