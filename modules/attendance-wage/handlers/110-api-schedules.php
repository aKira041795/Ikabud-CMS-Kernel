<?php
declare(strict_types=1);
/**
 * Employee schedule API handlers.
 */

/**
 * Resolve an employee profile_id to a valid attendance_wage_users.id,
 * auto-creating the user account if needed. Returns the user_id.
 */
function aw_resolveScheduleUserId(int $profileId): int
{
    $db = aw_db();
    $ps = $db->prepare("SELECT profile_id, user_id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name, employee_number FROM employee_profiles WHERE profile_id = :pid LIMIT 1");
    $ps->execute([':pid' => $profileId]);
    $prof = $ps->fetch(\PDO::FETCH_ASSOC);
    if (!$prof) {
        throw new \RuntimeException('Employee not found.');
    }
    $userId = (int)($prof['user_id'] ?? 0);
    if ($userId > 0) { return $userId; }
    $username = 'aw-' . ($prof['employee_number'] ?: 'emp' . $profileId);
    $fullName = $prof['full_name'] ?? ('Employee #' . $profileId);
    $ustmt = $db->prepare(
        "INSERT INTO attendance_wage_users (username, email, password_hash, full_name, role, is_active)
         VALUES (:u, :e, :ph, :fn, 'employee', 1)
         ON DUPLICATE KEY UPDATE is_active = 1"
    );
    $ustmt->execute([
        ':u' => $username,
        ':e' => $username . '@zap.local',
        ':ph' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
        ':fn' => $fullName,
    ]);
    $userId = (int)$db->lastInsertId();
    if ($userId <= 0) {
        $lu = $db->prepare("SELECT id FROM attendance_wage_users WHERE username = :u LIMIT 1");
        $lu->execute([':u' => $username]);
        $userId = (int)$lu->fetchColumn();
    }
    $db->prepare("UPDATE employee_profiles SET user_id = :uid WHERE profile_id = :pid")->execute([':uid' => $userId, ':pid' => $profileId]);
    return $userId;
}

