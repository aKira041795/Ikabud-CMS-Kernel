<?php
/**
 * User Route Handlers
 * 
 * @package Guidance\Routes
 */

function usersHasPhoneColumn(PDO $db): bool {
    static $hasPhone = [];
    $tid = app()->tenant()->current();

    if (array_key_exists($tid, $hasPhone)) {
        return $hasPhone[$tid];
    }

    try {
        $stmt = $db->query("SHOW COLUMNS FROM gm_users LIKE 'phone'");
        $hasPhone[$tid] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasPhone[$tid] = false;
    }

    return $hasPhone[$tid];
}

function apiGuidanceListUsers(): void {
    $currentUser = guidanceUser();
    $isAdmin = ($currentUser['role'] ?? '') === 'admin';
    $db = guidanceDb();
    
    $where = ["deleted_at IS NULL"];
    $params = [];
    
    if (!$isAdmin) {
        $where[] = "id = ?";
        $params[] = $currentUser['sub'];
    } else {
        if (!empty($_GET['user_search'])) {
            $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR CONCAT(first_name,' ',last_name) LIKE ?)";
            $s = '%' . $_GET['user_search'] . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        if (!empty($_GET['user_role'])) {
            $where[] = "role = ?";
            $params[] = $_GET['user_role'];
        }
    }
    $whereStr = implode(' AND ', $where);
    
    $stmt = $db->prepare("SELECT id, email, first_name, last_name, role, is_active, created_at FROM gm_users WHERE {$whereStr} ORDER BY created_at DESC");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $caStmt = $db->query("
        SELECT ca.counselor_id, c.code, c.name 
        FROM gm_counselor_assignments ca 
        JOIN gm_colleges c ON ca.college_id = c.id 
        WHERE ca.is_active = 1 AND c.is_active = 1
        ORDER BY c.sort_order
    ");
    $allAssignments = $caStmt->fetchAll(PDO::FETCH_ASSOC);
    $collegeMap = [];
    foreach ($allAssignments as $a) {
        $collegeMap[$a['counselor_id']][] = $a['code'];
    }
    
    $roleStats = ['total' => count($users), 'admin' => 0, 'counselor' => 0, 'supervisor' => 0];
    foreach ($users as &$u) {
        $u['colleges'] = $collegeMap[$u['id']] ?? [];
        $u['colleges_display'] = implode(', ', $u['colleges']);
    }
    unset($u);
    if ($isAdmin) {
        $statStmt = $db->query("SELECT role, COUNT(*) as cnt FROM gm_users WHERE deleted_at IS NULL GROUP BY role");
        foreach ($statStmt->fetchAll(PDO::FETCH_ASSOC) as $rs) {
            $roleStats[$rs['role']] = (int) $rs['cnt'];
        }
        $roleStats['total'] = array_sum(array_filter($roleStats, 'is_int'));
    }
    
    if (app()->isHtmx()) {
        echo guidanceRender('partials/users-table.disyl', [
            'users' => $users,
            'stats' => $roleStats,
            'result_count' => count($users),
            'current_user_id' => $currentUser['sub'],
            'is_admin' => $isAdmin,
        ]);
    } else {
        app()->json(['success' => true, 'data' => $users]);
    }
}

function apiGuidanceGetUser(string $id): void {
    app()->requireRole('admin');
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT id, email, first_name, last_name, role, created_at FROM gm_users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        app()->json(['error' => 'User not found'], 404);
    }
    app()->json(['success' => true, 'data' => $user]);
}

