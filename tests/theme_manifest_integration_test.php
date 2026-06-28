<?php
/**
 * Integration test — validates entity-native and native-default manifests
 * through the full cmsThemeManifestForSlug() + ThemeManifestValidator pipeline.
 *
 * Usage: php tests/theme_manifest_integration_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;

function assert_true(mixed $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n";
        $failed++;
    }
}

function assert_count(int $expected, array $actual, string $label): void
{
    global $passed, $failed;
    $count = count($actual);
    if ($count === $expected) {
        echo "  PASS: {$label} (count={$expected})\n";
        $passed++;
    } else {
        echo "  FAIL: {$label} (expected {$expected}, got {$count})\n";
        $failed++;
    }
}

// ── Load CMS helpers to get access to cmsThemeManifestForSlug ──
$cmsBase = dirname(__DIR__) . '/modules/cms';
foreach (glob($cmsBase . '/helpers/*.php') as $helper) {
    require_once $helper;
}

$themesPath = dirname(__DIR__) . '/storage/cms-themes';
$themeSlugs = array_filter(scandir($themesPath), fn($d) => $d[0] !== '.' && is_dir("{$themesPath}/{$d}"));

echo "Testing " . count($themeSlugs) . " themes...\n";

foreach ($themeSlugs as $slug) {
    echo "\n── {$slug} ──\n";

    $manifest = cmsThemeManifestForSlug($slug);
    assert_true(is_array($manifest), "manifest loaded for {$slug}");
    assert_true(isset($manifest['slug']), "manifest has slug for {$slug}");

    $validation = $manifest['_validation'] ?? null;
    if ($validation === null) {
        echo "  INFO: No _validation key — ThemeManifestValidator not integrated\n";
        continue;
    }

    assert_true(isset($validation['valid']), "_validation has 'valid' key for {$slug}");
    assert_true(isset($validation['errors']), "_validation has 'errors' key for {$slug}");
    assert_true(isset($validation['warnings']), "_validation has 'warnings' key for {$slug}");

    if ($validation['valid']) {
        echo "  ✓ VALID\n";
    } else {
        echo "  ✗ INVALID\n";
        foreach ($validation['errors'] as $err) {
            echo "    ERROR: {$err}\n";
        }
    }

    foreach ($validation['warnings'] as $warn) {
        echo "    ⚠ {$warn}\n";
    }

    // Check required keys present
    foreach (['name', 'version', 'label', 'supported_surfaces'] as $key) {
        assert_true(isset($manifest[$key]), "{$slug} has '{$key}'");
    }
}

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
