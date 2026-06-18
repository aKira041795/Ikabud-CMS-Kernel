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
        $tid = app()->tenant()->current() ?? '';
        $stmt = $db->prepare("SELECT * FROM office_locations WHERE location_id = :id AND tenant_id = :tid LIMIT 1");
        $stmt->execute([':id' => $id, ':tid' => $tid]);
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
        $tid = app()->tenant()->current() ?? '';

        // Prevent duplicate location names within the same tenant
        $dup = $db->prepare("SELECT location_id FROM office_locations WHERE tenant_id = :tid AND name = :name LIMIT 1");
        $dup->execute([':tid' => $tid, ':name' => $name]);
        if ($dup->fetch()) {
            $msg = 'A location with this name already exists.';
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/create?error=' . urlencode($msg)); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $msg]);
            return;
        }

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
        $tid = app()->tenant()->current() ?? '';
        // Verify the location belongs to this tenant
        $check = $db->prepare("SELECT location_id FROM office_locations WHERE location_id = :id AND tenant_id = :tid LIMIT 1");
        $check->execute([':id' => $id, ':tid' => $tid]);
        if (!$check->fetch()) {
            if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations?error=' . urlencode('Location not found.')); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Location not found']);
            return;
        }
        // Prevent duplicate location names (excluding current record)
        if (isset($vals[':name'])) {
            $dup = $db->prepare("SELECT location_id FROM office_locations WHERE tenant_id = :tid AND name = :name AND location_id != :lid LIMIT 1");
            $dup->execute([':tid' => $tid, ':name' => $vals[':name'], ':lid' => $id]);
            if ($dup->fetch()) {
                $msg = 'A location with this name already exists.';
                if ($isFormPost) { header('Location: ' . $base . '/admin/wage/locations/' . $id . '?error=' . urlencode($msg)); exit; }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $msg]);
                return;
            }
        }
        $db->prepare("UPDATE office_locations SET " . implode(', ', $fields) . " WHERE location_id = :id AND tenant_id = :tid2")->execute($vals + [':tid2' => $tid]);
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
        $tid = app()->tenant()->current() ?? '';
        $db->prepare("DELETE FROM office_locations WHERE location_id = :id AND tenant_id = :tid")->execute([':id' => $id, ':tid' => $tid]);
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

// ── Change Password ──

function wageApiChangePassword(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $user = attendanceWageUser();
    $userId = (int)($user['id'] ?? 0);
    $base = awBaseUrl();

    $current = (string)($_POST['current_password'] ?? '');
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($current === '' || $newPass === '' || $confirm === '') {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('All password fields are required.'));
        exit;
    }
    if ($newPass !== $confirm) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('New passwords do not match.'));
        exit;
    }
    if (strlen($newPass) < 8) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Password must be at least 8 characters.'));
        exit;
    }

    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT password_hash FROM attendance_wage_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Current password is incorrect.'));
            exit;
        }
        $db->prepare("UPDATE attendance_wage_users SET password_hash = :ph WHERE id = :id")
           ->execute([':ph' => password_hash($newPass, PASSWORD_BCRYPT), ':id' => $userId]);
        header('Location: ' . $base . '/admin/wage/settings?success=' . urlencode('Password updated.'));
        exit;
    } catch (\Throwable $e) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Add User ──

function wageApiAddUser(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $base = awBaseUrl();

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = trim((string)($_POST['role'] ?? 'employee'));

    if ($fullName === '' || $username === '' || $email === '' || $password === '') {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('All fields are required.'));
        exit;
    }
    if (strlen($password) < 8) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Password must be at least 8 characters.'));
        exit;
    }
    if (!in_array($role, ['admin', 'supervisor', 'employee'], true)) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Invalid role.'));
        exit;
    }

    try {
        $db = aw_db();
        $db->prepare(
            "INSERT INTO attendance_wage_users (username, email, password_hash, full_name, role, is_active)
             VALUES (:u, :e, :ph, :fn, :role, 1)"
        )->execute([
            ':u'    => $username,
            ':e'    => $email,
            ':ph'   => password_hash($password, PASSWORD_BCRYPT),
            ':fn'   => $fullName,
            ':role' => $role,
        ]);
        header('Location: ' . $base . '/admin/wage/settings?success=' . urlencode('User ' . $fullName . ' created.'));
        exit;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uq_') !== false) {
            $msg = 'Username or email already exists.';
        }
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode($msg));
        exit;
    }
}

