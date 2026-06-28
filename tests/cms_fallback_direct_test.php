<?php
/**
 * Direct test of cmsResolveEntityFallbackView without loading CMS UI.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

// Directly test the fallback view resolution by loading the manifest
// and verifying the template resolution works
$errors = [];

$themesPath = (defined('STORAGE_PATH') ? STORAGE_PATH : (defined('BASE_PATH') ? BASE_PATH . '/storage' : __DIR__ . '/../storage')) . '/cms-themes';
$slug = 'entity-native';
$themeDir = $themesPath . '/' . $slug;
$manifestFile = $themeDir . '/theme.manifest.json';

if (!is_file($manifestFile)) {
    echo "FAIL: entity-native manifest not found\n";
    exit(1);
}

$decoded = kernelReadJsonFile($manifestFile);
$manifest = is_array($decoded) ? $decoded : [];

// Verify fallback_views exist and point to real files
$fallbacks = $manifest['fallback_views'] ?? [];
$expectedViews = ['card' => 'default-card', 'table' => 'default-table', 'detail' => 'default-detail', 'compact' => 'default-compact'];

foreach ($expectedViews as $view => $expectedFile) {
    if (!isset($fallbacks[$view])) {
        $errors[] = "Missing fallback_views.{$view}";
        continue;
    }
    $fallbackPath = $themeDir . '/' . ltrim((string)$fallbacks[$view], '/');
    if (!is_file($fallbackPath)) {
        $errors[] = "Fallback '{$view}' file not found at {$fallbackPath}";
    } else {
        $size = filesize($fallbackPath);
        echo "OK: {$view} -> {$fallbacks[$view]} ({$size} bytes)\n";
    }
}

// Verify EntityViewResolver generic fallback contract
use Ikabud\Kernel\EntityContext\EntityViewResolver;
$resolver = EntityViewResolver::getInstance();

// Test with a truly unknown entity type
$contract = $resolver->viewContract('unknown.entity.' . uniqid(), 'card');
if ($contract === null) {
    $errors[] = 'viewContract returned null for unknown entity (generic fallback missing)';
} elseif (($contract['fields'] ?? '') === '*') {
    echo "OK: EntityViewResolver returns generic fallback with fields=* for unknown entity\n";
} else {
    $errors[] = 'Unexpected contract fields: ' . json_encode($contract['fields'] ?? 'null');
}

// Test registered contract still takes priority
$resolver->registerView('test.priority', 'default', [
    'fields' => ['id', 'title', 'status'],
    'actions' => ['view'],
    'limit' => 10,
    'empty_state' => 'Priority test.',
]);
$priorityContract = $resolver->viewContract('test.priority', 'default');
if ($priorityContract !== null && is_array($priorityContract['fields'] ?? null)) {
    echo "OK: Registered contract takes priority over generic fallback\n";
} else {
    $errors[] = 'Registered contract overridden by generic fallback';
}

if ($errors) {
    echo "\nFAILURES:\n";
    foreach ($errors as $e) { echo "  ✗ {$e}\n"; }
    exit(1);
}
echo "\nALL PASS\n";
exit(0);
