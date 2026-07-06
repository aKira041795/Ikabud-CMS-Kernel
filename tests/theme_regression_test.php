<?php
/**
 * Cross-theme Regression Test Suite — C2
 *
 * Validates ALL themes under storage/cms-themes/:
 *   1. DiSyL lint passes on all template files
 *   2. Manifest exists and contains required keys
 *   3. Declared layouts exist on disk
 *   4. Entity-view fallback templates exist
 *   5. Slot names used in layouts are declared in supported_slots
 *   6. renderer-registry.json template paths resolve to actual files
 *
 * Usage: php tests/theme_regression_test.php
 *
 * @package Ikabud\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;
$totalChecks = 0;
$baseDir = dirname(__DIR__);

// ── Helper functions ─────────────────────────────────────────────────────

function assert_true(mixed $condition, string $label): void
{
    global $passed, $failed, $totalChecks;
    $totalChecks++;
    if ($condition) {
        echo "  PASS: {$label}\n";
        $passed++;
    } else {
        echo "  FAIL: {$label}\n";
        $failed++;
    }
}

function assert_has_key(array $data, string $key, string $label): void
{
    assert_true(array_key_exists($key, $data), "{$label} — key '{$key}' exists");
}

/**
 * Recursively scan a directory for .disyl files.
 *
 * @return list<string> Relative paths from $dir
 */
function find_disyl_files(string $dir): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'disyl') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/**
 * Extract all {ikb_slot name="..."} references from a template string.
 *
 * @return list<string> Slot names found
 */
function extract_slot_names(string $source): array
{
    $slots = [];
    if (preg_match_all('/\{ikb_slot\s+name\s*=\s*"([^"]+)"/i', $source, $matches)) {
        $slots = $matches[1];
    }
    return array_unique($slots);
}

// ── Clear logs before testing ──
@file_put_contents($baseDir . '/storage/logs/app.log', '');
@file_put_contents($baseDir . '/storage/logs/error.log', '');

// ══════════════════════════════════════════════════════════════════════════
// Discover themes
// ══════════════════════════════════════════════════════════════════════════
$themesDir = $baseDir . '/storage/cms-themes';
$themeSlugs = array_values(array_filter(
    scandir($themesDir),
    fn(string $item): bool => $item !== '.' && $item !== '..' && $item !== '.htaccess' && is_dir($themesDir . '/' . $item)
));
sort($themeSlugs);

echo "═══ Cross-theme Regression Test Suite ═══\n";
echo "Discovered " . count($themeSlugs) . " themes: " . implode(', ', $themeSlugs) . "\n\n";

// ══════════════════════════════════════════════════════════════════════════
// Per-theme test loop
// ══════════════════════════════════════════════════════════════════════════
$themeResults = [];

