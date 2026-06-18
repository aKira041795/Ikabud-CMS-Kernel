<?php

declare(strict_types=1);

/**
 * Salary adjustment API handlers.
 */



function wageApiAdjustmentsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = aw_db();
        $stmt = $db->query(
            "SELECT sa.adjustment_id AS id, u.full_name AS employee_name, sa.adjustment_type, sa.amount, sa.description, sa.status, sa.effective_date, sa.created_at
             FROM salary_adjustments sa
             JOIN attendance_wage_users u ON u.id = sa.user_id
             ORDER BY sa.created_at DESC LIMIT 50"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        echo json_encode(['ok' => true, 'adjustments' => $rows, 'total' => count($rows)]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiAdjustmentCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    $userId         = (int)($input['user_id'] ?? 0);
    $adjustmentType = trim((string)($input['adjustment_type'] ?? ''));
    $amount         = (float)($input['amount'] ?? 0);
    $description    = trim((string)($input['description'] ?? ''));
    $effectiveDate  = trim((string)($input['effective_date'] ?? ''));
    $category       = trim((string)($input['category'] ?? 'taxable'));
    $periodId       = (int)($input['payroll_period_id'] ?? 0);

    $validTypes = ['bonus','allowance','penalty','deduction','correction','thirteenth_month','holiday_bonus'];
    if ($userId <= 0 || $adjustmentType === '' || $amount <= 0) {
        $msg = 'Employee, type, and a positive amount are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments/create?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    if (!in_array($adjustmentType, $validTypes, true)) {
        $msg = 'Invalid adjustment type.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments/create?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    $validCategories = ['taxable','non_taxable'];
    if (!in_array($category, $validCategories, true)) { $category = 'taxable'; }

    // Auto-resolve current active payroll period if not explicitly set
    if ($periodId <= 0) {
        try {
            $db = aw_db();
            $periodId = (int)($db->query("SELECT period_id FROM payroll_periods WHERE status IN ('draft','processing') ORDER BY start_date DESC LIMIT 1")->fetchColumn());
        } catch (\Throwable $e) { $periodId = 0; }
    }

    try {
        $db = aw_db();
        $db->prepare(
            "INSERT INTO salary_adjustments (tenant_id, user_id, payroll_period_id, adjustment_type, category, amount, description, effective_date, status, created_by)
             VALUES (:tid, :uid, :pid, :at, :cat, :amt, :desc, :ed, 'pending', :cb)"
        )->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':uid' => $userId,
            ':pid' => $periodId > 0 ? $periodId : null,
            ':at' => $adjustmentType,
            ':cat' => $category,
            ':amt' => $amount,
            ':desc' => $description !== '' ? $description : null,
            ':ed' => $effectiveDate !== '' ? $effectiveDate : date('Y-m-d'),
            ':cb' => aw_currentUserId(),
        ]);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/adjustments?success=' . urlencode('Adjustment created.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Adjustment created']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments/create?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiAdjustmentApprove(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    $adjustmentId = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($adjustmentId <= 0) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments?error=' . urlencode('Invalid adjustment ID.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid adjustment ID.']);
        return;
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT adjustment_id, status FROM salary_adjustments WHERE adjustment_id = :id LIMIT 1");
        $stmt->execute([':id' => $adjustmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments?error=' . urlencode('Adjustment not found.')); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Adjustment not found.']);
            return;
        }
        if ($row['status'] !== 'pending') {
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments?error=' . urlencode('Only pending adjustments can be approved.')); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Only pending adjustments can be approved.']);
            return;
        }
        $db->prepare("UPDATE salary_adjustments SET status = 'approved', approved_by = :ab, approval_date = NOW() WHERE adjustment_id = :id")
           ->execute([':id' => $adjustmentId, ':ab' => aw_currentUserId()]);
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments?success=' . urlencode('Adjustment approved.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Adjustment approved']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/adjustments?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
