<?php

declare(strict_types=1);

function users_capability_handlers(): array
{
    return [
        'users.get@1' => 'users_cap_users_get_1',
        'users.list@1' => 'users_cap_users_list_1',
        'users.create@1' => 'users_cap_users_create_1',
        'users.update@1' => 'users_cap_users_update_1',
    ];
}

function usersGetById(int $id): array
{
    if ($id <= 0) return ['ok' => false, 'error' => 'id is required'];
    $ctx = module('users');
    if (!$ctx) return ['ok' => false, 'error' => 'Module context unavailable'];
    try {
        $stmt = $ctx->db()->prepare("SELECT * FROM cms_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($u)) return ['ok' => false, 'error' => 'Not found'];
        return ['ok' => true, 'data' => $u];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function usersList(int $limit = 100, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $ctx = module('users');
    if (!$ctx) return ['ok' => false, 'error' => 'Module context unavailable'];
    try {
        $stmt = $ctx->db()->prepare(
            "SELECT id, username, email, display_name, role, is_active, last_login_at, created_at, updated_at\n             FROM cms_users\n             ORDER BY created_at DESC\n             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['ok' => true, 'data' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function usersCreate(array $input): array
{
    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $displayName = trim((string)($input['display_name'] ?? ''));
    $role = trim((string)($input['role'] ?? 'subscriber'));

    if ($username === '' || $email === '' || $password === '') {
        return ['ok' => false, 'error' => 'Username, email, and password are required'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters'];
    }

    $validRoles = defined('CMS_ROLES') ? array_keys(CMS_ROLES) : ['subscriber'];
    if (!in_array($role, $validRoles, true)) {
        $role = 'subscriber';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ctx = module('users');
    if (!$ctx) return ['ok' => false, 'error' => 'Module context unavailable'];

    try {
        $db = $ctx->db();
        $stmt = $db->prepare(
            "INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)\n             VALUES (:u, :e, :h, :d, :r, 1, NOW())"
        );
        $stmt->execute([
            ':u' => $username,
            ':e' => $email,
            ':h' => $hash,
            ':d' => $displayName ?: $username,
            ':r' => $role,
        ]);
        $newId = (int)$db->lastInsertId();

        $ctx->fireEvent('users.created', [
            'user_id' => $newId,
            'username' => $username,
            'role' => $role,
        ]);

        return ['ok' => true, 'id' => $newId];
    } catch (Throwable $e) {
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Username or email already exists' : 'Failed to create user';
        return ['ok' => false, 'error' => $msg];
    }
}

function usersUpdate(int $id, array $input): array
{
    if ($id <= 0) return ['ok' => false, 'error' => 'id is required'];
    $ctx = module('users');
    if (!$ctx) return ['ok' => false, 'error' => 'Module context unavailable'];

    $fields = [];
    $bind = [':id' => $id];

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
        return ['ok' => true, 'message' => 'No changes'];
    }

    try {
        $setStr = implode(', ', $fields);
        $ctx->db()->prepare("UPDATE cms_users SET {$setStr} WHERE id = :id")->execute($bind);

        $ctx->fireEvent('users.updated', ['user_id' => $id]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error'];
    }
}

function users_cap_users_get_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $id = is_array($payload) ? (int)($payload['id'] ?? 0) : 0;
    return usersGetById($id);
}

function users_cap_users_list_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $limit = 100;
    $offset = 0;
    if (is_array($payload)) {
        $limit = (int)($payload['limit'] ?? $limit);
        $offset = (int)($payload['offset'] ?? $offset);
    }
    return usersList($limit, $offset);
}

function users_cap_users_create_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    return usersCreate($payload);
}

function users_cap_users_update_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) return ['ok' => false, 'error' => 'Invalid payload'];
    $id = (int)($payload['id'] ?? 0);
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    if (isset($data['id'])) unset($data['id']);
    return usersUpdate($id, $data);
}
