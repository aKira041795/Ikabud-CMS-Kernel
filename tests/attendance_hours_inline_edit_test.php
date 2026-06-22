<?php
declare(strict_types=1);

/**
 * Integration tests: Attendance record entity view with inline-editable hours.
 *
 * Tests:
 *   1. Attendance hours field in builtinDefaults view contract
 *   2. Field contract declares editable=true with update_capability
 *   3. renderCellEditable wraps output in Alpine ikbInlineEdit component
 *   4. Hours update capability handler validation
 *   5. End-to-end: editable field contract → rendered Alpine HTML
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

define('BASE_PATH', $basePath);
define('KERNEL_PATH', $basePath . '/kernel');

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Ikabud\Kernel\EntityContext\DefaultEntityRenderer;
use Ikabud\Kernel\EntityContext\CellRenderContext;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── Attendance Hours Inline Editing Test ──\n\n";

// ════════════════════════════════════════════
// 1. BuiltinDefaults includes hours field
// ════════════════════════════════════════════

echo "  ── 1. View contract has hours field ──\n";

$resolver = EntityViewResolver::getInstance();
$resolver->reset();

$contract = $resolver->viewContract('attendance_record', 'table');
t('attendance_record table contract exists', $contract !== null);
t('fields includes hours', $contract !== null && in_array('hours', $contract['fields'], true));
t('renderers has hours entry', $contract !== null && isset($contract['renderers']['hours']));

// ════════════════════════════════════════════
// 2. Field contract declares editable hours
// ════════════════════════════════════════════

echo "\n  ── 2. Field contracts for hours ──\n";

t('field_contracts exists', $contract !== null && isset($contract['field_contracts']));
t('hours field_contract exists', $contract !== null && isset($contract['field_contracts']['hours']));

$hoursFc = $contract['field_contracts']['hours'] ?? [];
t('hours is editable', ($hoursFc['editable'] ?? '') === 'true');
t('hours has update_capability', ($hoursFc['update_capability'] ?? '') === 'attendance.record.hours.update@1');

// ════════════════════════════════════════════
// 3. renderCellEditable wraps in Alpine component
// ════════════════════════════════════════════

echo "\n  ── 3. renderCellEditable output ──\n";

$renderer = new DefaultEntityRenderer();

// Non-editable cell — plain output
$plain = $renderer->renderCell('8.5', 'string', 'employee_name', ['id' => 1, 'employee_name' => 'John']);
t('non-editable cell is plain text', $plain === '8.5');

// Editable cell — Alpine wrapped
$editable = $renderer->renderCellEditable(8.5, 'string', 'hours', ['id' => 42], ['editable' => 'true', 'update_capability' => 'attendance.record.hours.update@1']);
t('editable cell contains x-data', str_contains($editable, 'x-data'));
t('editable cell contains ikbInlineEdit', str_contains($editable, 'ikbInlineEdit'));
t('editable cell contains entityId 42', str_contains($editable, '42'));
t('editable cell contains capability name', str_contains($editable, 'attendance.record.hours.update@1'));
t('editable cell has @click handler', str_contains($editable, '@click'));
t('editable cell has save handler', str_contains($editable, 'save'));
t('editable cell has cancel button', str_contains($editable, 'Cancel'));
t('editable cell has aria-live for errors', str_contains($editable, 'aria-live'));

// Non-editable with editable=false
$notEditable = $renderer->renderCellEditable(8.5, 'string', 'hours', ['id' => 42], ['editable' => 'false', 'update_capability' => 'attendance.record.hours.update@1']);
t('editable=false returns plain output', $notEditable === '8.5');

// Missing entity_id — should not wrap
$noId = $renderer->renderCellEditable(8.5, 'string', 'hours', ['name' => 'test'], ['editable' => 'true', 'update_capability' => 'attendance.record.hours.update@1']);
t('missing entity_id returns plain output', $noId === '8.5');

// ════════════════════════════════════════════
// 4. Hours update handler validation (logic only, inline)
// ════════════════════════════════════════════

echo "\n  ── 4. Handler validation logic ──\n";

// Cannot test the full handler without app() — instead validate
// the renderCellEditable correctly embeds the capability name
// from the field contract
t('capability name in Alpine config', str_contains($editable, 'attendance.record.hours.update@1'));
t('value 8.5 in Alpine config', str_contains($editable, '8.5'));

// ════════════════════════════════════════════
// 5. End-to-end: renderList with field contracts
// ════════════════════════════════════════════

echo "\n  ── 5. renderList with editable field contracts ──\n";

$rows = [
    ['id' => 42, 'employee_name' => 'John Doe', 'store_name' => 'Main', 'clock_in' => '2026-06-22 08:00:00', 'clock_out' => '2026-06-22 17:00:00', 'hours' => 9.0, 'status' => 'active'],
    ['id' => 43, 'employee_name' => 'Jane Smith', 'store_name' => 'Branch', 'clock_in' => '2026-06-22 09:00:00', 'clock_out' => '2026-06-22 18:00:00', 'hours' => 9.0, 'status' => 'active'],
];

$view = [
    'fields' => ['employee_name', 'hours', 'status'],
    'view' => 'table',
    'field_contracts' => [
        'hours' => ['editable' => 'true', 'update_capability' => 'attendance.record.hours.update@1'],
    ],
    'renderers' => ['hours' => 'string', 'status' => 'badge'],
    'empty_state' => 'No records.',
];

$output = $renderer->renderList($rows, $view, ['source' => 'attendance_record.all', 'view' => 'table']);
t('renderList produces output', $output !== '');
t('output contains editable hours Alpine component', str_contains($output, 'ikbInlineEdit'));
t('output contains entity ID 42', str_contains($output, '42'));
t('output contains update capability', str_contains($output, 'attendance.record.hours.update@1'));
t('output contains table HTML', str_contains($output, '<table'));
t('output contains data-ikb-entity', str_contains($output, 'data-ikb-entity'));
t('output contains hours column label', str_contains($output, 'Hours') || str_contains($output, 'hours'));

// Non-editable field should not have Alpine wrapping — check count of x-data
$alpineCount = substr_count($output, 'x-data=');
t('Alpine components rendered per row', $alpineCount > 0);
// With 2 rows, should have 2 ikbInlineEdit instances (one per hours cell)
$inlineEditCount = substr_count($output, 'ikbInlineEdit');
t('inline edit component for each hours cell', $inlineEditCount === 2);

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
