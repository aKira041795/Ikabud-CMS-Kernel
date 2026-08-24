<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AttendanceGroupService.php';

function awGroupContext(array $user): array
{
    $tenantId = (int)(app()->tenant()->current() ?? ($user['tenant_id'] ?? 0));
    if ($tenantId <= 0) {
        throw new RuntimeException('Tenant context is required for attendance groups.');
    }

    $db = app()->dbForTenant($tenantId);
    if (!$db instanceof PDO) {
        throw new RuntimeException('Tenant database is unavailable for attendance groups.');
    }

    return [$tenantId, (string)$tenantId, $db];
}

function awGroupService(array $user, PDO $db, string $tenantId): AttendanceGroupService
{
    return new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
}

function awGroupLogFailure(string $handler, Throwable $e): void
{
    if (function_exists('write_log')) {
        write_log("attendance_wage.groups.{$handler}: " . $e->getMessage(), 'error');
    }
}

/**
 * Push a team lead to PAL (project-audit-ledger) when an AW attendance group
 * is created/updated with a pal_team_lead_email.
 *
 * Resolves the PAL tenant via the control plane (same mechanism the AW→PAL
 * mobilization link uses: the tenant whose entry module is
 * project-audit-ledger) and upserts pal_team_leads (unique on tenant_id+email).
 * Name is taken from the AW employee profile of the group leader.
 *
 * Failures are logged and never break the AW group save.
 */
