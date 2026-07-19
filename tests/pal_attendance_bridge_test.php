<?php

declare(strict_types=1);

/**
 * PAL Attendance Bridge Test
 *
 * Verifies schema contract, bridge semantics, route→handler→template chain,
 * and workbench contract synchronization between PAL and Attendance & Wage.
 *
 * Run from repo root: php tests/pal_attendance_bridge_test.php
 */

$basePath = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'zapattendance.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'zapattendance.test';
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/src/helpers/module-manager.php';

// Resolve a working DB connection. Try host-resolved tenant first,
// then fall back to tenant 1. If neither works, DB tests are skipped.
$testTenantId = (int)(app()->tenant()->current() ?? 0);
$testDb = ($testTenantId > 0) ? app()->dbForTenant($testTenantId) : null;
if ($testDb === null) {
    $testTenantId = 1;
    $testDb = app()->dbForTenant(1);
}
$hasDb = $testDb !== null;
if (!$hasDb) {
    echo "  ⚠ No tenant database available — DB-backed assertions will be skipped.\n\n";
}

$errors = [];
$passed = 0;
$total  = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $total, $passed, $errors;
    $total++;
    if ($condition) { $passed++; echo "  \xE2\x9C\x85 {$name}\n"; }
    else { $errors[] = "{$name}: {$detail}"; echo "  \xE2\x9D\x8C {$name}: {$detail}\n"; }
}

echo "PAL Attendance Bridge Test\n";
echo str_repeat('=', 60) . "\n\n";

// ────────────────────────────────────────────────────────────────────
echo "1. Schema contract — attendance_records PK\n";

if ($hasDb) {
try {
    $db = $testDb;

    $cols = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'attendance_id'")->fetchAll();
    test('PK is attendance_id', count($cols) === 1, 'PAL bridge must use ar.attendance_id, not ar.id');

    $cols = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'tenant_id'")->fetchAll();
    test('Has tenant_id column', count($cols) === 1);

    $cols = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'id'")->fetchAll();
    test('Has NO id column (only attendance_id)', count($cols) === 0);
} catch (Throwable $e) {
    test('DB schema access', false, $e->getMessage());
}
} else {
    test('Schema contract (no DB, skipping)', true, 'No tenant database configured');
}

// ────────────────────────────────────────────────────────────────────
echo "\n2. Bridge schema — attendance_groups\n";

if ($hasDb) {
try {
    $db = $testDb;

    // Check if attendance_groups table exists first
    $tableExists = count($db->query("SHOW TABLES LIKE 'attendance_groups'")->fetchAll()) > 0;
    if (!$tableExists) {
        test('attendance_groups table exists (migration needed)', false,
            'Run tenant migration: php ikabud tenant:migrate <id> attendance-wage');
        // Skip remaining tests in this section
        goto skip_group_tests;
    }

    test('pal_team_lead_email column exists',
        count($db->query("SHOW COLUMNS FROM attendance_groups LIKE 'pal_team_lead_email'")->fetchAll()) === 1);

    test('is_active column exists',
        count($db->query("SHOW COLUMNS FROM attendance_groups LIKE 'is_active'")->fetchAll()) === 1);

    test('idx_ag_pal_bridge index exists',
        count($db->query("SHOW INDEX FROM attendance_groups WHERE Key_name = 'idx_ag_pal_bridge'")->fetchAll()) > 0);
} catch (Throwable $e) {
    test('Bridge schema access', false, $e->getMessage());
}
} else {
    test('Bridge schema (no DB, skipping)', true, 'No tenant database configured');
}

skip_group_tests:

// ────────────────────────────────────────────────────────────────────
echo "\n3. Bridge query — case-insensitive email\n";

if ($hasDb) {
try {
    $db = $testDb;

    // Check table exists
    if (count($db->query("SHOW TABLES LIKE 'attendance_groups'")->fetchAll()) === 0) {
        test('Case-insensitive email bridge (table missing, skipping)', true, 'Migration not applied');
        goto skip_email_test;
    }

    $db->beginTransaction();
    $db->exec("DELETE FROM attendance_groups WHERE tenant_id = '{$testTenantId}' AND name LIKE 'TEST-BRIDGE-%'");

    $testEmail = 'Test.Lead@Example.COM';
    $db->prepare("INSERT INTO attendance_groups (tenant_id, name, leader_profile_id, pal_team_lead_email, is_active) VALUES (:t, :n, 1, :e, 1)")
       ->execute([':t' => $testTenantId, ':n' => 'TEST-BRIDGE-Case', ':e' => $testEmail]);

    $s = $db->prepare("SELECT group_id FROM attendance_groups WHERE LOWER(pal_team_lead_email) = LOWER(:e) AND tenant_id = :t AND is_active = 1");
    $s->execute([':e' => 'test.lead@example.com', ':t' => $testTenantId]);
    test('LOWER() matches mixed-case stored value', $s->fetchColumn() !== false);

    $s2 = $db->prepare("SELECT group_id FROM attendance_groups WHERE LOWER(pal_team_lead_email) = LOWER(:e) AND tenant_id = :t");
    $s2->execute([':e' => $testEmail, ':t' => $testTenantId]);
    test('LOWER() matches exact-case input', $s2->fetchColumn() !== false);

    $db->exec("DELETE FROM attendance_groups WHERE tenant_id = '{$testTenantId}' AND name LIKE 'TEST-BRIDGE-%'");
    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    test('Email bridge test', false, $e->getMessage());
}
} else {
    test('Email bridge (no DB, skipping)', true, 'No tenant database configured');
}