function apiGuidanceCreateUser(): void {
    $currentUser = app()->requireRole('admin');
    $input = app()->input();
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $role = $input['role'] ?? 'counselor';
    
    $createError = function (string $message, int $status = 400): void {
        if (app()->isHtmx()) {
            http_response_code($status);
            header('HX-Reswap: none');
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'error']]));
            echo '';
            exit;
        }
        
        app()->json(['error' => $message], $status);
    };
    
    if ($email === '' || $firstName === '' || $lastName === '') {
        $createError('Email, first name, and last name are required', 400);
    }
    
    if (!isValidRole($role)) {
        $createError('Invalid role. Must be admin, supervisor, or counselor.', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $createError('Invalid email address', 400);
    }
    
    $db = guidanceDb();
    $hasPhoneColumn = usersHasPhoneColumn($db);
    
    $password = $input['password'] ?? '';
    if (empty($password)) {
        $password = bin2hex(random_bytes(4)) . 'A1!';
    }
    $pwError = validatePasswordStrength($password);
    if ($pwError) {
        $createError($pwError, 400);
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $userId = null;
    $restoredUser = false;

    try {
        $db->beginTransaction();

        $existingStmt = $db->prepare("SELECT id, deleted_at FROM gm_users WHERE email = ? LIMIT 1");
        $existingStmt->execute([$email]);
        $existingUser = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser && empty($existingUser['deleted_at'])) {
            $db->rollBack();
            $createError('A user with this email already exists', 409);
        }

        if ($existingUser) {
            if ($hasPhoneColumn) {
                $restoreStmt = $db->prepare("
                    UPDATE gm_users
                    SET email = ?, password = ?, first_name = ?, last_name = ?, phone = ?, role = ?, is_active = 1, deleted_at = NULL
                    WHERE id = ?
                ");
                $restoreStmt->execute([$email, $hashedPassword, $firstName, $lastName, $phone !== '' ? $phone : null, $role, $existingUser['id']]);
            } else {
                $restoreStmt = $db->prepare("
                    UPDATE gm_users
                    SET email = ?, password = ?, first_name = ?, last_name = ?, role = ?, is_active = 1, deleted_at = NULL
                    WHERE id = ?
                ");
                $restoreStmt->execute([$email, $hashedPassword, $firstName, $lastName, $role, $existingUser['id']]);
            }
            $userId = (string) $existingUser['id'];
            $restoredUser = true;
        } else {
            if ($hasPhoneColumn) {
                $stmt = $db->prepare("INSERT INTO gm_users (email, password, first_name, last_name, phone, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$email, $hashedPassword, $firstName, $lastName, $phone !== '' ? $phone : null, $role]);
            } else {
                $stmt = $db->prepare("INSERT INTO gm_users (email, password, first_name, last_name, role, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$email, $hashedPassword, $firstName, $lastName, $role]);
            }
            $userId = $db->lastInsertId();
        }

        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        if ($e->getCode() === '23000') {
            $createError('A user with this email already exists', 409);
        }

        app()->log('User create error: ' . $e->getMessage(), 'error');
        $createError('Failed to create user', 500);
    }

    if (!$restoredUser) {
        try {
            fireModuleHook('user.created', [
                'user_id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'created_by' => $currentUser['sub'],
            ]);
        } catch (Throwable $e) {
            app()->log('User created hook error: ' . $e->getMessage(), 'error');
        }
    }
    
    if (app()->isHtmx()) {
        $message = $restoredUser ? 'User restored successfully' : 'User created successfully';
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $message, 'type' => 'success'], 'refreshUsers' => true]));
        echo '';
    } else {
        app()->json(['success' => true, 'id' => $userId, 'restored' => $restoredUser]);
    }
}

function apiGuidanceUpdateUser(string $id): void {
    $currentUser = guidanceUser();
    $isAdmin = ($currentUser['role'] ?? '') === 'admin';
    $isSelf = ((string)$id === (string)$currentUser['sub']);
    $input = app()->input();
    
    if (!$isAdmin && !$isSelf) {
        app()->json(['error' => 'Access denied'], 403);
    }
    
    $updateError = function(string $msg, int $code = 400) {
        if (app()->isHtmx()) {
            http_response_code($code);
            header('HX-Reswap: none');
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => $msg, 'type' => 'error']]));
            echo '';
            exit;
        }
        app()->json(['error' => $msg], $code);
    };
    
    $db = guidanceDb();
    $hasPhoneColumn = usersHasPhoneColumn($db);
    $updates = [];
    $values = [];
    
    if (!empty($input['email']) && $isAdmin) {
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $updateError('Invalid email address');
        }
        $dupStmt = $db->prepare("SELECT id FROM gm_users WHERE email = ? AND id != ? AND deleted_at IS NULL");
        $dupStmt->execute([$input['email'], $id]);
        if ($dupStmt->fetchColumn()) {
            $updateError('A user with this email already exists', 409);
        }
        $updates[] = 'email = ?';
        $values[] = $input['email'];
    }
    if (!empty($input['first_name'])) {
        $updates[] = 'first_name = ?';
        $values[] = $input['first_name'];
    }
    if (!empty($input['last_name'])) {
        $updates[] = 'last_name = ?';
        $values[] = $input['last_name'];
    }
    if ($hasPhoneColumn && array_key_exists('phone', $input)) {
        $updates[] = 'phone = ?';
        $values[] = $input['phone'] ?: null;
    }
    if (!empty($input['role']) && $isAdmin) {
        if (!isValidRole($input['role'])) {
            $updateError('Invalid role');
        }
        if ($isSelf && $input['role'] !== 'admin') {
            $updateError('You cannot change your own role');
        }
        $updates[] = 'role = ?';
        $values[] = $input['role'];
    }
    $password = trim($input['password'] ?? '');
    if ($password !== '') {
        $pwError = validatePasswordStrength($password);
        if ($pwError) {
            $updateError($pwError);
        }
        $updates[] = 'password = ?';
        $values[] = password_hash($password, PASSWORD_DEFAULT);
    }
    
    if (!empty($updates)) {
        $values[] = $id;
        $stmt = $db->prepare("UPDATE gm_users SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($values);
    }
    
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User updated successfully', 'type' => 'success'], 'refreshUsers' => true]));
        echo '';
    } else {
        app()->json(['success' => true]);
    }
}

