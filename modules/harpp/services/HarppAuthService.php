<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppAuthService
{
    public const COOKIE_NAME = 'harpp_token';

    public function __construct(private ?ModuleDB $database = null)
    {
    }

    public function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) {
            return $this->database;
        }
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) {
            throw new \RuntimeException('HARPP module database is unavailable.');
        }
        return $this->database = $db;
    }

    public function authenticate(string $email, string $password)
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return HarppServiceResult::failure('Invalid email or password.', 401, 'invalid_credentials');
        }

        try {
            $stmt = $this->db()->prepare(
                'SELECT id, email, password_hash, full_name, role, is_active FROM harpp_users WHERE LOWER(email) = :email AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user) || (int)($user['is_active'] ?? 0) !== 1) {
                return HarppServiceResult::failure('Invalid email or password.', 401, 'invalid_credentials');
            }
            if ($this->isBlockedPasswordHash((string)($user['password_hash'] ?? ''))) {
                return HarppServiceResult::failure(
                    'This bootstrap account requires a password reset before sign-in.',
                    403,
                    'password_reset_required'
                );
            }
            if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
                return HarppServiceResult::failure('Invalid email or password.', 401, 'invalid_credentials');
            }

            return HarppServiceResult::success(['user' => $this->publicUser($user)]);
        } catch (Throwable $e) {
            $this->log('HARPP authentication failed', 'error', ['error' => $e->getMessage()]);
            return HarppServiceResult::failure('Authentication temporarily unavailable.', 500, 'auth_unavailable');
        }
    }

    public function login(string $email, string $password)
    {
        $result = $this->authenticate($email, $password);
        if (empty($result['ok'])) {
            return $result;
        }
        $user = $result['data']['user'];
        $token = $this->issueToken($user);
        $cookieSet = $this->setCookie($token);
        return HarppServiceResult::success([
            'user' => $user,
            'token' => $token,
            'cookie_name' => self::COOKIE_NAME,
            'cookie_set' => $cookieSet,
            'expires_in' => (int)\config('app.jwt.expiration', 86400),
        ]);
    }

    public function issueToken(array $user): string
    {
        $storeId = $this->currentStoreId();
        return \app()->jwt()->generate([
            'sub' => 'harpp:' . (int)($user['id'] ?? 0),
            'id' => (int)($user['id'] ?? 0),
            'user_id' => (int)($user['id'] ?? 0),
            'store_id' => $storeId,
            'tenant_id' => $storeId,
            'username' => (string)($user['email'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'name' => (string)($user['full_name'] ?? ''),
            'role' => (string)($user['role'] ?? 'member'),
            'source' => 'harpp',
        ]);
    }

    public function verifyToken(string $token)
    {
        $payload = \app()->jwt()->verify(trim($token));
        $storeId = $this->currentStoreId();
        if (!is_array($payload)
            || ($payload['source'] ?? '') !== 'harpp'
            || (int)($payload['user_id'] ?? 0) <= 0
            || (int)($payload['store_id'] ?? 0) !== $storeId) {
            return HarppServiceResult::failure('Invalid or expired HARPP session.', 401, 'invalid_session');
        }

        $user = $this->findActiveUser((int)$payload['user_id']);
        if ($user === null) {
            return HarppServiceResult::failure('Invalid or expired HARPP session.', 401, 'invalid_session');
        }
        return HarppServiceResult::success(['user' => $user, 'claims' => $payload]);
    }

    public function authenticateRequest()
    {
        $token = $this->requestToken();
        return $token === ''
            ? HarppServiceResult::failure('Authentication required.', 401, 'authentication_required')
            : $this->verifyToken($token);
    }

    public function refresh(string $token = '')
    {
        $verified = $this->verifyToken($token !== '' ? $token : $this->requestToken());
        if (empty($verified['ok'])) {
            return $verified;
        }
        $newToken = $this->issueToken($verified['data']['user']);
        $cookieSet = $this->setCookie($newToken);
        return HarppServiceResult::success([
            'token' => $newToken,
            'user' => $verified['data']['user'],
            'cookie_name' => self::COOKIE_NAME,
            'cookie_set' => $cookieSet,
        ]);
    }

    public function logout()
    {
        $cookieSet = $this->clearCookie();
        return HarppServiceResult::success(['cookie_name' => self::COOKIE_NAME, 'cookie_cleared' => $cookieSet]);
    }

    public function me(string $token = '')
    {
        return $token !== '' ? $this->verifyToken($token) : $this->authenticateRequest();
    }

    public function updateProfile(int $userId, array $input)
    {
        $name = trim(strip_tags((string)($input['full_name'] ?? '')));
        if ($userId <= 0 || $name === '' || strlen($name) > 255) {
            return HarppServiceResult::failure('A valid full name of at most 255 characters is required.');
        }
        try {
            $stmt = $this->db()->prepare('UPDATE harpp_users SET full_name = :name, updated_at = NOW() WHERE id = :id AND is_active = 1');
            $stmt->execute([':name' => $name, ':id' => $userId]);
            $user = $this->findActiveUser($userId);
            return $user !== null
                ? HarppServiceResult::success(['user' => $user])
                : HarppServiceResult::failure('User not found.', 404);
        } catch (Throwable $e) {
            $this->log('HARPP profile update failed', 'error', ['error' => $e->getMessage(), 'user_id' => $userId]);
            return HarppServiceResult::failure('Unable to update profile.', 500);
        }
    }

    public function register(array $actor, array $input)
    {
        return $this->invite($actor, $input);
    }

    public function invite(array $actor, array $input)
    {
        if (($actor['source'] ?? 'harpp') !== 'harpp' || ($actor['role'] ?? '') !== 'owner') {
            return HarppServiceResult::failure('Owner access is required.', 403, 'forbidden');
        }
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $name = trim(strip_tags((string)($input['full_name'] ?? '')));
        $role = strtolower(trim((string)($input['role'] ?? 'member')));
        $password = (string)($input['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 255) {
            return HarppServiceResult::failure('Valid email and full name are required.');
        }
        if (!in_array($role, ['admin', 'member'], true)) {
            return HarppServiceResult::failure('Invite role must be admin or member.');
        }
        if (!$this->validPassword($password)) {
            return HarppServiceResult::failure('Password must be at least 12 characters and contain upper, lower, and numeric characters.');
        }
        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO harpp_users (email, password_hash, full_name, role, is_active, created_at, updated_at) VALUES (:email, :hash, :name, :role, 1, NOW(), NOW())'
            );
            $stmt->execute([
                ':email' => $email,
                ':hash' => password_hash($password, PASSWORD_BCRYPT),
                ':name' => $name,
                ':role' => $role,
            ]);
            $user = $this->findActiveUser((int)$this->db()->lastInsertId());
            return HarppServiceResult::success(['user' => $user], 'Invitation created.');
        } catch (Throwable $e) {
            $this->log('HARPP invitation failed', 'error', ['error' => $e->getMessage()]);
            $duplicate = str_contains(strtolower($e->getMessage()), 'duplicate');
            return HarppServiceResult::failure($duplicate ? 'Email is already registered.' : 'Unable to create invitation.', $duplicate ? 409 : 500);
        }
    }

    public function selectTenant(array $user, int $storeId)
    {
        if ($storeId <= 0 || $storeId !== $this->currentStoreId()) {
            return HarppServiceResult::failure('Select HARPP through the requested tenant host before changing tenant.', 409, 'tenant_host_required');
        }
        $liveUser = $this->findActiveUser((int)($user['id'] ?? 0));
        if ($liveUser === null || strcasecmp((string)$liveUser['email'], (string)($user['email'] ?? '')) !== 0) {
            return HarppServiceResult::failure('User is not available in this tenant.', 403, 'tenant_access_denied');
        }
        $token = $this->issueToken($liveUser);
        $cookieSet = $this->setCookie($token);
        return HarppServiceResult::success(['token' => $token, 'user' => $liveUser, 'store_id' => $storeId, 'cookie_set' => $cookieSet]);
    }

    public function findActiveUser(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db()->prepare('SELECT id, email, full_name, role, is_active FROM harpp_users WHERE id = :id AND is_active = 1 AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicUser($row) : null;
    }

    public function isBlockedPasswordHash(string $hash): bool
    {
        return in_array($hash, [
            '!harpp-bootstrap-password-reset-required!',
            '$2y$12$mq2QCTxGTbJ4eUTYQ1.Kn.0Ek/Dc2eah/AbwkckZyzSDnFYHFWV/S',
            '$2y$12$eAn.t5dP1Y5GX1bZ21L6GOajsBhSYLKDw6yLygEVXzU6pYoOObOcW',
            '$2y$12$tJz4g/pPDmaZbMK0gLqpBuNrfblxB5aSEtWnbCEbBleXT/YQxh/y.',
        ], true);
    }

    public function validPassword(string $password): bool
    {
        return strlen($password) >= 12
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    private function publicUser(array $user)
    {
        $id = (int)($user['id'] ?? 0);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $role = strtolower(trim((string)($user['role'] ?? '')));
        // Old tenants could have the deterministic administrator row with a
        // blank/invalid role. Do not silently reinterpret that administrator
        // as a member while the corrective migration is being deployed.
        if (($id === 2 || $email === 'admin@harpp.local') && !in_array($role, ['owner', 'admin', 'member'], true)) {
            $role = 'admin';
        } elseif (!in_array($role, ['owner', 'admin', 'member'], true)) {
            $role = 'invalid';
        }
        return [
            'id' => $id,
            'email' => $email,
            'username' => $email,
            'full_name' => (string)($user['full_name'] ?? ''),
            'role' => $role,
            'source' => 'harpp',
        ];
    }

    private function requestToken(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $match) === 1) {
            return trim($match[1]);
        }
        return trim((string)($_COOKIE[self::COOKIE_NAME] ?? ''));
    }

    private function setCookie(string $token): bool
    {
        if (headers_sent()) {
            return false;
        }
        $ok = setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + (int)\config('app.jwt.expiration', 86400),
            'path' => '/', 'httponly' => true, 'secure' => \is_https(),
            'samesite' => \config('cookie.samesite', 'Strict'),
        ]);
        if ($ok) {
            $_COOKIE[self::COOKIE_NAME] = $token;
        }
        return $ok;
    }

    private function clearCookie(): bool
    {
        if (headers_sent()) {
            return false;
        }
        $ok = setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600, 'path' => '/', 'httponly' => true,
            'secure' => \is_https(), 'samesite' => \config('cookie.samesite', 'Strict'),
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
        return $ok;
    }

    private function currentStoreId(): int
    {
        $storeId = (int)(\app()->tenant()->current() ?? 0);
        if ($storeId <= 0) {
            throw new \RuntimeException('HARPP tenant context is unavailable.');
        }
        return $storeId;
    }

    private function log(string $message, string $level, array $context = []): void
    {
        if (function_exists('write_log')) {
            \write_log($message, $level, $context + ['module' => 'harpp']);
        }
    }
}
