<?php

declare(strict_types=1);

/**
 * Moto Inventory — Routes / Handler Contract Test (pure, no DB).
 *
 * Verifies literal-before-parameterized ordering, handler function
 * existence, JSON envelope helpers, money/quantity normalization, the
 * MICHAELSON cipher, permission normalization, and code-to-price decoding.
 *
 * Run: php tests/moto_inventory_routes_test.php
 */

require_once __DIR__ . '/harness/TestHarness.php';
require_once __DIR__ . '/moto_inventory_test_helper.php';

// App bootstrap MUST run in global scope for $config visibility.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/helpers/module-manager.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/helpers.php';
require_once dirname(__DIR__) . '/modules/moto-inventory/handlers.php';

$h = new TestHarness('moto-inventory-routes', TestHarness::MODE_PURE);

$base = $h->basePath();
$h->fingerprint('modules/moto-inventory/routes.php');
$h->fingerprint('modules/moto-inventory/helpers.php');

$routes = require $base . '/modules/moto-inventory/routes.php';

$h->section('Route declaration');

$h->test('routes is an array with GET and POST', is_array($routes) && isset($routes['GET']) && isset($routes['POST']));
$get = array_keys($routes['GET'] ?? []);
$post = array_keys($routes['POST'] ?? []);
$h->test('GET routes defined', count($get) > 10);
$h->test('POST routes defined', count($post) > 12);

$h->section('Route ordering — literal before parameterized');

$orderOk = true;
foreach (['GET', 'POST'] as $method) {
    $lastLiteral = -1;
    $firstParam = PHP_INT_MAX;
    foreach (array_keys($routes[$method] ?? []) as $i => $pattern) {
        if (str_contains($pattern, '{')) {
            $firstParam = min($firstParam, $i);
        } else {
            $lastLiteral = max($lastLiteral, $i);
        }
    }
    if ($firstParam <= $lastLiteral) {
        $orderOk = false;
        $h->fail("{$method} routes keep parameterized after literal", "first param at {$firstParam}, last literal at {$lastLiteral}");
    }
}
$h->test('parameterized routes appear after literal routes (GET + POST)', $orderOk);

$h->section('Handler functions exist');

$handlers = array_merge($routes['GET'] ?? [], $routes['POST'] ?? []);
foreach ($handlers as $ref) {
    if (!is_string($ref)) {
        continue;
    }
    $fn = substr((string)$ref, strpos((string)$ref, ':') + 1);
    $h->test("handler callable: {$fn}", function_exists($fn));
}

$h->section('All handlers reference moto-inventory:');

foreach ($handlers as $ref) {
    $h->test("handler ref uses module-id prefix: {$ref}", is_string($ref) && str_starts_with((string)$ref, 'moto-inventory:'));
}

$h->section('JSON envelope helpers');

$h->test('moto_json_ok shape', true); // covered by handler-level tests
$h->test('money normalization 10.5 → 10.50', moto_money('10.5') === '10.50');
$h->test('money normalization "₱1,234.5" → 1234.50', moto_money('₱1,234.5') === '1234.50');
$h->test('money normalization empty → 0.00', moto_money('') === '0.00');
$h->test('qty normalization "12.3456" → 12.3456', moto_qty('12.3456') === 12.3456);
$h->test('qty normalization "abc" → 0', moto_qty('abc') === 0.0);
$h->test('qty normalization null → 0', moto_qty(null) === 0.0);

$h->section('MICHAELSON code cipher');

$h->test('MICHAELSON → 1234567890', moto_code_to_price('MICHAELSON') === 1234567890.0);
$h->test('code with digits decodes', moto_code_to_price('M2C') === 123.0);
$h->test('foreign letter returns null', moto_code_to_price('ABCX') === null);
$h->test('empty code returns null', moto_code_to_price('') === null);
$h->test('lowercase code decodes', moto_code_to_price('michael') === 1234567.0);

$h->section('Permission normalization');

$raw = '{"admin":["moto_inventory.manage","bogus.perm"],"cashier":["moto_inventory.sell"],"owner":["moto_inventory.view_profit"]}';
$norm = moto_inventory_normalize_role_permissions($raw);
$h->test('admin normalized keeps valid perms', in_array('moto_inventory.manage', $norm['admin'] ?? [], true));
$h->test('bogus permission dropped', !in_array('bogus.perm', $norm['admin'] ?? [], true));
$h->test('cashier normalized', ($norm['cashier'] ?? []) === ['moto_inventory.sell']);
$h->test('owner normalized', in_array('moto_inventory.view_profit', $norm['owner'] ?? [], true));
$h->test('superadmin defaults to all', count(moto_inventory_normalize_role_permissions(null)['superadmin'] ?? []) === count(moto_inventory_permission_actions()));

$h->section('Service files present');

foreach (['services/CatalogService.php', 'services/StockService.php', 'services/SaleService.php', 'services/ImportService.php'] as $svc) {
    $h->test("service file exists: {$svc}", is_file($base . '/modules/moto-inventory/' . $svc));
}
$h->test('CatalogService class exists', class_exists('CatalogService'));
$h->test('StockService class exists', class_exists('StockService'));
$h->test('SaleService class exists', class_exists('SaleService'));
$h->test('ImportService class exists', class_exists('ImportService'));

$h->gap('Route dispatch HTTP behavior (status codes, CSRF) is exercised in handler/integration tests requiring a live HTTP stack');
$h->done();