function apiGuidanceDeleteUser(string $id): void {
    $currentAdmin = app()->requireRole('admin');
    
    if ((string)$id === (string)$currentAdmin['sub']) {
        if (app()->isHtmx()) {
            header('HX-Reswap: none');
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'You cannot delete your own account', 'type' => 'error']]));
            echo '';
        } else {
            app()->json(['error' => 'You cannot delete your own account'], 400);
        }
        return;
    }
    
    $db = guidanceDb();
    $stmt = $db->prepare("UPDATE gm_users SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'User deleted', 'type' => 'success'], 'refreshUsers' => true]));
        echo '';
    } else {
        app()->json(['success' => true]);
    }
}

function apiGuidanceToggleUserActive(string $id): void {
    $currentAdmin = app()->requireRole('admin');
    
    if ((string)$id === (string)$currentAdmin['sub']) {
        if (app()->isHtmx()) {
            header('HX-Reswap: none');
            header('HX-Trigger: ' . json_encode(['showToast' => ['message' => 'You cannot deactivate your own account', 'type' => 'error']]));
            echo '';
        } else {
            app()->json(['error' => 'You cannot deactivate your own account'], 400);
        }
        return;
    }
    
    $db = guidanceDb();
    $stmt = $db->prepare("SELECT is_active FROM gm_users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        app()->json(['error' => 'User not found'], 404);
        return;
    }
    $newStatus = $row['is_active'] ? 0 : 1;
    $db->prepare("UPDATE gm_users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
    $label = $newStatus ? 'activated' : 'deactivated';
    
    if (app()->isHtmx()) {
        header('HX-Trigger: ' . json_encode(['showToast' => ['message' => "User {$label} successfully", 'type' => 'success'], 'refreshUsers' => true]));
        echo '';
    } else {
        app()->json(['success' => true, 'is_active' => $newStatus]);
    }
}

function apiGuidanceModalEditUser(string $id): void {
    $currentUser = guidanceUser();
    $isAdmin = ($currentUser['role'] ?? '') === 'admin';
    $isSelf = ((string)$id === (string)$currentUser['sub']);
    
    if (!$isAdmin && !$isSelf) {
        http_response_code(403);
        echo '<div class="p-4 text-red-600">Access denied</div>';
        return;
    }
    
    $db = guidanceDb();
    $selectPhone = usersHasPhoneColumn($db) ? 'phone' : 'NULL AS phone';
    $stmt = $db->prepare("SELECT id, email, first_name, last_name, {$selectPhone}, role, is_active FROM gm_users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editUser) {
        echo '<div class="p-4 text-red-600">User not found</div>';
        return;
    }
    $colleges = [];
    $assignedColleges = [];
    if ($isAdmin) {
        $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $assignedStmt = $db->prepare("SELECT college_id FROM gm_counselor_assignments WHERE counselor_id = ? AND is_active = 1");
        $assignedStmt->execute([$editUser['id']]);
        $assignedColleges = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    echo guidanceRender('modals/user-form.disyl', [
        'user' => $editUser,
        'colleges' => $colleges,
        'assigned_colleges' => $assignedColleges,
        'assigned_colleges_json' => json_encode(array_map('intval', $assignedColleges)),
        'is_self' => $isSelf,
        'is_admin' => $isAdmin,
    ]);
}

function apiGuidanceModalNewUser(): void {
    app()->requireRole('admin');
    $db = guidanceDb();
    $colleges = $db->query("SELECT id, code, name FROM gm_colleges WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    echo guidanceRender('modals/user-form.disyl', ['user' => [], 'colleges' => $colleges, 'assigned_colleges' => [], 'assigned_colleges_json' => '[]', 'is_admin' => true, 'is_self' => false]);
}
