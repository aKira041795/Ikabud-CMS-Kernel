<?php
/**
 * Theme Studio structured contract save tests.
 *
 * Verifies the structured save paths for ARK block registry, entity view map,
 * and safety policy write the expected JSON and reject duplicate rows.
 *
 * Usage: php tests/theme_studio_contract_structured_save_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers/40-theme-settings.php';
require_once __DIR__ . '/../modules/theme-studio/helpers.php';

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

function read_json_file(string $path): array
{
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function remove_dir_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

@file_put_contents(dirname(__DIR__) . '/storage/logs/app.log', '');
@file_put_contents(dirname(__DIR__) . '/storage/logs/error.log', '');

$themeSlug = 'tmp-theme-studio-' . bin2hex(random_bytes(4));
$themeDir = cmsThemesPath() . '/' . $themeSlug;
@mkdir($themeDir, 0777, true);

try {
    echo "Test 1: Structured block registry save\n";
    $error = null;
    $saved = themeStudioSaveStructuredBlockRegistry($themeSlug, [
        'version' => '2.1.0',
        'description' => 'Temporary block registry test.',
        'category_name' => ['layout', 'content'],
        'category_block_types' => ["page\nsection\ngrid", "text\nimage\nbutton"],
        'extra_registry_json' => '{"source":"test"}',
    ], $error);
    assert_true($saved === true, 'block registry save succeeds');
    assert_true($error === null, 'block registry save has no error');

    $blockRegistryPath = $themeDir . '/block-registry.json';
    $blockRegistry = read_json_file($blockRegistryPath);
    assert_true(($blockRegistry['version'] ?? '') === '2.1.0', 'block registry version saved');
    assert_true(($blockRegistry['description'] ?? '') === 'Temporary block registry test.', 'block registry description saved');
    assert_true(($blockRegistry['source'] ?? '') === 'test', 'block registry extra key preserved');
    assert_true(($blockRegistry['categories']['layout'] ?? []) === ['page', 'section', 'grid'], 'layout category saved');
    assert_true(($blockRegistry['categories']['content'] ?? []) === ['text', 'image', 'button'], 'content category saved');

    $detail = themeStudioEditableContractDetail($themeSlug, 'block-registry');
    assert_true(($detail['form']['version'] ?? '') === '2.1.0', 'block registry form model reloads saved version');
    assert_true(count($detail['form']['block_registry_rows'] ?? []) === 2, 'block registry form model has two rows');

    echo "\nTest 2: Duplicate block registry category rejected\n";
    $error = null;
    $saved = themeStudioSaveStructuredBlockRegistry($themeSlug, [
        'version' => '2.1.0',
        'description' => 'Duplicate category test.',
        'category_name' => ['layout', 'layout'],
        'category_block_types' => ["page", "section"],
        'extra_registry_json' => '{}',
    ], $error);
    assert_true($saved === false, 'duplicate block registry category fails');
    assert_true(is_string($error) && str_contains($error, 'Duplicate block registry category'), 'duplicate block registry error is specific');

    echo "\nTest 3: Structured entity view map save\n";
    $error = null;
    $saved = themeStudioSaveStructuredEntityViewMap($themeSlug, [
        'version' => '2.2.0',
        'description' => 'Temporary entity map test.',
        'entity_type' => ['ecommerce_product', 'ehr_patient'],
        'view_name' => ['card_grid', 'detail'],
        'view_fields' => ["name\nprice\nimage", "name\nage\nmrn"],
        'view_actions' => ["view\nadd_to_cart", "open_chart"],
        'view_block' => ['product_card', 'patient_summary'],
        'view_extra_json' => ['{"priority":"high"}', '{}'],
    ], $error);
    assert_true($saved === true, 'entity view map save succeeds');
    assert_true($error === null, 'entity view map save has no error');

    $entityViewMap = read_json_file($themeDir . '/entity-view-map.json');
    assert_true(($entityViewMap['version'] ?? '') === '2.2.0', 'entity view map version saved');
    assert_true(($entityViewMap['entity_views']['ecommerce_product']['card_grid']['fields'] ?? []) === ['name', 'price', 'image'], 'entity view fields saved');
    assert_true(($entityViewMap['entity_views']['ecommerce_product']['card_grid']['actions'] ?? []) === ['view', 'add_to_cart'], 'entity view actions saved');
    assert_true(($entityViewMap['entity_views']['ecommerce_product']['card_grid']['priority'] ?? '') === 'high', 'entity view extra JSON preserved');
    assert_true(($entityViewMap['entity_views']['ehr_patient']['detail']['block'] ?? '') === 'patient_summary', 'entity view block saved');

    echo "\nTest 4: Duplicate entity view row rejected\n";
    $error = null;
    $saved = themeStudioSaveStructuredEntityViewMap($themeSlug, [
        'version' => '2.2.0',
        'description' => 'Duplicate entity row test.',
        'entity_type' => ['ehr_patient', 'ehr_patient'],
        'view_name' => ['detail', 'detail'],
        'view_fields' => ["name", "name"],
        'view_actions' => ["open_chart", "open_chart"],
        'view_block' => ['patient_summary', 'patient_summary'],
        'view_extra_json' => ['{}', '{}'],
    ], $error);
    assert_true($saved === false, 'duplicate entity view row fails');
    assert_true(is_string($error) && str_contains($error, 'Duplicate entity view row'), 'duplicate entity view error is specific');

    echo "\nTest 5: Structured safety policy save\n";
    $error = null;
    $saved = themeStudioSaveStructuredSafetyPolicy($themeSlug, [
        'version' => '1.1.0',
        'allowed_raw_keys_text' => "content_html\nicon",
        'requires_capability_text' => "cms.content.render_raw@1",
        'raw_output_note' => 'Temporary note.',
        'allowed_context_sources_text' => "kernel\ncms\nentity_view",
        'blocked_patterns_text' => "session access\nfilesystem access",
        'allowed_js_bridges_text' => "alpine\nhtmx",
        'csp_note' => 'Use Alpine bindings instead of inline handlers.',
        'extra_policy_json' => '{"review":"required"}',
    ], $error);
    assert_true($saved === true, 'safety policy save succeeds');
    assert_true($error === null, 'safety policy save has no error');

    $safetyPolicy = read_json_file($themeDir . '/safety-policy.json');
    assert_true(($safetyPolicy['version'] ?? '') === '1.1.0', 'safety policy version saved');
    assert_true(($safetyPolicy['policy']['raw_output']['allowed_keys'] ?? []) === ['content_html', 'icon'], 'safety policy allowed raw keys saved');
    assert_true(($safetyPolicy['policy']['allowed_context_sources'] ?? []) === ['kernel', 'cms', 'entity_view'], 'safety policy context sources saved');
    assert_true(($safetyPolicy['policy']['review'] ?? '') === 'required', 'safety policy extra JSON preserved');
    assert_true(($safetyPolicy['policy']['csp_note'] ?? '') === 'Use Alpine bindings instead of inline handlers.', 'safety policy CSP note saved');

    echo "\nTest 6: Structured page composition schema save\n";
    $error = null;
    $saved = themeStudioSaveStructuredPageCompositionSchema($themeSlug, [
        'version' => '1.2.0',
        'description' => 'Temporary page composition schema test.',
        'envelope_required_keys_text' => "schema_version\ndocument",
        'envelope_schema_version_default' => '1.0',
        'envelope_extra_json' => '{"legacy_wrapper":"document"}',
        'root_type' => 'document',
        'root_required_keys_text' => "id\ntype\nprops\nstyle\nchildren\nmeta",
        'root_children_key' => 'children',
        'root_extra_json' => '{"editor_hint":"root-only"}',
        'allowed_top_level_children_text' => "section\nhero",
        'node_required_keys_text' => "id\ntype\nprops\nstyle\nchildren\nmeta",
        'props_must_be_object' => '1',
        'style_must_be_object' => '1',
        'children_must_be_array' => '1',
        'meta_must_be_object' => '1',
        'node_contract_extra_json' => '{"disallow_unknown_props":true}',
        'cms_builder_schema_version' => '1.0',
        'normalizer' => 'cmsBuilderNormalizeDocument',
        'default_document_factory' => 'cmsBuilderDefaultDocument',
        'compatibility_extra_json' => '{"source":"test"}',
        'extra_schema_json' => '{"authority_layer":"ark"}',
    ], $error);
    assert_true($saved === true, 'page composition schema save succeeds');
    assert_true($error === null, 'page composition schema save has no error');

    $pageCompositionSchema = read_json_file($themeDir . '/page-composition.schema.json');
    assert_true(($pageCompositionSchema['version'] ?? '') === '1.2.0', 'page composition schema version saved');
    assert_true(($pageCompositionSchema['document_envelope']['required_keys'] ?? []) === ['schema_version', 'document'], 'page composition envelope required keys saved');
    assert_true(($pageCompositionSchema['document_envelope']['legacy_wrapper'] ?? '') === 'document', 'page composition envelope extra JSON preserved');
    assert_true(($pageCompositionSchema['root_node']['editor_hint'] ?? '') === 'root-only', 'page composition root extra JSON preserved');
    assert_true(($pageCompositionSchema['allowed_top_level_children'] ?? []) === ['section', 'hero'], 'page composition top-level children saved');
    assert_true(($pageCompositionSchema['node_contract']['disallow_unknown_props'] ?? false) === true, 'page composition node contract extra JSON preserved');
    assert_true(($pageCompositionSchema['compatibility']['source'] ?? '') === 'test', 'page composition compatibility extra JSON preserved');
    assert_true(($pageCompositionSchema['authority_layer'] ?? '') === 'ark', 'page composition top-level extra JSON preserved');

    $pageSchemaDetail = themeStudioEditableContractDetail($themeSlug, 'page-composition-schema');
    assert_true(($pageSchemaDetail['exists'] ?? false) === true, 'editable contract detail marks page composition schema as existing');
    assert_true(($pageSchemaDetail['form']['version'] ?? '') === '1.2.0', 'editable contract detail reloads page composition schema form');

    echo "\nTest 7: Editable contract detail recognizes temporary files\n";
    $safetyDetail = themeStudioEditableContractDetail($themeSlug, 'safety-policy');
    assert_true(($safetyDetail['exists'] ?? false) === true, 'editable contract detail marks safety policy as existing');
    assert_true(($safetyDetail['form']['version'] ?? '') === '1.1.0', 'editable contract detail reloads safety policy form');
} finally {
    remove_dir_tree($themeDir);
}

echo "\n==============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "==============================\n";

exit($failed > 0 ? 1 : 0);