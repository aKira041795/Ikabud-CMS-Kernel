<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btWorkflow(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function renderBakeshopWorkflowSupervisor(array $user): string
{
    $bufferLevel = ob_get_level();
    app()->setUser($user);
    ob_start();
    try {
        bakeshopPageSupervisor();
        return (string)ob_get_clean();
    } finally {
        if (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
    }
}

echo "\n=== BAKESHOP SUPERVISOR DR WORKFLOW RENDER TEST ===\n\n";

$originalSettings = getModuleSettings('bakeshop');

try {
    saveModuleSettings('bakeshop', array_merge(is_array($originalSettings) ? $originalSettings : [], [
        'role_permissions' => json_encode([
            'admin' => ['bakeshop.read', 'bakeshop.manage'],
            'supervisor' => ['bakeshop.read'],
        ], JSON_UNESCAPED_SLASHES),
        'default_dr_coverage_days' => '3',
        'production_recipe_mode' => 'optional',
    ]));

    $html = renderBakeshopWorkflowSupervisor([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);

    btWorkflow('delivery form renders coverage days field', str_contains($html, 'Coverage Days') && str_contains($html, 'name="coverage_days"'), $html);
    btWorkflow('delivery form uses configured default coverage days', str_contains($html, 'id="delivery-coverage-days"') && str_contains($html, 'value="3"'), $html);
    btWorkflow('ingredient form renders pack metadata fields', str_contains($html, 'Pack Label') && str_contains($html, 'name="pack_label"') && str_contains($html, 'name="pack_qty"') && str_contains($html, 'name="pack_unit_id"'), $html);
    btWorkflow('ingredients table renders pack column', str_contains($html, '<th>Pack</th>'), $html);
    btWorkflow('production tab renders branch target section', str_contains($html, 'Branch Product Daily Targets') && str_contains($html, 'id="product-target-form"') && str_contains($html, 'id="product-targets-table"'), $html);
    btWorkflow('usage form renders projection horizon field', str_contains($html, 'Projection Horizon') && str_contains($html, 'name="horizon_days"'), $html);
    btWorkflow('usage tab renders dr projection section', str_contains($html, 'DR Projection') && str_contains($html, 'id="dr-projection-summary"'), $html);
    btWorkflow('usage tab renders dr projection print controls', str_contains($html, 'Open DR Projection Print') && str_contains($html, 'Print This Projection'), $html);
    btWorkflow('usage tab renders projected ingredients table', str_contains($html, 'id="dr-projection-ingredients-table"') && str_contains($html, 'Per Pack'), $html);
    btWorkflow('usage tab renders projected products table', str_contains($html, 'id="dr-projection-products-table"') && str_contains($html, 'Missing Days'), $html);
} finally {
    saveModuleSettings('bakeshop', is_array($originalSettings) ? $originalSettings : []);
}

echo "\nRESULT: {$pass} passed, {$fail} failed\n";

if ($fail > 0) {
    exit(1);
}
