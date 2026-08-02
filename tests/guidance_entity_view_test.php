<?php
declare(strict_types=1);

/**
 * Guidance entity view integration test.
 *
 * Verifies the full entity view pipeline:
 *   1. View config registration via {ikb_entity_view} DiSyL configs
 *   2. EntityViewResolver contract lookup
 *   3. Entity list component rendering (including error states)
 *   4. Capability handler data shape
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');
define('STORAGE_PATH', $basePath . '/storage');

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\EntityContext\EntityViewResolver;

$pass = 0;
$fail = 0;

function vt(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── Guidance Entity View Integration Test ──\n\n";

// ════════════════════════════════════════════
// 1. View config registration
// ════════════════════════════════════════════

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

echo "  ── 1. View Config Registration ──\n";

$viewsDir = BASE_PATH . '/modules/guidance/helpers/views';
vt('views dir exists', is_dir($viewsDir));

$count = TemplateEngine::loadViewConfigs($viewsDir);
vt('loadViewConfigs loads files', $count > 0, 'loaded: ' . $count);

$caseViewFile = $viewsDir . '/guidance_case.disyl';
vt('guidance_case.disyl exists', is_file($caseViewFile));

// ════════════════════════════════════════════
// 2. View contract resolution
// ════════════════════════════════════════════

echo "\n  ── 2. View Contract Resolution ──\n";

$tableContract = $resolver->viewContract('guidance_case', 'table');
vt('table contract registered', is_array($tableContract));

$tableFields = $tableContract['fields'] ?? [];
$expectedTableFields = ['student_name', 'case_number', 'status', 'severity', 'category', 'college_code', 'counselor_name', 'updated_at'];
vt('table has 8 display fields plus hidden id', count(array_intersect($expectedTableFields, $tableFields)) === 8 && in_array('id', $tableFields, true), 'got: ' . count($tableFields) . ' — ' . implode(', ', $tableFields));
foreach ($expectedTableFields as $field) {
    vt("table field '{$field}' present", in_array($field, $tableFields, true));
}

$tableActions = $tableContract['actions'] ?? [];
vt('table has 3 actions', count($tableActions) === 3, 'got: ' . count($tableActions) . ' — ' . implode(', ', $tableActions));
vt('table has view action', in_array('view', $tableActions, true));
vt('table has edit action', in_array('edit', $tableActions, true));
vt('table has delete action', in_array('delete', $tableActions, true));
vt('table action_urls.view has {id}', str_contains($tableContract['action_urls']['view'] ?? '', '{id}'));
vt('table action_methods.delete is POST', ($tableContract['action_methods']['delete'] ?? '') === 'POST');
vt('table action_confirm.delete set', !empty($tableContract['action_confirm']['delete']));

$compactContract = $resolver->viewContract('guidance_case', 'compact');
vt('compact contract registered', is_array($compactContract));

$compactFields = $compactContract['fields'] ?? [];
$expectedCompactFields = ['student_name', 'status', 'counselor_name', 'updated_at'];
vt('compact has 4 display fields plus hidden id', count(array_intersect($expectedCompactFields, $compactFields)) === 4 && in_array('id', $compactFields, true), 'got: ' . count($compactFields) . ' — ' . implode(', ', $compactFields));
foreach ($expectedCompactFields as $field) {
    vt("compact field '{$field}' present", in_array($field, $compactFields, true));
}

$detailedContract = $resolver->viewContract('guidance_case', 'detailed');
vt('detailed contract registered', is_array($detailedContract));

$detailedActions = $detailedContract['actions'] ?? [];
vt('detailed has 3 actions', count($detailedActions) === 3, 'got: ' . count($detailedActions));

// ════════════════════════════════════════════
// 3. Entity list component rendering
// ════════════════════════════════════════════

echo "\n  ── 3. {ikb_entity_list} Component Rendering ──\n";

// 3a. Error: missing source
$e = new TemplateEngine('/tmp', '/tmp/cache');
$e->enableStrictMode(true);
$out = $e->renderString('{ikb_entity_list /}', []);
vt('missing source shows error state', str_contains($out, 'Missing source') || str_contains($out, 'not available'));

// 3b. Error: unknown source (no capability registered in test context)
$e2 = new TemplateEngine('/tmp', '/tmp/cache');
$e2->enableStrictMode(true);
$out2 = $e2->renderString('{ikb_entity_list source="nonexistent.entity" view="table" /}', []);
vt('unknown source shows error state', str_contains($out2, 'Unable to load') || str_contains($out2, 'error') || str_contains($out2, 'not available') || $out2 === '');

// 3c. Render with known source but no capability available (test context)
$e3 = new TemplateEngine('/tmp', '/tmp/cache');
$e3->enableStrictMode(true);
$out3 = $e3->renderString('{ikb_entity_list source="guidance_case" view="table" empty="No cases found." /}', []);
vt('guidance_case table renders without crash', $out3 !== false);
vt('guidance_case table shows graceful state', str_contains($out3, 'Unable') || str_contains($out3, 'error') || str_contains($out3, 'not available') || str_contains($out3, 'No cases') || $out3 === '');

// 3d. Entity list with header attribute
$e4 = new TemplateEngine('/tmp', '/tmp/cache');
$e4->enableStrictMode(true);
$out4 = $e4->renderString('{ikb_entity_list source="guidance_case" view="table" empty="Test empty." header="<div class=\'test-header\'>Filter bar</div>" /}', []);
vt('entity list with header', str_contains($out4, 'test-header') || str_contains($out4, 'Unable') || str_contains($out4, 'not available'));

// ════════════════════════════════════════════
// 4. Resolver source parsing
// ════════════════════════════════════════════

echo "\n  ── 4. EntityViewResolver source parsing ──\n";

$parsed = $resolver->parseSource('guidance_case');
vt('parses entity type', ($parsed['entity_type'] ?? '') === 'guidance_case', 'got: ' . ($parsed['entity_type'] ?? 'null'));
vt('parses empty qualifier', ($parsed['qualifier'] ?? '') === '', 'got: ' . ($parsed['qualifier'] ?? 'null'));

$parsed2 = $resolver->parseSource('guidance_case.open');
vt('parses qualifier from source', ($parsed2['qualifier'] ?? '') === 'open', 'got: ' . ($parsed2['qualifier'] ?? 'null'));

$parsed3 = $resolver->parseSource('cms.post.recent');
vt('parses cms entity', ($parsed3['entity_type'] ?? '') === 'cms.post', 'got: ' . ($parsed3['entity_type'] ?? 'null'));

// ════════════════════════════════════════════
// 5. View contract detail resolution
// ════════════════════════════════════════════

echo "\n  ── 5. Detail view contract ──\n";

$detailFields = $detailedContract['fields'] ?? [];
$detailedExpected = ['student_name', 'status', 'counselor_name', 'severity', 'student_status', 'created_at', 'updated_at'];
foreach ($detailedExpected as $field) {
    vt("detailed field '{$field}' present", in_array($field, $detailFields, true));
}

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
