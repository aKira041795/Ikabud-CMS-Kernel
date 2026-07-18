<?php

declare(strict_types=1);

/**
 * Attendance & Wage — Route / Manifest / Template Parity Test
 *
 * Verifies:
 *   1. Every route handler function exists
 *   2. Every page route has a template file
 *   3. Module.json nav URLs resolve to registered routes
 *   4. PAL team-lead attendance chain complete
 *   5. Workbench contracts exist and are synchronized
 *   6. No stale handler references
 *
 * Run from repo root: php tests/attendance_wage_contract_parity_test.php
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';

$errors = [];
$passed = 0;
$total  = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $total, $passed, $errors;
    $total++;
    if ($condition) { $passed++; echo "  \xE2\x9C\x85 {$name}\n"; }
    else { $errors[] = "{$name}: {$detail}"; echo "  \xE2\x9D\x8C {$name}: {$detail}\n"; }
}

echo "Attendance & Wage — Contract Parity Test\n";
echo str_repeat('=', 60) . "\n\n";

// Load all handler files
foreach (glob($basePath . '/modules/attendance-wage/handlers/*.php') as $hf) {
    require_once $hf;
}
foreach (glob($basePath . '/modules/project-audit-ledger/handlers/*.php') as $hf) {
    require_once $hf;
}

// ────────────────────────────────────────────────────────────────────
echo "1. Route → Handler function existence\n";

$awRoutes = include $basePath . '/modules/attendance-wage/routes.php';
$missing = 0;
foreach (['GET', 'POST'] as $method) {
    foreach (($awRoutes[$method] ?? []) as $path => $ref) {
        $parts = explode(':', $ref);
        $fn = $parts[1] ?? '';
        if (!function_exists($fn)) {
            $missing++;
            test("Handler: {$ref}", false, "{$method} {$path}");
        }
    }
}
if ($missing === 0) {
    test('All AW route handlers exist', true);
}

$groupsHandler = file_get_contents($basePath . '/modules/attendance-wage/handlers/140-api-groups.php') ?: '';
test('Groups handler resolves tenant DB through typed context',
    str_contains($groupsHandler, 'function awGroupContext(array $user): array')
    && str_contains($groupsHandler, '$tenantId = (int)')
    && !str_contains($groupsHandler, '$tenantId = (string)(app()->tenant()->current()'),
    'Group handlers must not pass string tenant IDs directly to dbForTenant');
test('Groups index renders controlled database error state',
    str_contains($groupsHandler, "Attendance groups are unavailable")
    && str_contains($groupsHandler, "awGroupLogFailure('index'"),
    'Groups index should not expose raw tenant DB failures as HTTP 500');

// ────────────────────────────────────────────────────────────────────
echo "\n2. Template coverage for page routes\n";

$tplBase = $basePath . '/templates/modules/attendance-wage';
$pageRouteTemplateMap = [
    '/attendance-wage'              => 'auth/login.disyl',
    '/attendance-wage/login'        => 'auth/login.disyl',
    '/attendance-wage/kiosk'        => 'attendance/clock.disyl',
    '/attendance-wage/forgot-password' => 'auth/forgot-password.disyl',
    '/attendance-wage/reset-password'  => 'auth/reset-password.disyl',
    '/admin/attendance'             => 'attendance/clock.disyl',
    '/admin/attendance/history'     => 'attendance/history.disyl',
    '/admin/attendance/report'      => 'attendance/report.disyl',
    '/admin/wage'                   => 'wage/dashboard.disyl',
    '/admin/wage/employees'         => 'wage/employees/index.disyl',
    '/admin/wage/employees/create'  => 'wage/employees/form.disyl',
    '/admin/wage/employees/{id}'    => 'wage/employees/form.disyl',
    '/admin/wage/employees/{id}/view' => 'wage/employees/view.disyl',
    '/admin/wage/periods'           => 'wage/periods/index.disyl',
    '/admin/wage/periods/create'    => 'wage/periods/form.disyl',
    '/admin/wage/periods/{id}'      => 'wage/periods/form.disyl',
    '/admin/wage/computations'      => 'wage/computations/index.disyl',
    '/admin/wage/adjustments'       => 'wage/adjustments/index.disyl',
    '/admin/wage/adjustments/create'=> 'wage/adjustments/form.disyl',
    '/admin/wage/adjustments/{id}'   => 'wage/adjustments/form.disyl',
    '/admin/wage/deductions'        => 'wage/deductions/index.disyl',
    '/admin/wage/deductions/create' => 'wage/deductions/form.disyl',
    '/admin/wage/cash-advances'     => 'wage/cash-advances/index.disyl',
    '/admin/wage/cash-advances/create' => 'wage/cash-advances/form.disyl',
    '/admin/wage/holidays'          => 'wage/holidays/index.disyl',
    '/admin/wage/schedules'         => 'wage/schedules/index.disyl',
    '/admin/wage/reports'           => 'wage/reports/index.disyl',
    '/admin/wage/reports/summary'   => 'wage/reports/summary.disyl',
    '/admin/wage/reports/{periodId}' => 'wage/reports/detail.disyl',
    '/admin/wage/locations'         => 'wage/locations/index.disyl',
    '/admin/wage/locations/create'  => 'wage/locations/form.disyl',
    '/admin/wage/locations/{id}'    => 'wage/locations/form.disyl',
    '/admin/wage/benefits-calculator' => 'wage/benefits-calculator.disyl',
    '/admin/wage/migration'         => 'wage/migration-wizard.disyl',
    '/admin/wage/settings'          => 'wage/settings.disyl',
    '/admin/wage/groups'            => 'wage/groups/index.disyl',
    '/admin/wage/groups/create'     => 'wage/groups/form.disyl',
    '/admin/wage/groups/{id}/edit'  => 'wage/groups/form.disyl',
    '/admin/wage/groups/{id}'       => 'wage/groups/view.disyl',
    '/admin/wage/profile'           => 'wage/profile.disyl',
    '/admin/wage/payslip/{computationId}' => 'wage/payslip.disyl',
];

$missingTpl = 0;
foreach ($pageRouteTemplateMap as $route => $tplRel) {
    if (!file_exists($tplBase . '/' . $tplRel)) {
        $missingTpl++;
        test("Template: {$route}", false, "Missing {$tplRel}");
    }
}
if ($missingTpl === 0) {
    test('All ' . count($pageRouteTemplateMap) . ' page routes have templates', true);
}

// ────────────────────────────────────────────────────────────────────
echo "\n3. PAL team-lead attendance chain\n";

$palRoutes = include $basePath . '/modules/project-audit-ledger/routes.php';
$tlRef = $palRoutes['GET']['/admin/project-audit-ledger/team-lead/attendance'] ?? null;
test('PAL team-lead attendance route', $tlRef !== null);
test('PAL handler function exists', function_exists('palPageTeamLeadAttendance'));
test('PAL guard function exists', function_exists('palTeamLeadGuard'));
test('PAL template exists',
    file_exists($basePath . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-attendance.disyl'));

// ────────────────────────────────────────────────────────────────────
echo "\n4. Workbench contract compliance\n";

// PAL workbench-contract
$palWb = json_decode(file_get_contents($basePath . '/modules/project-audit-ledger/workbench-contract.json'), true);
$palGet = $palWb['ownership']['routes']['GET'] ?? [];
test('PAL WB: team-lead/attendance listed',
    in_array('/admin/project-audit-ledger/team-lead/attendance', $palGet));

// PAL module.json workbench.page_routes
$palManifest = json_decode(file_get_contents($basePath . '/modules/project-audit-ledger/module.json'), true);
$palPageRoutes = $palManifest['workbench']['page_routes'] ?? [];
test('PAL module.json: team-lead-attendance in page_routes',
    isset($palPageRoutes['team-lead-attendance']));

// AW workbench-contract
test('AW workbench-contract.json exists',
    file_exists($basePath . '/modules/attendance-wage/workbench-contract.json'));

$awWb = json_decode(file_get_contents($basePath . '/modules/attendance-wage/workbench-contract.json'), true);
$awGet = $awWb['ownership']['routes']['GET'] ?? [];

// Core pages must be in the contract
test('AW WB: /admin/attendance listed', in_array('/admin/attendance', $awGet));
test('AW WB: /admin/wage listed', in_array('/admin/wage', $awGet));
test('AW WB: /admin/wage/groups listed', in_array('/admin/wage/groups', $awGet));

// Computation APIs
$awPost = $awWb['ownership']['routes']['POST'] ?? [];
test('AW WB: POST /api/v1/wage/compute listed', in_array('/api/v1/wage/compute', $awPost));
test('AW WB: POST /api/v1/wage/compute/bulk listed', in_array('/api/v1/wage/compute/bulk', $awPost));

// shared_with contract
test('AW WB: shared_with declares PAL bridge',
    (function () use ($awWb) {
        foreach (($awWb['ownership']['shared_with'] ?? []) as $s) {
            if (($s['module'] ?? '') === 'project-audit-ledger') return true;
        }
        return false;
    })());

// relevance_rules — must reference only registered routes
test('AW WB: relevance_rules exist', !empty($awWb['relevance_rules'] ?? null));
test('AW WB: relevance_rules covers attendance', isset($awWb['relevance_rules']['attendance']));
test('AW WB: relevance_rules covers wage', isset($awWb['relevance_rules']['wage']));

// Verify every relevance_rules route reference resolves to a registered route
$allRegisteredRoutes = array_merge(
    array_keys($awRoutes['GET'] ?? []),
    array_keys($awRoutes['POST'] ?? [])
);
$relevanceRules = $awWb['relevance_rules'] ?? [];
$unresolvedRelevance = 0;
foreach ($relevanceRules as $family => $ruleSet) {
    foreach (['pages', 'api'] as $key) {
        foreach (($ruleSet[$key] ?? []) as $refRoute) {
            $clean = strtok($refRoute, '?');
            if (!in_array($clean, $allRegisteredRoutes, true)) {
                $unresolvedRelevance++;
                test("relevance_rules.{$family}.{$key}: {$refRoute}", false, 'Not a registered route');
            }
        }
    }
}
if ($unresolvedRelevance === 0) {
    test('All relevance_rules routes resolve to registered routes', true);
}

// ────────────────────────────────────────────────────────────────────
echo "\n5. PAL route handler completeness\n";

$palMissing = 0;
foreach (['GET', 'POST'] as $method) {
    foreach (($palRoutes[$method] ?? []) as $path => $ref) {
        $parts = explode(':', $ref);
        $fn = $parts[1] ?? '';
        if (!function_exists($fn)) {
            $palMissing++;
            test("PAL handler: {$ref}", false, "{$method} {$path}");
        }
    }
}
if ($palMissing === 0) {
    test('All PAL route handlers exist', true);
}

// ────────────────────────────────────────────────────────────────────
echo "\n6. Module.json cross-module contract\n";

// Attendance-wage does NOT declare dependency on PAL
$awManifest = json_decode(file_get_contents($basePath . '/modules/attendance-wage/module.json'), true);
$awDepends = $awManifest['capabilities']['depends'] ?? [];
test('AW does not depend on PAL capabilities',
    (function () use ($awDepends) {
        foreach ($awDepends as $dep) {
            if (str_contains($dep['id'] ?? '', 'pal')) return false;
        }
        return true;
    })(),
    'Attendance & Wage must be independent of PAL');

// PAL reads_tables contract
$palReads = $palManifest['reads_tables'] ?? [];
foreach (['attendance_groups','attendance_group_members','attendance_wage_users','employee_profiles','attendance_records'] as $t) {
    test("PAL reads_tables: {$t}", in_array($t, $palReads));
}

$palOwns = $palManifest['owns_tables'] ?? [];
test('PAL does NOT own attendance_groups', !in_array('attendance_groups', $palOwns));
test('PAL does NOT own attendance_records', !in_array('attendance_records', $palOwns));

// ────────────────────────────────────────────────────────────────────
echo "\n7. AW nav → route resolution\n";

$awNav = $awManifest['nav'] ?? [];
$navUrls = [];
$walkNav = function (array $items) use (&$walkNav, &$navUrls) {
    foreach ($items as $item) {
        $url = $item['url'] ?? '';
        if ($url !== '' && !str_starts_with($url, '#')) {
            $navUrls[] = strtok($url, '?');
        }
        if (!empty($item['children'])) {
            $walkNav($item['children']);
        }
    }
};

if (!empty($awNav)) {
    $walkNav($awNav);
    test('Nav entries found', count($navUrls) > 0);

    $routePaths = array_keys(($awRoutes['GET'] ?? []) + ($awRoutes['POST'] ?? []));
    $unresolved = 0;
    foreach ($navUrls as $navUrl) {
        $matched = false;
        foreach ($routePaths as $rp) {
            $pattern = '#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $rp) . '$#';
            if (preg_match($pattern, $navUrl)) { $matched = true; break; }
        }
        if (!$matched) {
            $unresolved++;
            test("Nav URL: {$navUrl}", false, 'No matching route');
        }
    }
    if ($unresolved === 0) {
        test('All nav URLs resolve to routes', true);
    }
} else {
    test('Nav entries defined', false, 'No nav in module.json — add navigation');
}

// ────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$passed}/{$total} passed";
if (!empty($errors)) { echo " — " . count($errors) . " failures"; }
echo "\n";
if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $e) { echo "  \xE2\x9D\x8C {$e}\n"; }
    exit(1);
}
echo "\xE2\x9C\x85 All tests passed.\n";
exit(0);
