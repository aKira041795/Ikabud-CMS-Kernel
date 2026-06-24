<?php
declare(strict_types=1);

/**
 * Quick test for keyof implementation.
 * Run: php tests/test_keyof_impl.php
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';
require_once $basePath . '/src/helpers/security.php';

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

use Ikabud\Kernel\EntityContext\EntityViewResolver;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$passed = 0;
$failed = 0;

function test(string $name, string $expected, string $actual): void {
    global $passed, $failed;
    if ($expected === $actual) {
        echo "  PASS: {$name}\n";
        $passed++;
    } else {
        echo "  FAIL: {$name}\n";
        echo "    Expected: " . json_encode($expected) . "\n";
        echo "    Actual:   " . json_encode($actual) . "\n";
        $failed++;
    }
}

// Register test entity views
$resolver = EntityViewResolver::getInstance();
$resolver->reset();
$resolver->registerView('employee_profile', 'compact', [
    'fields' => ['first_name', 'last_name', 'email', 'phone', 'department'],
    'actions' => ['view', 'edit'],
]);
$resolver->registerView('employee_profile', 'detailed', [
    'fields' => ['first_name', 'last_name', 'email', 'phone', 'department', 'salary', 'start_date', 'address'],
    'actions' => ['view', 'edit', 'delete'],
]);

$engine = new TemplateEngine($basePath . '/templates', $basePath . '/storage/cache');

echo "=== keyof Implementation Tests ===\n\n";

// Test 1: Basic keyof returns JSON array
$out = $engine->renderString('{keyof employee_profile}', []);
test('default view returns JSON with field names', '["first_name","last_name","email","phone","department"]', $out);

// Test 2: keyof with explicit view
$out2 = $engine->renderString('{keyof employee_profile.detailed}', []);
test('detailed view returns all fields', '["first_name","last_name","email","phone","department","salary","start_date","address"]', $out2);

// Test 3: keyof with | json filter
$out3 = $engine->renderString('{keyof employee_profile | json}', []);
test('keyof with json filter', '["first_name","last_name","email","phone","department"]', $out3);

// Test 4: keyof with | join filter (DiSyL uses : for filter args, not parentheses)
$out4 = $engine->renderString('{keyof employee_profile | join:", "}', []);
test('keyof with join filter', 'first_name, last_name, email, phone, department', $out4);

// Test 5: Unknown entity returns empty array
$out5 = $engine->renderString('{keyof nonexistent_entity}', []);
test('unknown entity returns empty array', '[]', $out5);

// Test 6: Strict mode doesn't crash
$engine2 = new TemplateEngine($basePath . '/templates', $basePath . '/storage/cache');
$engine2->enableStrictMode(true);
$out6 = $engine2->renderString('{keyof employee_profile}', []);
test('strict mode works', '["first_name","last_name","email","phone","department"]', $out6);

// Test 7: Entity with wildcard fields returns empty
$resolver->registerView('wildcard_entity', 'compact', [
    'fields' => '*',
]);
$out7 = $engine->renderString('{keyof wildcard_entity}', []);
test('wildcard fields returns empty array', '[]', $out7);

// Test 8: Empty expression falls through to variable resolution (no crash)
$out8 = $engine->renderString('{keyof }', []);
test('empty keyof expression falls through safely', '', $out8);

// Test 9: {for field in keyof entity_type} — field iteration
$out9 = $engine->renderString('{for f in keyof employee_profile}<span>{f}</span>{/for}', []);
$expected9 = '<span>first_name</span><span>last_name</span><span>email</span><span>phone</span><span>department</span>';
test('for loop over keyof fields', $expected9, $out9);

// Test 10: {for field in keyof entity_type.view} — specific view
$out10 = $engine->renderString('{for f in keyof employee_profile.detailed}<li>{f}</li>{/for}', []);
$hasSalary = str_contains($out10, 'salary') ? 'yes' : 'no';
$hasStartDate = str_contains($out10, 'start_date') ? 'yes' : 'no';
test('for loop over keyof detailed view has salary', 'yes', $hasSalary);
test('for loop over keyof detailed view has start_date', 'yes', $hasStartDate);

// Test 11: {for field in keyof unknown_entity} — unknown entity (empty loop)
$out11 = $engine->renderString('{for f in keyof nonexistent}<span>{f}</span>{empty}no fields{/for}', []);
test('for loop over unknown keyof shows empty content', 'no fields', $out11);

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