function wageApiSchedulesList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = aw_db();
        $stmt = $db->query(
            "SELECT ep.profile_id AS id, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name,
                    ep.position, ep.department,
                    GROUP_CONCAT(DISTINCT CONCAT(UCASE(LEFT(es.day_of_week,1)), SUBSTRING(es.day_of_week,2,2)) ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday') SEPARATOR ', ') AS days_label,
                    GROUP_CONCAT(DISTINCT es.day_of_week ORDER BY FIELD(es.day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')) AS days_csv,
                    MAX(es.shift_type) AS shift_type,
                    MIN(NULLIF(es.start_time, '')) AS min_start,
                    MAX(NULLIF(es.end_time, '')) AS max_end,
                    SUM(es.is_dayoff) AS dayoff_count,
                    COUNT(*) AS total_days
             FROM employee_schedules es
             JOIN employee_profiles ep ON ep.user_id = es.user_id
             WHERE ep.is_active = 1
             GROUP BY ep.profile_id, ep.first_name, ep.middle_name, ep.last_name, ep.suffix, ep.position, ep.department
             ORDER BY ep.last_name ASC, ep.first_name ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        echo json_encode(['ok' => true, 'schedules' => $rows, 'total' => count($rows)]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiSchedulesByEmployee(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    header('Content-Type: application/json; charset=utf-8');
    $profileId = (int)($params['profileId'] ?? 0);
    if ($profileId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Employee profile ID required.']);
        return;
    }
    try {
        $db = aw_db();
        // Get user_id from profile_id
        $stmt = $db->prepare("SELECT user_id FROM employee_profiles WHERE profile_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $profileId]);
        $userId = (int)$stmt->fetchColumn();
        if ($userId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
            return;
        }
        $stmt = $db->prepare(
            "SELECT schedule_id, day_of_week, shift_type, start_time, end_time, is_dayoff
             FROM employee_schedules
             WHERE user_id = :uid
             ORDER BY FIELD(day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')"
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'rows' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiScheduleGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Schedule ID required.']);
        return;
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "SELECT es.*, CONCAT_WS(' ', ep.first_name, ep.middle_name, ep.last_name, ep.suffix) AS employee_name, ep.position, ep.department
             FROM employee_schedules es
             JOIN employee_profiles ep ON ep.user_id = es.user_id
             WHERE es.schedule_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'schedule' => $row ?: null]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiScheduleCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    // Bulk mode: form includes hidden bulk=1 + days[] checkboxes
    if (($input['bulk'] ?? '') === '1' || !empty($input['days'])) {
        wageApiScheduleBulkCreate($params);
        return;
    }
    $profileId  = (int)($input['employee_id'] ?? 0);
    $dayOfWeek  = trim((string)($input['day_of_week'] ?? ''));
    $shiftType  = trim((string)($input['shift_type'] ?? 'day'));
    $startTime  = trim((string)($input['start_time'] ?? ''));
    $endTime    = trim((string)($input['end_time'] ?? ''));
    $isDayoff   = (int)($input['is_dayoff'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($profileId <= 0 || $dayOfWeek === '') {
        $msg = 'Employee and day are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    $validDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    if (!in_array($dayOfWeek, $validDays, true)) {
        $msg = 'Invalid day of week.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $userId = aw_resolveScheduleUserId($profileId);
        $db->prepare(
            "INSERT INTO employee_schedules (tenant_id, user_id, day_of_week, shift_type, start_time, end_time, is_dayoff)
             VALUES (:tid, :uid, :dow, :st, :stm, :etm, :ido)
             ON DUPLICATE KEY UPDATE shift_type = VALUES(shift_type), start_time = VALUES(start_time), end_time = VALUES(end_time), is_dayoff = VALUES(is_dayoff)"
        )->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':uid' => $userId, ':dow' => $dayOfWeek,
            ':st' => $shiftType, ':stm' => $startTime !== '' ? $startTime : null,
            ':etm' => $endTime !== '' ? $endTime : null, ':ido' => $isDayoff,
        ]);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/schedules?success=' . urlencode('Schedule saved.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Schedule saved']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiScheduleBulkCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $profileId  = (int)($input['employee_id'] ?? 0);
    $shiftType  = trim((string)($input['shift_type'] ?? 'day'));
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($profileId <= 0) {
        $msg = 'Employee is required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    $validDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $starts  = $input['start'] ?? [];
    $ends    = $input['end'] ?? [];
    $dayoffs = $input['dayoff'] ?? [];
    $replace = ($input['replace'] ?? '') === '1';

    try {
        $db = aw_db();
        $userId = aw_resolveScheduleUserId($profileId);
        $tid = app()->tenant()->current() ?? '';

        // Collect days that have data (time filled or dayoff checked)
        $activeDays = [];
        foreach ($validDays as $day) {
            $st = trim((string)($starts[$day] ?? ''));
            $et = trim((string)($ends[$day] ?? ''));
            $off = (int)(($dayoffs[$day] ?? '') === '1');
            if ($st !== '' || $et !== '' || $off === 1) {
                $activeDays[$day] = ['start' => $st, 'end' => $et, 'dayoff' => $off];
            }
        }

        // If replacing, delete all existing rows for this employee first
        if ($replace) {
            $db->prepare("DELETE FROM employee_schedules WHERE user_id = :uid")->execute([':uid' => $userId]);
        }

        if (empty($activeDays)) {
            $msg = 'No days configured. Fill in times or check Day Off for at least one day.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO employee_schedules (tenant_id, user_id, day_of_week, shift_type, start_time, end_time, is_dayoff)
             VALUES (:tid, :uid, :dow, :st, :stm, :etm, :ido)
             ON DUPLICATE KEY UPDATE shift_type = VALUES(shift_type), start_time = VALUES(start_time), end_time = VALUES(end_time), is_dayoff = VALUES(is_dayoff)"
        );
        $count = 0;
        foreach ($activeDays as $day => $d) {
            $stmt->execute([
                ':tid' => $tid, ':uid' => $userId, ':dow' => $day,
                ':st' => $shiftType,
                ':stm' => $d['start'] !== '' ? $d['start'] . ':00' : null,
                ':etm' => $d['end'] !== '' ? $d['end'] . ':00' : null,
                ':ido' => $d['dayoff'],
            ]);
            $count++;
        }
        // Delete rows for days that are no longer active (when not doing full replace)
        if (!$replace && count($activeDays) < 7) {
            $activeDayList = array_keys($activeDays);
            $placeholders = implode(',', array_fill(0, count($activeDayList), '?'));
            if ($placeholders !== '') {
                $db->prepare("DELETE FROM employee_schedules WHERE user_id = ? AND day_of_week NOT IN ({$placeholders})")
                   ->execute(array_merge([$userId], $activeDayList));
            }
        }
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/schedules?success=' . urlencode("{$count} schedule(s) saved."));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => "{$count} schedule(s) saved.", 'count' => $count]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/schedules?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
