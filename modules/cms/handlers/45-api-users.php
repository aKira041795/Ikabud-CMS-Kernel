<?php

declare(strict_types=1);

function cmsApiUserCreate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('users.manage');

    $input = cmsInput();
    $username    = trim((string)($input['username'] ?? ''));
    $email       = trim((string)($input['email'] ?? ''));
    $password    = (string)($input['password'] ?? '');
    $displayName = trim((string)($input['display_name'] ?? ''));
    $role        = trim((string)($input['role'] ?? 'subscriber'));

    if ($username === '' || $email === '' || $password === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Username, email, and password are required']);
        exit;
    }

    if (strlen($password) < 8) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters']);
        exit;
    }

    $validRoles = array_keys(CMS_ROLES);
    if (!in_array($role, $validRoles, true)) {
        $role = 'subscriber';
    }

    // Only superadmin can create superadmin/administrator
    $currentRole = (string)($user['role'] ?? '');
    $source = (string)($user['source'] ?? '');
    if (in_array($role, ['superadmin', 'administrator'], true)) {
        if ($source === 'cms' && $currentRole !== 'superadmin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only superadmin can create admin users']);
            exit;
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $db = cmsDb();
    try {
        $stmt = $db->prepare(
            "INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)
             VALUES (:u, :e, :h, :d, :r, 1, NOW())"
        );
        $stmt->execute([
            ':u' => $username, ':e' => $email, ':h' => $hash,
            ':d' => $displayName ?: $username, ':r' => $role,
        ]);
        $newId = (int)$db->lastInsertId();

        if ($ctx = module('cms')) {
            $ctx->fireEvent('cms.user.created', [
                'user_id'  => $newId,
                'username' => $username,
                'role'     => $role,
            ]);
        }

        adminViewCacheInvalidate(['cms:admin', 'cms:admin:users']);

        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (Throwable $e) {
        http_response_code(422);
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Username or email already exists' : 'Failed to create user';
        echo json_encode(['ok' => false, 'error' => $msg]);
    }
    exit;
}

function cmsApiUserUpdate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('users.manage');
    $id   = (int)($params['id'] ?? 0);

    $input = cmsInput();
    $fields = [];
    $bind   = [':id' => $id];

    foreach (['display_name', 'email', 'role', 'bio'] as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = "{$f} = :{$f}";
            $bind[":{$f}"] = trim((string)$input[$f]);
        }
    }
    if (array_key_exists('is_active', $input)) {
        $fields[] = 'is_active = :active';
        $bind[':active'] = (int)$input['is_active'];
    }
    if (!empty($input['password']) && strlen((string)$input['password']) >= 8) {
        $fields[] = 'password_hash = :hash';
        $bind[':hash'] = password_hash((string)$input['password'], PASSWORD_DEFAULT);
    }

    if (empty($fields)) {
        echo json_encode(['ok' => true, 'message' => 'No changes']);
        exit;
    }

    $setStr = implode(', ', $fields);
    $db = cmsDb();
    $db->prepare("UPDATE cms_users SET {$setStr} WHERE id = :id")->execute($bind);
    adminViewCacheInvalidate(['cms:admin', 'cms:admin:users']);
    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// SETTINGS API
// ═══════════════════════════════════════════════════════════════════════
