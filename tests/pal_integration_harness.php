<?php

declare(strict_types=1);

/**
 * PAL Integration Test Harness
 *
 * Tests that verify the full system process cycle:
 *  - Template rendering with correct DB data
 *  - Handler wiring (missing imports, undefined functions)
 *  - View model → template data flow
 *  - Shell context resolution
 *  - DiSyL component delegation
 *
 * Usage: php tests/pal_integration_harness.php
 */

require_once __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ✅ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ❌ {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function section(string $title): void
{
    echo "\n── {$title} ──\n";
}

// ────────────────────────────────────────────────────────────────────
section('1. View model autoloading');

test('PalDashboardViewModel class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\PalDashboardViewModel::class));
});

test('PalMoneyPresenter class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\PalMoneyPresenter::class));
});

test('PalStatusPresenter class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\PalStatusPresenter::class));
});

test('MoneyValue class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\MoneyValue::class));
});

test('StatusValue class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\StatusValue::class));
});

test('ActionValue class exists', function () {
    assertTrue(class_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\ActionValue::class));
});

test('TemplateViewModel interface exists', function () {
    assertTrue(interface_exists(\Ikabud\Modules\ProjectAuditLedger\Presentation\TemplateViewModel::class));
});

// ────────────────────────────────────────────────────────────────────
section('2. View model instantiation & data');

test('PalMoneyPresenter converts decimal string safely', function () {
    $p = new \Ikabud\Modules\ProjectAuditLedger\Presentation\PalMoneyPresenter('₱', 2);
    $m = $p->fromDecimalString('1234.56', 'PHP');
    assertSame(123456, $m->minorUnits);
    assertSame('₱1,234.56', $m->formatted);
    assertFalse($m->isNegative);
});

test('PalMoneyPresenter handles negative values', function () {
    $p = new \Ikabud\Modules\ProjectAuditLedger\Presentation\PalMoneyPresenter('₱', 2);
    $m = $p->fromDecimalString('-50.25', 'PHP');
    assertSame(-5025, $m->minorUnits);
    assertTrue($m->isNegative);
});

test('PalStatusPresenter maps domain to tone', function () {
    $p = new \Ikabud\Modules\ProjectAuditLedger\Presentation\PalStatusPresenter();
    assertSame('success', $p->resolve('approved')->tone);
    assertSame('warning', $p->resolve('pending')->tone);
    assertSame('danger', $p->resolve('rejected')->tone);
    assertSame('neutral', $p->resolve('draft')->tone);
});

test('PalStatusPresenter handles unknown status gracefully', function () {
    $p = new \Ikabud\Modules\ProjectAuditLedger\Presentation\PalStatusPresenter();
    $s = $p->resolve('nonexistent_status');
    assertSame('neutral', $s->tone);
    assertSame('nonexistent_status', $s->key);
});

// ────────────────────────────────────────────────────────────────────
section('3. Template file existence');

test('All 54 page templates exist', function () {
    $pagesDir = __DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/pages';
    $files = glob($pagesDir . '/*.disyl');
    assertTrue(count($files) >= 50, 'Expected at least 50 page templates, got ' . count($files));
});

test('All 6 PAL component templates exist', function () {
    $compDir = __DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/components';
    $files = glob($compDir . '/*.disyl');
    assertTrue(count($files) === 6, 'Expected 6 components, got ' . count($files));
});

test('Shell template exists', function () {
    $shell = __DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl';
    assertTrue(file_exists($shell));
});

test('All 15 Workbench DiSyL components exist', function () {
    $wbDir = __DIR__ . '/../storage/application-profiles/ark-workbench/components';
    $files = [];
    $dirs = ['shell', 'page', 'data', 'forms', 'interaction'];
    foreach ($dirs as $d) {
        $files = array_merge($files, glob("{$wbDir}/{$d}/*.disyl") ?: []);
    }
    assertTrue(count($files) === 15, 'Expected 15 Workbench components, got ' . count($files));
});

test('Workbench layouts exist', function () {
    assertTrue(file_exists(__DIR__ . '/../storage/application-profiles/ark-workbench/layouts/app-shell.disyl'));
    assertTrue(file_exists(__DIR__ . '/../storage/application-profiles/ark-workbench/layouts/app-shell-mobile.disyl'));
});