function awSyncTeamLeadToPal(PDO $db, string $awTenantId, int $leaderProfileId, ?string $palEmail): void
{
    if ($palEmail === null || trim($palEmail) === '') {
        return;
    }
    $palEmail = trim($palEmail);

    try {
        $cp = app()->controlDb();
        $palTid = (int)$cp->query(
            "SELECT id FROM kernel_tenants WHERE entry_module_id = 'project-audit-ledger' AND status = 'active' ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($palTid <= 0) {
            return;
        }
        $palDb = app()->dbForTenant($palTid);
        if (!$palDb) {
            return;
        }

        // Leader display name from AW employee_profiles (attendance-wage context).
        $name = $palEmail;
        if ($leaderProfileId > 0) {
            $nStmt = $db->prepare(
                "SELECT CONCAT_WS(' ', NULLIF(first_name,''), NULLIF(last_name,'')) FROM employee_profiles WHERE profile_id = :pid AND tenant_id = :tid LIMIT 1"
            );
            $nStmt->execute([':pid' => $leaderProfileId, ':tid' => $awTenantId]);
            $n = $nStmt->fetchColumn();
            if ($n && $n !== '') {
                $name = $n;
            }
        }

        // PAL-side upsert runs inside the project-audit-ledger module context so
        // the kernel DB guard enforces PAL's declared tables (pal_team_leads).
        if (!function_exists('moduleWithContext')) {
            return;
        }
        moduleWithContext('project-audit-ledger', function () use ($palDb, $palTid, $name, $palEmail): void {
            $palDb->prepare("
                INSERT INTO pal_team_leads (tenant_id, name, email, is_active, created_at)
                VALUES (:t, :name, :email, 1, NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1
            ")->execute([':t' => $palTid, ':name' => $name, ':email' => $palEmail]);
        });

        if (function_exists('write_log')) {
            write_log("attendance_wage.groups: pushed team lead {$palEmail} to PAL tenant {$palTid}", 'info');
        }
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('attendance_wage.groups: PAL team lead push failed: ' . $e->getMessage(), 'warning');
        }
    }
}

// ── Page Handlers ──

function awPageAttendanceGroups(): void
{
    $user = attendanceWageGuard();
    $error = null;
    $groups = [];

    try {
        [, $tenantId, $db] = awGroupContext($user);
        $svc = awGroupService($user, $db, $tenantId);
        $showAll = ($_GET['show'] ?? '') === 'all';
        $groups = $svc->list($showAll);
    } catch (Throwable $e) {
        awGroupLogFailure('index', $e);
        $error = 'Attendance groups are unavailable. Verify the tenant database and Attendance & Wage migrations.';
    }

    echo app()->render('modules/attendance-wage/wage/groups/index', [
        'page_title' => 'Attendance Groups',
        'active_nav' => 'groups',
        'groups' => $groups,
        'error' => $error,
        'show_all' => $showAll,
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function awPageAttendanceGroupForm(array $rp = []): void
{
    $user = attendanceWageGuard();
    [, $tenantId, $db] = awGroupContext($user);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $group = [
        'group_id' => 0,
        'name' => '',
        'leader_profile_id' => 0,
        'pal_team_lead_email' => '',
        'description' => '',
        'members' => [],
    ];

    if ($groupId > 0) {
        $svc = awGroupService($user, $db, $tenantId);
        $storedGroup = $svc->get($groupId);
        if (is_array($storedGroup)) {
            $group = $storedGroup + $group;
        }
    }

    $employees = $db->prepare("
        SELECT ep.profile_id, CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS name,
               ep.position, ep.department, ep.is_active
        FROM employee_profiles ep
        WHERE ep.tenant_id = :tid AND ep.is_active = 1
        ORDER BY ep.last_name, ep.first_name
    ");
    $employees->execute([':tid' => $tenantId]);

    echo app()->render('modules/attendance-wage/wage/groups/form', [
        'page_title' => $groupId > 0 ? 'Edit Group' : 'Create Group',
        'active_nav' => 'groups',
        'group' => $group,
        'is_edit' => $groupId > 0,
        'employees' => $employees->fetchAll(PDO::FETCH_ASSOC),
        'current_user_role' => $user['role'] ?? '',
    ]);
}

function awPageAttendanceGroupView(array $rp = []): void
{
    $user = attendanceWageGuard();
    [, $tenantId, $db] = awGroupContext($user);
    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);

    $svc = awGroupService($user, $db, $tenantId);
    $group = $svc->get($groupId);
    if (!$group) {
        http_response_code(404);
        echo 'Group not found';
        return;
    }

    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-t');
    $attendance = $svc->getGroupAttendance($groupId, $dateFrom, $dateTo);

    // Compute per-employee salary summary (simple: daily_rate × days_worked)
    $employeeSummary = [];
    foreach ($attendance as $row) {
        $pid = $row['profile_id'];
        if (!isset($employeeSummary[$pid])) {
            $employeeSummary[$pid] = [
                'name' => $row['employee_name'],
                'salary_type' => $row['salary_type'] ?? 'daily',
                'daily_rate' => (float)(($row['daily_rate'] ?? 0) ?: ($row['basic_salary'] ?? 0) ?: 0),
                'hourly_rate' => (float)(($row['hourly_rate'] ?? 0) ?: 0),
                'total_hours' => 0,
                'days' => [],
            ];
        }
        $employeeSummary[$pid]['total_hours'] += (float)($row['hours_worked'] ?? 0);
        $d = substr($row['clock_in'] ?? '', 0, 10);
        if ($d !== '') {
            $employeeSummary[$pid]['days'][$d] = true;
        }
    }

    // Compute simple salary per employee
    foreach ($employeeSummary as $pid => &$es) {
        $daysWorked = count($es['days']);
        $es['days_worked'] = $daysWorked;
        if ($es['salary_type'] === 'hourly') {
            $rate = $es['hourly_rate'] > 0 ? $es['hourly_rate'] : ($es['daily_rate'] / 8);
            $es['computed_salary'] = round($es['total_hours'] * $rate, 2);
        } elseif ($es['salary_type'] === 'fixed') {
            $workingDays = aw_workingDaysInPeriod($dateFrom, $dateTo);
            $dailyEquivalent = $workingDays > 0 ? $es['daily_rate'] / $workingDays : $es['daily_rate'];
            $es['computed_salary'] = round($dailyEquivalent * max(1, $daysWorked), 2);
        } else {
            $es['computed_salary'] = round($es['daily_rate'] * max(1, $daysWorked), 2);
        }
    }
    unset($es);

    echo app()->render('modules/attendance-wage/wage/groups/view', [
        'page_title' => 'Group: ' . $group['name'],
        'active_nav' => 'groups',
        'group' => $group,
        'attendance' => $attendance,
        'employee_summary' => $employeeSummary,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'current_user_role' => $user['role'] ?? '',
    ]);
}

// ── API Handlers ──

function awApiGroupStore(): void
{
    $user = attendanceWageGuard();
    [, $tenantId, $db] = awGroupContext($user);

    $data = $_POST;
    if (!empty($data['member_profile_ids']) && is_string($data['member_profile_ids'])) {
        $data['member_profile_ids'] = json_decode($data['member_profile_ids'], true) ?? [];
    }

    $svc = awGroupService($user, $db, $tenantId);
    $id = $svc->create($data);

    // Push the team lead to PAL so PAL JOs can assign it immediately.
    awSyncTeamLeadToPal(
        $db,
        $tenantId,
        (int)($data['leader_profile_id'] ?? 0),
        $data['pal_team_lead_email'] ?? null
    );

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'group_id' => $id]);
}

function awApiGroupUpdate(array $rp = []): void
{
    $user = attendanceWageGuard();
    [, $tenantId, $db] = awGroupContext($user);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
    if ($groupId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid group ID']); return; }

    $data = $_POST;
    if (!empty($data['member_profile_ids']) && is_string($data['member_profile_ids'])) {
        $data['member_profile_ids'] = json_decode($data['member_profile_ids'], true) ?? [];
    }

    $svc = awGroupService($user, $db, $tenantId);
    $svc->update($groupId, $data);

    // Push the (possibly updated) team lead to PAL.
    $leaderId = (int)($data['leader_profile_id'] ?? 0);
    $palEmail = $data['pal_team_lead_email'] ?? null;
    if ($leaderId <= 0 || $palEmail === null) {
        // Pull current values so an update that only changes name/description
        // still keeps the PAL side in sync with the stored team lead email.
        $cur = $db->prepare('SELECT leader_profile_id, pal_team_lead_email FROM attendance_groups WHERE group_id = :gid AND tenant_id = :tid LIMIT 1');
        $cur->execute([':gid' => $groupId, ':tid' => $tenantId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $leaderId = (int)($row['leader_profile_id'] ?? 0);
            $palEmail = $row['pal_team_lead_email'] ?? null;
        }
    }
    awSyncTeamLeadToPal($db, $tenantId, $leaderId, $palEmail);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

function awApiGroupToggle(array $rp = []): void
{
    $user = attendanceWageGuard();
    [, $tenantId, $db] = awGroupContext($user);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $svc = awGroupService($user, $db, $tenantId);
    $svc->toggleActive($groupId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}
