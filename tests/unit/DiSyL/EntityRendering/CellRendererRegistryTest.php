<?php
declare(strict_types=1);

/**
 * Unit tests: CellRendererRegistry and built-in renderers.
 *
 * @see tests/entity_cell_renderer_registry_test.php (canonical)
 */

require_once __DIR__ . '/bootstrap.php';

use Ikabud\Kernel\EntityContext\CellRendererRegistry;
use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\Renderer\TextCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\BadgeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\MoneyCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\DateTimeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\BooleanCellRenderer;

echo "── CellRendererRegistry ──\n";

$registry = new CellRendererRegistry();
test_ok('empty initially', $registry->all() === []);
test_ok('has false for unknown', !$registry->has('x'));

$r = new TextCellRenderer();
$registry->register('text', $r, 'kernel');
test_ok('register + has', $registry->has('text'));
test_ok('get returns same', $registry->get('text') === $r);
test_ok('all includes provider', $registry->all()['text'] === 'kernel');

$registry->register('guidance.rating', new TextCellRenderer(), 'guidance');
test_ok('namespaced key', $registry->has('guidance.rating'));

$registry->reset();
test_ok('reset clears', $registry->all() === []);

test_summary('CellRendererRegistry');

// ── TextCellRenderer ──
echo "\n── TextCellRenderer ──\n";

$text = new TextCellRenderer();
$res = $text->render(new CellRenderContext(value: 'hello', field: 'name', row: ['name' => 'hello']));
test_ok('plain text', $res->html === 'hello');
$res2 = $text->render(new CellRenderContext(value: '<script>', field: 'x', row: ['x' => '<script>']));
test_ok('html escaping', $res2->html === '&lt;script&gt;');

test_summary('TextCellRenderer');

// ── BadgeCellRenderer ──
echo "\n── BadgeCellRenderer ──\n";

$badge = new BadgeCellRenderer();
$b1 = $badge->render(new CellRenderContext(value: true, field: 'active', row: ['active' => true]));
test_ok('truthy → Active/green', str_contains($b1->html, 'Active') && str_contains($b1->html, 'bg-green-100'));
$b2 = $badge->render(new CellRenderContext(value: false, field: 'active', row: ['active' => false]));
test_ok('falsy → Inactive/gray', str_contains($b2->html, 'Inactive') && str_contains($b2->html, 'bg-gray-100'));
$b3 = $badge->render(new CellRenderContext(value: 'pending', field: 's', row: ['s' => 'pending'], options: ['map' => ['pending' => 'Pending|amber']]));
test_ok('color map', str_contains($b3->html, 'Pending') && str_contains($b3->html, 'bg-amber-100'));

test_summary('BadgeCellRenderer');

// ── MoneyCellRenderer ──
echo "\n── MoneyCellRenderer ──\n";

$money = new MoneyCellRenderer();
$m1 = $money->render(new CellRenderContext(value: 1234.56, field: 'p', row: ['p' => 1234.56]));
test_ok('format ₱1,234.56', $m1->text === '₱1,234.56');
$m2 = $money->render(new CellRenderContext(value: -500, field: 'p', row: ['p' => -500]));
test_ok('negative shows minus', str_contains($m2->html, '-500'));

test_summary('MoneyCellRenderer');

// ── DateTimeCellRenderer ──
echo "\n── DateTimeCellRenderer ──\n";

$dt = new DateTimeCellRenderer();
$d1 = $dt->render(new CellRenderContext(value: '2026-06-19 14:30:00', field: 'u', row: []));
test_ok('full format', str_contains($d1->html, 'Jun') && str_contains($d1->html, '14:30'));
$d2 = $dt->render(new CellRenderContext(value: '2026-06-19 14:30:00', field: 'u', row: [], options: ['format' => 'date']));
test_ok('date format', str_contains($d2->html, 'Jun 19'));
$d3 = $dt->render(new CellRenderContext(value: 'not-a-date', field: 'u', row: []));
test_ok('invalid date', $d3->html === 'not-a-date');

test_summary('DateTimeCellRenderer');

// ── BooleanCellRenderer ──
echo "\n── BooleanCellRenderer ──\n";

$bool = new BooleanCellRenderer();
$o1 = $bool->render(new CellRenderContext(value: true, field: 'a', row: ['a' => true]));
test_ok('true → Yes', str_contains($o1->html, 'Yes'));
$o2 = $bool->render(new CellRenderContext(value: false, field: 'a', row: ['a' => false]));
test_ok('false → No', str_contains($o2->html, 'No'));

test_summary('BooleanCellRenderer');

// ── Context & Result ──
echo "\n── CellRenderContext / CellRenderResult ──\n";

$ctx = new CellRenderContext(value: 'x', field: 'f', row: ['f' => 'x'], outputTarget: 'csv');
test_ok('context outputTarget', $ctx->outputTarget === 'csv');

$res = new CellRenderResult(html: '<b>hi</b>', text: 'hi', exportValue: 42);
test_ok('result html', $res->html === '<b>hi</b>');
test_ok('result exportValue', $res->exportValue === 42);

test_summary('Context & Result');
