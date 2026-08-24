<?php

declare(strict_types=1);

/**
 * Direct fixture for data with no Attendance & Wage product creation surface.
 * Business entities are deliberately not seeded here.
 */

$tenant = getenv('AW_E2E_TENANT_ID') ?: '';
$allow = getenv('AW_E2E_ALLOW_RESET') ?: '';
$leastPassword = getenv('AW_E2E_LEAST_PASSWORD') ?: '';

if ($tenant !== '441' || $allow !== '1') {
    fwrite(STDERR, "Refusing fixture: tenant 441 and AW_E2E_ALLOW_RESET=1 are required.\n");
    exit(2);
}
if (strlen($leastPassword) < 16) {
    fwrite(STDERR, "Refusing fixture: generated least-privilege password is missing.\n");
    exit(2);
}

$_SERVER['HTTP_HOST'] = parse_url(getenv('APP_URL') ?: 'http://zapattendance.test', PHP_URL_HOST) ?: 'zapattendance.test';
$_SERVER['REQUEST_METHOD'] = 'CLI';
$basePath = dirname(__DIR__, 5);
require_once $basePath . '/bootstrap.php';
require_once $basePath . '/modules/attendance-wage/helpers.php';

$resolvedTenant = aw_tenant_id();
if ($resolvedTenant !== 441) {
    fwrite(STDERR, "Refusing fixture: host resolved to tenant {$resolvedTenant}, expected 441.\n");
    exit(2);
}

$db = aw_db();
$db->beginTransaction();
try {
    $passwordHash = password_hash($leastPassword, PASSWORD_BCRYPT);
    $users = [
        ['AW-E2E-supervisor', 'aw-e2e-supervisor@example.test', 'AW-E2E Supervisor', 'supervisor'],
        ['AW-E2E-employee-role', 'aw-e2e-employee-role@example.test', 'AW-E2E Employee Role', 'employee'],
    ];
    $userSql = "INSERT INTO attendance_wage_users (username,email,password_hash,full_name,role,is_active)
                VALUES (:username,:email,:password_hash,:full_name,:role,1)
                ON DUPLICATE KEY UPDATE email=VALUES(email),password_hash=VALUES(password_hash),full_name=VALUES(full_name),role=VALUES(role),is_active=1";
    $userStmt = $db->prepare($userSql);
    foreach ($users as [$username, $email, $fullName, $role]) {
        $userStmt->execute([
            'username' => $username, 'email' => $email, 'password_hash' => $passwordHash,
            'full_name' => $fullName, 'role' => $role,
        ]);
    }

    $db->prepare("DELETE FROM benefits_contribution_rates WHERE tenant_id=:tid AND description LIKE 'AW-E2E-%'")
        ->execute(['tid' => '441']);
    $rateSql = "INSERT INTO benefits_contribution_rates
        (tenant_id,benefit_type,effective_date,salary_from,salary_to,employee_share_pct,employer_share_pct,employee_fixed,employer_fixed,min_contribution,max_contribution,description,is_active)
        VALUES ('441',:type,'2020-01-01',:salary_from,:salary_to,:employee_pct,:employer_pct,:employee_fixed,:employer_fixed,:minimum,:maximum,:description,1)";
    $rateStmt = $db->prepare($rateSql);
    $rates = [
        ['sss', 0, 19999.99, 0.045, 0.095, null, null, 0, 2000, 'AW-E2E-SSS-LOW'],
        ['sss', 20000, null, 0.05, 0.10, null, null, 0, 2500, 'AW-E2E-SSS-HIGH'],
        ['philhealth', 0, null, 0.025, 0.025, null, null, 250, 2500, 'AW-E2E-PHILHEALTH'],
        ['pagibig', 0, 1499.99, 0, 0, 50, 50, null, null, 'AW-E2E-PAGIBIG-LOW'],
        ['pagibig', 1500, null, 0, 0, 100, 100, null, null, 'AW-E2E-PAGIBIG-HIGH'],
    ];
    foreach ($rates as [$type, $from, $to, $employeePct, $employerPct, $employeeFixed, $employerFixed, $minimum, $maximum, $description]) {
        $rateStmt->execute([
            'type' => $type, 'salary_from' => $from, 'salary_to' => $to,
            'employee_pct' => $employeePct, 'employer_pct' => $employerPct,
            'employee_fixed' => $employeeFixed, 'employer_fixed' => $employerFixed,
            'minimum' => $minimum, 'maximum' => $maximum, 'description' => $description,
        ]);
    }

    $verify = $db->prepare("SELECT benefit_type,salary_from,salary_to,employee_fixed,employer_fixed,description FROM benefits_contribution_rates WHERE tenant_id=:tid AND description LIKE 'AW-E2E-%' ORDER BY rate_id");
    $verify->execute(['tid' => '441']);
    $seededBands = $verify->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($seededBands) !== count($rates)) throw new RuntimeException('Contribution-band cardinality mismatch.');
    $match = $db->prepare("SELECT benefit_type,employee_fixed,employer_fixed FROM benefits_contribution_rates WHERE tenant_id=:tid AND benefit_type=:type AND is_active=1 AND effective_date<=CURDATE() AND salary_from<=:salary AND (salary_to IS NULL OR salary_to>=:salary2) ORDER BY effective_date DESC LIMIT 1");
    $match->execute(['tid' => '441', 'type' => 'pagibig', 'salary' => 1000, 'salary2' => 1000]);
    $pagibigLow = $match->fetch(PDO::FETCH_ASSOC) ?: null;
    if (($pagibigLow['employee_fixed'] ?? null) !== '50.00') throw new RuntimeException('Pag-IBIG low-band lookup mismatch.');
    $db->commit();
    echo json_encode(['ok' => true, 'tenant_id' => 441, 'users' => count($users), 'contribution_bands' => count($seededBands), 'bands' => $seededBands], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Fixture failed: {$e->getMessage()}\n");
    exit(1);
}
