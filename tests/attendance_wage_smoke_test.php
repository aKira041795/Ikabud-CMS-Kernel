<?php

declare(strict_types=1);

/**
 * Integration test for the Attendance & Wage module.
 *
 * Verifies:
 *   - manifest is valid JSON and parses
 *   - module.json id matches folder name
 *   - capability handlers are registered
 *   - entity view contracts are callable
 *   - auth-owned manifest is properly configured
 *   - all migrations are listed
 *   - route file is loadable
 *
 * Run from repo root: php tests/attendance_wage_smoke_test.php
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

$errors = [];
$passed = 0;
$total  = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $total, $passed, $errors;
    $total++;
    if ($condition) { $passed++; echo "  ✅ {$name}\n"; }
    else { $errors[] = "{$name}: {$detail}"; echo "  ❌ {$name}: {$detail}\n"; }
}

echo "Attendance & Wage — Integration Test\n";
echo str_repeat('=', 60) . "\n\n";

// 1. Manifest validation
echo "1. Manifest\n";
$manifestPath = $basePath . '/modules/attendance-wage/module.json';
$check = validateModuleManifest($manifestPath);
test('Manifest is valid JSON', !empty($check['ok']), $check['error'] ?? 'unknown');

$manifest = $check['manifest'] ?? [];
test('Manifest id matches folder', ($manifest['id'] ?? '') === 'attendance-wage', 'got: ' . ($manifest['id'] ?? 'none'));

// 2. Auth-owned configuration
echo "\n2. Auth-owned\n";
$authOwned = $manifest['auth_owned'] ?? [];
test('auth_owned present', !empty($authOwned));
test('users_table = attendance_wage_users', ($authOwned['users_table'] ?? '') === 'attendance_wage_users');
test('admin_roles includes admin', in_array('admin', $authOwned['admin_roles'] ?? []));
test('blocked_password_hashes contains bootstrap hash', in_array('!attendance-wage-bootstrap-password-reset-required!', $authOwned['blocked_password_hashes'] ?? []));
test('requires_named_admin_on_provision', !empty($authOwned['requires_named_admin_on_provision']));

// 3. Capabilities
echo "\n3. Capabilities\n";
$caps = $manifest['capabilities']['exposes'] ?? [];
test('Capabilities defined', count($caps) > 0, 'got ' . count($caps));
$capIds = array_column($caps, 'id');
test('kernel.auth.authenticate@1 registered', in_array('kernel.auth.authenticate@1', $capIds));
test('attendance_wage.clock@1 registered', in_array('attendance_wage.clock@1', $capIds));
test('entity.list.attendance_record@1 registered', in_array('entity.list.attendance_record@1', $capIds));
test('entity.get.attendance_record@1 registered', in_array('entity.get.attendance_record@1', $capIds));

// 4. Entity views — verify all required entities
echo "\n4. Entity views\n";
$requiredEntities = [
    'attendance_record', 'employee_profile', 'payroll_period', 'salary_computation',
    'salary_adjustment', 'employee_deduction', 'holiday', 'cash_advance', 'employee_schedule',
    'office_location',
];
foreach ($requiredEntities as $entity) {
    $hasList = in_array("entity.list.{$entity}@1", $capIds);
    $hasGet  = in_array("entity.get.{$entity}@1", $capIds);
    test("entity.list.{$entity}@1", $hasList);
    test("entity.get.{$entity}@1", $hasGet);
}

// 5. Migrations
echo "\n5. Migrations\n";
$migrations = $manifest['migrations'] ?? [];
test('Migrations defined', count($migrations) > 0, 'got ' . count($migrations));
test('Has attendance_records migration', !empty(array_filter($migrations, fn($m) => str_contains($m, 'attendance_records'))));
test('Has cash_advances migration', !empty(array_filter($migrations, fn($m) => str_contains($m, 'cash_advances'))));
test('Has payroll_settings migration', !empty(array_filter($migrations, fn($m) => str_contains($m, 'payroll_settings'))));

// 6. Route file
echo "\n6. Routes\n";
$routesPath = $basePath . '/modules/attendance-wage/routes.php';
test('routes.php exists', is_file($routesPath));
$routes = include $routesPath;
test('routes is array', is_array($routes));
test('GET routes defined', !empty($routes['GET']), 'got ' . count($routes['GET'] ?? []));
test('POST routes defined', !empty($routes['POST']), 'got ' . count($routes['POST'] ?? []));

// 7. Handler file includes
echo "\n7. Handler files\n";
$handlerDir = $basePath . '/modules/attendance-wage/handlers';
$handlerFiles = [
    '00-bootstrap.php', '05-auth.php', '10-pages-attendance.php', '20-api-attendance.php',
    '30-pages-wage.php', '40-api-employees.php', '50-api-periods.php', '60-api-computations.php',
    '70-api-adjustments.php', '80-api-deductions.php', '90-api-cash-advances.php',
    '100-api-holidays.php', '110-api-schedules.php', '110-api-reports.php', '120-api-locations.php',
    '130-api-kiosk.php', '140-api-groups.php', '150-team-lead.php',
];
foreach ($handlerFiles as $hf) {
    $path = $handlerDir . '/' . $hf;
    test("{$hf} exists", is_file($path));
    if (is_file($path)) {
        $content = file_get_contents($path);
        test("{$hf} no TODO stubs", !str_contains($content, '// TODO'), 'contains TODO stub — must be implemented');
    }
}

// 8. Templates exist for all page routes
echo "\n8. Template coverage\n";
$tplBase = $basePath . '/templates/modules/attendance-wage';
$expectedTemplates = [
    'layouts/admin.disyl', 'auth/login.disyl', 'auth/forgot-password.disyl', 'auth/reset-password.disyl',
    'auth/team-lead-login.disyl', 'auth/team-lead-dashboard.disyl',
    'attendance/clock.disyl', 'attendance/history.disyl', 'attendance/report.disyl',
    'wage/dashboard.disyl', 'wage/employees/index.disyl', 'wage/employees/form.disyl',
    'wage/periods/index.disyl', 'wage/periods/form.disyl', 'wage/computations/index.disyl',
    'wage/adjustments/index.disyl', 'wage/adjustments/form.disyl',
    'wage/deductions/index.disyl', 'wage/deductions/form.disyl',
    'wage/cash-advances/index.disyl', 'wage/cash-advances/form.disyl',
    'wage/holidays/index.disyl', 'wage/schedules/index.disyl',
    'wage/reports/index.disyl', 'wage/reports/detail.disyl',
    'wage/locations/index.disyl', 'wage/locations/form.disyl',
    'wage/settings.disyl',
];
foreach ($expectedTemplates as $et) {
    test("{$et}", is_file($tplBase . '/' . $et));
}

// Summary
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed}/{$total} passed";
if (!empty($errors)) {
    echo " — " . count($errors) . " failures";
}
echo "\n";

if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) { echo "  ❌ {$e}\n"; }
    exit(1);
}

echo "✅ All tests passed.\n";
exit(0);
