<?php

declare(strict_types=1);

/**
 * Office location API handlers — CRUD + geo-fence management.
 */

function wageApiLocationsList(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    try {
        $db = aw_db();
        $tid = app()->tenant()->current() ?? '';
        $stmt = $db->prepare("SELECT location_id AS id, name, address, latitude, longitude, radius_meters, is_active, created_at FROM office_locations WHERE tenant_id = :tid ORDER BY name ASC");
        $stmt->execute([':tid' => $tid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiLocationGet(array $params = []): void
{
    attendanceWageGuard('attendance_wage.manage@1');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Missing location ID']);
        return;
    }
    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT * FROM office_locations WHERE location_id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $row ?: null]);
    } catch (\Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiLocationCreate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    $name    = trim((string)($input['name'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $lat     = (float)($input['latitude'] ?? 0);
    $lng     = (float)($input['longitude'] ?? 0);
    $radius  = (int)($input['radius_meters'] ?? 100);

    if ($name === '' || $lat === 0.0 || $lng === 0.0) {
        $msg = 'Name, latitude, and longitude are required.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/create?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }
    if ($radius < 10 || $radius > 10000) {
        $msg = 'Radius must be between 10 and 10,000 meters.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/create?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    try {
        $db = aw_db();
        $db->prepare(
            "INSERT INTO office_locations (tenant_id, name, address, latitude, longitude, radius_meters)
             VALUES (:tid, :name, :addr, :lat, :lng, :radius)"
        )->execute([
            ':tid'    => app()->tenant()->current() ?? '',
            ':name'   => $name,
            ':addr'   => $address !== '' ? $address : null,
            ':lat'    => $lat,
            ':lng'    => $lng,
            ':radius' => $radius,
        ]);
        $id = (int)$db->lastInsertId();
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/locations?success=' . urlencode('Location created.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Location created', 'id' => $id]);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/create?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiLocationUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? 0);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    if ($id <= 0) {
        $msg = 'Missing location ID.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    $fields = [];
    $vals   = [':id' => $id];
    foreach (['name', 'address', 'latitude', 'longitude', 'radius_meters', 'is_active'] as $f) {
        if (isset($input[$f])) {
            $fields[] = "`{$f}` = :{$f}";
            $vals[":{$f}"] = in_array($f, ['latitude', 'longitude']) ? (float)$input[$f] : (is_numeric($input[$f]) ? (int)$input[$f] : trim((string)$input[$f]));
        }
    }

    if (isset($vals[':radius_meters']) && ((int)$vals[':radius_meters'] < 10 || (int)$vals[':radius_meters'] > 10000)) {
        $msg = 'Radius must be between 10 and 10,000 meters.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/' . $id . '?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    if (empty($fields)) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/' . $id . '?error=' . urlencode('No fields to update')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No fields to update']);
        return;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE office_locations SET " . implode(', ', $fields) . " WHERE location_id = :id")->execute($vals);
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations?success=' . urlencode('Location updated.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Location updated']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/' . $id . '?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

function wageApiLocationDelete(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $id = (int)($params['id'] ?? $_POST['id'] ?? 0);
    $isFormPost = $_SERVER['REQUEST_METHOD'] === 'POST' && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    $base = awBaseUrl();

    if ($id <= 0) {
        $msg = 'Missing location ID.';
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations?error=' . urlencode($msg)); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
        return;
    }

    try {
        $db = aw_db();
        $db->prepare("DELETE FROM office_locations WHERE location_id = :id")->execute([':id' => $id]);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/locations?success=' . urlencode('Location deleted.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Location deleted']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── Module settings ──

function wageApiSettingsSave(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($contentType, 'application/json') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_POST;
    $isFormPost = !str_contains($contentType, 'application/json');
    $base = awBaseUrl();

    $data = [];
    if (isset($input['google_maps_api_key'])) {
        $data['google_maps_api_key'] = trim((string)$input['google_maps_api_key']);
    }

    if (empty($data)) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('No settings to save.')); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No settings to save.']);
        return;
    }

    try {
        saveModuleSettings('attendance-wage', $data);
        if ($isFormPost) {
            header('Location: ' . $base . '/admin/wage/settings?success=' . urlencode('Settings saved.'));
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Settings saved.']);
    } catch (\Throwable $e) {
        if ($isFormPost) { header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode($e->getMessage())); exit; }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
