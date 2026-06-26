<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0; $fail = 0; $errors = [];
function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; $errors[] = $label . ($detail !== '' ? ": {$detail}" : ''); echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};
// Redefine t properly
function ta(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else { $fail++; $errors[] = $label . ($detail !== '' ? ": {$detail}" : ''); echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== REPORT APPROVAL WORKFLOW ===\n";

$engine = app()->workflowEngine();
$db = app()->db();

// Ensure table exists
try { $db->query('SELECT 1 FROM report_approvals LIMIT 1'); }
catch (\Throwable $e) {
    $sql = file_get_contents(__DIR__ . '/../migrations/010_report_approvals.sql');
    foreach (explode(';', $sql) as $stmt) { $stmt = trim($stmt); if ($stmt !== '') { try { $db->exec($stmt); } catch (\Throwable $e2) {} } }
}

$handlers = cms_capability_handlers();
ta('report.request_approval handler registered', isset($handlers['report.export.request_approval@1']));
ta('report.approve handler registered', isset($handlers['report.export.approve@1']));
ta('report.reject handler registered', isset($handlers['report.export.reject@1']));
ta('report.list_pending handler registered', isset($handlers['report.export.list_pending@1']));

function callCap(string $id, array $payload): array {
    $h = cms_capability_handlers();
    $fn = $h[$id] ?? null;
    if (!$fn || !function_exists($fn)) return ['ok' => false, 'error' => "Handler $id not found"];
    try { $r = $fn($payload, $id, 'cms'); return is_array($r) ? $r : ['ok' => true, 'data' => $r]; }
    catch (\Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
}

$loaded = $engine->loadDefinitions(__DIR__ . '/../kernel', 'kernel');
ta('report.approval definition loaded', in_array('report.approval', $loaded, true));
$def = app()->workflow()->getDefinition('report.approval', 'kernel', 'report_export');
ta('report.approval definition exists in DB', $def !== null);
if ($def) {
    $keys = array_column(json_decode($def['states_json'] ?? '[]', true) ?: [], 'key');
    ta('has pending state', in_array('pending', $keys, true));
    ta('has approved state', in_array('approved', $keys, true));
    ta('has rejected state', in_array('rejected', $keys, true));
}

$r1 = callCap('report.export.request_approval@1', ['export_source'=>'cms_post','export_format'=>'csv','title'=>'Test']);
ta('request_approval returns ok', ($r1['ok']??false)===true, json_encode($r1));
$aid1 = $r1['data']['approval_id'] ?? 0;
ta('request_approval returns approval_id', $aid1 > 0);

$rl = callCap('report.export.list_pending@1', []);
ta('list_pending returns ok', ($rl['ok']??false)===true);
ta('list_pending has results', count($rl['data']??[]) >= 1);

$ra = callCap('report.export.approve@1', ['approval_id'=>$aid1]);
ta('approve returns ok', ($ra['ok']??false)===true, json_encode($ra));

$r2 = callCap('report.export.request_approval@1', ['export_source'=>'cms_post','export_format'=>'pdf','title'=>'Reject Test']);
$aid2 = $r2['data']['approval_id'] ?? 0;
$rr = callCap('report.export.reject@1', ['approval_id'=>$aid2, 'reason'=>'Test']);
ta('reject returns ok', ($rr['ok']??false)===true, json_encode($rr));

$wf = $engine->start('report.approval', 'kernel', ['export_source'=>'cms_post','export_format'=>'csv','title'=>'WF Test','approval_id'=>0,'requires_approval'=>'1'], 'report_export', 'wf-test');
ta('workflow engine starts approval run', ($wf['ok']??false)===true, json_encode($wf));

$log = @file_get_contents(STORAGE_PATH.'/logs/app.log')?:'';
$err = @file_get_contents(STORAGE_PATH.'/logs/error.log')?:'';
ta('no critical errors', !str_contains($log, '[critical]'));
ta('no PHP errors', trim($err)==='', trim($err));

echo "\n  PASS: {$pass}  FAIL: {$fail}\n";
if ($errors) { echo "\nFailed:\n"; foreach($errors as $e) echo "  - $e\n"; }
exit($fail > 0 ? 1 : 0);
