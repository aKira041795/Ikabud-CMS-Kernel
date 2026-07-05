<?php
/**
 * ThemeManifestValidator tests — verifies manifest schema validation,
 * file existence checks, and integration with cmsThemeManifestForSlug().
 *
 * Usage: php tests/theme_manifest_validation_test.php
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
        echo "  FAIL: {$label} (expected {$expected} errors, got {$count})\n";
        $failed++;
    }
}

// ── Test 1: Valid full manifest passes ──
echo "Test 1: Valid full manifest passes\n";
$validManifest = [
    'name' => 'test-theme',
    'version' => '1.0.0',
    'label' => 'Test Theme',
    'description' => 'A test theme',
    'author' => 'Test',
    'license' => 'MIT',
    'kernel_os_compat' => '6.1.0',
    'disyl_compat' => '4.7.0',
    'supported_surfaces' => ['public', 'print'],
    'supported_slots' => ['content.before', 'content', 'content.after'],
    'tokens' => 'tokens.json',
    'shell' => 'shell.disyl',
    'fallback_views' => [
        'card' => 'public/entity.list.disyl',
        'detail' => 'public/entity.view.disyl',
    ],
    'accessibility' => ['semantic_landmarks' => true],
    'browser_support' => ['chrome >= 90'],
    'required_assets' => ['css' => ['style.css']],
];
$result = ThemeManifestValidator::validate('test-theme', $validManifest);
assert_true($result['valid'], 'valid manifest is valid');
assert_count(0, $result['errors'], 'no errors');

// ── Test 2: Missing required key fails ──
echo "\nTest 2: Missing required key fails\n";
$missingName = $validManifest;
unset($missingName['name']);
$result = ThemeManifestValidator::validate('test-theme', $missingName);
assert_true(!$result['valid'], 'missing name is invalid');
assert_count(1, $result['errors'], 'one error for missing name');
assert_true(str_contains($result['errors'][0], 'name'), 'error mentions name');

// ── Test 3: Missing label fails ──
echo "\nTest 3: Missing label fails\n";
$missingLabel = $validManifest;
unset($missingLabel['label']);
$result = ThemeManifestValidator::validate('test-theme', $missingLabel);
assert_true(!$result['valid'], 'missing label is invalid');
assert_count(1, $result['errors'], 'one error for missing label');

// ── Test 4: Missing supported_surfaces fails ──
echo "\nTest 4: Missing supported_surfaces fails\n";
$missingSurfaces = $validManifest;
unset($missingSurfaces['supported_surfaces']);
$result = ThemeManifestValidator::validate('test-theme', $missingSurfaces);
assert_true(!$result['valid'], 'missing supported_surfaces is invalid');

// ── Test 5: Invalid version format warns ──
echo "\nTest 5: Invalid version format warns\n";
$badVersion = $validManifest;
$badVersion['version'] = '1.0'; // missing patch
$result = ThemeManifestValidator::validate('test-theme', $badVersion);
assert_true(!$result['valid'], 'invalid version format fails');
assert_count(1, $result['errors'], 'one error for version format');

// ── Test 6: Non-standard surface warns ──
echo "\nTest 6: Non-standard surface warns\n";
$customSurface = $validManifest;
$customSurface['supported_surfaces'] = ['public', 'quantum'];
$result = ThemeManifestValidator::validate('test-theme', $customSurface);
// 'public' is valid, 'quantum' should produce a warning
assert_true($result['valid'], 'custom surface is still valid');
assert_count(1, $result['warnings'], 'one warning for non-standard surface');

// ── Test 7: Wrong type for array field fails ──
echo "\nTest 7: Wrong type for array field fails\n";
$badType = $validManifest;
$badType['supported_surfaces'] = 'public'; // string, not array
$result = ThemeManifestValidator::validate('test-theme', $badType);
assert_true(!$result['valid'], 'string for array field fails');

// ── Test 8: Empty name fails ──
echo "\nTest 8: Empty name fails\n";
$emptyName = $validManifest;
$emptyName['name'] = '';
$result = ThemeManifestValidator::validate('test-theme', $emptyName);
assert_true(!$result['valid'], 'empty name fails');

// ── Test 9: File existence warnings ──
echo "\nTest 9: File existence warnings\n";
$tmpDir = sys_get_temp_dir() . '/theme-test-' . uniqid();
@mkdir($tmpDir, 0777, true);

$manifest = $validManifest;
$result = ThemeManifestValidator::validate('test-theme', $manifest, $tmpDir);
// tokens.json and shell.disyl don't exist, layouts/ and public/ dirs don't exist
assert_true($result['valid'], 'missing files are warnings, not errors');
assert_true(count($result['warnings']) > 0, 'warnings exist for missing files');

// Now create the directories and verify warnings decrease
@mkdir($tmpDir . '/layouts', 0777, true);
@mkdir($tmpDir . '/public', 0777, true);
$result2 = ThemeManifestValidator::validate('test-theme', $manifest, $tmpDir);
$warningsAfter = count($result2['warnings']);
assert_true($warningsAfter > 0, 'still warns about missing tokens.json');
// tokens.json warning should still be there

// Cleanup
$cleanup = function(string $dir): void {
    if (!is_dir($dir)) { return; }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
    }
    @rmdir($dir);
};
$cleanup($tmpDir);

// ── Test 10: Schema descriptions ──
echo "\nTest 10: Schema descriptions\n";
$descriptions = ThemeManifestValidator::getFieldDescriptions();
assert_true(isset($descriptions['name']), 'name has description');
assert_true(isset($descriptions['supported_surfaces']), 'supported_surfaces has description');
assert_true(str_contains($descriptions['name'], 'REQUIRED'), 'required key marked REQUIRED');
assert_true(str_contains($descriptions['accessibility'], 'optional'), 'optional key marked optional');

// ── Test 11: Standard slots list ──
echo "\nTest 11: Standard slots list\n";
$slots = ThemeManifestValidator::getStandardSlots();
assert_true(count($slots) > 5, 'multiple standard slots defined');
assert_true(in_array('content.before', $slots, true), 'content.before is a standard slot');
assert_true(in_array('site.after', $slots, true), 'site.after is a standard slot');

// ── Test 12: Non-standard slot warns ──
echo "\nTest 12: Non-standard slot warns\n";
$customSlot = $validManifest;
$customSlot['supported_slots'] = ['content.before', 'my-custom-slot'];
$result = ThemeManifestValidator::validate('test-theme', $customSlot);
assert_true(count($result['warnings']) >= 1, 'non-standard slot triggers warning');

// ── Test 13: Fallback view warn when missing ──
echo "\nTest 13: Missing fallback_views warns\n";
$noFallbacks = $validManifest;
unset($noFallbacks['fallback_views']);
$result = ThemeManifestValidator::validate('test-theme', $noFallbacks);
assert_true(count($result['warnings']) >= 1, 'missing fallback_views triggers warning');
$found = false;
foreach ($result['warnings'] as $w) {
    if (str_contains($w, 'fallback_views')) { $found = true; break; }
}
assert_true($found, 'warning mentions fallback_views');

// ── Test 14: Integration with cmsThemeManifestForSlug ──
echo "\nTest 14: Integration with cmsThemeManifestForSlug\n";
if (function_exists('cmsThemeManifestForSlug')) {
    $enManifest = cmsThemeManifestForSlug('entity-native');
    assert_true(isset($enManifest['_validation']), 'entity-native manifest has _validation key');
    assert_true($enManifest['_validation']['valid'] ?? false, 'entity-native manifest is valid');
    assert_count(0, $enManifest['_validation']['errors'] ?? [], 'entity-native has no validation errors');

    $ndManifest = cmsThemeManifestForSlug('native-default');
    assert_true(isset($ndManifest['_validation']), 'native-default manifest has _validation key');
} else {
    echo "  SKIP: cmsThemeManifestForSlug not available\n";
}

// ── Test 15: Valid entity-view-map contract passes semantic validation ──
echo "\nTest 15: Valid entity-view-map contract\n";
$entityTmpDir = sys_get_temp_dir() . '/theme-entity-map-valid-' . uniqid();
@mkdir($entityTmpDir . '/layouts', 0777, true);
@mkdir($entityTmpDir . '/public', 0777, true);
@mkdir($entityTmpDir . '/block-definitions/ecommerce', 0777, true);
@mkdir($entityTmpDir . '/block-definitions/healthcare', 0777, true);
@file_put_contents($entityTmpDir . '/tokens.json', json_encode(['colors' => ['primary' => '#000000', 'surface' => '#ffffff', 'text' => '#111111', 'border' => '#dddddd'], 'typography' => [], 'spacing' => [], 'radius' => []], JSON_PRETTY_PRINT));
@file_put_contents($entityTmpDir . '/shell.disyl', "{block content}{/block}\n");
@file_put_contents($entityTmpDir . '/block-definitions/block-definition.schema.json', json_encode(['type' => 'object'], JSON_PRETTY_PRINT));
@file_put_contents($entityTmpDir . '/block-registry.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Test registry',
    'categories' => [
        'ecommerce' => ['product_card'],
        'healthcare' => ['patient_summary'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($entityTmpDir . '/block-definitions/ecommerce/product_card.json', json_encode([
    'type' => 'product_card',
    'label' => 'Product Card',
    'controls' => ['variant'],
    'renders_with' => 'ark.blocks.product_card',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($entityTmpDir . '/block-definitions/healthcare/patient_summary.json', json_encode([
    'type' => 'patient_summary',
    'label' => 'Patient Summary',
    'controls' => ['variant'],
    'renders_with' => 'ark.blocks.patient_summary',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($entityTmpDir . '/entity-view-map.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Valid entity map',
    'entity_views' => [
        'ecommerce_product' => [
            'compact' => [
                'fields' => ['name', 'price'],
                'actions' => ['view'],
                'block' => 'product_card',
            ],
        ],
        'ehr_patient' => [
            'detail' => [
                'fields' => ['first_name', 'last_name'],
                'actions' => ['view'],
                'block' => 'patient_summary',
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$result = ThemeManifestValidator::validate('test-theme', $validManifest, $entityTmpDir);
$entityWarnings = array_values(array_filter($result['warnings'], static fn(string $warning): bool => str_contains($warning, 'entity-view-map')));
assert_count(0, $entityWarnings, 'valid entity-view-map produces no entity-view warnings');
$cleanup($entityTmpDir);

// ── Test 16: Invalid entity-view-map contract warns on semantic errors ──
echo "\nTest 16: Invalid entity-view-map contract\n";
$invalidEntityTmpDir = sys_get_temp_dir() . '/theme-entity-map-invalid-' . uniqid();
@mkdir($invalidEntityTmpDir . '/layouts', 0777, true);
@mkdir($invalidEntityTmpDir . '/public', 0777, true);
@file_put_contents($invalidEntityTmpDir . '/tokens.json', json_encode(['colors' => ['primary' => '#000000', 'surface' => '#ffffff', 'text' => '#111111', 'border' => '#dddddd'], 'typography' => [], 'spacing' => [], 'radius' => []], JSON_PRETTY_PRINT));
@file_put_contents($invalidEntityTmpDir . '/shell.disyl', "{block content}{/block}\n");
@file_put_contents($invalidEntityTmpDir . '/block-registry.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Invalid registry reference test',
    'categories' => [
        'ecommerce' => ['product_card'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($invalidEntityTmpDir . '/block-definitions/block-definition.schema.json', json_encode(['type' => 'object'], JSON_PRETTY_PRINT));
@mkdir($invalidEntityTmpDir . '/block-definitions/ecommerce', 0777, true);
@file_put_contents($invalidEntityTmpDir . '/block-definitions/ecommerce/product_card.json', json_encode([
    'type' => 'product_card',
    'label' => 'Product Card',
    'controls' => ['variant'],
    'renders_with' => 'ark.blocks.product_card',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($invalidEntityTmpDir . '/entity-view-map.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Invalid entity map',
    'entity_views' => [
        'ecommerce_product' => [
            'compact' => [
                'fields' => [],
                'actions' => ['view'],
                'block' => 'missing_block',
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$result = ThemeManifestValidator::validate('test-theme', $validManifest, $invalidEntityTmpDir);
$unknownBlockWarning = false;
$emptyFieldsWarning = false;
foreach ($result['warnings'] as $warning) {
    if (str_contains($warning, "references unknown block 'missing_block'")) {
        $unknownBlockWarning = true;
    }
    if (str_contains($warning, 'must declare a non-empty fields array')) {
        $emptyFieldsWarning = true;
    }
}
assert_true($unknownBlockWarning, 'invalid entity-view-map warns on unknown block');
assert_true($emptyFieldsWarning, 'invalid entity-view-map warns on empty fields');
$cleanup($invalidEntityTmpDir);

// ── Test 17: Valid page-composition schema passes semantic validation ──
echo "\nTest 17: Valid page-composition schema\n";
$pageSchemaTmpDir = sys_get_temp_dir() . '/theme-page-schema-valid-' . uniqid();
@mkdir($pageSchemaTmpDir . '/layouts', 0777, true);
@mkdir($pageSchemaTmpDir . '/public', 0777, true);
@file_put_contents($pageSchemaTmpDir . '/tokens.json', json_encode(['colors' => ['primary' => '#000000', 'surface' => '#ffffff', 'text' => '#111111', 'border' => '#dddddd'], 'typography' => [], 'spacing' => [], 'radius' => []], JSON_PRETTY_PRINT));
@file_put_contents($pageSchemaTmpDir . '/shell.disyl', "{block content}{/block}\n");
@file_put_contents($pageSchemaTmpDir . '/block-registry.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Registry present to trigger page schema expectation',
    'categories' => ['layout' => ['section']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($pageSchemaTmpDir . '/page-composition.schema.json', json_encode([
    'version' => '1.0.0',
    'document_envelope' => [
        'required_keys' => ['schema_version', 'document'],
        'schema_version_default' => '1.0',
    ],
    'root_node' => [
        'type' => 'document',
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'children_key' => 'children',
    ],
    'allowed_top_level_children' => ['section'],
    'node_contract' => [
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'props_must_be_object' => true,
        'style_must_be_object' => true,
        'children_must_be_array' => true,
        'meta_must_be_object' => true,
    ],
    'compatibility' => [
        'cms_builder_schema_version' => '1.0',
        'normalizer' => 'cmsBuilderNormalizeDocument',
        'default_document_factory' => 'cmsBuilderDefaultDocument',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$result = ThemeManifestValidator::validate('test-theme', $validManifest, $pageSchemaTmpDir);
$pageSchemaWarnings = array_values(array_filter($result['warnings'], static fn(string $warning): bool => str_contains($warning, 'page-composition.schema.json')));
assert_count(0, $pageSchemaWarnings, 'valid page-composition schema produces no page schema warnings');
$cleanup($pageSchemaTmpDir);

// ── Test 18: Invalid page-composition schema warns on contract drift ──
echo "\nTest 18: Invalid page-composition schema\n";
$invalidPageSchemaTmpDir = sys_get_temp_dir() . '/theme-page-schema-invalid-' . uniqid();
@mkdir($invalidPageSchemaTmpDir . '/layouts', 0777, true);
@mkdir($invalidPageSchemaTmpDir . '/public', 0777, true);
@file_put_contents($invalidPageSchemaTmpDir . '/tokens.json', json_encode(['colors' => ['primary' => '#000000', 'surface' => '#ffffff', 'text' => '#111111', 'border' => '#dddddd'], 'typography' => [], 'spacing' => [], 'radius' => []], JSON_PRETTY_PRINT));
@file_put_contents($invalidPageSchemaTmpDir . '/shell.disyl', "{block content}{/block}\n");
@file_put_contents($invalidPageSchemaTmpDir . '/block-registry.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Registry present to trigger page schema expectation',
    'categories' => ['layout' => ['section']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($invalidPageSchemaTmpDir . '/page-composition.schema.json', json_encode([
    'version' => '',
    'document_envelope' => [
        'required_keys' => ['document'],
    ],
    'root_node' => [
        'type' => 'page',
        'required_keys' => ['id', 'type'],
        'children_key' => 'items',
    ],
    'allowed_top_level_children' => [],
    'node_contract' => [
        'required_keys' => ['id', 'type'],
    ],
    'compatibility' => [
        'cms_builder_schema_version' => '',
        'normalizer' => 'customNormalize',
        'default_document_factory' => 'customDefault',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$result = ThemeManifestValidator::validate('test-theme', $validManifest, $invalidPageSchemaTmpDir);
$missingEnvelopeKeyWarning = false;
$wrongRootTypeWarning = false;
$wrongNormalizerWarning = false;
foreach ($result['warnings'] as $warning) {
    if (str_contains($warning, "document_envelope.required_keys must include 'schema_version'")) {
        $missingEnvelopeKeyWarning = true;
    }
    if (str_contains($warning, "root_node.type should be 'document'")) {
        $wrongRootTypeWarning = true;
    }
    if (str_contains($warning, "compatibility.normalizer should be 'cmsBuilderNormalizeDocument'")) {
        $wrongNormalizerWarning = true;
    }
}
assert_true($missingEnvelopeKeyWarning, 'invalid page-composition schema warns on missing envelope key');
assert_true($wrongRootTypeWarning, 'invalid page-composition schema warns on wrong root type');
assert_true($wrongNormalizerWarning, 'invalid page-composition schema warns on wrong normalizer');
$cleanup($invalidPageSchemaTmpDir);

// ── Test 19: Page schema warns when top-level children are missing from ARK registry ──
echo "\nTest 19: Page-composition schema unknown top-level child\n";
$unknownTopLevelTmpDir = sys_get_temp_dir() . '/theme-page-schema-unknown-top-level-' . uniqid();
@mkdir($unknownTopLevelTmpDir . '/layouts', 0777, true);
@mkdir($unknownTopLevelTmpDir . '/public', 0777, true);
@file_put_contents($unknownTopLevelTmpDir . '/tokens.json', json_encode(['colors' => ['primary' => '#000000', 'surface' => '#ffffff', 'text' => '#111111', 'border' => '#dddddd'], 'typography' => [], 'spacing' => [], 'radius' => []], JSON_PRETTY_PRINT));
@file_put_contents($unknownTopLevelTmpDir . '/shell.disyl', "{block content}{/block}\n");
@file_put_contents($unknownTopLevelTmpDir . '/block-registry.json', json_encode([
    'version' => '2.0.0',
    'description' => 'Registry missing layout_container on purpose',
    'categories' => ['layout' => ['section']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@file_put_contents($unknownTopLevelTmpDir . '/page-composition.schema.json', json_encode([
    'version' => '1.0.0',
    'document_envelope' => [
        'required_keys' => ['schema_version', 'document'],
        'schema_version_default' => '1.0',
    ],
    'root_node' => [
        'type' => 'document',
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'children_key' => 'children',
    ],
    'allowed_top_level_children' => ['section', 'layout_container'],
    'node_contract' => [
        'required_keys' => ['id', 'type', 'props', 'style', 'children', 'meta'],
        'props_must_be_object' => true,
        'style_must_be_object' => true,
        'children_must_be_array' => true,
        'meta_must_be_object' => true,
    ],
    'compatibility' => [
        'cms_builder_schema_version' => '1.0',
        'normalizer' => 'cmsBuilderNormalizeDocument',
        'default_document_factory' => 'cmsBuilderDefaultDocument',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$result = ThemeManifestValidator::validate('test-theme', $validManifest, $unknownTopLevelTmpDir);
$unknownTopLevelWarning = false;
foreach ($result['warnings'] as $warning) {
    if (str_contains($warning, "allowed_top_level_children references unknown ARK block type 'layout_container'")) {
        $unknownTopLevelWarning = true;
    }
}
assert_true($unknownTopLevelWarning, 'page-composition schema warns when top-level child is not published in block-registry');
$cleanup($unknownTopLevelTmpDir);

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);
