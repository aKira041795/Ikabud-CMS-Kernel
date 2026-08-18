<?php
declare(strict_types=1);

/**
 * Unit tests for the cell renderer registry and built-in renderers.
 *
 * Tests:
 *   1. CellRendererRegistry — register, get, has, all, reset, missing renderer error
 *   2. TextCellRenderer — plain text with HTML escaping
 *   3. BadgeCellRenderer — truthy/falsy, color map from JSON, default Active/Inactive
 *   4. MoneyCellRenderer — format, negative values, custom options
 *   5. DateTimeCellRenderer — time, date, full, iso formats, invalid input
 *   6. BooleanCellRenderer — truthy/falsy, "0" handling
 *   7. CellRenderContext — value object construction
 *   8. CellRenderResult — value object construction
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

use Ikabud\Kernel\EntityContext\CellRendererRegistry;
use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\Renderer\TextCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\BadgeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\MoneyCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\DateTimeCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\BooleanCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\LocationCellRenderer;
use Ikabud\Kernel\EntityContext\Renderer\ImageCellRenderer;

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "── Cell Renderer Registry & Built-in Renderers Test ──\n\n";

// ════════════════════════════════════════════
// 1. CellRendererRegistry
// ════════════════════════════════════════════

echo "  ── 1. CellRendererRegistry ──\n";

$registry = new CellRendererRegistry();
t('registry is empty initially', $registry->all() === []);
t('has returns false for unknown', !$registry->has('nonexistent'));

$gotException = false;
try { $registry->get('nonexistent'); } catch (\RuntimeException $e) { $gotException = true; }
t('get throws on unknown', $gotException);

$textRenderer = new TextCellRenderer();
$registry->register('text', $textRenderer, 'kernel');
t('has returns true after register', $registry->has('text'));
t('get returns renderer', $registry->get('text') === $textRenderer);
t('all returns name→provider map', ($all = $registry->all()) && $all['text'] === 'kernel');
t('all filtered by provider', ($kernelOnly = $registry->all('kernel')) && isset($kernelOnly['text']));
t('all filtered by unknown provider returns empty', $registry->all('unknown') === []);

$registry->register('guidance.rating', new TextCellRenderer(), 'guidance');
t('namespaced key stores correctly', $registry->has('guidance.rating'));
t('all with no filter includes both', count($registry->all()) === 2);

$registry->reset();
t('reset clears all', $registry->all() === []);
t('reset clears has', !$registry->has('text'));

// ════════════════════════════════════════════
// 2. TextCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 2. TextCellRenderer ──\n";

$text = new TextCellRenderer();

$result = $text->render(new CellRenderContext(value: 'hello', field: 'name', row: ['name' => 'hello']));
t('renders plain text', $result->html === 'hello');
t('text matches html', $result->text === 'hello');
t('exportValue matches', $result->exportValue === 'hello');

$result = $text->render(new CellRenderContext(value: '<script>', field: 'name', row: ['name' => '<script>']));
t('escapes HTML', $result->html === '&lt;script&gt;');

$result = $text->render(new CellRenderContext(value: 42, field: 'count', row: ['count' => 42]));
t('renders integer', $result->html === '42');

$result = $text->render(new CellRenderContext(value: null, field: 'empty', row: []));
t('renders null as empty string', $result->html === '');

// ════════════════════════════════════════════
// 3. BadgeCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 3. BadgeCellRenderer ──\n";

$badge = new BadgeCellRenderer();

// Truthy → Active/green
$result = $badge->render(new CellRenderContext(value: true, field: 'active', row: ['active' => true]));
t('truthy renders Active badge', str_contains($result->html, 'Active'));
t('truthy badge has green class', str_contains($result->html, 'bg-green-100'));
t('truthy text is Active', $result->text === 'Active');

// Falsy → Inactive/gray
$result = $badge->render(new CellRenderContext(value: false, field: 'active', row: ['active' => false]));
t('falsy renders Inactive badge', str_contains($result->html, 'Inactive'));
t('falsy badge has gray class', str_contains($result->html, 'bg-gray-100'));
t('falsy text is Inactive', $result->text === 'Inactive');

// String '0' → Inactive
$result = $badge->render(new CellRenderContext(value: '0', field: 'active', row: ['active' => '0']));
t('string "0" renders Inactive', str_contains($result->html, 'Inactive'));

// Empty string → Inactive
$result = $badge->render(new CellRenderContext(value: '', field: 'active', row: ['active' => '']));
t('empty string renders Inactive', str_contains($result->html, 'Inactive'));

// Color map via options
$result = $badge->render(new CellRenderContext(
    value: 'pending',
    field: 'status',
    row: ['status' => 'pending'],
    options: ['map' => [
        'pending' => 'Pending|amber',
        'approved' => 'Approved|green',
    ]],
));
t('color map renders mapped label', str_contains($result->html, 'Pending'));
t('color map uses mapped color', str_contains($result->html, 'bg-amber-100'));
t('color map text uses label', $result->text === 'Pending');

// JSON arg via options
$result = $badge->render(new CellRenderContext(
    value: 'closed',
    field: 'status',
    row: ['status' => 'closed'],
    options: ['arg' => '{"open":"Open|blue","closed":"Closed|gray"}'],
));
t('JSON arg renders mapped label', str_contains($result->html, 'Closed'));
t('JSON arg uses mapped color', str_contains($result->html, 'bg-gray-100'));

// Color map with label only (no |color)
$result = $badge->render(new CellRenderContext(
    value: 'active',
    field: 'status',
    row: ['status' => 'active'],
    options: ['map' => ['active' => 'Active']],
));
t('map label without pipe defaults to gray', str_contains($result->html, 'bg-gray-100'));
t('map label without pipe shows label', str_contains($result->html, 'Active'));

// Pipe-pair arg (renderer="badge:{pending|amber|approved|green|...}"):
// alternating value|color pairs — label is the value itself, color from pair.
$result = $badge->render(new CellRenderContext(
    value: 'approved',
    field: 'status',
    row: ['status' => 'approved'],
    options: ['arg' => '{pending|amber|approved|green|settled|blue|voided|gray}'],
));
t('pipe-pair arg renders value as label', str_contains($result->html, 'approved'));
t('pipe-pair arg uses mapped color', str_contains($result->html, 'bg-green-100'));
t('pipe-pair arg text is value', $result->text === 'approved');

$result = $badge->render(new CellRenderContext(
    value: 'pending',
    field: 'status',
    row: ['status' => 'pending'],
    options: ['arg' => '{pending|amber|approved|green|settled|blue|voided|gray}'],
));
t('pipe-pair arg pending uses amber', str_contains($result->html, 'bg-amber-100'));
t('pipe-pair arg pending text', $result->text === 'pending');

// ════════════════════════════════════════════
// 4. MoneyCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 4. MoneyCellRenderer ──\n";

$money = new MoneyCellRenderer();

$result = $money->render(new CellRenderContext(value: 1234.56, field: 'price', row: ['price' => 1234.56]));
t('formats with currency symbol', str_contains($result->html, '₱'));
t('formats with commas', str_contains($result->html, '1,234'));
t('formats with decimals', str_contains($result->html, '56'));
t('text matches formatted value', $result->text === '₱1,234.56');
t('exportValue is float', $result->exportValue === 1234.56);

$result = $money->render(new CellRenderContext(value: -500, field: 'price', row: ['price' => -500]));
t('negative has red class', str_contains($result->html, 'text-red-600'));
t('negative shows minus', str_contains($result->html, '-500'));
t('negative shows decimals', str_contains($result->html, '500.00'));

$result = $money->render(new CellRenderContext(
    value: 99.9,
    field: 'price',
    row: ['price' => 99.9],
    options: ['decimals' => 0, 'currency' => '$'],
));
t('custom currency symbol', str_contains($result->html, '$'));
t('custom decimals', $result->text === '$100');

// ════════════════════════════════════════════
// 5. DateTimeCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 5. DateTimeCellRenderer ──\n";

$dt = new DateTimeCellRenderer();
$ts = strtotime('2026-06-19 14:30:00');

$result = $dt->render(new CellRenderContext(value: '2026-06-19 14:30:00', field: 'updated_at', row: ['updated_at' => '2026-06-19 14:30:00']));
t('full format shows month', str_contains($result->html, 'Jun'));
t('full format shows time', str_contains($result->html, '14:30'));

$result = $dt->render(new CellRenderContext(
    value: '2026-06-19 14:30:00', field: 'updated_at', row: [],
    options: ['format' => 'time'],
));
t('time format shows hours:minutes', str_contains($result->html, '14:30'));

$result = $dt->render(new CellRenderContext(
    value: '2026-06-19 14:30:00', field: 'updated_at', row: [],
    options: ['format' => 'date'],
));
t('date format shows month + day', str_contains($result->html, 'Jun 19'));

$result = $dt->render(new CellRenderContext(
    value: '2026-06-19 14:30:00', field: 'updated_at', row: [],
    options: ['format' => 'iso'],
));
t('iso format shows Y-m-d', str_contains($result->html, '2026-06-19'));

$result = $dt->render(new CellRenderContext(value: 'not-a-date', field: 'updated_at', row: ['updated_at' => 'not-a-date']));
t('invalid date returns raw value', $result->html === 'not-a-date');
t('invalid date export is raw', $result->exportValue === 'not-a-date');

$result = $dt->render(new CellRenderContext(value: 0, field: 'updated_at', row: ['updated_at' => 0]));
t('zero timestamp returns raw "0"', $result->html === '0');

// ════════════════════════════════════════════
// 6. BooleanCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 6. BooleanCellRenderer ──\n";

$bool = new BooleanCellRenderer();

$result = $bool->render(new CellRenderContext(value: true, field: 'is_active', row: ['is_active' => true]));
t('true renders "Yes" badge', str_contains($result->html, 'Yes'));
t('true has green class', str_contains($result->html, 'bg-green-100'));
t('true text is Yes', $result->text === 'Yes');
t('true exportValue is true', $result->exportValue === true);

$result = $bool->render(new CellRenderContext(value: false, field: 'is_active', row: ['is_active' => false]));
t('false renders "No" badge', str_contains($result->html, 'No'));
t('false has gray class', str_contains($result->html, 'bg-gray-100'));
t('false text is No', $result->text === 'No');
t('false exportValue is false', $result->exportValue === false);

$result = $bool->render(new CellRenderContext(value: '0', field: 'is_active', row: ['is_active' => '0']));
t('string "0" renders No', str_contains($result->html, 'No'));

$result = $bool->render(new CellRenderContext(value: 1, field: 'is_active', row: ['is_active' => 1]));
t('integer 1 renders Yes', str_contains($result->html, 'Yes'));

// ════════════════════════════════════════════
// 7. CellRenderContext
// ════════════════════════════════════════════

echo "\n  ── 7. CellRenderContext ──\n";

$ctx = new CellRenderContext(value: 'test', field: 'name', row: ['id' => 1, 'name' => 'test']);
t('value is accessible', $ctx->value === 'test');
t('field is accessible', $ctx->field === 'name');
t('row is accessible', $ctx->row === ['id' => 1, 'name' => 'test']);
t('default outputTarget is html', $ctx->outputTarget === 'html');
t('default view is table', $ctx->view === 'table');
t('fieldContract defaults to empty', $ctx->fieldContract === []);
t('options defaults to empty', $ctx->options === []);

$ctx2 = new CellRenderContext(
    value: 42, field: 'count', row: [],
    fieldContract: ['renderer' => 'money:2'],
    view: 'detailed',
    outputTarget: 'csv',
    options: ['decimals' => 0],
);
t('custom outputTarget', $ctx2->outputTarget === 'csv');
t('custom view', $ctx2->view === 'detailed');
t('custom fieldContract', $ctx2->fieldContract === ['renderer' => 'money:2']);
t('custom options', $ctx2->options === ['decimals' => 0]);

// ════════════════════════════════════════════
// 8. CellRenderResult
// ════════════════════════════════════════════

echo "\n  ── 8. CellRenderResult ──\n";

$res = new CellRenderResult(html: '<b>hi</b>', text: 'hi', exportValue: 42, ariaLabel: 'forty two');
t('html is accessible', $res->html === '<b>hi</b>');
t('text is accessible', $res->text === 'hi');
t('exportValue is accessible', $res->exportValue === 42);
t('ariaLabel is accessible', $res->ariaLabel === 'forty two');

$res2 = new CellRenderResult(html: 'hello');
t('text defaults to empty', $res2->text === '');
t('exportValue defaults to null', $res2->exportValue === null);
t('ariaLabel defaults to null', $res2->ariaLabel === null);

// ════════════════════════════════════════════
// 9. LocationCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 9. LocationCellRenderer ──\n";

$loc = new LocationCellRenderer();

$result = $loc->render(new CellRenderContext(value: 'Main Office', field: 'location', row: []));
t('renders place name', str_contains($result->html, 'Main Office'));
t('no coords when row has none', !str_contains($result->html, '📍'));

$result2 = $loc->render(new CellRenderContext(
    value: 'Branch', field: 'location', row: ['latitude' => '14.123', 'longitude' => '121.456']
));
t('renders name with coords', str_contains($result2->html, 'Branch'));
t('shows coords', str_contains($result2->html, '📍'));
t('includes latitude', str_contains($result2->html, '14.123'));

$result3 = $loc->render(new CellRenderContext(value: '', field: 'location', row: []));
t('empty value shows placeholder', str_contains($result3->html, '—'));

$result4 = $loc->render(new CellRenderContext(
    value: 'HQ', field: 'loc', row: ['latitude_in' => '10.5', 'longitude_in' => '120.5']
));
t('reads latitude_in from row', str_contains($result4->html, '10.5'));

// Name already contains coordinates — strip from display but keep 📍 line
$result5 = $loc->render(new CellRenderContext(
    value: 'Office (14.123,121.456)', field: 'location', row: ['latitude' => '14.123', 'longitude' => '121.456']
));
t('shows 📍 line for coords', str_contains($result5->html, '📍'));
t('strips coords from display name', !str_contains($result5->html, '(14.123,121.456)'));
t('renders clean name', str_contains($result5->html, 'Office'));
t('still shows 📍 line', str_contains($result5->html, '📍'));

// ════════════════════════════════════════════
// 10. ImageCellRenderer
// ════════════════════════════════════════════

echo "\n  ── 10. ImageCellRenderer ──\n";

$img = new ImageCellRenderer();

$result5 = $img->render(new CellRenderContext(value: '/photos/photo1.jpg', field: 'photo', row: []));
t('renders img tag', str_contains($result5->html, '<img'));
t('includes src', str_contains($result5->html, '/photos/photo1.jpg'));
t('has loading lazy', str_contains($result5->html, 'loading="lazy"'));

$result6 = $img->render(new CellRenderContext(value: '', field: 'photo', row: []));
t('empty shows placeholder', str_contains($result6->html, '—'));

$result7 = $img->render(new CellRenderContext(
    value: '/photos/pic.jpg', field: 'photo', row: [],
    options: ['modal' => true]
));
t('modal adds Alpine x-data', str_contains($result7->html, 'x-data'));
t('modal has overlay with x-show', str_contains($result7->html, 'x-show'));

$result8 = $img->render(new CellRenderContext(
    value: '/thumb.jpg', field: 'photo', row: ['name' => 'Profile pic'],
    options: ['alt_field' => 'name']
));
t('uses alt_field from row', str_contains($result8->html, 'Profile pic'));

// Modal via arg (renderer="image:modal" format)
$result9 = $img->render(new CellRenderContext(
    value: '/pic.jpg', field: 'photo', row: [],
    options: ['arg' => 'modal']
));
t('modal via arg has Alpine', str_contains($result9->html, 'x-data'));
t('modal via arg has overlay', str_contains($result9->html, 'x-show'));

// ════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
exit($fail > 0 ? 1 : 0);
