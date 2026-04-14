<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/helpers.php';
require_once __DIR__ . '/../modules/wms/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;

    if ($ok) {
        $pass++;
        echo "  + {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  - {$label}" . ($detail !== '' ? ' :: ' . $detail : '') . "\n";
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$manifestPath = __DIR__ . '/../modules/wms/module.json';
$routesPath = __DIR__ . '/../modules/wms/routes.php';
$manifest = json_decode((string)file_get_contents($manifestPath), true);
$routes = require $routesPath;

$html = '';
$loginHtml = '';
$diagnosticsHtml = '';
$renderError = '';

try {
    $html = app()->render('modules/wms/admin/dashboard.disyl', wmsAdminContext(
        ['id' => 1, 'role' => 'admin', 'source' => 'kernel', 'name' => 'WMS Admin', 'display_name' => 'WMS Admin'],
        'dashboard',
        [
            'page_title' => 'Warehouse Dashboard',
            'summary' => [
                'products' => 12,
                'warehouses' => 2,
                'locations' => 18,
                'deliveries_pending' => 3,
                'orders_pending' => 5,
                'low_stock_count' => 4,
            ],
            'recent_deliveries' => [
                ['reference_number' => 'DR-1001', 'supplier_name' => 'ACME Supply', 'status' => 'pending'],
            ],
            'recent_orders' => [
                ['order_number' => 'SO-1001', 'customer_name' => 'Jane Doe', 'status' => 'picking'],
            ],
            'recent_movements' => [
                ['product_name' => 'Widget A', 'movement_type' => 'in', 'qty' => '5.0000', 'location_code' => 'A-01'],
            ],
        ]
    ));
} catch (Throwable $e) {
    $renderError = $e->getMessage();
}

try {
    $loginHtml = app()->render('pages/login.disyl', wmsLoginPageContext());
} catch (Throwable $e) {
    if ($renderError === '') {
        $renderError = $e->getMessage();
    }
}

try {
    $diagnosticsHtml = app()->render('modules/wms/admin/diagnostics.disyl', wmsAdminContext(
        ['id' => 1, 'role' => 'admin', 'source' => 'kernel', 'name' => 'WMS Admin', 'display_name' => 'WMS Admin'],
        'diagnostics',
        [
            'page_title' => 'Diagnostics & Observability',
            'filters' => [
                'product_id' => 12,
                'ecommerce_order_id' => 321,
                'external_reference' => 'EC-2026-0001',
            ],
            'products' => [
                ['id' => 12, 'sku' => 'SKU-12', 'name' => 'Bridge Product'],
            ],
            'bridge_orders' => [
                [
                    'id' => 88,
                    'order_number' => 'WMS-1001',
                    'external_reference' => 'EC-2026-0001',
                    'ecommerce_order_id' => 321,
                    'status' => 'dispatched',
                    'reserved_qty' => 2,
                ],
            ],
            'reservations' => [
                [
                    'ecommerce_order_id' => 321,
                    'wms_order_number' => 'WMS-1001',
                    'external_reference' => 'EC-2026-0001',
                    'sku' => 'SKU-12',
                    'product_name' => 'Bridge Product',
                    'location_code' => 'A-01',
                    'warehouse_name' => 'Main Warehouse',
                    'qty' => 2,
                ],
            ],
            'trace' => [
                [
                    'movement_type' => 'reserved',
                    'reference_type' => 'order',
                    'reference_id' => 321,
                    'product_sku' => 'SKU-12',
                    'product_name' => 'Bridge Product',
                    'location_code' => 'A-01',
                    'qty' => 2,
                    'created_at' => '2026-04-09 08:00:00',
                ],
            ],
        ]
    ));
} catch (Throwable $e) {
    if ($renderError === '') {
        $renderError = $e->getMessage();
    }
}

echo "\n=== WMS MODULE ===\n";

t('wms manifest parses', is_array($manifest), 'module.json parse failed');
t('wms manifest owns 29 tables', count((array)($manifest['owns_tables'] ?? [])) === 29, (string)count((array)($manifest['owns_tables'] ?? [])));
t('wms manifest has 22 migrations', count((array)($manifest['migrations'] ?? [])) === 22, (string)count((array)($manifest['migrations'] ?? [])));
t('wms manifest contains audit_logs ownership', in_array('audit_logs', (array)($manifest['owns_tables'] ?? []), true));
t('wms manifest declares auth cookie', ($manifest['auth_cookie'] ?? '') === 'wms_token');
t('wms routes expose login page', isset($routes['GET']['/wms/login']) && $routes['GET']['/wms/login'] === 'wms:wmsPageLogin');
t('wms routes expose login post', isset($routes['POST']['/wms/auth/login']) && $routes['POST']['/wms/auth/login'] === 'wms:wmsAuthLogin');
t('wms routes expose dashboard', isset($routes['GET']['/wms']) && $routes['GET']['/wms'] === 'wms:wmsPageDashboard');
t('wms routes expose stock snapshot api', isset($routes['GET']['/api/v1/wms/stock']) && $routes['GET']['/api/v1/wms/stock'] === 'wms:wmsApiStockSnapshot');
t('wms routes expose delivery receive api', isset($routes['POST']['/api/v1/wms/deliveries/{id}/receive']) && $routes['POST']['/api/v1/wms/deliveries/{id}/receive'] === 'wms:wmsApiDeliveryReceive');
t('wms routes expose order deliver api', isset($routes['POST']['/api/v1/wms/orders/{id}/deliver']) && $routes['POST']['/api/v1/wms/orders/{id}/deliver'] === 'wms:wmsApiOrderDeliver');

$capabilityHandlers = wms_capability_handlers();
t('wms capability handler map includes kernel auth', ($capabilityHandlers['kernel.auth.authenticate@1'] ?? '') === 'wms_cap_kernel_auth_authenticate_1');
t('wms capability handler map includes stock query', ($capabilityHandlers['wms.stock.query@1'] ?? '') === 'wms_cap_wms_stock_query_1');
t('wms movement types include transfer and reservation flows', in_array('transfer_out', wmsMovementTypes(), true) && in_array('reserved', wmsMovementTypes(), true));
t('wms dashboard template renders successfully', $renderError === '', $renderError);
t('wms dashboard render includes key headings', str_contains($html, 'Warehouse Dashboard') && str_contains($html, 'Recent Deliveries') && str_contains($html, 'Recent Movements'));
t('wms diagnostics render includes reservation proof headings', str_contains($diagnosticsHtml, 'Bridge-Linked WMS Orders') && str_contains($diagnosticsHtml, 'Recent Ecommerce Reservation Trace') && str_contains($diagnosticsHtml, 'Movement Trace'));
t('wms login render includes WMS branding', str_contains($loginHtml, 'WMS') && str_contains($loginHtml, 'warehouse operations'));
t('wms login render posts to WMS auth route', str_contains($loginHtml, '/wms/auth/login'));

$leakedDisylControlTag = '';
if (preg_match('/\{(?:\/)?(?:if|foreach|for|block)\b[^}]*\}/', $html, $matches) === 1) {
    $leakedDisylControlTag = (string)($matches[0] ?? '');
}

t('wms dashboard render does not leak raw Disyl control tags', $leakedDisylControlTag === '', $leakedDisylControlTag);

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticalLines = array_values(array_filter(explode("\n", $appLog), static fn (string $line): bool => str_contains($line, '[critical]')));
$unexpectedErrorLines = array_values(array_filter(explode("\n", $errorLog), static fn (string $line): bool => trim($line) !== ''));

t('no app.log critical errors', empty($criticalLines), implode('; ', $criticalLines));
t('no PHP errors in error.log', empty($unexpectedErrorLines), implode('; ', $unexpectedErrorLines));

echo "\n==========================================\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
echo "==========================================\n";

if ($errors !== []) {
    echo "\nFailed checks:\n";
    foreach ($errors as $error) {
        echo ' - ' . $error . "\n";
    }
}

exit($fail > 0 ? 1 : 0);
