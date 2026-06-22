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
        // Check if photo_url column exists (tenant DB might not have it migrated yet)
        static $hasPhotoCol = null;
        if ($hasPhotoCol === null) {
            try {
                $c = $db->query("SHOW COLUMNS FROM employee_profiles LIKE 'photo_url'");
                $hasPhotoCol = (bool)$c->fetch();
            } catch (\Throwable $e) { $hasPhotoCol = false; }
        }
        $photoField = $hasPhotoCol ? ', photo_url' : '';
        $stmt = $db->query("SELECT profile_id AS id, last_name, first_name, middle_name, suffix, employee_number, position, department, hire_date, employment_status, salary_type, basic_salary, hourly_rate, daily_rate, monthly_rate, overtime_allowed, holiday_pay_enabled, night_diff_enabled, sss_number, philhealth_number, pagibig_number, tin_number, tax_exemption_status, dependents_count{$photoField}, is_active FROM employee_profiles ORDER BY last_name ASC, first_name ASC LIMIT {$limit}");
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
    $th13th     = isset($input['thirteenth_month_enabled']) ? 1 : 0;
    $sssNum      = trim((string)($input['sss_number'] ?? ''));
    $sssApp      = isset($input['sss_applicable']) ? 1 : 0;
    $phNum       = trim((string)($input['philhealth_number'] ?? ''));
    $phApp       = isset($input['philhealth_applicable']) ? 1 : 0;
    $pagNum      = trim((string)($input['pagibig_number'] ?? ''));
    $pagApp      = isset($input['pagibig_applicable']) ? 1 : 0;
    $tinNum      = trim((string)($input['tin_number'] ?? ''));
    $taxStatus   = trim((string)($input['tax_exemption_status'] ?? 'single'));

    // Handle photo upload (file upload or base64)
    $photoUrl = null;
    if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photoUrl = aw_saveEmployeePhoto($_FILES['photo']);
    } elseif (!empty($input['photo_data'])) {
        $photoUrl = aw_saveEmployeePhotoFromBase64((string)$input['photo_data']);
    }

    if ($lastName === '' || $firstName === '' || $position === '') {
        awJsonOut(['ok' => false, 'error' => 'Last name, first name, and position are required'], 422);
    }

    try {
        $db = aw_db();
        static $hasPhotoColInsert = null;
        if ($hasPhotoColInsert === null) {
            try { $c = $db->query("SHOW COLUMNS FROM employee_profiles LIKE 'photo_url'"); $hasPhotoColInsert = (bool)$c->fetch(); } catch (\Throwable $e) { $hasPhotoColInsert = false; }
        }
        $photoColPart = $hasPhotoColInsert ? ', photo_url' : '';
        $photoValPart = $hasPhotoColInsert ? ', :photo' : '';
        $stmt = $db->prepare(
            "INSERT INTO employee_profiles
                (tenant_id, first_name, last_name, middle_name, suffix, employee_number, position, department,
                 hire_date, employment_status, salary_type, basic_salary, hourly_rate,
                 overtime_allowed, overtime_rate, max_daily_hours, max_weekly_hours,
                 holiday_pay_enabled, rest_day_pay_enabled, night_diff_enabled, cash_advance_allowed, thirteenth_month_enabled, onsite_attendance,
                 sss_number, sss_applicable, philhealth_number, philhealth_applicable,
                 pagibig_number, pagibig_applicable, tin_number, tax_exemption_status{$photoColPart})
             VALUES
                (:tid, :fn, :ln, :mn, :sx, :en, :pos, :dept,
                 :hd, :es, :st, :bs, :hr,
                 :oa, :or, :mdh, :mwh,
                 :hp, :rdp, :nd, :ca, :t13, :osa,
                 :sss, :sssa, :ph, :pha,
                 :pag, :paga, :tin, :tx{$photoValPart})"
        );
        $params = [
            ':tid' => app()->tenant()->current() ?? '',
            ':fn' => $firstName, ':ln' => $lastName, ':mn' => $middleName, ':sx' => $suffix,
            ':en' => $empNo, ':pos' => $position, ':dept' => $department, ':hd' => $hireDate ?: null,
            ':es' => $status, ':st' => $salaryType, ':bs' => $basicSalary, ':hr' => $hourlyRate,
            ':oa' => $otAllowed, ':or' => $otRate, ':mdh' => $maxDailyHrs, ':mwh' => $maxWeekHrs,
            ':hp' => $holidayPay, ':rdp' => $restDayPay, ':nd' => $nightDiff, ':ca' => $cashAdv,
            ':osa' => isset($input['onsite_attendance']) ? 1 : 0,
            ':t13' => $th13th,
            ':sss' => $sssNum, ':sssa' => $sssApp, ':ph' => $phNum, ':pha' => $phApp,
            ':pag' => $pagNum, ':paga' => $pagApp, ':tin' => $tinNum, ':tx' => $taxStatus,
        ];
        if ($hasPhotoColInsert) { $params[':photo'] = $photoUrl; }
        $stmt->execute($params);
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
    foreach (['overtime_allowed','holiday_pay_enabled','rest_day_pay_enabled','night_diff_enabled','cash_advance_allowed','thirteenth_month_enabled','onsite_attendance','sss_applicable','philhealth_applicable','pagibig_applicable'] as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "`{$f}` = :{$f}";
            $vals[":{$f}"] = $input[$f] ? 1 : 0;
        } elseif ($isFormPost) {
            // HTML forms don't submit unchecked checkboxes — explicitly set to 0
            $fields[] = "`{$f}` = :{$f}";
            $vals[":{$f}"] = 0;
        }
    }
    foreach (['sss_number','philhealth_number','pagibig_number','tin_number','tax_exemption_status'] as $f) {
        if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = trim((string)$input[$f]); }
    }

    // Handle photo upload on update (check column exists first to avoid errors on tenant DBs)
    static $hasPhotoColUpdate = null;
    $canSavePhoto = false;
    if ($hasPhotoColUpdate === null) {
        try { $c = aw_db()->query("SHOW COLUMNS FROM employee_profiles LIKE 'photo_url'"); $hasPhotoColUpdate = (bool)$c->fetch(); } catch (\Throwable $e) { $hasPhotoColUpdate = false; }
    }
    $canSavePhoto = $hasPhotoColUpdate;
    if ($canSavePhoto && !empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photoUrl = aw_saveEmployeePhoto($_FILES['photo']);
        $fields[] = '`photo_url` = :photo';
        $vals[':photo'] = $photoUrl;
    } elseif ($canSavePhoto && !empty($input['photo_data'])) {
        $photoUrl = aw_saveEmployeePhotoFromBase64((string)$input['photo_data']);
        $fields[] = '`photo_url` = :photo';
        $vals[':photo'] = $photoUrl;
    } elseif ($canSavePhoto && isset($input['photo_remove']) && $input['photo_remove']) {
        $fields[] = '`photo_url` = :photo';
        $vals[':photo'] = null;
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

/**
 * Save an uploaded employee photo file and return its URL.
 */
function aw_saveEmployeePhoto(array $file): ?string
{
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMime, true)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $ext = match ($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
    };
    $filename = 'emp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/uploads/employee-photos' : (defined('BASE_PATH') ? BASE_PATH . '/public/uploads/employee-photos' : sys_get_temp_dir() . '/employee-photos');
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . '/' . $filename;
    return move_uploaded_file($file['tmp_name'], $dest)
        ? '/uploads/employee-photos/' . rawurlencode($filename)
        : null;
}

/**
 * Save a base64-encoded employee photo and return its URL.
 */
function aw_saveEmployeePhotoFromBase64(string $data): ?string
{
    if (!preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/', $data, $m)) return null;
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $binary = base64_decode(str_replace(' ', '+', $m[2]), true);
    if ($binary === false || strlen($binary) > 5 * 1024 * 1024) return null;

    $filename = 'emp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $uploadDir = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/uploads/employee-photos' : (defined('BASE_PATH') ? BASE_PATH . '/public/uploads/employee-photos' : sys_get_temp_dir() . '/employee-photos');
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    $dest = $uploadDir . '/' . $filename;
    return file_put_contents($dest, $binary) !== false
        ? '/uploads/employee-photos/' . rawurlencode($filename)
        : null;
}
