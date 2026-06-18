<?php
declare(strict_types=1);

function attendanceApiClockIn(array $params = []): void
{
    $user = app()->user();
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    if (!$userId) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $latitude  = (float)($input['latitude'] ?? 0);
    $longitude = (float)($input['longitude'] ?? 0);

    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT attendance_id FROM attendance_records WHERE user_id=:uid AND DATE(clock_in)=CURDATE() AND clock_out IS NULL LIMIT 1");
        $stmt->execute([':uid'=>$userId]);
        if ($stmt->fetch()) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Already clocked in']); return; }

        // Geo-fence check: if lat/lng provided, verify within an office location radius
        $locationName = null;
        $locationId   = null;
        if ($latitude !== 0.0 && $longitude !== 0.0) {
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

        $locationIn = $locationName ? ($locationName . ' (' . $latitude . ',' . $longitude . ')') : null;
        $stmt = $db->prepare("INSERT INTO attendance_records (tenant_id, user_id, clock_in, status, location_in) VALUES (:tid, :uid, NOW(), 'active', :loc)");
        $stmt->execute([':tid'=>app()->tenant()->current()??'', ':uid'=>$userId, ':loc'=>$locationIn]);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>true,'message'=>'Clocked in','id'=>(int)$db->lastInsertId(),'location'=>$locationName]);
    } catch (\Throwable $e) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
}

function attendanceApiClockOut(array $params = []): void
{
    $user = app()->user();
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    if (!$userId) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT attendance_id FROM attendance_records WHERE user_id=:uid AND DATE(clock_in)=CURDATE() AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
        $stmt->execute([':uid'=>$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not clocked in']); return; }
        $db->prepare("UPDATE attendance_records SET clock_out=NOW(), status='completed' WHERE attendance_id=:id")->execute([':id'=>(int)$row['attendance_id']]);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>true,'message'=>'Clocked out']);
    } catch (\Throwable $e) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
}

function attendanceApiRecords(array $params = []): void
{
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
        '/var/www/html/applicationostest/storage/uploads/attendance/' . $file,
        '/var/www/html/applicationostest/public/uploads/attendance/' . $file,
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
