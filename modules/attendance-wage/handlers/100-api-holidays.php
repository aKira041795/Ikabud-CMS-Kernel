<?php
declare(strict_types=1);
/**
 * Holiday calendar API handlers.
 */
function wageApiHolidaysList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.read@1');
    try {
        $db = aw_db();
        $year = (int)($params['year'] ?? date('Y'));
        $stmt = $db->prepare("SELECT holiday_id AS id, holiday_name, holiday_date, holiday_type, pay_multiplier, is_recurring, is_active FROM holidays WHERE tenant_id = :tid AND YEAR(holiday_date) = :yr AND is_active = 1 ORDER BY holiday_date ASC");
        $stmt->execute([':tid' => app()->tenant()->current() ?? '', ':yr' => $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
function wageApiHolidayCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $name = trim((string)($input['holiday_name'] ?? ''));
    $date = trim((string)($input['holiday_date'] ?? ''));
    $type = trim((string)($input['holiday_type'] ?? 'regular'));
    $multiplier = (float)($input['pay_multiplier'] ?? 0);
    $recurring = (int)($input['is_recurring'] ?? 0);
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($name === '' || $date === '') {
        $msg = 'Holiday name and date are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    if ($multiplier <= 0) {
        $multiplier = match ($type) {
            'regular' => 2.00,
            'special_non_working' => 1.30,
            'special_working' => 1.00,
            default => 1.00,
        };
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare(
            "INSERT INTO holidays (tenant_id, holiday_name, holiday_date, holiday_type, pay_multiplier, is_recurring, is_active)
             VALUES (:tid, :name, :date, :type, :mult, :rec, 1)
             ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), holiday_type = VALUES(holiday_type), pay_multiplier = VALUES(pay_multiplier), is_recurring = VALUES(is_recurring), is_active = 1"
        );
        $stmt->execute([
            ':tid' => app()->tenant()->current() ?? '',
            ':name' => $name, ':date' => $date, ':type' => $type,
            ':mult' => $multiplier, ':rec' => $recurring,
        ]);
        $id = (int)$db->lastInsertId();
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/holidays?success=' . urlencode('Holiday added.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Holiday created', 'id' => $id]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiHolidayUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();
    if ($id <= 0) {
        $msg = 'Missing holiday ID.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $fields = [];
        $vals = [':id' => $id];
        foreach (['holiday_name', 'holiday_date', 'holiday_type', 'pay_multiplier', 'is_recurring'] as $f) {
            if (isset($input[$f])) { $fields[] = "`{$f}` = :{$f}"; $vals[":{$f}"] = trim((string)$input[$f]); }
        }
        if (empty($fields)) {
            $msg = 'No fields to update.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }
        $db->prepare("UPDATE holidays SET " . implode(', ', $fields) . " WHERE holiday_id = :id")->execute($vals);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/holidays?success=' . urlencode('Holiday updated.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Holiday updated']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiHolidayDelete(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? $_POST['id'] ?? 0);
    $isFormPost = $_SERVER['REQUEST_METHOD'] === 'POST' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $base = awBaseUrl();
    if ($id <= 0) {
        $msg = 'Missing holiday ID.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    try {
        $db = aw_db();
        $db->prepare("DELETE FROM holidays WHERE holiday_id = :id")->execute([':id' => $id]);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/holidays?success=' . urlencode('Holiday deleted.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Holiday deleted']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/holidays?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