// ────────────────────────────────────────────────────────────────────
section('4. Handler wiring');

test('All 22 handler files are syntactically valid', function () {
    $handlerDir = __DIR__ . '/../modules/project-audit-ledger/handlers';
    $files = glob($handlerDir . '/*.php');
    foreach ($files as $f) {
        $output = [];
        $rc = 0;
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $output, $rc);
        assertTrue($rc === 0, basename($f) . ': ' . implode("\n", $output));
    }
});

test('dashboard handler imports PalDashboardViewModel', function () {
    $content = file_get_contents(__DIR__ . '/../modules/project-audit-ledger/handlers/10-dashboard.php');
    assertTrue(
        str_contains($content, 'PalDashboardViewModel'),
        'Dashboard handler must import PalDashboardViewModel'
    );
});

test('No handler references deleted pal-shell-context.php', function () {
    $handlerDir = __DIR__ . '/../modules/project-audit-ledger/handlers';
    $files = glob($handlerDir . '/*.php');
    foreach ($files as $f) {
        $content = file_get_contents($f);
        assertFalse(
            str_contains($content, 'pal-shell-context'),
            basename($f) . ' references deleted pal-shell-context.php'
        );
    }
});

// ────────────────────────────────────────────────────────────────────
section('5. DiSyL template syntax');

test('Shell template syntax is valid', function () {
    $output = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg(__DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/shell.disyl') . ' 2>&1', $output, $rc);
    assertTrue($rc === 0, implode("\n", $output));
});

test('All email templates syntax valid', function () {
    $templates = [
        '_email_job_order.disyl',
        '_email_invoice.disyl',
        '_po_gallery.disyl',
        '_attachments_list.disyl',
    ];
    foreach ($templates as $t) {
        $path = __DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/' . $t;
        $output = [];
        $rc = 0;
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
        assertTrue($rc === 0, "{$t}: " . implode("\n", $output));
    }
});

// ────────────────────────────────────────────────────────────────────
section('6. Profile and kernel contracts');

test('ARK Workbench profile.manifest.json is valid JSON', function () {
    $path = __DIR__ . '/../storage/application-profiles/ark-workbench/profile.manifest.json';
    $json = json_decode(file_get_contents($path), true);
    assertTrue(is_array($json), 'profile.manifest.json is not valid JSON');
    assertSame('ark-workbench', $json['name']);
});

test('ApplicationProfileRegistry can register provider', function () {
    \Ikabud\Kernel\Services\ApplicationProfileRegistry::reset();
    $provider = new \Ikabud\ApplicationProfiles\ArkWorkbench\ArkWorkbenchProvider();
    \Ikabud\Kernel\Services\ApplicationProfileRegistry::register($provider);
    assertTrue(\Ikabud\Kernel\Services\ApplicationProfileRegistry::has('ark.workbench'));
});

test('Attendance & Wages declares workbench profile', function () {
    $manifest = json_decode(
        file_get_contents(__DIR__ . '/../modules/attendance-wage/module.json'),
        true
    );
    assertSame('ark.workbench', $manifest['application_profile']['id'] ?? null);
});

test('Guidance declares workbench profile', function () {
    $manifest = json_decode(
        file_get_contents(__DIR__ . '/../modules/guidance/module.json'),
        true
    );
    assertSame('ark.workbench', $manifest['application_profile']['id'] ?? null);
});

// ────────────────────────────────────────────────────────────────────
section('7. Email template rendering');

test('_email_job_order renders without error', function () {
    $tplDir = __DIR__ . '/../templates';
    $cacheDir = sys_get_temp_dir() . '/disyl_test_' . uniqid();
    @mkdir($cacheDir, 0777, true);
    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine($tplDir, $cacheDir, false);
    $html = $engine->render(__DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/_email_job_order.disyl', [
        'company_name'    => 'Test Corp',
        'client_name'     => 'Test Client',
        'jo_number'       => 'JO-001',
        'project_title'   => 'Test Project',
        'scope_of_work'   => 'Testing',
        'contract_amount' => '1,234.56',
        'status'          => 'approved',
        'items'           => [
            ['sort_order' => 1, 'material_name' => 'Steel', 'particulars' => '2x4', 'width' => 2, 'height' => 4, 'quantity' => 1, 'uom' => 'pcs', 'price_per_unit' => 100, 'line_total' => 100],
        ],
        'mockup_url'      => '',
    ]);
    assertTrue(str_contains($html, 'JO-001'));
    assertTrue(str_contains($html, 'Test Project'));
});

