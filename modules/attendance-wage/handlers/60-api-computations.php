<?php

declare(strict_types=1);

/**
 * Salary computation API handlers.
 */



function wageApiComputationsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    try {
        $db = aw_db();
        $periodId = (int)($params['period_id'] ?? $_GET['period_id'] ?? 0);
        if ($periodId > 0) {
            $stmt = $db->prepare(
                "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                        ep.position, ep.department, ep.salary_type, pp.period_name
                 FROM salary_computations sc
                 JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                 JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
                 WHERE sc.payroll_period_id = :pid
                 ORDER BY ep.last_name ASC, ep.first_name ASC"
            );
            $stmt->execute([':pid' => $periodId]);
        } else {
            $limit = min((int)($params['limit'] ?? 50), 200);
            $stmt = $db->query(
                "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                        ep.position, ep.department, ep.salary_type, pp.period_name
                 FROM salary_computations sc
                 JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
                 JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
                 ORDER BY sc.created_at DESC LIMIT {$limit}"
            );
        }
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        awJsonOut(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiComputationGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) { awJsonOut(['ok' => false, 'error' => 'Missing computation ID'], 422); return; }
    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "SELECT sc.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.department, ep.employee_number, ep.salary_type,
                    pp.period_name, pp.start_date AS period_start, pp.end_date AS period_end, pp.pay_date
             FROM salary_computations sc
             JOIN employee_profiles ep ON ep.profile_id = sc.employee_profile_id
             JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id
             WHERE sc.computation_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        awJsonOut(['ok' => true, 'data' => $row ?: null]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiComputeEmployee(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $userId = (int)($input['user_id'] ?? $input['employee_id'] ?? 0);
    $periodId = (int)($input['period_id'] ?? $input['payroll_period_id'] ?? $params['period_id'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json');
    if ($userId <= 0 || $periodId <= 0) {
        $msg = 'Employee ID and period ID are required.';
        if ($isFormPost) { header('Location: ' . awBaseUrl() . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
        awJsonOut(['ok' => false, 'error' => $msg], 422); return;
    }
    try {
        $computedBy = aw_currentUserId();
        $result = aw_computeSalary($userId, $periodId, $computedBy);
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/computations?period_id=' . $periodId . '&success=' . urlencode('Salary computed: net pay ₱' . number_format((float)($result['net_pay'] ?? 0), 2)));
            exit;
        }
        awJsonOut(['ok' => true, 'message' => 'Salary computed', 'computation' => $result]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . awBaseUrl() . '/admin/wage/computations?error=' . urlencode($e->getMessage())); exit; }
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiBulkCompute(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $salaryType = trim((string)($input['salary_type'] ?? ''));
    $periodId = (int)($input['period_id'] ?? $input['payroll_period_id'] ?? $input['id'] ?? $params['period_id'] ?? $_GET['period_id'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json');

    // Auto-create period when salary_type is provided (quick prepare flow)
    if ($salaryType !== '' && $periodId <= 0) {
        try {
            $db = aw_db();
            $tz = new \DateTimeZone('Asia/Manila');
            $now = new \DateTimeImmutable('now', $tz);

            // Determine appropriate period_type from salary_type
            $periodType = match ($salaryType) {
                'hourly', 'daily' => 'weekly',
                'monthly', 'fixed' => 'semi_monthly',
                default => 'semi_monthly',
            };

            if ($periodType === 'weekly') {
                // Weekly: Monday → Saturday, payday Saturday, cutoff Saturday
                $dow = (int)$now->format('N'); // 1=Mon, 7=Sun
                $daysSinceMon = $dow - 1;
                $startDate = $now->modify("-{$daysSinceMon} days")->format('Y-m-d');
                $endDate = $now->modify("+" . (6 - $daysSinceMon) . " days")->format('Y-m-d'); // Saturday
                $payDate = $endDate;   // Payday = Saturday
                $cutoffDate = $endDate;
                $weekNum = $now->format('W');
                $monthName = $now->format('F');
                $typeLabel = ucfirst($salaryType);
                $periodName = "{$monthName} Week {$weekNum} — {$typeLabel} Payroll";
            } else {
                // Semi-monthly: 1st–15th or 16th–end, payday 5th/20th, cutoff = end
                $day = (int)$now->format('d');
                $monthStart = $now->format('Y-m-01');
                $monthEnd = $now->format('Y-m-t');
                $monthName = $now->format('F Y');
                $typeLabel = ucfirst($salaryType);
                if ($day <= 15) {
                    $startDate = $monthStart;
                    $endDate = $now->format('Y-m-15');
                    $payDate = $now->format('Y-m-20');
                    $periodName = "{$monthName} 1st–15th — {$typeLabel} Payroll";
                } else {
                    $startDate = $now->format('Y-m-16');
                    $endDate = $monthEnd;
                    $payDate = (new \DateTimeImmutable('first day of next month', $tz))->modify('+4 days')->format('Y-m-d');
                    $periodName = "{$monthName} 16th–{$now->format('t')}th — {$typeLabel} Payroll";
                }
                $cutoffDate = $endDate;
            }

            $stmt = $db->prepare(
                "INSERT INTO payroll_periods (tenant_id, period_name, period_type, start_date, end_date, pay_date, cutoff_date, status, created_by)
                 VALUES (:tid, :name, :type, :start, :end, :pay, :cutoff, 'draft', :by)"
            );
            $user = attendanceWageUser();
            $stmt->execute([
                ':tid' => app()->tenant()->current() ?? '',
                ':name' => $periodName, ':type' => $periodType,
                ':start' => $startDate, ':end' => $endDate,
                ':pay' => $payDate, ':cutoff' => $cutoffDate,
                ':by' => $user['id'] ?? null,
            ]);
            $periodId = (int)$db->lastInsertId();

            // Migrate approved/pending adjustments from any old draft periods to this new period
            $db->prepare(
                "UPDATE salary_adjustments sa
                 SET sa.payroll_period_id = :newPid
                 WHERE sa.payroll_period_id IN (SELECT period_id FROM payroll_periods WHERE status = 'draft' AND period_id != :newPid2)
                 AND sa.status IN ('pending','approved')"
            )->execute([':newPid' => $periodId, ':newPid2' => $periodId]);

            // Also pick up any approved adjustments with NULL period that fall within this period's date range
            $db->prepare(
                "UPDATE salary_adjustments
                 SET payroll_period_id = :newPid
                 WHERE payroll_period_id IS NULL AND status IN ('pending','approved')
                 AND effective_date BETWEEN :start AND :end"
            )->execute([':newPid' => $periodId, ':start' => $startDate, ':end' => $endDate]);
        } catch (\Throwable $e) {
            if ($isFormPost) {
                header('Location: ' . awBaseUrl() . '/admin/wage/periods?error=' . urlencode('Failed to create period: ' . $e->getMessage()));
                exit;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            return;
        }
    }

    if ($periodId <= 0) {
        $msg = 'Missing period_id or salary_type.';
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/periods?error=' . urlencode($msg));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    try {
        $db = aw_db();

        // Verify period exists
        $s = $db->prepare("SELECT * FROM payroll_periods WHERE period_id = :pid LIMIT 1");
        $s->execute([':pid' => $periodId]);
        $period = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$period) {
            $msg = 'Period not found.';
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }

        // Update period status to processing
        $db->prepare("UPDATE payroll_periods SET status = 'processing', processed_by = :by, processed_at = NOW() WHERE period_id = :pid")
           ->execute([':pid' => $periodId, ':by' => attendanceWageUser()['id'] ?? null]);

        // Filter employees: if salary_type provided, use it directly; otherwise use period type mapping
        if ($salaryType !== '') {
            $employees = $db->prepare("SELECT * FROM employee_profiles WHERE is_active = 1 AND salary_type = :st");
            $employees->execute([':st' => $salaryType]);
            $employees = $employees->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $periodType = $period['period_type'] ?? 'semi_monthly';
            $applicableTypes = aw_salaryTypesForPeriod($periodType);
            $typeList = "'" . implode("','", $applicableTypes) . "'";
            $employees = $db->query("SELECT * FROM employee_profiles WHERE is_active = 1 AND salary_type IN ({$typeList})")->fetchAll(\PDO::FETCH_ASSOC);
        }

        $totalGross = 0; $totalDeductions = 0; $totalNet = 0; $computed = 0;
        $computedBy = (int)(attendanceWageUser()['id'] ?? 0);
        foreach ($employees as $emp) {
            $userId = (int)($emp['user_id'] ?? 0);
            if ($userId > 0) {
                // Has linked user account — full computation with attendance
                $result = aw_computeSalary($userId, $periodId, $computedBy);
            } else {
                // No linked user — simple salary-based computation
                $result = aw_computeSimpleSalary($emp, $periodId, $computedBy);
            }
            if (($result['ok'] ?? false) && ($result['computation_id'] ?? 0) > 0) {
                $totalGross += (float)($result['gross_pay'] ?? 0);
                $totalDeductions += (float)($result['total_deductions'] ?? 0);
                $totalNet += (float)($result['net_pay'] ?? 0);
                $computed++;
            }
        }

        // Update period totals
        $db->prepare("UPDATE payroll_periods SET total_employees = :te, total_gross_pay = :tg, total_deductions = :td, total_net_pay = :tn, status = 'processing' WHERE period_id = :pid")
           ->execute([':pid' => $periodId, ':te' => $computed, ':tg' => $totalGross, ':td' => $totalDeductions, ':tn' => $totalNet]);

        $isFormPost = !str_contains($contentType, 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/computations?period_id=' . $periodId . '&success=' . urlencode("Bulk computation complete: {$computed} employees processed."));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => "Bulk computation complete: {$computed} employees.", 'period_id' => $periodId, 'computed' => $computed, 'total_gross' => $totalGross, 'total_deductions' => $totalDeductions, 'total_net_pay' => $totalNet]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiApproveComputation(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    aw_csrfGuard();
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
    $base = awBaseUrl();
    if ($id <= 0) {
        $msg = 'Missing computation ID';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $s = $db->prepare("SELECT sc.*, pp.pay_date FROM salary_computations sc JOIN payroll_periods pp ON pp.period_id = sc.payroll_period_id WHERE sc.computation_id = :id AND sc.status = 'computed' LIMIT 1");
        $s->execute([':id' => $id]);
        $comp = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$comp) {
            $msg = 'Computation not found or already processed.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $now = date('Y-m-d');
        $payDate = $comp['pay_date'] ?? '9999-12-31';
        if ($payDate > $now) {
            $msg = 'Cannot approve before pay date (' . $payDate . ').';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?period_id=' . ((int)$comp['payroll_period_id']) . '&error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $guardUser = attendanceWageUser();
        $db->prepare("UPDATE salary_computations SET status = 'approved', approved_by = :by, approval_date = NOW() WHERE computation_id = :id AND status = 'computed'")
           ->execute([':id' => $id, ':by' => $guardUser['id'] ?? null]);
        // Also approve the parent period if all computations are approved
        $pid = (int)$comp['payroll_period_id'];
        $pc = $db->prepare("SELECT COUNT(*) FROM salary_computations WHERE payroll_period_id = :pid AND status = 'computed'");
        $pc->execute([':pid' => $pid]);
        $pending = (int)$pc->fetchColumn();
        if ($pending === 0) {
            $db->prepare("UPDATE payroll_periods SET status = 'approved' WHERE period_id = :pid AND status = 'processing'")->execute([':pid' => $pid]);
        }
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/computations?period_id=' . $pid . '&success=' . urlencode('Computation approved.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Computation approved.', 'status' => 'approved']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiPayComputation(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
    $base = awBaseUrl();
    if ($id <= 0) {
        $msg = 'Missing computation ID';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $s = $db->prepare("SELECT * FROM salary_computations WHERE computation_id = :id AND status = 'approved' LIMIT 1");
        $s->execute([':id' => $id]);
        $comp = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$comp) {
            $msg = 'Computation not found, not yet approved, or already paid.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $guardUser = attendanceWageUser();
        $db->prepare("UPDATE salary_computations SET status = 'paid', paid_by = :by, payment_date = NOW() WHERE computation_id = :id AND status = 'approved'")
           ->execute([':id' => $id, ':by' => $guardUser['id'] ?? null]);
        // Mark all cash advance repayments for this computation as paid
        $pid = (int)$comp['payroll_period_id'];
        $eid = (int)$comp['employee_profile_id'];
        $db->prepare("UPDATE cash_advance_repayments car JOIN cash_advances ca ON ca.advance_id = car.advance_id SET car.status = 'paid' WHERE ca.employee_profile_id = :eid AND car.payroll_period_id = :pid AND car.status = 'deducted'")
           ->execute([':eid' => $eid, ':pid' => $pid]);
        // Check if all computations in the period are paid
        $uc = $db->prepare("SELECT COUNT(*) FROM salary_computations WHERE payroll_period_id = :pid AND status IN ('computed','approved')");
        $uc->execute([':pid' => $pid]);
        $unpaid = (int)$uc->fetchColumn();
        if ($unpaid === 0) {
            $db->prepare("UPDATE payroll_periods SET status = 'completed' WHERE period_id = :pid AND status IN ('processing','approved')")->execute([':pid' => $pid]);
        }
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/computations?period_id=' . $pid . '&success=' . urlencode('Payment marked as paid.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Payment marked as paid.', 'status' => 'paid']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiBatchApprove(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $periodId = (int)($input['period_id'] ?? $params['period_id'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
    $base = awBaseUrl();
    if ($periodId <= 0) {
        $msg = 'Missing period_id.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $ps = $db->prepare("SELECT pay_date, status FROM payroll_periods WHERE period_id = :pid LIMIT 1");
        $ps->execute([':pid' => $periodId]);
        $period = $ps->fetch(\PDO::FETCH_ASSOC);
        if (!$period) {
            $msg = 'Period not found.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $now = date('Y-m-d');
        $payDate = $period['pay_date'] ?? '9999-12-31';
        if ($payDate > $now) {
            $msg = 'Cannot approve before pay date (' . $payDate . ').';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?period_id=' . $periodId . '&error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $guardUser = attendanceWageUser();
        $stmt = $db->prepare("UPDATE salary_computations SET status = 'approved', approved_by = :by, approval_date = NOW() WHERE payroll_period_id = :pid AND status = 'computed'");
        $stmt->execute([':pid' => $periodId, ':by' => $guardUser['id'] ?? null]);
        $count = $stmt->rowCount();
        $db->prepare("UPDATE payroll_periods SET status = 'approved' WHERE period_id = :pid AND status = 'processing'")->execute([':pid' => $periodId]);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/computations?period_id=' . $periodId . '&success=' . urlencode("{$count} computation(s) approved."));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => "{$count} computation(s) approved.", 'count' => $count]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiBatchPay(array $params = []): void
{
    attendanceWageGuard('attendance_wage.approve@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $periodId = (int)($input['period_id'] ?? $params['period_id'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST';
    $base = awBaseUrl();
    if ($periodId <= 0) {
        $msg = 'Missing period_id.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $guardUser = attendanceWageUser();
        $stmt = $db->prepare("UPDATE salary_computations SET status = 'paid', paid_by = :by, payment_date = NOW() WHERE payroll_period_id = :pid AND status = 'approved'");
        $stmt->execute([':pid' => $periodId, ':by' => $guardUser['id'] ?? null]);
        $count = $stmt->rowCount();
        $db->prepare("UPDATE cash_advance_repayments car JOIN cash_advances ca ON ca.advance_id = car.advance_id SET car.status = 'paid' WHERE car.payroll_period_id = :pid AND car.status = 'deducted'")
           ->execute([':pid' => $periodId]);
        $uc = $db->prepare("SELECT COUNT(*) FROM salary_computations WHERE payroll_period_id = :pid AND status IN ('computed','approved')");
        $uc->execute([':pid' => $periodId]);
        if ((int)$uc->fetchColumn() === 0) {
            $db->prepare("UPDATE payroll_periods SET status = 'completed' WHERE period_id = :pid AND status IN ('processing','approved')")->execute([':pid' => $periodId]);
        }
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/computations?period_id=' . $periodId . '&success=' . urlencode("{$count} payment(s) marked as paid."));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => "{$count} payment(s) marked as paid.", 'count' => $count]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/computations?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
