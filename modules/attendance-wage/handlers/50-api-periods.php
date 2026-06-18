<?php

declare(strict_types=1);

/**
 * Payroll period API handlers.
 */

function wageApiPeriodsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    try {
        $db = aw_db();
        $limit = min((int)($params['limit'] ?? 12), 50);
        $stmt = $db->query("SELECT period_id AS id, period_name, period_type, start_date, end_date, pay_date, status, total_employees, total_gross_pay, total_deductions, total_net_pay FROM payroll_periods ORDER BY start_date DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiPeriodGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $id = (int)($params['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing period ID']); return; }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT * FROM payroll_periods WHERE period_id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $row ?: null]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiPeriodCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;

    $name     = trim((string)($input['period_name'] ?? ''));
    $type     = trim((string)($input['period_type'] ?? 'semi_monthly'));
    $start    = trim((string)($input['start_date'] ?? ''));
    $end      = trim((string)($input['end_date'] ?? ''));
    $pay      = trim((string)($input['pay_date'] ?? ''));
    $cutoff   = trim((string)($input['cutoff_date'] ?? ''));

    if ($name === '' || $start === '' || $end === '') {
        $msg = 'Period name, start date, and end date are required.';
        if (!str_contains($contentType, 'application/json')) {
            header('Location: ' . awBaseUrl() . '/admin/wage/periods/create?error=' . urlencode($msg));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "INSERT INTO payroll_periods (tenant_id, period_name, period_type, start_date, end_date, pay_date, cutoff_date, status, created_by)
             VALUES (:tid, :name, :type, :start, :end, :pay, :cutoff, 'draft', :by)"
        );
        $user = attendanceWageUser();
        $stmt->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':name' => $name, ':type' => $type,
            ':start' => $start, ':end' => $end,
            ':pay' => $pay ?: null, ':cutoff' => $cutoff ?: null,
            ':by' => $user['id'] ?? null,
        ]);
        $id = (int)$db->lastInsertId();

        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/periods?success=Period+created+successfully');
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Payroll period created', 'id' => $id]);
    } catch (\Throwable $e) {
        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/periods?error=' . urlencode($e->getMessage()));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiPeriodUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? 0);
    if (!$id) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Missing period ID']);
        return;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;

    $fields = [];
    $vals = [':id' => $id];
    foreach (['period_name','period_type','start_date','end_date','pay_date','cutoff_date'] as $f) {
        if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = trim((string)$input[$f]); }
    }

    if (empty($fields)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No fields to update']);
        return;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE payroll_periods SET " . implode(', ', $fields) . " WHERE period_id = :id")->execute($vals);

        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/periods?success=Period+updated+successfully');
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Payroll period updated']);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiPeriodDelete(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($id <= 0) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/periods?error=' . urlencode('Invalid period.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid period ID.']);
        return;
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT period_id, status FROM payroll_periods WHERE period_id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $period = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$period) {
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/periods?error=' . urlencode('Period not found.')); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Period not found.']);
            return;
        }
        if (!in_array($period['status'], ['draft', 'processing'])) {
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/periods?error=' . urlencode('Only draft or processing periods can be deleted.')); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Only draft or processing periods can be deleted.']);
            return;
        }
        $db->prepare("DELETE FROM salary_computations WHERE payroll_period_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM payroll_periods WHERE period_id = :id")->execute([':id' => $id]);
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/periods?success=' . urlencode('Period and computations deleted.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Period and computations deleted.']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/periods?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
