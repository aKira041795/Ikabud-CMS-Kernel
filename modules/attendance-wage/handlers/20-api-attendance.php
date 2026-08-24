<?php
declare(strict_types=1);

function attendanceApiClockIn(array $params = []): void
{
    $user = attendanceWageUser();
    $userId = is_array($user) ? aw_extractUserId($user) : 0;
    if (!$userId) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }
    // CSRF enforcement
    app()->csrfEnforce();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $latitude  = (float)($input['latitude'] ?? 0);
    $longitude = (float)($input['longitude'] ?? 0);

    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT attendance_id FROM attendance_records WHERE user_id=:uid AND DATE(clock_in)=CURDATE() AND clock_out IS NULL LIMIT 1");
        $stmt->execute([':uid'=>$userId]);
        if ($stmt->fetch()) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Already clocked in']); return; }

        // Geo-fence check: if BOTH lat AND lng provided, verify within an office location radius.
        // If no office locations exist at all, skip geo-fence (auto-pass).
        $locationName = null;
        $locationId   = null;
        if ($latitude !== 0.0 && $longitude !== 0.0) {
            $tid = app()->tenant()->current() ?? '';
            $locCount = (int)$db->query("SELECT COUNT(*) FROM office_locations WHERE tenant_id = '{$tid}' AND is_active = 1")->fetchColumn();
            if ($locCount === 0) {
                // No office locations configured — allow clock-in without geo-fence
                $locationName = null;
            } else {
                $matched = aw_findLocationByGeo($latitude, $longitude);
                if ($matched) {
                    $locationName = $matched['name'] ?? null;
                    $locationId   = (int)($matched['location_id'] ?? 0);
                } else {
                    header("Content-Type: application/json; charset=utf-8");
                    echo json_encode(['ok'=>false,'error'=>'You are outside all office locations. Clock-in requires you to be within an office geo-fence.']);
                    return;
                }
            }
        }

        $locationIn = $locationName ? ($locationName . ' (' . $latitude . ',' . $longitude . ')') : null;
        $hasLatLng = aw_hasColumn($db, 'attendance_records', 'latitude_in');
        if ($hasLatLng) {
            $stmt = $db->prepare("INSERT INTO attendance_records (tenant_id, user_id, clock_in, status, location_in, latitude_in, longitude_in) VALUES (:tid, :uid, NOW(), 'active', :loc, :lat, :lng)");
            $stmt->execute([':tid'=>app()->tenant()->current()??'', ':uid'=>$userId, ':loc'=>$locationIn, ':lat'=>($latitude !== 0.0 ? $latitude : null), ':lng'=>($longitude !== 0.0 ? $longitude : null)]);
        } else {
            $stmt = $db->prepare("INSERT INTO attendance_records (tenant_id, user_id, clock_in, status, location_in) VALUES (:tid, :uid, NOW(), 'active', :loc)");
            $stmt->execute([':tid'=>app()->tenant()->current()??'', ':uid'=>$userId, ':loc'=>$locationIn]);
        }
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>true,'message'=>'Clocked in','id'=>(int)$db->lastInsertId(),'location'=>$locationName]);
    } catch (\Throwable $e) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Clock-in failed. Please try again.']); }
}

function attendanceApiClockOut(array $params = []): void
{
    $user = attendanceWageUser();
    $userId = is_array($user) ? aw_extractUserId($user) : 0;
    if (!$userId) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }
    app()->csrfEnforce();
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT attendance_id FROM attendance_records WHERE user_id=:uid AND DATE(clock_in)=CURDATE() AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
        $stmt->execute([':uid'=>$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not clocked in']); return; }
        $db->prepare("UPDATE attendance_records SET clock_out=NOW(), status='completed' WHERE attendance_id=:id")->execute([':id'=>(int)$row['attendance_id']]);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>true,'message'=>'Clocked out']);
    } catch (\Throwable $e) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Clock-out failed. Please try again.']); }
}

function attendanceApiAdminRecordCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $input = awInputJSON();
    $profileId = (int)($input['employee_profile_id'] ?? 0);
    $clockIn = trim((string)($input['clock_in'] ?? ''));
    $clockOut = trim((string)($input['clock_out'] ?? ''));
    if ($profileId <= 0 || $clockIn === '' || $clockOut === '') {
        awJsonOut(['ok' => false, 'error' => 'Employee, clock in, and clock out are required.'], 422);
    }
    try {
        $in = new \DateTimeImmutable($clockIn, new \DateTimeZone('Asia/Manila'));
        $out = new \DateTimeImmutable($clockOut, new \DateTimeZone('Asia/Manila'));
        if ($out <= $in || ($out->getTimestamp() - $in->getTimestamp()) > 86400) {
            awJsonOut(['ok' => false, 'error' => 'Clock out must be after clock in and within 24 hours.'], 422);
        }
        $db = aw_db();
        $tenantId = (string)aw_tenant_id();
        $profile = $db->prepare('SELECT user_id FROM employee_profiles WHERE profile_id=:pid AND tenant_id=:tid AND is_active=1 LIMIT 1');
        $profile->execute([':pid' => $profileId, ':tid' => $tenantId]);
        $userId = (int)($profile->fetchColumn() ?: 0);
        if ($userId <= 0) awJsonOut(['ok' => false, 'error' => 'Active employee not found.'], 404);
        $duplicate = $db->prepare('SELECT attendance_id FROM attendance_records WHERE tenant_id=:tid AND user_id=:uid AND clock_in=:clock_in LIMIT 1');
        $duplicate->execute([':tid' => $tenantId, ':uid' => $userId, ':clock_in' => $in->format('Y-m-d H:i:s')]);
        if ($duplicate->fetchColumn()) awJsonOut(['ok' => false, 'error' => 'An attendance record already exists for this clock-in.'], 409);
        $stmt = $db->prepare("INSERT INTO attendance_records (tenant_id,user_id,clock_in,clock_out,status,notes,last_edited_by,last_edited_at) VALUES (:tid,:uid,:clock_in,:clock_out,'edited',:notes,:by,NOW())");
        $stmt->execute([
            ':tid' => $tenantId, ':uid' => $userId,
            ':clock_in' => $in->format('Y-m-d H:i:s'), ':clock_out' => $out->format('Y-m-d H:i:s'),
            ':notes' => trim((string)($input['notes'] ?? 'Admin attendance entry')),
            ':by' => aw_currentUserId(),
        ]);
        awJsonOut(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    } catch (\Throwable $e) {
        awJsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function attendanceApiRecords(array $params = []): void
{
    $user = attendanceWageUser();
    if (!$user) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }
    $db = aw_db();
    $limit = min((int)($params['limit'] ?? 30), 100);
    $stmt = $db->query("SELECT ar.*, au.full_name FROM attendance_records ar JOIN attendance_wage_users au ON au.id=ar.user_id ORDER BY ar.clock_in DESC LIMIT {$limit}");
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['ok'=>true,'data'=>$rows]);
}

function attendanceApiPhoto(array $params = [], string $file = ''): void
{
    $file = $file !== '' ? $file : ($params['file'] ?? '');
    if ($file === '') {
        http_response_code(404);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>false,'error'=>'No file specified']);
        return;
    }
    // Security: only allow alphanumeric, dash, underscore, dot filenames
    if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $file)) {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>false,'error'=>'Invalid filename']);
        return;
    }
    $paths = [
        STORAGE_PATH . '/uploads/attendance/' . $file,
        PUBLIC_PATH . '/uploads/attendance/' . $file,
    ];
    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            $mime = mime_content_type($path) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=3600');
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['ok'=>false,'error'=>'Photo not found']);
}

function attendanceApiLogo(array $params = [], string $file = ''): void
{
    $file = $file !== '' ? $file : ($params['file'] ?? '');
    if ($file === '') {
        http_response_code(404);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>false,'error'=>'No file specified']);
        return;
    }
    if (!preg_match('/^[a-zA-Z0-9_\-.]+$/', $file)) {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>false,'error'=>'Invalid filename']);
        return;
    }
    $paths = [
        STORAGE_PATH . '/uploads/logos/' . $file,
        PUBLIC_PATH . '/uploads/logos/' . $file,
    ];
    foreach ($paths as $path) {
        if (is_file($path) && is_readable($path)) {
            $mime = mime_content_type($path) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=3600');
            readfile($path);
            exit;
        }
    }
    http_response_code(404);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['ok'=>false,'error'=>'Logo not found']);
}
