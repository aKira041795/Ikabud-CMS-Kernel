<?php

declare(strict_types=1);

/**
 * Tests for Milestone 3 — Customer Segmentation & Tier Pricing
 * helpers/39-pricing-tiers.php + catalog wiring
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

// Ensure migration is applied
$migFile = __DIR__ . '/../modules/ecommerce/database/migrations/029_ec_customer_segments.sql';
if (is_file($migFile)) {
    try { app()->db()->exec((string)file_get_contents($migFile)); } catch (\Throwable $e) {}
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tSeg(string $label, bool $ok, string $detail = ''): void
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
    "SELECT id FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!is_array($customer) || (int)($customer['id'] ?? 0) < 1) {
    echo "SKIP — no active cms_users row\n";
    exit(0);
}
$customerId = (int)$customer['id'];

// ─────────────────────────────────────────────────────────────────────────
// §1  Storage availability
// ─────────────────────────────────────────────────────────────────────────

echo "\n§1  Segment storage\n";

tSeg('ecSegmentStorageAvailable() = true after migration', ecSegmentStorageAvailable());

if (!ecSegmentStorageAvailable()) {
    echo "  SKIP rest — storage unavailable\n";
    goto summary;
}

// ─────────────────────────────────────────────────────────────────────────
// §2  Segment CRUD helpers
// ─────────────────────────────────────────────────────────────────────────

echo "\n§2  Segment setup\n";

// Insert a test segment (price_list type)
$segCode = 'test_wholesale_' . substr(md5((string)time()), 0, 8);
try {
    ecDb()->query(
        "INSERT INTO ec_customer_segments (code, name, discount_type, discount_value, priority, is_active)
         VALUES (?, 'Test Wholesale', 'price_list', NULL, 10, 1)",
        [$segCode]
    );
    $segId = (int)ecDb()->query("SELECT id FROM ec_customer_segments WHERE code = ? LIMIT 1", [$segCode])->fetchColumn();
    tSeg('segment created with price_list type', $segId > 0);
} catch (\Throwable $e) {
    tSeg('segment insert', false, $e->getMessage());
    goto cleanup;
}

// Insert a percent-discount segment
$segCodePct = 'test_vip_' . substr(md5((string)time() . 'pct'), 0, 8);
$segIdPct   = 0;
try {
    ecDb()->query(
        "INSERT INTO ec_customer_segments (code, name, discount_type, discount_value, priority, is_active)
         VALUES (?, 'Test VIP 10%', 'percent', 10, 5, 1)",
        [$segCodePct]
    );
    $segIdPct = (int)ecDb()->query("SELECT id FROM ec_customer_segments WHERE code = ? LIMIT 1", [$segCodePct])->fetchColumn();
    tSeg('percent-discount segment created', $segIdPct > 0);
} catch (\Throwable $e) {
    tSeg('percent segment insert', false, $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// §3  Segment membership
// ─────────────────────────────────────────────────────────────────────────

echo "\n§3  Segment membership\n";

$addResult = ecSegmentAddMember($segId, $customerId);
tSeg('ecSegmentAddMember returns true', $addResult);

$members = ecDb()->query(
    "SELECT COUNT(*) FROM ec_customer_segment_members WHERE segment_id = ? AND user_id = ?",
    [$segId, $customerId]
)->fetchColumn();
tSeg('member row exists in DB', (int)$members === 1);

// idempotent re-add
$addAgain = ecSegmentAddMember($segId, $customerId);
tSeg('ecSegmentAddMember idempotent (no exception)', $addAgain);

$membersAfterDupe = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_customer_segment_members WHERE segment_id = ? AND user_id = ?",
    [$segId, $customerId]
)->fetchColumn();
tSeg('still only one row after duplicate add', $membersAfterDupe === 1);

// ─────────────────────────────────────────────────────────────────────────
// §4  ecCustomerActiveSegments
// ─────────────────────────────────────────────────────────────────────────

echo "\n§4  Active segment resolution\n";

// Clear cache by using a different user ID won't work (static cache); test fresh
$activeSegs = ecCustomerActiveSegments($customerId);
tSeg('ecCustomerActiveSegments returns non-empty for member', !empty($activeSegs));
tSeg('returned segment id matches', in_array($segId, array_column($activeSegs, 'id'), false));

$noSegs = ecCustomerActiveSegments(0);
tSeg('ecCustomerActiveSegments([userId=0]) returns empty', empty($noSegs));

$noSegs2 = ecCustomerActiveSegments(999999999);
tSeg('ecCustomerActiveSegments([unknown user]) returns empty', empty($noSegs2));

// ─────────────────────────────────────────────────────────────────────────
// §5  ecSegmentResolvePrice — price_list type
// ─────────────────────────────────────────────────────────────────────────

echo "\n§5  Price resolution — price_list\n";

$testProdId = 777777;
$upsertOk   = ecSegmentUpsertProductPrice($segId, $testProdId, 29.99, 19.99);
tSeg('ecSegmentUpsertProductPrice returns true', $upsertOk);

$priceList = ecSegmentProductPriceList($segId);
tSeg('ecSegmentProductPriceList returns row', isset($priceList[$testProdId]));
tSeg('listed price = 29.99', isset($priceList[$testProdId]) && (float)$priceList[$testProdId]['price'] === 29.99);
tSeg('listed sale_price = 19.99', isset($priceList[$testProdId]) && (float)$priceList[$testProdId]['sale_price'] === 19.99);

// Resolve via segments
$resolved = ecSegmentResolvePrice($testProdId, $activeSegs, 99.00);
tSeg('ecSegmentResolvePrice returns non-null for price_list match', $resolved !== null);
tSeg('resolved price = 29.99', $resolved !== null && (float)$resolved['price'] === 29.99);
tSeg('resolved sale_price = 19.99', $resolved !== null && (float)$resolved['sale_price'] === 19.99);

// Product with no price_list row should return null (no fallback discount on this segment)
$resolvedMiss = ecSegmentResolvePrice(888888, $activeSegs, 50.00);
tSeg('ecSegmentResolvePrice returns null when no row for product', $resolvedMiss === null);

// ─────────────────────────────────────────────────────────────────────────
// §6  ecSegmentResolvePrice — percent and fixed types
// ─────────────────────────────────────────────────────────────────────────

echo "\n§6  Price resolution — percent / fixed\n";

if ($segIdPct > 0) {
    $pctSegs = [['id' => $segIdPct, 'discount_type' => 'percent', 'discount_value' => '10', 'priority' => 5]];
    $resolvedPct = ecSegmentResolvePrice(888, $pctSegs, 100.00);
    tSeg('percent 10% off 100.00 = 90.00', $resolvedPct !== null && (float)$resolvedPct['price'] === 90.00);
    tSeg('percent resolved sale_price = null', $resolvedPct !== null && $resolvedPct['sale_price'] === null);

    $resolvedPctEdge = ecSegmentResolvePrice(888, $pctSegs, 0.0);
    tSeg('percent with basePrice=0 returns null (no-op)', $resolvedPctEdge === null);
}

$fixedSegs   = [['id' => 9999, 'discount_type' => 'fixed', 'discount_value' => '15', 'priority' => 1]];
$resolvedFix = ecSegmentResolvePrice(888, $fixedSegs, 80.00);
tSeg('fixed 15 off 80.00 = 65.00', $resolvedFix !== null && (float)$resolvedFix['price'] === 65.00);

$resolvedNeg = ecSegmentResolvePrice(888, [['id' => 9999, 'discount_type' => 'fixed', 'discount_value' => '200', 'priority' => 1]], 50.00);
tSeg('fixed discount floored at 0 (no negative price)', $resolvedNeg !== null && (float)$resolvedNeg['price'] === 0.0);

// ─────────────────────────────────────────────────────────────────────────
// §7  ecSegmentApplyProductPrice
// ─────────────────────────────────────────────────────────────────────────

echo "\n§7  ecSegmentApplyProductPrice\n";

$mockProduct = ['id' => $testProdId, 'price' => 99.00, 'sale_price' => null, 'title' => 'Test Product'];
$applied = ecSegmentApplyProductPrice($mockProduct, $activeSegs);
tSeg('product price replaced to 29.99', (float)($applied['price'] ?? 0) === 29.99);
tSeg('product sale_price replaced to 19.99', (float)($applied['sale_price'] ?? 0) === 19.99);
tSeg('_segment_priced flag set', (bool)($applied['_segment_priced'] ?? false));

$mockNoSeg = ['id' => 888888, 'price' => 55.00, 'sale_price' => null];
$notApplied = ecSegmentApplyProductPrice($mockNoSeg, $activeSegs);
tSeg('product with no override unchanged', (float)($notApplied['price'] ?? 0) === 55.00);
tSeg('_segment_priced NOT set when no override', !isset($notApplied['_segment_priced']));

$noSegResult = ecSegmentApplyProductPrice($mockProduct, []);
tSeg('empty segments array returns product unchanged', (float)($noSegResult['price'] ?? 0) === 99.00);

// ─────────────────────────────────────────────────────────────────────────
// §8  ecSegmentRemoveMember
// ─────────────────────────────────────────────────────────────────────────

echo "\n§8  Segment remove member\n";

$removeResult = ecSegmentRemoveMember($segId, $customerId);
tSeg('ecSegmentRemoveMember returns true', $removeResult);

$afterRemove = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_customer_segment_members WHERE segment_id = ? AND user_id = ?",
    [$segId, $customerId]
)->fetchColumn();
tSeg('member row removed from DB', $afterRemove === 0);

// ─────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────

cleanup:
foreach (array_filter([$segId ?? 0, $segIdPct]) as $sid) {
    try { ecDb()->query("DELETE FROM ec_customer_segments WHERE id = ?", [$sid]); } catch (\Throwable $e) {}
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