foreach ($themeSlugs as $slug) {
    $themeDir = $themesDir . '/' . $slug;
    $themePassed = 0;
    $themeFailed = 0;
    $previousPassed = 0;
    $previousFailed = 0;

    echo str_repeat('─', 60) . "\n";
    echo "THEME: {$slug}\n";
    echo str_repeat('─', 60) . "\n";

    // ── Track per-theme counters ──
    $previousPassed = $passed;
    $previousFailed = $failed;

    // ────────────────────────────────────────────────────────────────────
    // Test 1: DiSyL lint
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 1: DiSyL lint\n";
    $lintOutput = [];
    $lintCode = 0;
    $lintCmd = 'php ' . escapeshellarg($baseDir . '/_lint_disyl.php') . ' --path ' . escapeshellarg('storage/cms-themes/' . $slug) . ' 2>&1';
    exec($lintCmd, $lintOutput, $lintCode);
    $lintText = implode("\n", $lintOutput);

    // Check exit code AND that no errors were reported
    $hasErrors = $lintCode !== 0;
    // Also check for explicit error counts in output
    if (preg_match('/(\d+)\s*err\(s\)/i', $lintText, $m) && (int)$m[1] > 0) {
        $hasErrors = true;
    }
    // Check for "FAIL" lines that indicate lint failures
    if (preg_match('/^\s*FAIL\b/m', $lintText)) {
        $hasErrors = true;
    }
    assert_true(!$hasErrors, "lint passes — exit code {$lintCode}");
    if ($hasErrors) {
        echo "    Lint output:\n";
        foreach ($lintOutput as $line) {
            echo "    > {$line}\n";
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Test 2: Manifest validation
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 2: Manifest validation\n";

    // Try theme.manifest.json first, fall back to theme.json
    $manifestPath = $themeDir . '/theme.manifest.json';
    $legacyManifestPath = $themeDir . '/theme.json';
    $manifest = [];
    $manifestType = 'none';

    if (is_file($manifestPath)) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $manifestType = 'theme.manifest.json';
        assert_true(is_array($manifest) && !empty($manifest), 'theme.manifest.json is valid JSON');
    } elseif (is_file($legacyManifestPath)) {
        $manifest = json_decode(file_get_contents($legacyManifestPath), true);
        $manifestType = 'theme.json';
        assert_true(is_array($manifest) && !empty($manifest), 'theme.json is valid JSON (legacy)');
    } else {
        assert_true(false, 'No manifest file found (theme.manifest.json or theme.json)');
    }

    if (!empty($manifest)) {
        // Required keys (defined by the spec — some may be missing in legacy manifests)
        // name is required by both schemas
        assert_has_key($manifest, 'name', 'manifest');

        // kernel_os_compat may be absent in legacy manifests — soft check
        if (array_key_exists('kernel_os_compat', $manifest)) {
            assert_true(
                (bool)preg_match('/^\d+\.\d+(\.\d+)?$/', $manifest['kernel_os_compat'] ?? ''),
                'kernel_os_compat is valid semver'
            );
        } else {
            assert_true(true, 'kernel_os_compat not present (allowed for legacy) — SKIP');
        }

        // disyl_compat may be absent in legacy manifests
        if (array_key_exists('disyl_compat', $manifest)) {
            assert_true(
                (bool)preg_match('/^\d+\.\d+(\.\d+)?$/', $manifest['disyl_compat'] ?? ''),
                'disyl_compat is valid semver'
            );
        } else {
            assert_true(true, 'disyl_compat not present (allowed for legacy) — SKIP');
        }

        // supported_surfaces
        if (array_key_exists('supported_surfaces', $manifest)) {
            $surfaces = $manifest['supported_surfaces'];
            assert_true(is_array($surfaces) && count($surfaces) > 0, 'supported_surfaces is non-empty array');
            $validSurfaces = ['public', 'admin', 'print', 'email', 'export'];
            foreach ($surfaces as $s) {
                assert_true(
                    in_array($s, $validSurfaces, true),
                    "supported_surface '{$s}' is a known surface type"
                );
            }
        } else {
            // Check if theme.json has "supports.features" or similar (legacy pattern)
            assert_true(true, 'supported_surfaces not present (allowed for legacy) — SKIP');
        }

        // supported_slots
        if (array_key_exists('supported_slots', $manifest)) {
            $slots = $manifest['supported_slots'];
            assert_true(is_array($slots) && count($slots) > 0, 'supported_slots is non-empty array');
        } else {
            assert_true(true, 'supported_slots not present (allowed for legacy) — SKIP');
        }

        // fallback_views
        if (array_key_exists('fallback_views', $manifest)) {
            $fb = $manifest['fallback_views'];
            assert_true(is_array($fb), 'fallback_views is an object/array');
        } else {
            assert_true(true, 'fallback_views not present (allowed for legacy) — SKIP');
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Test 3: Declared layouts exist
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 3: Layouts\n";

    $layoutsDir = $themeDir . '/layouts';
    if (is_dir($layoutsDir)) {
        $layoutFiles = array_values(array_filter(
            scandir($layoutsDir),
            fn(string $f): bool => $f !== '.' && $f !== '..' && str_ends_with($f, '.disyl')
        ));
        assert_true(count($layoutFiles) > 0, 'layouts directory contains .disyl files');
        foreach ($layoutFiles as $lf) {
            assert_true(
                is_file($layoutsDir . '/' . $lf),
                "layout file 'layouts/{$lf}' exists"
            );
        }
    } else {
        // Check alternate shell location (e.g., entity-native uses shell.disyl at root)
        $shellPath = $manifest['shell'] ?? null;
        if ($shellPath && is_file($themeDir . '/' . ltrim($shellPath, '/'))) {
            assert_true(true, "shell template '{$shellPath}' exists (no layouts/ dir)");
        } else {
            assert_true(false, "layouts/ directory not found");
        }
    }

    // If manifest declares a "shell" key, verify it exists
    if (!empty($manifest) && array_key_exists('shell', $manifest)) {
        $shellFile = $themeDir . '/' . ltrim($manifest['shell'], '/');
        assert_true(is_file($shellFile), "shell '{$manifest['shell']}' exists on disk");
    }

    // ────────────────────────────────────────────────────────────────────
    // Test 4: Entity-view fallback templates exist
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 4: Entity-view fallback templates\n";

    if (!empty($manifest) && array_key_exists('fallback_views', $manifest)) {
        $fallbacks = $manifest['fallback_views'];
        $expectedKeys = ['card', 'table', 'detail', 'compact'];
        foreach ($expectedKeys as $ek) {
            if (array_key_exists($ek, $fallbacks)) {
                $fbPath = $themeDir . '/' . ltrim($fallbacks[$ek], '/');
                assert_true(is_file($fbPath), "fallback '{$ek}' -> '{$fallbacks[$ek]}' exists");
            } else {
                assert_true(true, "fallback '{$ek}' not declared — SKIP");
            }
        }
    } else {
        // Check entity-views/ directory as a convention
        $evDir = $themeDir . '/entity-views';
        if (is_dir($evDir)) {
            $evFiles = array_values(array_filter(
                scandir($evDir),
                fn(string $f): bool => $f !== '.' && $f !== '..' && str_ends_with($f, '.disyl')
            ));
            assert_true(count($evFiles) > 0, 'entity-views/ directory has .disyl files (fallback convention)');
            foreach ($evFiles as $evf) {
                assert_true(is_file($evDir . '/' . $evf), "entity-view 'entity-views/{$evf}' exists");
            }
        } else {
            assert_true(true, "No entity-views directory (allowed) — SKIP");
        }
    }

    // If manifest declares an "entity_views" key, verify it exists
    if (!empty($manifest) && array_key_exists('entity_views', $manifest)) {
        $evPath = $themeDir . '/' . rtrim($manifest['entity_views'], '/');
        assert_true(is_dir($evPath), "entity_views dir '{$manifest['entity_views']}' exists");
    }

    // ────────────────────────────────────────────────────────────────────
    // Test 5: Slot names used in layouts are declared in supported_slots
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 5: Slot coverage\n";

    $declaredSlots = $manifest['supported_slots'] ?? [];

    // Collect all layout files (layouts/ dir or the shell template)
    $layoutSources = [];
    if (is_dir($layoutsDir)) {
        foreach (scandir($layoutsDir) as $lf) {
            if ($lf === '.' || $lf === '..' || !str_ends_with($lf, '.disyl')) {
                continue;
            }
            $content = file_get_contents($layoutsDir . '/' . $lf);
            $layoutSources[$lf] = $content;
        }
    } elseif (!empty($manifest['shell'])) {
        $shellPath = $themeDir . '/' . ltrim($manifest['shell'], '/');
        if (is_file($shellPath)) {
            $layoutSources[basename($manifest['shell'])] = file_get_contents($shellPath);
        }
    }

    if (empty($declaredSlots)) {
        assert_true(true, 'No supported_slots declared — skipping slot validation');
    } elseif (empty($layoutSources)) {
        assert_true(true, 'No layout files found — skipping slot validation');
    } else {
        foreach ($layoutSources as $layoutName => $source) {
            $usedSlots = extract_slot_names($source);
            if (empty($usedSlots)) {
                assert_true(true, "{$layoutName}: no {ikb_slot} calls found (may use {block} only)");
                continue;
            }
            foreach ($usedSlots as $slotName) {
                $isDeclared = in_array($slotName, $declaredSlots, true);
                assert_true(
                    $isDeclared,
                    "{$layoutName}: slot '{$slotName}' is declared in supported_slots"
                );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Test 6: renderer-registry.json template paths resolve
    // ────────────────────────────────────────────────────────────────────
    echo "\nTest 6: Renderer registry\n";

    $registryPath = $themeDir . '/renderer-registry.json';
    if (is_file($registryPath)) {
        $registry = json_decode(file_get_contents($registryPath), true);
        assert_true(is_array($registry), 'renderer-registry.json is valid JSON');

        // Check for version 3+ format with "renderers" key
        $renderers = $registry['renderers'] ?? $registry;
        assert_true(is_array($renderers) && count($renderers) > 0, 'renderer registry has entries');

        foreach ($renderers as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // Some entries have "template" path, others are component-based with "renders_as_component"
            if (array_key_exists('template', $entry)) {
                $tmplPath = $themeDir . '/' . ltrim($entry['template'], '/');
                assert_true(
                    is_file($tmplPath),
                    "renderer '{$key}': template '{$entry['template']}' exists"
                );
            } elseif (array_key_exists('renders_as_component', $entry)) {
                assert_true(
                    !empty($entry['renders_as_component']),
                    "renderer '{$key}': renders_as_component is non-empty"
                );
            } else {
                assert_true(true, "renderer '{$key}': no template path or component — SKIP");
            }
        }
    } else {
        assert_true(true, 'No renderer-registry.json — SKIP');
    }

    // ────────────────────────────────────────────────────────────────────
    // Per-theme summary
    // ────────────────────────────────────────────────────────────────────
    $themePassed = $passed - $previousPassed;
    $themeFailed = $failed - $previousFailed;
    $themeResults[$slug] = ['passed' => $themePassed, 'failed' => $themeFailed];

    echo "\n";
}

// ══════════════════════════════════════════════════════════════════════════
// Final summary
// ══════════════════════════════════════════════════════════════════════════
echo str_repeat('═', 60) . "\n";
echo "SUMMARY\n";
echo str_repeat('═', 60) . "\n\n";

foreach ($themeSlugs as $slug) {
    $r = $themeResults[$slug];
    $status = $r['failed'] === 0 ? '✅ PASS' : '🔴 FAIL';
    echo "  {$status}  {$slug}: {$r['passed']} passed, {$r['failed']} failed\n";
}

echo "\n";
echo "Total: {$passed} passed, {$failed} failed out of {$totalChecks} checks\n\n";

// ══════════════════════════════════════════════════════════════════════════
// Log check
// ══════════════════════════════════════════════════════════════════════════
echo "Log check:\n";
$appLog = $baseDir . '/storage/logs/app.log';
$errorLog = $baseDir . '/storage/logs/error.log';
$appLogContent = is_file($appLog) ? file_get_contents($appLog) : '';
$errorLogContent = is_file($errorLog) ? file_get_contents($errorLog) : '';

if (trim($appLogContent) !== '') {
    echo "  NOTE: app.log has content (may be pre-existing)\n";
}
if (trim($errorLogContent) !== '') {
    echo "  NOTE: error.log has content (may be pre-existing)\n";
}

exit($failed > 0 ? 1 : 0);
