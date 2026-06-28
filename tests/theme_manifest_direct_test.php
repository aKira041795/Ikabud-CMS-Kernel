<?php
/**
 * Direct integration test — validates real theme manifests using
 * ThemeManifestValidator on disk, without loading CMS module.
 *
 * Usage: php tests/theme_manifest_direct_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\ThemeManifestValidator;

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

$themesPath = dirname(__DIR__) . '/storage/cms-themes';
$themeSlugs = array_filter(
    scandir($themesPath),
    fn($d) => $d[0] !== '.' && is_dir("{$themesPath}/{$d}")
);

echo "Direct testing " . count($themeSlugs) . " themes...\n";

foreach ($themeSlugs as $slug) {
    echo "\n── {$slug} ──\n";
    $themeDir = "{$themesPath}/{$slug}";

    // Load manifest the same way cmsThemeManifestForSlug does
    $manifestFile = $themeDir . '/theme.manifest.json';
    if (!is_file($manifestFile)) {
        $manifestFile = $themeDir . '/theme.json';
    }
    if (!is_file($manifestFile)) {
        echo "  ⚠ No manifest file found\n";
        continue;
    }

    $decoded = kernelReadJsonFile($manifestFile);
    $manifest = is_array($decoded) ? $decoded : [];
    $manifest['slug'] = $slug;

    // Run validator
    $validation = ThemeManifestValidator::validate($slug, $manifest, $themeDir);

    assert_true(isset($validation['valid']), "{$slug}: _validation has 'valid' key");
    assert_true(isset($validation['errors']), "{$slug}: _validation has 'errors' key");
    assert_true(isset($validation['warnings']), "{$slug}: _validation has 'warnings' key");

    if ($validation['valid']) {
        echo "  ✓ VALID\n";
    } else {
        echo "  ✗ INVALID\n";
    }

    foreach ($validation['errors'] as $err) {
        echo "    ERROR: {$err}\n";
    }
    foreach ($validation['warnings'] as $warn) {
        echo "    ⚠ {$warn}\n";
    }

    // Check required keys
    foreach (['name', 'version', 'label', 'supported_surfaces'] as $key) {
        assert_true(isset($manifest[$key]), "{$slug}: has '{$key}'");
    }
}

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