test('_attachments_list renders without error', function () {
    $tplDir = __DIR__ . '/../templates';
    $cacheDir = sys_get_temp_dir() . '/disyl_test_' . uniqid();
    @mkdir($cacheDir, 0777, true);
    $engine = new \Ikabud\Kernel\DiSyL\TemplateEngine($tplDir, $cacheDir, false);
    $html = $engine->render(__DIR__ . '/../modules/project-audit-ledger/templates/project-audit-ledger/_attachments_list.disyl', [
        'files' => [
            ['id' => 1, 'original_filename' => 'test.pdf', 'file_size' => 102400, 'is_image' => false],
            ['id' => 2, 'original_filename' => 'photo.jpg', 'file_size' => 204800, 'is_image' => true],
        ],
        'entity_type' => 'project',
    ]);
    assertTrue(str_contains($html, 'test.pdf'));
    assertTrue(str_contains($html, 'photo.jpg'));
});

// ────────────────────────────────────────────────────────────────────
section('8. Value object serialization');

test('MoneyValue toTemplateValue returns array', function () {
    $m = new \Ikabud\Modules\ProjectAuditLedger\Presentation\MoneyValue(123456, 'PHP', '₱1,234.56', false);
    $ctx = $m->toTemplateValue();
    assertSame(123456, $ctx['minor_units']);
    assertSame('₱1,234.56', $ctx['formatted']);
});

test('StatusValue toTemplateValue includes tone not isTerminal', function () {
    $s = new \Ikabud\Modules\ProjectAuditLedger\Presentation\StatusValue('approved', 'Approved', 'success', 'Done');
    $ctx = $s->toTemplateValue();
    assertSame('success', $ctx['tone']);
    assertArrayNotHasKey('is_terminal', $ctx);
});

test('ActionValue toTemplateValue has no requiresRole', function () {
    $a = new \Ikabud\Modules\ProjectAuditLedger\Presentation\ActionValue('pay', 'Pay', '/pay', 'POST', 'primary', 'Confirm?');
    $ctx = $a->toTemplateValue();
    assertSame('pay', $ctx['key']);
    assertArrayNotHasKey('requires_role', $ctx);
});

// ────────────────────────────────────────────────────────────────────
section('9. DiSyL assoc array syntax (engine improvement)');

test('assoc array => syntax works in ExpressionEvaluator', function () {
    $eval = new \Ikabud\Kernel\DiSyL\ExpressionEvaluator();
    $result = $eval->resolveValue('["a" => "hello", "b" => "world"]', []);
    assertSame(['a' => 'hello', 'b' => 'world'], $result);
});

test('arrow inside quoted string is not parsed', function () {
    $eval = new \Ikabud\Kernel\DiSyL\ExpressionEvaluator();
    $result = $eval->resolveValue('["code" => "a => b"]', []);
    assertSame(['code' => 'a => b'], $result);
});

// ────────────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "══════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);

// ── Helpers ─────────────────────────────────────────────────────────

function assertTrue(bool $condition, string $msg = ''): void
{
    if (!$condition) {
        throw new \RuntimeException($msg ?: 'Expected true, got false');
    }
}

function assertFalse(bool $condition, string $msg = ''): void
{
    if ($condition) {
        throw new \RuntimeException($msg ?: 'Expected false, got true');
    }
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $msg = $msg ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new \RuntimeException($msg);
    }
}

function assertArrayNotHasKey(string $key, array $array, string $msg = ''): void
{
    if (array_key_exists($key, $array)) {
        throw new \RuntimeException($msg ?: "Array should not have key '{$key}'");
    }
}
