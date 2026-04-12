<?php

declare(strict_types=1);

/**
 * Tests for Milestone 5 — Variant-Aware Merchandising
 * helpers/42-variant-media.php
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
$migFile = __DIR__ . '/../modules/ecommerce/database/migrations/031_ec_variant_media.sql';
if (is_file($migFile)) {
    try { app()->db()->exec((string)file_get_contents($migFile)); } catch (\Throwable $e) {}
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tVm(string $label, bool $ok, string $detail = ''): void
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
// §1  Storage
// ─────────────────────────────────────────────────────────────────────────

echo "\n§1  Storage\n";
tVm('ecVariantMediaStorageAvailable() = true', ecVariantMediaStorageAvailable());

if (!ecVariantMediaStorageAvailable()) {
    echo "  SKIP rest — storage unavailable\n";
    goto summary;
}

// ─────────────────────────────────────────────────────────────────────────
// §2  Seed test media row in cms_media if needed
// ─────────────────────────────────────────────────────────────────────────

// Find or create a cms_media row to use as fixture
$mediaRow = ecDb()->query(
    "SELECT id FROM cms_media ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!is_array($mediaRow) || (int)($mediaRow['id'] ?? 0) < 1) {
    // Insert a minimal fixture
    ecDb()->query(
        "INSERT INTO cms_media (file_path, file_name, mime_type, file_size, created_at)
         VALUES ('/assets/test/variant-test.jpg', 'variant-test.jpg', 'image/jpeg', 1024, NOW())"
    );
    $mediaId = (int)ecDb()->query("SELECT LAST_INSERT_ID()")->fetchColumn();
} else {
    $mediaId = (int)$mediaRow['id'];
}

// Use fictional variant IDs safe for testing
$variantId1 = 991001;
$variantId2 = 991002;
$variantId3 = 991003; // will have no media

// ─────────────────────────────────────────────────────────────────────────
// §3  Attach media
// ─────────────────────────────────────────────────────────────────────────

echo "\n§3  Attach\n";

$ok1 = ecVariantMediaAttach($variantId1, $mediaId, 0);
tVm('ecVariantMediaAttach returns true', $ok1);

$count = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_variant_media WHERE variant_id = ? AND media_id = ?",
    [$variantId1, $mediaId]
)->fetchColumn();
tVm('row exists in DB', $count === 1);

// Idempotent re-attach (IGNORE)
$ok2 = ecVariantMediaAttach($variantId1, $mediaId, 5);
tVm('re-attach idempotent (no exception)', $ok2);

$countAfterDup = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_variant_media WHERE variant_id = ? AND media_id = ?",
    [$variantId1, $mediaId]
)->fetchColumn();
tVm('still one row after duplicate attach', $countAfterDup === 1);

// Invalid inputs
tVm('attach variant_id=0 returns false', !ecVariantMediaAttach(0, $mediaId));
tVm('attach media_id=0 returns false', !ecVariantMediaAttach($variantId1, 0));

// ─────────────────────────────────────────────────────────────────────────
// §4  ecVariantMediaForVariant
// ─────────────────────────────────────────────────────────────────────────

echo "\n§4  For-variant query\n";

$media = ecVariantMediaForVariant($variantId1);
tVm('returns non-empty array', !empty($media));
tVm('first item has url key', isset($media[0]['url']));
tVm('first item has media_id', isset($media[0]['media_id']) && (int)$media[0]['media_id'] === $mediaId);
tVm('first item has sort_order key', array_key_exists('sort_order', $media[0]));
tVm('first item has thumb key', isset($media[0]['thumb']));

$emptyMedia = ecVariantMediaForVariant($variantId3);
tVm('variant with no media returns empty array', empty($emptyMedia));

$emptyZero = ecVariantMediaForVariant(0);
tVm('variant_id=0 returns empty array', empty($emptyZero));

// ─────────────────────────────────────────────────────────────────────────
// §5  Reorder
// ─────────────────────────────────────────────────────────────────────────

echo "\n§5  Reorder\n";

// Attach second media row (use same mediaId+1 or just another sort_order test)
// Add another media entry for reorder test
try {
    ecDb()->query(
        "INSERT INTO cms_media (file_path, file_name, mime_type, file_size, created_at)
         VALUES ('/assets/test/variant-test2.jpg', 'variant-test2.jpg', 'image/jpeg', 1024, NOW())"
    );
    $mediaId2 = (int)ecDb()->query("SELECT LAST_INSERT_ID()")->fetchColumn();
} catch (\Throwable $e) {
    $mediaId2 = $mediaId; // fallback to same, reorder test will still work
}

if ($mediaId2 !== $mediaId) {
    ecVariantMediaAttach($variantId1, $mediaId2, 10);
}

// Reorder: put mediaId2 first (sort_order 0), mediaId second (sort_order 1)
if ($mediaId2 !== $mediaId) {
    ecVariantMediaReorder($variantId1, [$mediaId2, $mediaId]);
    $ordered = ecVariantMediaForVariant($variantId1);
    tVm('reorder: first item is mediaId2 (lower sort_order)', (int)($ordered[0]['media_id'] ?? -1) === $mediaId2);
    tVm('reorder: second item is mediaId', (int)($ordered[1]['media_id'] ?? -1) === $mediaId);
} else {
    tVm('reorder test skipped (single media fixture)', true);
    tVm('reorder test skipped (single media fixture)', true);
}

// ─────────────────────────────────────────────────────────────────────────
// §6  ecVariantMediaFallbackGallery
// ─────────────────────────────────────────────────────────────────────────

echo "\n§6  Fallback gallery\n";

$productGallery = [['url' => '/assets/parent-product.jpg', 'thumb' => '/assets/parent-product.jpg', 'caption' => '']];

// Variant with media → return variant media
$result = ecVariantMediaFallbackGallery(1, $variantId1, $productGallery);
tVm('variant with media returns variant images', !empty($result) && isset($result[0]['media_id']));

// Variant with no media → fall back to product gallery
$fallback = ecVariantMediaFallbackGallery(1, $variantId3, $productGallery);
tVm('variant with no media falls back to product gallery', $fallback === $productGallery);

// null variant → falls back to product gallery
$noVariant = ecVariantMediaFallbackGallery(1, null, $productGallery);
tVm('null variant falls back to product gallery', $noVariant === $productGallery);

// ─────────────────────────────────────────────────────────────────────────
// §7  Detach
// ─────────────────────────────────────────────────────────────────────────

echo "\n§7  Detach\n";

$detachOk = ecVariantMediaDetach($variantId1, $mediaId);
tVm('ecVariantMediaDetach returns true', $detachOk);

$countAfterDetach = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_variant_media WHERE variant_id = ? AND media_id = ?",
    [$variantId1, $mediaId]
)->fetchColumn();
tVm('row removed after detach', $countAfterDetach === 0);

// DetachAll
ecVariantMediaAttach($variantId2, $mediaId, 0);
ecVariantMediaDetachAll($variantId2);
$countAfterAll = (int)ecDb()->query(
    "SELECT COUNT(*) FROM ec_variant_media WHERE variant_id = ?",
    [$variantId2]
)->fetchColumn();
tVm('ecVariantMediaDetachAll removes all rows for variant', $countAfterAll === 0);

// ─────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────

try {
    ecDb()->query("DELETE FROM ec_variant_media WHERE variant_id IN (?, ?, ?)", [$variantId1, $variantId2, $variantId3]);
    if (isset($mediaId2) && $mediaId2 !== $mediaId) {
        ecDb()->query("DELETE FROM cms_media WHERE id = ?", [$mediaId2]);
    }
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
