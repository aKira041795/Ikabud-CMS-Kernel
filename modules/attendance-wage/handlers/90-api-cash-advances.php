<?php

declare(strict_types=1);

/**
 * Cash advance API handlers.
 */

function wageApiCashAdvancesList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    try {
        $db = aw_db();
        $limit = min((int)($params['limit'] ?? 20), 50);
        $stmt = $db->query("SELECT ca.advance_id AS id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, ca.amount, ca.balance, ca.repayment_type, ca.status, ca.request_date FROM cash_advances ca JOIN employee_profiles ep ON ep.profile_id = ca.employee_profile_id ORDER BY ca.request_date DESC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiCashAdvanceCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;

    $profileId  = (int)($input['employee_name'] ?? 0);
    $amount     = (float)($input['amount'] ?? 0);
    $repayment  = trim((string)($input['repayment_type'] ?? 'full_next_payroll'));
    $reqDate    = trim((string)($input['request_date'] ?? date('Y-m-d')));
    $notes      = trim((string)($input['notes'] ?? ''));
    $instAmt    = (float)($input['installment_amount'] ?? 0);
    $instTotal  = (int)($input['total_installments'] ?? 0);
    $targetDate = trim((string)($input['target_repay_date'] ?? ''));

    if ($profileId <= 0 || $amount <= 0) {
        $msg = 'Employee and amount are required.';
        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) { header('Location: ' . (rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/')) . '/admin/wage/cash-advances/create?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    try {
        $db = aw_db();
        // Validate the employee and tenant policy before creating a request.
        $userId = 0;
        $p = $db->prepare("SELECT user_id,basic_salary,cash_advance_allowed FROM employee_profiles WHERE profile_id=:pid AND tenant_id=:tid AND is_active=1 LIMIT 1");
        $p->execute([':pid' => $profileId, ':tid' => (string)aw_tenant_id()]);
        $prof = $p->fetch(\PDO::FETCH_ASSOC);
        if (!$prof || empty($prof['cash_advance_allowed'])) {
            awJsonOut(['ok' => false, 'error' => 'Cash advances are not enabled for this employee.'], 422);
        }
        $userId = max(0, (int)($prof['user_id'] ?? 0));
        $settings = aw_payrollSettings();
        $limit = round((float)$prof['basic_salary'] * ((float)($settings['max_cash_advance_pct'] ?? 50) / 100), 2);
        if ($amount > $limit) awJsonOut(['ok' => false, 'error' => 'Amount exceeds the cash advance policy limit of ₱' . number_format($limit, 2) . '.'], 422);
        $active = $db->prepare("SELECT COUNT(*) FROM cash_advances WHERE tenant_id=:tid AND employee_profile_id=:pid AND status IN ('pending','approved','active')");
        $active->execute([':tid' => (string)aw_tenant_id(), ':pid' => $profileId]);
        if ((int)$active->fetchColumn() >= (int)($settings['max_active_advances'] ?? 2)) {
            awJsonOut(['ok' => false, 'error' => 'Maximum active cash advances reached.'], 422);
        }

        $guardUser = attendanceWageUser();

        $stmt = $db->prepare(
            "INSERT INTO cash_advances (tenant_id, user_id, employee_profile_id, amount, balance, repayment_type, installment_amount, total_installments, target_repay_date, status, request_date, notes, requested_by)
             VALUES (:tid, :uid, :eid, :amt, :bal, :rt, :ia, :it, :td, 'pending', :rd, :notes, :by)"
        );
        $stmt->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':uid' => $userId, ':eid' => $profileId, ':amt' => $amount, ':bal' => $amount,
            ':rt' => $repayment, ':ia' => ($instAmt > 0 ? $instAmt : null), ':it' => ($instTotal > 0 ? $instTotal : null),
            ':td' => ($targetDate !== '' ? $targetDate : null), ':rd' => $reqDate, ':notes' => $notes,
            ':by' => $guardUser['id'] ?? 0,
        ]);
        $id = (int)$db->lastInsertId();

        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) {
            header('Location: ' . (rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/')) . '/admin/wage/cash-advances?success=Advance+request+submitted');
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Cash advance request submitted', 'id' => $id]);
    } catch (\Throwable $e) {
        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) { header('Location: ' . (rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/')) . '/admin/wage/cash-advances/create?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiCashAdvanceApprove(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
    $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    if ($id <= 0) {
        $msg = 'Missing advance ID';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/cash-advances?error=' . urlencode($msg)); exit; }
        echo json_encode(['ok' => false, 'error' => $msg]); return;
    }
    try {
        $db = aw_db();
        $db->beginTransaction();

        $s = $db->prepare("SELECT * FROM cash_advances WHERE advance_id = :id AND status = 'pending' LIMIT 1 FOR UPDATE");
        $s->execute([':id' => $id]);
        $advance = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$advance) {
            $msg = 'Advance not found or already processed';
            $db->rollBack();
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/cash-advances?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]); return;
        }

        $guardUser = attendanceWageUser();
        $db->prepare("UPDATE cash_advances SET status = 'approved', approved_by = :by, approved_at = NOW() WHERE advance_id = :id")
           ->execute([':id' => $id, ':by' => $guardUser['id'] ?? null]);

        // Schedule repayment(s) based on repayment_type
        $repaymentType = $advance['repayment_type'] ?? 'full_next_payroll';
        $totalAmount   = (float)($advance['balance'] ?? $advance['amount'] ?? 0);

        if ($repaymentType === 'full_next_payroll') {
            // Single repayment in current active period
            $period = $db->query("SELECT period_id FROM payroll_periods WHERE status IN ('draft','processing') ORDER BY start_date DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            $periodId = $period ? (int)$period['period_id'] : 0;
            if ($periodId > 0) {
                $db->prepare("INSERT INTO cash_advance_repayments (advance_id, payroll_period_id, amount, status)
                              VALUES (:aid, :pid, :amt, 'pending')")
                   ->execute([':aid' => $id, ':pid' => $periodId, ':amt' => $totalAmount]);
            }
        } elseif ($repaymentType === 'installment') {
            $instAmt   = (float)($advance['installment_amount'] ?? 0);
            $instTotal = (int)($advance['total_installments'] ?? 0);
            if ($instAmt <= 0) { $instAmt = $totalAmount / max($instTotal, 1); }
            if ($instTotal <= 0) { $instTotal = (int)ceil($totalAmount / $instAmt); }
            // Get next N payroll periods
            $periods = $db->query("SELECT period_id FROM payroll_periods WHERE status IN ('draft','processing') ORDER BY start_date ASC LIMIT {$instTotal}")->fetchAll(\PDO::FETCH_ASSOC);
            $remaining = $totalAmount;
            foreach ($periods as $i => $p) {
                $amt = ($i === count($periods) - 1) ? $remaining : min($instAmt, $remaining);
                if ($amt <= 0) break;
                $db->prepare("INSERT INTO cash_advance_repayments (advance_id, payroll_period_id, amount, status)
                              VALUES (:aid, :pid, :amt, 'pending')")
                   ->execute([':aid' => $id, ':pid' => (int)$p['period_id'], ':amt' => $amt]);
                $remaining -= $amt;
            }
        } elseif ($repaymentType === 'lumpsum_date') {
            $targetDate = $advance['target_repay_date'] ?? '';
            if ($targetDate !== '') {
                // Find the payroll period covering the target date
                $period = $db->prepare("SELECT period_id FROM payroll_periods WHERE start_date <= :td AND end_date >= :td2 AND status IN ('draft','processing') ORDER BY start_date ASC LIMIT 1");
                $period->execute([':td' => $targetDate, ':td2' => $targetDate]);
                $p = $period->fetch(\PDO::FETCH_ASSOC);
                if ($p) {
                    $db->prepare("INSERT INTO cash_advance_repayments (advance_id, payroll_period_id, amount, status)
                                  VALUES (:aid, :pid, :amt, 'pending')")
                       ->execute([':aid' => $id, ':pid' => (int)$p['period_id'], ':amt' => $totalAmount]);
                }
            }
        }

        // Mark advance as active if repayments were created
        $repCount = (int)$db->query("SELECT COUNT(*) FROM cash_advance_repayments WHERE advance_id = {$id}")->fetchColumn();
        if ($repCount > 0) {
            $db->prepare("UPDATE cash_advances SET status = 'active' WHERE advance_id = :id")->execute([':id' => $id]);
        }

        $db->commit();
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/cash-advances?success=Cash+advance+approved');
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Cash advance approved. Repayment scheduled for next payroll.']);
    } catch (\Throwable $e) {
        if (isset($db) && $db instanceof \PDO && $db->inTransaction()) $db->rollBack();
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/cash-advances?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
