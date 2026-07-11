<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AttendanceGroupService.php';

// ── Page Handlers ──

function awPageAttendanceGroups(): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);

    $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
    $groups = $svc->list();

    echo app()->render('modules/attendance-wage/wage/groups/index', [
        'page_title' => 'Attendance Groups',
        'active_nav' => 'groups',
        'groups' => $groups,
    ]);
}

function awPageAttendanceGroupForm(array $rp = []): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $group = null;

    if ($groupId > 0) {
        $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
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
    ]);
}

function awPageAttendanceGroupView(array $rp = []): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);
    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);

    $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
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
    ]);
}

// ── API Handlers ──

function awApiGroupStore(): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);

    $data = $_POST;
    if (!empty($data['member_profile_ids']) && is_string($data['member_profile_ids'])) {
        $data['member_profile_ids'] = json_decode($data['member_profile_ids'], true) ?? [];
    }

    $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
    $id = $svc->create($data);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'group_id' => $id]);
}

function awApiGroupUpdate(array $rp = []): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
    if ($groupId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Invalid group ID']); return; }

    $data = $_POST;
    if (!empty($data['member_profile_ids']) && is_string($data['member_profile_ids'])) {
        $data['member_profile_ids'] = json_decode($data['member_profile_ids'], true) ?? [];
    }

    $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
    $svc->update($groupId, $data);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}

function awApiGroupToggle(array $rp = []): void
{
    $user = attendanceWageGuard();
    $tenantId = (string)($user['tenant_id'] ?? $_SESSION['tenant_id'] ?? '');
    $db = app()->dbForTenant($tenantId);

    $groupId = (int)($rp['id'] ?? $_GET['id'] ?? 0);
    $svc = new AttendanceGroupService($db, $tenantId, (int)($user['id'] ?? 0));
    $svc->toggleActive($groupId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
}
