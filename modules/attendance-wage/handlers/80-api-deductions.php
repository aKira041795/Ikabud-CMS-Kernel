<?php

declare(strict_types=1);

/**
 * Employee deduction API handlers.
 */



function wageApiDeductionsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    try {
        $db = aw_db();
        $limit = min((int)($params['limit'] ?? 30), 100);
        $stmt = $db->query("SELECT d.deduction_id AS id, d.employee_name, d.amount, d.description, d.status, d.deduction_date, d.created_at, 'manual' AS source FROM employee_deductions d ORDER BY d.deduction_date DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        awJsonOut(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiDeductionCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    $userId       = (int)($input['user_id'] ?? 0);
    $employeeName = trim((string)($input['employee_name'] ?? ''));
    $amount       = (float)($input['amount'] ?? 0);
    $description  = trim((string)($input['description'] ?? ''));
    $deductionDate = trim((string)($input['deduction_date'] ?? date('Y-m-d')));

    if ($userId <= 0 || $amount <= 0) {
        $msg = 'Employee and a positive amount are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions/create?error=' . urlencode($msg)); exit; }
        awJsonOut(['ok' => false, 'error' => $msg], 422); return;
    }

    try {
        $db = aw_db();
        $employee = $db->prepare("SELECT CONCAT_WS(' ', first_name, middle_name, last_name, suffix) FROM employee_profiles WHERE user_id=:uid AND tenant_id=:tid AND is_active=1 LIMIT 1");
        $employee->execute([':uid' => $userId, ':tid' => (string)aw_tenant_id()]);
        $employeeName = trim((string)($employee->fetchColumn() ?: ''));
        if ($employeeName === '') awJsonOut(['ok' => false, 'error' => 'Active employee not found.'], 404);
        $db->prepare(
            "INSERT INTO employee_deductions (tenant_id, user_id, employee_name, amount, description, status, deduction_date)
             VALUES (:tid, :uid, :name, :amt, :desc, 'pending', :dd)"
        )->execute([
            ':tid' => app()->tenant()->current() ?? '', ':uid' => $userId,
            ':name' => $employeeName, ':amt' => $amount, ':desc' => $description !== '' ? $description : null,
            ':dd' => $deductionDate,
        ]);
        $id = (int)$db->lastInsertId();
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions?success=' . urlencode('Deduction created.')); exit; }
        awJsonOut(['ok' => true, 'message' => 'Deduction created', 'id' => $id]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions/create?error=' . urlencode($e->getMessage())); exit; }
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiDeductionStatus(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $id = (int)($input['id'] ?? $params['id'] ?? 0);
    $newStatus = trim((string)($input['status'] ?? ''));
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    $validStatuses = ['pending', 'approved', 'processed', 'cancelled'];
    if ($id <= 0 || !in_array($newStatus, $validStatuses, true)) {
        $msg = 'Valid deduction ID and status are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions?error=' . urlencode($msg)); exit; }
        awJsonOut(['ok' => false, 'error' => $msg], 422); return;
    }
    try {
        $db = aw_db();
        $db->prepare("UPDATE employee_deductions SET status = :st WHERE deduction_id = :id")->execute([':st' => $newStatus, ':id' => $id]);
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions?success=' . urlencode('Deduction status updated.')); exit; }
        awJsonOut(['ok' => true, 'message' => 'Deduction status updated to ' . $newStatus]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/deductions?error=' . urlencode($e->getMessage())); exit; }
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