// ── Update User Role ──

function wageApiUpdateRole(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $base = awBaseUrl();
    $userId = (int)($_POST['user_id'] ?? 0);
    $role   = trim((string)($_POST['role'] ?? ''));

    if ($userId <= 0 || !in_array($role, ['admin', 'supervisor', 'employee'], true)) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Invalid request.'));
        exit;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE attendance_wage_users SET role = :role WHERE id = :id")->execute([':role' => $role, ':id' => $userId]);
        header('Location: ' . $base . '/admin/wage/settings?success=' . urlencode('Role updated.'));
        exit;
    } catch (\Throwable $e) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Toggle User Active/Inactive ──

function wageApiToggleUser(array $params = []): void
{
    attendanceWageGuard('attendance_wage.admin@1');
    $base = awBaseUrl();
    $userId = (int)($_POST['user_id'] ?? 0);
    $currentUser = attendanceWageUser();
    $currentId = (int)($currentUser['id'] ?? 0);

    if ($userId <= 0) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Invalid user.'));
        exit;
    }
    if ($userId === $currentId) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode('Cannot deactivate your own account.'));
        exit;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE attendance_wage_users SET is_active = NOT is_active WHERE id = :id")->execute([':id' => $userId]);
        header('Location: ' . $base . '/admin/wage/settings?success=' . urlencode('User status toggled.'));
        exit;
    } catch (\Throwable $e) {
        header('Location: ' . $base . '/admin/wage/settings?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Profile Change Password (all roles) ──

function wageApiProfileChangePassword(array $params = []): void
{
    attendanceWageGuard('attendance_wage.clock@1');
    $user = attendanceWageUser();
    $userId = aw_extractUserId($user);
    $base = awBaseUrl();

    $current = (string)($_POST['current_password'] ?? '');
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($current === '' || $newPass === '' || $confirm === '') {
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode('All password fields are required.'));
        exit;
    }
    if ($newPass !== $confirm) {
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode('New passwords do not match.'));
        exit;
    }
    if (strlen($newPass) < 8) {
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode('Password must be at least 8 characters.'));
        exit;
    }

    try {
        $db = aw_db();
        $stmt = $db->prepare("SELECT password_hash FROM attendance_wage_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash'])) {
            header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode('Current password is incorrect.'));
            exit;
        }
        $db->prepare("UPDATE attendance_wage_users SET password_hash = :ph WHERE id = :id")
           ->execute([':ph' => password_hash($newPass, PASSWORD_BCRYPT), ':id' => $userId]);
        header('Location: ' . $base . '/admin/wage/profile?success=' . urlencode('Password updated.'));
        exit;
    } catch (\Throwable $e) {
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// ── Profile Update (all roles, username not editable) ──

function wageApiProfileUpdate(array $params = []): void
{
    attendanceWageGuard('attendance_wage.clock@1');
    $user = attendanceWageUser();
    $userId = aw_extractUserId($user);
    $base = awBaseUrl();

    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));

    if ($fullName === '' || $email === '') {
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode('Full name and email are required.'));
        exit;
    }

    try {
        $db = aw_db();
        $db->prepare("UPDATE attendance_wage_users SET full_name = :fn, email = :em WHERE id = :id")
           ->execute([':fn' => $fullName, ':em' => $email, ':id' => $userId]);
        header('Location: ' . $base . '/admin/wage/profile?success=' . urlencode('Profile updated.'));
        exit;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uq_') !== false) {
            $msg = 'Email already in use by another account.';
        }
        header('Location: ' . $base . '/admin/wage/profile?error=' . urlencode($msg));
        exit;
    }
}