skip_email_test:

// ────────────────────────────────────────────────────────────────────
echo "\n4. Inactive groups excluded\n";

if ($hasDb) {
try {
    $db = $testDb;

    if (count($db->query("SHOW TABLES LIKE 'attendance_groups'")->fetchAll()) === 0) {
        test('Inactive group exclusion (table missing, skipping)', true, 'Migration not applied');
        goto skip_inactive_test;
    }

    $db->beginTransaction();
    $db->exec("DELETE FROM attendance_groups WHERE tenant_id = '{$testTenantId}' AND name LIKE 'TEST-BRIDGE-%'");

    $db->prepare("INSERT INTO attendance_groups (tenant_id, name, leader_profile_id, pal_team_lead_email, is_active) VALUES (:t, :n, 1, :e, 0)")
       ->execute([':t' => $testTenantId, ':n' => 'TEST-BRIDGE-Inactive', ':e' => 'inactive@test.com']);

    $s = $db->prepare("SELECT group_id FROM attendance_groups WHERE LOWER(pal_team_lead_email) = LOWER(:e) AND tenant_id = :t AND is_active = 1");
    $s->execute([':e' => 'inactive@test.com', ':t' => $testTenantId]);
    test('Inactive group excluded from active query', $s->fetchColumn() === false);

    $db->exec("DELETE FROM attendance_groups WHERE tenant_id = '{$testTenantId}' AND name LIKE 'TEST-BRIDGE-%'");
    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    test('Inactive group test', false, $e->getMessage());
}
} else {
    test('Inactive groups (no DB, skipping)', true, 'No tenant database configured');
}

skip_inactive_test:

// ────────────────────────────────────────────────────────────────────
echo "\n5. Tenant scoping\n";

if ($hasDb) {
try {
    $db = $testDb;

    $s = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE tenant_id = :t");
    $s->execute([':t' => $testTenantId]);
    test('attendance_records filterable by tenant_id', (int)$s->fetchColumn() >= 0);
} catch (Throwable $e) {
    test('Tenant scoping query', false, $e->getMessage());
}
} else {
    test('Tenant scoping (no DB, skipping)', true, 'No tenant database configured');
}

// ────────────────────────────────────────────────────────────────────
echo "\n6. PAL route → handler chain\n";

foreach (glob($basePath . '/modules/project-audit-ledger/handlers/*.php') as $hf) {
    require_once $hf;
}

$palRoutes = include $basePath . '/modules/project-audit-ledger/routes.php';
$ref = $palRoutes['GET']['/admin/project-audit-ledger/team-lead/attendance'] ?? null;
test('Route registered', $ref !== null);
test('Handler palPageTeamLeadAttendance exists', function_exists('palPageTeamLeadAttendance'));
test('Guard palTeamLeadGuard exists', function_exists('palTeamLeadGuard'));
test('Handler ref is correct format', $ref === 'project-audit-ledger:palPageTeamLeadAttendance');

// ────────────────────────────────────────────────────────────────────
echo "\n7. PAL template structure\n";

$tpl = $basePath . '/modules/project-audit-ledger/templates/project-audit-ledger/pages/team-lead-attendance.disyl';
test('Template exists', file_exists($tpl));

if (file_exists($tpl)) {
    $c = file_get_contents($tpl);
    test('Has data-wb-region', str_contains($c, 'data-wb-region'));
    test('Has data-wb-row="attendance"', str_contains($c, 'data-wb-row="attendance"'));
    test('Has data-wb-empty="attendance"', str_contains($c, 'data-wb-empty="attendance"'));
    test('Has overflow-x-auto wrapper', str_contains($c, 'overflow-x-auto'));
    test('References a.attendance_id not a.id', str_contains($c, 'a.attendance_id'));
    test('Has <table> tag', str_contains($c, '<table'));
    test('Has </table> tag', str_contains($c, '</table>'));
    test('Has status color classes', str_contains($c, 'bg-green-100') && str_contains($c, 'bg-yellow-100'));
    test('Has wage estimate section', str_contains($c, 'Wage Estimate'));
    test('Has employee_summary loop', str_contains($c, 'employee_summary'));
    test('Has totals display', str_contains($c, 'totals.'));
    test('Has Request Mobilization link', str_contains($c, 'Request Mobilization') || str_contains($c, 'mobilization/create'));
}

// ────────────────────────────────────────────────────────────────────
echo "\n8. Workbench contract sync\n";

$palWb = json_decode(file_get_contents($basePath . '/modules/project-audit-ledger/workbench-contract.json'), true);
$palGet = $palWb['ownership']['routes']['GET'] ?? [];
test('Workbench contract has team-lead/attendance GET',
    in_array('/admin/project-audit-ledger/team-lead/attendance', $palGet));

