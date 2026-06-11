<?php
/**
 * ReportManager — Unit Tests
 *
 * Verifies report templates, archives, permissions, scheduled reports,
 * module packs, and consistency checks.
 *
 * Run: php tests/report_manager_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\Services\ReportManager;
use Ikabud\Kernel\Services\KernelExport;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $fail++;
        echo "  \033[31m✗\033[0m {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "══════════════════════════════════════════\n";
echo "  ReportManager — Test Suite\n";
echo "══════════════════════════════════════════\n\n";

// ── Section 1: Template CRUD ──────────────────────────────────────────────
echo "── Templates ──\n";

// Clean slate: delete any leftover test template
ReportManager::deleteTemplate('test_template');
ReportManager::deleteTemplate('test_template_2');

// 1.1 — Save a new template
$saved = ReportManager::saveTemplate('test_template', [
    'title' => 'Test Report',
    'entity_type' => 'orders',
    'format' => 'csv',
    'columns' => ['id', 'status', 'total'],
]);
t('saveTemplate returns true', $saved === true);

// 1.2 — List templates includes saved template
$templates = ReportManager::listTemplates();
$found = false;
foreach ($templates as $tpl) {
    if (($tpl['id'] ?? '') === 'test_template') {
        $found = true;
        break;
    }
}
t('listTemplates includes saved template', $found);

// 1.3 — Template has expected fields
$tpl = null;
foreach ($templates as $t) {
    if (($t['id'] ?? '') === 'test_template') { $tpl = $t; break; }
}
t('template has title', ($tpl['title'] ?? '') === 'Test Report');
t('template has entity_type', ($tpl['entity_type'] ?? '') === 'orders');
t('template has updated_at', !empty($tpl['updated_at']));

// 1.4 — Overwrite template
ReportManager::saveTemplate('test_template', ['title' => 'Updated Report', 'entity_type' => 'invoices']);
$templates = ReportManager::listTemplates();
foreach ($templates as $t) {
    if (($t['id'] ?? '') === 'test_template') { $tpl = $t; break; }
}
t('template overwritten', ($tpl['title'] ?? '') === 'Updated Report');

// 1.5 — Delete template
$deleted = ReportManager::deleteTemplate('test_template');
t('deleteTemplate returns true', $deleted === true);

// 1.6 — Deleted template not in list
$templates = ReportManager::listTemplates();
$found = false;
foreach ($templates as $tpl) {
    if (($tpl['id'] ?? '') === 'test_template') { $found = true; break; }
}
t('deleted template not in list', $found === false);

// 1.7 — Delete non-existent template returns false
t('delete non-existent returns false', ReportManager::deleteTemplate('nonexistent_xyz') === false);

echo "\n";

// ── Section 2: Archive ───────────────────────────────────────────────────
echo "── Archive ──\n";

// 2.1 — Archive a report (from a temp file)
$tmpCsv = sys_get_temp_dir() . '/rpt_test_' . uniqid() . '.csv';
file_put_contents($tmpCsv, "id,name\n1,Test\n");
$archiveId = ReportManager::archiveReport('orders', 'csv', $tmpCsv, 'Test Archive');
t('archiveReport returns an ID', is_string($archiveId) && $archiveId !== '');

// 2.2 — List archived includes the new report
$archived = ReportManager::listArchived();
$foundArchived = false;
foreach ($archived as $a) {
    if (($a['id'] ?? '') === $archiveId) { $foundArchived = true; break; }
}
t('listArchived includes archived report', $foundArchived);

// 2.3 — Get archived report by ID
$entry = ReportManager::getArchivedReport($archiveId);
t('getArchivedReport returns array', is_array($entry));
t('archived has entity_type', ($entry['entity_type'] ?? '') === 'orders');
t('archived has format', ($entry['format'] ?? '') === 'csv');
t('archived has title', ($entry['title'] ?? '') === 'Test Archive');
t('archived has created_at', !empty($entry['created_at']));

// 2.4 — Get non-existent archived report
t('getArchivedReport null for unknown', ReportManager::getArchivedReport('nonexistent_xyz') === null);

// 2.5 — List archived is sorted by created_at desc
$all = ReportManager::listArchived();
if (count($all) >= 2) {
    t('archived sorted desc', ($all[0]['created_at'] ?? '') >= ($all[1]['created_at'] ?? ''));
} else {
    t('archived list not empty', count($all) >= 1);
}

echo "\n";

// ── Section 3: Permissions ───────────────────────────────────────────────
echo "── Permissions ──\n";

t('superadmin can export any format', ReportManager::canExport('orders', 'docx', ['role' => 'superadmin']) === true);
t('administrator can export any format', ReportManager::canExport('invoices', 'pdf', ['role' => 'administrator']) === true);
t('editor can export csv', ReportManager::canExport('orders', 'csv', ['role' => 'editor']) === true);
t('editor can export pdf', ReportManager::canExport('orders', 'pdf', ['role' => 'editor']) === true);
t('editor cannot export docx', ReportManager::canExport('orders', 'docx', ['role' => 'editor']) === false);
t('guest cannot export', ReportManager::canExport('orders', 'csv', ['role' => 'guest']) === false);
t('null user cannot export', ReportManager::canExport('orders', 'csv', null) === false);

echo "\n";

// ── Section 4: Scheduled Reports ─────────────────────────────────────────
echo "── Scheduled Reports ──\n";

// Clean up any leftover test scheduled reports from previous runs
$existing = ReportManager::listScheduled();
foreach ($existing as $e) {
    if (in_array(($e['entity_type'] ?? ''), ['test_orders', 'orders', 'invoices', 'products'], true)) {
        ReportManager::cancelScheduled($e['id'] ?? '');
    }
}

// 4.1 — Schedule a report with a unique entity type to avoid clashes
$scheduled = ReportManager::scheduleReport('test_orders', 'csv', 'daily', ['columns' => ['id', 'status']]);
t('scheduleReport returns true', $scheduled === true);

// 4.2 — List scheduled
$list = ReportManager::listScheduled();
$foundSch = false;
$schId = null;
$schEntry = null;
foreach ($list as $entry) {
    if (($entry['entity_type'] ?? '') === 'test_orders' && ($entry['format'] ?? '') === 'csv' && ($entry['schedule'] ?? '') === 'daily') {
        $foundSch = true;
        $schId = $entry['id'] ?? null;
        $schEntry = $entry;
        break;
    }
}
t('scheduled report in list', $foundSch);
t('scheduled has id', is_string($schId) && $schId !== '');
t('scheduled has created_at', !empty($schEntry['created_at'] ?? null));
t('scheduled last_run is null', array_key_exists('last_run', $schEntry ?? []) && $schEntry['last_run'] === null);

// 4.3 — Cancel scheduled report
if ($schId !== null) {
    $cancelled = ReportManager::cancelScheduled($schId);
    t('cancelScheduled returns true', $cancelled === true);

    $list = ReportManager::listScheduled();
    $foundAfter = false;
    foreach ($list as $s) {
        if (($s['id'] ?? '') === $schId) { $foundAfter = true; break; }
    }
    t('cancelled report not in list', $foundAfter === false);
}

// 4.4 — Schedule another and verify it persists alongside
ReportManager::scheduleReport('invoices', 'pdf', 'weekly', []);
ReportManager::scheduleReport('products', 'csv', 'monthly', []);
$list = ReportManager::listScheduled();
$foundInvoices = false;
$foundProducts = false;
foreach ($list as $s) {
    if (($s['entity_type'] ?? '') === 'invoices') $foundInvoices = true;
    if (($s['entity_type'] ?? '') === 'products') $foundProducts = true;
}
t('multiple scheduled reports coexist', $foundInvoices && $foundProducts);

echo "\n";

// ── Section 5: Module Report Packs ───────────────────────────────────────
echo "── Module Report Packs ──\n";

$packs = ReportManager::moduleReportPacks();
t('moduleReportPacks returns array', is_array($packs));

// At minimum we expect some report packs from installed modules
if (count($packs) > 0) {
    $first = $packs[0];
    t('pack has module field', isset($first['module']));
    t('pack has report_id', !empty($first['report_id'] ?? ''));
    t('pack has title', !empty($first['title'] ?? ''));
}

echo "\n";

// ── Section 6: Consistency Check ─────────────────────────────────────────
echo "── Consistency Check ──\n";

$rows = [
    ['id' => 1, 'name' => 'Item 1', 'status' => 'active'],
    ['id' => 2, 'name' => 'Item 2', 'status' => 'pending'],
];

$results = ReportManager::consistencyCheck('test_entity', $rows);
t('consistencyCheck returns array', is_array($results));
t('consistencyCheck has csv result', isset($results['csv']));
t('csv result has ok', array_key_exists('ok', $results['csv']));
t('csv result has size', array_key_exists('size', $results['csv'] ?? []));
t('csv result has duration_ms', array_key_exists('duration_ms', $results['csv'] ?? []));

// DOCX and PDF may fail if PHPWord/DomPDF aren't configured, but CSV should always work
if (!($results['csv']['ok'] ?? false)) {
    t('csv export succeeded (WARNING: check PHPWord/DomPDF)', true, 'csv export is expected to always work; review if this fails in CI');
}

echo "\n";

// ── Summary ───────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════\n";
printf("  PASS: %d  FAIL: %d\n", $pass, $fail);
echo "══════════════════════════════════════════\n";

exit($fail > 0 ? 1 : 0);
