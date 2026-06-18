<?php

declare(strict_types=1);

/**
 * Employee profile API handlers.
 */

function awInputJSON(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function awJsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function wageApiEmployeesList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    try {
        $db = aw_db();
        $limit = min((int)($params['limit'] ?? 25), 100);
        $stmt = $db->query("SELECT profile_id AS id, last_name, first_name, middle_name, suffix, employee_number, position, department, hire_date, employment_status, salary_type, basic_salary, is_active FROM employee_profiles ORDER BY last_name ASC, first_name ASC LIMIT {$limit}");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        awJsonOut(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiEmployeeGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $id = (int)($params['id'] ?? 0);
    if (!$id) { awJsonOut(['ok' => false, 'error' => 'Missing employee ID'], 422); return; }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT * FROM employee_profiles WHERE profile_id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        awJsonOut(['ok' => true, 'data' => $row ?: null]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiEmployeeCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $input = awInputJSON();

    $lastName    = trim((string)($input['last_name'] ?? ''));
    $firstName   = trim((string)($input['first_name'] ?? ''));
    $middleName  = trim((string)($input['middle_name'] ?? ''));
    $suffix      = trim((string)($input['suffix'] ?? ''));
    $empNo       = trim((string)($input['employee_number'] ?? ''));
    $position    = trim((string)($input['position'] ?? ''));
    $department  = trim((string)($input['department'] ?? ''));
    $hireDate    = trim((string)($input['hire_date'] ?? ''));
    $status      = trim((string)($input['employment_status'] ?? 'probationary'));
    $salaryType  = trim((string)($input['salary_type'] ?? 'daily'));
    $basicSalary = (float)($input['basic_salary'] ?? 0);
    $hourlyRate  = (float)($input['hourly_rate'] ?? 0);
    $otAllowed   = isset($input['overtime_allowed']) ? 1 : 0;
    $otRate      = (float)($input['overtime_rate'] ?? 1.25);
    $maxDailyHrs = (float)($input['max_daily_hours'] ?? 8.00);
    $maxWeekHrs  = (float)($input['max_weekly_hours'] ?? 40.00);
    $holidayPay  = isset($input['holiday_pay_enabled']) ? 1 : 0;
    $restDayPay  = isset($input['rest_day_pay_enabled']) ? 1 : 0;
    $nightDiff   = isset($input['night_diff_enabled']) ? 1 : 0;
    $cashAdv     = isset($input['cash_advance_allowed']) ? 1 : 0;
    $sssNum      = trim((string)($input['sss_number'] ?? ''));
    $sssApp      = isset($input['sss_applicable']) ? 1 : 0;
    $phNum       = trim((string)($input['philhealth_number'] ?? ''));
    $phApp       = isset($input['philhealth_applicable']) ? 1 : 0;
    $pagNum      = trim((string)($input['pagibig_number'] ?? ''));
    $pagApp      = isset($input['pagibig_applicable']) ? 1 : 0;
    $tinNum      = trim((string)($input['tin_number'] ?? ''));
    $taxStatus   = trim((string)($input['tax_exemption_status'] ?? 'single'));

    if ($lastName === '' || $firstName === '' || $position === '') {
        awJsonOut(['ok' => false, 'error' => 'Last name, first name, and position are required'], 422);
    }

    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "INSERT INTO employee_profiles
                (tenant_id, first_name, last_name, middle_name, suffix, employee_number, position, department,
                 hire_date, employment_status, salary_type, basic_salary, hourly_rate,
                 overtime_allowed, overtime_rate, max_daily_hours, max_weekly_hours,
                 holiday_pay_enabled, rest_day_pay_enabled, night_diff_enabled, cash_advance_allowed, onsite_attendance,
                 sss_number, sss_applicable, philhealth_number, philhealth_applicable,
                 pagibig_number, pagibig_applicable, tin_number, tax_exemption_status)
             VALUES
                (:tid, :fn, :ln, :mn, :sx, :en, :pos, :dept,
                 :hd, :es, :st, :bs, :hr,
                 :oa, :or, :mdh, :mwh,
                 :hp, :rdp, :nd, :ca, :osa,
                 :sss, :sssa, :ph, :pha,
                 :pag, :paga, :tin, :tx)"
        );
        $stmt->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':fn' => $firstName, ':ln' => $lastName, ':mn' => $middleName, ':sx' => $suffix,
            ':en' => $empNo, ':pos' => $position, ':dept' => $department, ':hd' => $hireDate ?: null,
            ':es' => $status, ':st' => $salaryType, ':bs' => $basicSalary, ':hr' => $hourlyRate,
            ':oa' => $otAllowed, ':or' => $otRate, ':mdh' => $maxDailyHrs, ':mwh' => $maxWeekHrs,
            ':hp' => $holidayPay, ':rdp' => $restDayPay, ':nd' => $nightDiff, ':ca' => $cashAdv,
            ':osa' => isset($input['onsite_attendance']) ? 1 : 0,
            ':sss' => $sssNum, ':sssa' => $sssApp, ':ph' => $phNum, ':pha' => $phApp,
            ':pag' => $pagNum, ':paga' => $pagApp, ':tin' => $tinNum, ':tx' => $taxStatus,
        ]);
        $id = (int)$db->lastInsertId();
        $isFormPost = !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/employees?success=Employee+created+successfully');
            exit;
        }
        awJsonOut(['ok' => true, 'message' => 'Employee profile created', 'id' => $id]);
    } catch (\Throwable $e) {
        $isFormPost = !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
        if ($isFormPost) {
            header('Location: ' . awBaseUrl() . '/admin/wage/employees?error=' . urlencode($e->getMessage()));
            exit;
        }
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function wageApiEmployeeUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    if (!$id) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/employees?error=' . urlencode('Missing employee ID')); exit; }
        awJsonOut(['ok' => false, 'error' => 'Missing employee ID'], 422); return;
    }

    $input = awInputJSON();
    $fields = [];
    $vals = [':id' => $id];

    foreach (['first_name','last_name','middle_name','suffix','employee_number','position','department','hire_date','employment_status','salary_type'] as $f) {
        if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = trim((string)$input[$f]); }
    }
    foreach (['basic_salary','hourly_rate','overtime_rate','max_daily_hours','max_weekly_hours'] as $f) {
        if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = (float)$input[$f]; }
    }
    foreach (['overtime_allowed','holiday_pay_enabled','rest_day_pay_enabled','night_diff_enabled','cash_advance_allowed','onsite_attendance','sss_applicable','philhealth_applicable','pagibig_applicable'] as $f) {
        if (array_key_exists($f, $input)) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = $input[$f] ? 1 : 0; }
    }
    foreach (['sss_number','philhealth_number','pagibig_number','tin_number','tax_exemption_status'] as $f) {
        if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = trim((string)$input[$f]); }
    }

    if (empty($fields)) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/employees/' . $id . '?error=' . urlencode('No fields to update')); exit; }
        awJsonOut(['ok' => false, 'error' => 'No fields to update'], 422); return;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE employee_profiles SET " . implode(', ', $fields) . " WHERE profile_id = :id")->execute($vals);
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/employees?success=' . urlencode('Employee profile updated')); exit; }
        awJsonOut(['ok' => true, 'message' => 'Employee profile updated']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/employees/' . $id . '?error=' . urlencode($e->getMessage())); exit; }
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
