<?php

declare(strict_types=1);

/**
 * Page: User Management List
 */
function palPageUserList(): void
{
    $user = palRequireRole('admin');
    $db = palDb();
    $tid = (int)($user['tenant_id'] ?? 0);
    $stmt = $db->prepare('SELECT id, username, email, full_name, role, is_active, last_login_at, created_at FROM pal_users WHERE tenant_id = :tid ORDER BY is_active DESC, created_at DESC');
    $stmt->execute([':tid' => $tid]);
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $activeUsers = array_filter($allUsers, fn($u) => (int)$u['is_active'] === 1);
    $inactiveUsers = array_filter($allUsers, fn($u) => (int)$u['is_active'] !== 1);

    $template = __DIR__ . '/../templates/project-audit-ledger/shell.disyl';
    palRender($template, [
        'current_user' => $user,
        'page_title' => 'User Management',
        'page_content' => 'users-list',
        'active_users' => array_values($activeUsers),
        'inactive_users' => array_values($inactiveUsers),
    ]);
}

/**
 * API: Create User
 */
function palApiUserStore(): void
{
    palResponseGuard(function (): void {
        $user = palRequireRole('admin');
        palEnforceCsrf();

        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $fullName = $_POST['full_name'] ?? '';
        $role = $_POST['role'] ?? 'encoder';

        if ($username === '' || $password === '' || $fullName === '') {
            palJsonError('Username, password, and full name are required.');
            return;
        }

        if (!in_array($role, ['admin', 'supervisor', 'encoder', 'printer'], true)) {
            palJsonError('Invalid role.');
            return;
        }

        if (strlen($password) < 8) {
            palJsonError('Password must be at least 8 characters.');
            return;
        }

        $db = palDb();
        $tid = (int)($user['tenant_id'] ?? 0);
        $check = $db->prepare('SELECT id FROM pal_users WHERE tenant_id = :tid AND username = :username LIMIT 1');
        $check->execute([':tid' => $tid, ':username' => $username]);
        if ($check->fetch()) {
            palJsonError('Username already exists.');
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare(
            'INSERT INTO pal_users (tenant_id, username, email, password_hash, full_name, role, is_active, created_by)
             VALUES (:tenant_id, :username, :email, :password_hash, :full_name, :role, 1, :created_by)'
        );
        $stmt->execute([
            ':tenant_id' => (int)($user['tenant_id'] ?? 0),
            ':username' => $username,
            ':email' => $email ?: null,
            ':password_hash' => $hash,
            ':full_name' => $fullName,
            ':role' => $role,
            ':created_by' => (int)$user['id'],
        ]);

        $newId = (int)$db->lastInsertId();
        palAudit('pal.user.created', (int)$user['id'], 'pal_users', (string)$newId, null, [
            'username' => $username,
            'role' => $role,
        ]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $newId]);
    });
}

/**
 * API: Update User
 */
function palApiUserUpdate(array $routeParams = []): void
{
    palResponseGuard(function () use ($routeParams): void {
        $authUser = palRequireRole('admin');
        palEnforceCsrf();

        $userId = (int)($routeParams['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($userId <= 0) {
            palJsonError('Invalid user ID.');
            return;
        }

        $db = palDb();
        $fields = [];
        $params = [':id' => $userId];

        if (isset($_POST['email'])) {
            $fields[] = 'email = :email';
            $params[':email'] = $_POST['email'] ?: null;
        }
        if (isset($_POST['full_name'])) {
            $fields[] = 'full_name = :full_name';
            $params[':full_name'] = $_POST['full_name'];
        }
        if (isset($_POST['role'])) {
            if (!in_array($_POST['role'], ['admin', 'supervisor', 'encoder', 'printer'], true)) {
                palJsonError('Invalid role.');
                return;
            }
            $fields[] = 'role = :role';
            $params[':role'] = $_POST['role'];
        }
        if (isset($_POST['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = (int)$_POST['is_active'];
        }
        if (isset($_POST['password']) && $_POST['password'] !== '') {
            if (strlen($_POST['password']) < 8) {
                palJsonError('Password must be at least 8 characters.');
                return;
            }
            $fields[] = 'password_hash = :password_hash, token_version = token_version + 1';
            $params[':password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (empty($fields)) {
            palJsonError('No fields to update.');
            return;
        }

        $fields[] = 'updated_at = NOW()';
        $params[':tenant_id'] = (int)($authUser['tenant_id'] ?? 0);
        $sql = 'UPDATE pal_users SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        palAudit('pal.user.updated', (int)$authUser['id'], 'pal_users', (string)$userId, null, [
            'updated_fields' => array_keys($params),
        ]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    });
}

/**
 * API: Toggle user active/inactive (soft delete/reactivate)
 */
function palApiUserDelete(array $routeParams = []): void
{
    palResponseGuard(function () use ($routeParams): void {
        $authUser = palRequireRole('admin');
        palEnforceCsrf();

        $userId = (int)($routeParams['id'] ?? $_GET['id'] ?? $_POST['id'] ?? 0);
        if ($userId <= 0) {
            palJsonError('Invalid user ID.');
            return;
        }

        // Prevent self-deactivation
        if ($userId === (int)$authUser['id']) {
            palJsonError('You cannot deactivate your own account.');
            return;
        }

        $db = palDb();
        $tid = (int)$authUser['tenant_id'] ?? 0;

        // Get current status
        $stmt = $db->prepare('SELECT id, is_active FROM pal_users WHERE tenant_id = :tid AND id = :id LIMIT 1');
        $stmt->execute([':tid' => $tid, ':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            palJsonError('User not found.');
            return;
        }

        $newStatus = (int)$row['is_active'] === 1 ? 0 : 1;
        $action = $newStatus === 1 ? 'restored' : 'deactivated';

        $stmt = $db->prepare('UPDATE pal_users SET is_active = :is_active, updated_at = NOW() WHERE id = :id AND tenant_id = :tid');
        $stmt->execute([':is_active' => $newStatus, ':id' => $userId, ':tid' => $tid]);

        palAudit('pal.user.' . $action, (int)$authUser['id'], 'pal_users', (string)$userId, null, []);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'is_active' => $newStatus, 'action' => $action]);
    });
}
