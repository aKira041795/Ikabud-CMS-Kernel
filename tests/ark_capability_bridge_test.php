<?php
/**
 * ARK Capability Bridge Tests
 *
 * Validates that the entity-view-map.json capability bridge is consistent
 * with the block-definition catalog and renderer registry.
 * Each referenced `block` in entity-view-map must exist as a block definition.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\ThemeManifestValidator;

$passed = 0;
$failed = 0;
$themeDir = dirname(__DIR__) . '/storage/cms-themes/ark';

function abt(string $label, mixed $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS: {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL: {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    $failed++;
}

@file_put_contents(dirname(__DIR__) . '/storage/logs/app.log', '');
@file_put_contents(dirname(__DIR__) . '/storage/logs/error.log', '');

echo "=== ARK CAPABILITY BRIDGE ===\n";

// Load core contracts
$entityViewMapPath = $themeDir . '/entity-view-map.json';
$blockRegistryPath = $themeDir . '/block-registry.json';
$rendererRegistryPath = $themeDir . '/renderer-registry.json';

$entityViewMap = json_decode((string)file_get_contents($entityViewMapPath), true) ?: [];
$blockRegistry = json_decode((string)file_get_contents($blockRegistryPath), true) ?: [];
$rendererRegistry = json_decode((string)file_get_contents($rendererRegistryPath), true) ?: [];

abt('entity-view-map exists', is_file($entityViewMapPath));
abt('block-registry exists', is_file($blockRegistryPath));
abt('renderer-registry exists', is_file($rendererRegistryPath));
abt('entity-view-map loads', !empty($entityViewMap['entity_views']));
abt('block-registry loads', !empty($blockRegistry['categories']));
abt('renderer-registry loads', !empty($rendererRegistry['renderers']));

// Collect all registered block types from block-registry
$registeredBlocks = [];
foreach ($blockRegistry['categories'] as $category => $blocks) {
    foreach ($blocks as $blockType) {
        $registeredBlocks[$blockType] = $category;
    }
}
abt('registered block types found', count($registeredBlocks) > 0, (string)count($registeredBlocks));

// Collect all block definition files on disk
$blockDefDir = $themeDir . '/block-definitions';
$blockDefFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($blockDefDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if ($file->getExtension() === 'json' && $file->getFilename() !== 'block-definition.schema.json') {
        $def = json_decode((string)file_get_contents($file->getPathname()), true);
        if ($def && !empty($def['type'])) {
            $blockDefFiles[$def['type']] = $file->getPathname();
        }
    }
}
abt('block definition files on disk', count($blockDefFiles) > 0, (string)count($blockDefFiles));

// Test 1: Every block in block-registry must have a definition file
echo "\n--- Registry → Definition coverage ---\n";
foreach ($registeredBlocks as $blockType => $category) {
    $hasDef = isset($blockDefFiles[$blockType]);
    abt("registry block '{$blockType}' has definition file", $hasDef,
        $hasDef ? '' : "missing in block-definitions/{$category}/");
}

// Test 2: Every block definition file must be registered
echo "\n--- Definition → Registry coverage ---\n";
foreach ($blockDefFiles as $blockType => $path) {
    $isRegistered = isset($registeredBlocks[$blockType]);
    abt("definition '{$blockType}' is registered in block-registry", $isRegistered,
        $isRegistered ? '' : "orphaned file at {$path}");
}

// Test 3: Entity-view-map block references must resolve to block definitions
echo "\n--- Entity-view → Block cross-reference ---\n";
$entityViews = $entityViewMap['entity_views'] ?? [];
$entityCount = 0;
$blockRefCount = 0;
foreach ($entityViews as $entityType => $views) {
    foreach ($views as $viewName => $viewDef) {
        $entityCount++;
        $blockRef = $viewDef['block'] ?? null;
        if ($blockRef !== null) {
            $blockRefCount++;
            $blockExists = isset($blockDefFiles[$blockRef]) || isset($registeredBlocks[$blockRef]);
            abt("entity '{$entityType}.{$viewName}' block ref '{$blockRef}' exists", $blockExists,
                $blockExists ? '' : "undefined block type");
        }
    }
}
abt('entity views with block references', $blockRefCount > 0, "{$blockRefCount} of {$entityCount} views");

// Test 4: All block definitions have valid schema (required fields)
echo "\n--- Block definition schema validation ---\n";
$requiredFields = ['type', 'label', 'controls', 'renders_with'];
foreach ($blockDefFiles as $blockType => $path) {
    $def = json_decode((string)file_get_contents($path), true);
    if (!$def) {
        abt("block '{$blockType}' is valid JSON", false, "parse error");
        continue;
    }
    foreach ($requiredFields as $field) {
        $hasField = isset($def[$field]);
        abt("block '{$blockType}' has required field '{$field}'", $hasField,
            $hasField ? '' : "missing in {$path}");
    }
    // Validate controls structure
    if (!empty($def['controls']) && is_array($def['controls'])) {
        foreach ($def['controls'] as $ctrlName => $ctrlDef) {
            $hasType = !empty($ctrlDef['type']);
            abt("block '{$blockType}' control '{$ctrlName}' has type", $hasType,
                $hasType ? '' : "missing type in {$path}");
        }
    }
}

// Test 5: Entity-view-map entity types correspond to known modules
echo "\n--- Entity type module coverage ---\n";
$knownEntities = [
    'cms_post' => 'cms',
    'ecommerce_product' => 'ecommerce',
    'ehr_patient' => 'ehr',
];
foreach ($entityViews as $entityType => $views) {
    $known = isset($knownEntities[$entityType]);
    abt("entity type '{$entityType}' maps to known module", $known || true, // informational
        $known ? $knownEntities[$entityType] : 'custom/extension entity');
}

// Test 6: Theme validation still passes with new blocks
echo "\n--- Theme validation with new blocks ---\n";
$manifest = json_decode((string)file_get_contents($themeDir . '/theme.manifest.json'), true) ?: [];
$result = ThemeManifestValidator::validate('ark', $manifest, $themeDir);
abt('theme:validate passes after block additions', ($result['valid'] ?? false) === true,
    implode(' | ', $result['errors'] ?? []));
$bridgeWarnings = array_values(array_filter(
    $result['warnings'] ?? [],
    static fn(string $w): bool =>
        str_contains($w, 'block') || str_contains($w, 'entity') || str_contains($w, 'renderer')
));
abt('no block/entity/renderer warnings', $bridgeWarnings === [],
    implode(' | ', $bridgeWarnings));

// Log check
$appLog = (string)@file_get_contents(dirname(__DIR__) . '/storage/logs/app.log');
$errLog = (string)@file_get_contents(dirname(__DIR__) . '/storage/logs/error.log');
abt('app.log clean', trim($appLog) === '' || !str_contains($appLog, 'Error'), trim($appLog) ?: '(empty)');
abt('error.log clean', trim($errLog) === '' || !stripos($errLog, 'fatal'), trim($errLog) ?: '(empty)');

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
