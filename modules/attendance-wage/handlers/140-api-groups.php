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
    $group = null;

    if ($groupId > 0) {
        $svc = awGroupService($user, $db, $tenantId);
        $group = $svc->get($groupId);
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
        'page_title' => $group ? 'Edit Group' : 'Create Group',
        'active_nav' => 'groups',
        'group' => $group,
        'is_edit' => $group !== null,
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

    echo app()->render('modules/attendance-wage/wage/groups/view', [
        'page_title' => 'Group: ' . $group['name'],
        'active_nav' => 'groups',
        'group' => $group,
        'attendance' => $attendance,
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
