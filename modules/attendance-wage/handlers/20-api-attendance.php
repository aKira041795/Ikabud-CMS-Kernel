<?php
declare(strict_types=1);

function attendanceApiClockIn(array $params = []): void
{
    $user = app()->user();
    $userId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    if (!$userId) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); return; }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT attendance_id FROM attendance_records WHERE user_id=:uid AND DATE(clock_in)=CURDATE() AND clock_out IS NULL LIMIT 1");
        $stmt->execute([':uid'=>$userId]);
        if ($stmt->fetch()) { header("Content-Type: application/json; charset=utf-8"); echo json_encode(['ok'=>false,'error'=>'Already clocked in']); return; }
        $stmt = $db->prepare("INSERT INTO attendance_records (tenant_id, user_id, clock_in, status) VALUES (:tid, :uid, NOW(), 'active')");
        $stmt->execute([':tid'=>app()->tenant()->current()??'', ':uid'=>$userId]);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(['ok'=>true,'message'=>'Clocked in','id'=>(int)$db->lastInsertId()]);
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
    http_response_code(404);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['ok'=>false,'error'=>'Photo not found']);
}