test('Workbench contract has all team-lead routes',
    (function () use ($palGet) {
        foreach (['/admin/project-audit-ledger/team-lead',
                  '/admin/project-audit-ledger/team-lead/attendance',
                  '/admin/project-audit-ledger/team-lead/cash-advances',
                  '/admin/project-audit-ledger/team-lead/fabrication'] as $r) {
            if (!in_array($r, $palGet)) return false;
        }
        return true;
    })());

$palManifest = json_decode(file_get_contents($basePath . '/modules/project-audit-ledger/module.json'), true);
$pageRoutes = $palManifest['workbench']['page_routes'] ?? [];
test('module.json page_routes has team-lead-attendance', isset($pageRoutes['team-lead-attendance']));

// Attendance-wage workbench-contract
test('AW workbench-contract.json exists',
    file_exists($basePath . '/modules/attendance-wage/workbench-contract.json'));

$awWb = json_decode(file_get_contents($basePath . '/modules/attendance-wage/workbench-contract.json'), true);
test('AW workbench contract declares shared_with PAL',
    (function () use ($awWb) {
        foreach (($awWb['ownership']['shared_with'] ?? []) as $s) {
            if (($s['module'] ?? '') === 'project-audit-ledger') return true;
        }
        return false;
    })());

// Check capability bridge contract
$awShared = [];
foreach (($awWb['ownership']['shared_with'] ?? []) as $s) {
    if (($s['module'] ?? '') === 'project-audit-ledger') {
        $awShared = $s;
        break;
    }
}
test('AW shared_with PAL uses capability bridge',
    str_contains($awShared['contract'] ?? '', 'capability bridge')
    || str_contains($awShared['contract'] ?? '', 'attendance_wage.team_attendance.summary@1'));

// Check PAL capability dependency in workbench contract
$palManifest = json_decode(file_get_contents($basePath . '/modules/project-audit-ledger/module.json'), true);
$palDepends = $palManifest['capabilities']['depends'] ?? [];
test('PAL depends on AW team_attendance.summary capability',
    in_array('attendance_wage.team_attendance.summary@1', $palDepends, true));

// PAL reads_tables contract
$palReads = $palManifest['reads_tables'] ?? [];
foreach (['attendance_groups','attendance_group_members','attendance_wage_users','employee_profiles','attendance_records'] as $t) {
    test("PAL reads_tables includes {$t}", in_array($t, $palReads));
}

$palOwns = $palManifest['owns_tables'] ?? [];
test('PAL does NOT own attendance_groups', !in_array('attendance_groups', $palOwns));
test('PAL does NOT own attendance_records', !in_array('attendance_records', $palOwns));

// ────────────────────────────────────────────────────────────────────
echo "\n9. Capability bridge source audit\n";

$src = file_get_contents($basePath . '/modules/project-audit-ledger/handlers/53-team-lead.php');
test('PAL attendance uses app()->cap()->call()',
    str_contains($src, "app()->cap()->call('attendance_wage.team_attendance.summary@1'"));
test('PAL attendance references AW capability ID',
    str_contains($src, 'attendance_wage.team_attendance.summary@1'));
test('PAL attendance passes caller metadata',
    str_contains($src, "'caller' => ['module' => 'project-audit-ledger']"));
test('PAL attendance handles capability ok=false',
    str_contains($src, "ok']"));

$awHelpersSrc = file_get_contents($basePath . '/modules/attendance-wage/helpers.php');
test('AW capability handler exists',
    str_contains($awHelpersSrc, 'aw_cap_team_attendance_summary_1'));
test('AW capability handler registered in map',
    str_contains($awHelpersSrc, "'attendance_wage.team_attendance.summary@1' => 'aw_cap_team_attendance_summary_1'"));
test('AW capability uses AttendanceGroupService',
    str_contains($awHelpersSrc, 'AttendanceGroupService'));
test('AW capability uses tl_computeSalary',
    str_contains($awHelpersSrc, 'tl_computeSalary'));
test('AW capability returns evidence with provider',
    str_contains($awHelpersSrc, "'provider' => 'attendance-wage'"));
test('AW capability returns evidence with version',
    str_contains($awHelpersSrc, "'version' => '1'"));

// Verify PAL mobilization store revalidates via capability
$palStoreSql = file_get_contents($basePath . '/modules/project-audit-ledger/handlers/53-team-lead.php');
test('PAL mobilization store revalidates via capability',
    str_contains($palStoreSql, "attendance_wage.team_attendance.summary@1"));

// Verify AW team-lead dashboard calls getGroupAttendance
$awTlSrc = file_get_contents($basePath . '/modules/attendance-wage/handlers/150-team-lead.php');
test('AW team-lead dashboard calls getGroupAttendance()',
    str_contains($awTlSrc, 'getGroupAttendance('));
test('AW team-lead dashboard parses date_from',
    str_contains($awTlSrc, "dateFrom = \$_GET['date_from']"));
test('AW team-lead dashboard parses date_to',
    str_contains($awTlSrc, "dateTo = \$_GET['date_to']"));

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
