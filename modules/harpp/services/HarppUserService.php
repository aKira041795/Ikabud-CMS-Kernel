<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppUserService
{
    public function __construct(private ?ModuleDB $database = null)
    {
    }

    public function list(array $actor, array $filters = []): HarppServiceResult
    {
        if (!$this->isAdmin($actor)) return $this->forbidden();
        $includeDeleted = !empty($filters['include_deleted']);
        $limit = max(1, min(100, (int)($filters['limit'] ?? 100)));
        try {
            $sql = 'SELECT id, email, full_name, role, is_active, created_at, updated_at, deleted_at FROM harpp_users'
                . ($includeDeleted ? '' : ' WHERE deleted_at IS NULL')
                . ' ORDER BY id ASC LIMIT ' . $limit;
            $rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return HarppServiceResult::success(['users' => array_map([$this, 'publicUser'], $rows), 'total' => count($rows)]);
        } catch (Throwable $e) {
            $this->log('HARPP user list failed', $e);
            return HarppServiceResult::failure('Unable to list users.', 500);
        }
    }

    public function create(array $actor, array $input): HarppServiceResult
    {
        if (!$this->isAdmin($actor)) return $this->forbidden();
        $valid = $this->validated($input, true);
        if ($valid instanceof HarppServiceResult) return $valid;
        if (!$this->canManageRole($actor, $valid['role'])) return $this->ownerRequired();
        try {
            $stmt = $this->db()->prepare('INSERT INTO harpp_users (email,password_hash,full_name,role,is_active,deleted_at,created_at,updated_at) VALUES (:email,:hash,:name,:role,:active,NULL,NOW(),NOW())');
            $stmt->execute([
                ':email' => $valid['email'], ':hash' => password_hash($valid['password'], PASSWORD_BCRYPT),
                ':name' => $valid['full_name'], ':role' => $valid['role'], ':active' => $valid['is_active'],
            ]);
            $id = (int)$this->db()->lastInsertId();
            return HarppServiceResult::success(['user' => $this->find($id)], 'User created.', [], 'harpp_user', $id);
        } catch (Throwable $e) {
            $this->log('HARPP user create failed', $e);
            $duplicate = str_contains(strtolower($e->getMessage()), 'duplicate');
            return HarppServiceResult::failure($duplicate ? 'Email is already registered.' : 'Unable to create user.', $duplicate ? 409 : 500, $duplicate ? 'duplicate_email' : '');
        }
    }

    public function update(array $actor, int $id, array $input): HarppServiceResult
    {
        if (!$this->isAdmin($actor)) return $this->forbidden();
        if ($id <= 0) return HarppServiceResult::failure('User not found.', 404, 'not_found');
        try {
            $this->db()->beginTransaction();
            $stmt = $this->db()->prepare('SELECT id,email,full_name,role,is_active,deleted_at FROM harpp_users WHERE id=:id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) return $this->rollback(HarppServiceResult::failure('User not found.', 404, 'not_found'));
            if (!$this->canManageRole($actor, (string)$current['role'])) return $this->rollback($this->ownerRequired());

            $merged = array_merge($current, $input);
            $valid = $this->validated($merged, false);
            if ($valid instanceof HarppServiceResult) return $this->rollback($valid);
            if (!$this->canManageRole($actor, $valid['role'])) return $this->rollback($this->ownerRequired());
            $self = ($actor['source'] ?? 'harpp') === 'harpp' && (int)($actor['id'] ?? 0) === $id;
            $roleRank = ['member'=>1, 'admin'=>2, 'owner'=>3];
            $selfDemotion = ($roleRank[$valid['role']] ?? 0) < ($roleRank[(string)$current['role']] ?? 0);
            if ($self && ($selfDemotion || $valid['is_active'] !== 1)) {
                return $this->rollback(HarppServiceResult::failure('You cannot demote or deactivate yourself.', 409, 'self_protected'));
            }
            if ($current['role'] === 'owner' && (int)$current['is_active'] === 1 && ($valid['role'] !== 'owner' || $valid['is_active'] !== 1) && $this->activeOwnerCountLocked() <= 1) {
                return $this->rollback(HarppServiceResult::failure('The last active owner cannot be demoted or deactivated.', 409, 'last_owner'));
            }
            $update = $this->db()->prepare('UPDATE harpp_users SET email=:email,full_name=:name,role=:role,is_active=:active,updated_at=NOW() WHERE id=:id AND deleted_at IS NULL');
            $update->execute([':email'=>$valid['email'], ':name'=>$valid['full_name'], ':role'=>$valid['role'], ':active'=>$valid['is_active'], ':id'=>$id]);
            $this->db()->commit();
            return HarppServiceResult::success(['user' => $this->find($id)], 'User updated.', [], 'harpp_user', $id);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('HARPP user update failed', $e);
            $duplicate = str_contains(strtolower($e->getMessage()), 'duplicate');
            return HarppServiceResult::failure($duplicate ? 'Email is already registered.' : 'Unable to update user.', $duplicate ? 409 : 500, $duplicate ? 'duplicate_email' : '');
        }
    }

    public function delete(array $actor, int $id): HarppServiceResult
    {
        if (!$this->isAdmin($actor)) return $this->forbidden();
        if ($id <= 0) return HarppServiceResult::failure('User not found.', 404, 'not_found');
        if (($actor['source'] ?? 'harpp') === 'harpp' && (int)($actor['id'] ?? 0) === $id) {
            return HarppServiceResult::failure('You cannot delete yourself.', 409, 'self_protected');
        }
        try {
            $this->db()->beginTransaction();
            $stmt = $this->db()->prepare('SELECT id,role,is_active FROM harpp_users WHERE id=:id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
            $stmt->execute([':id'=>$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) return $this->rollback(HarppServiceResult::failure('User not found.', 404, 'not_found'));
            if (!$this->canManageRole($actor, (string)$user['role'])) return $this->rollback($this->ownerRequired());
            if ($user['role'] === 'owner' && (int)$user['is_active'] === 1 && $this->activeOwnerCountLocked() <= 1) {
                return $this->rollback(HarppServiceResult::failure('The last active owner cannot be deleted.', 409, 'last_owner'));
            }
            $update = $this->db()->prepare('UPDATE harpp_users SET is_active=0,deleted_at=NOW(),updated_at=NOW() WHERE id=:id AND deleted_at IS NULL');
            $update->execute([':id'=>$id]);
            $this->db()->commit();
            return HarppServiceResult::success(['user_id'=>$id, 'deleted'=>true], 'User deleted.', [], 'harpp_user', $id);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            $this->log('HARPP user delete failed', $e);
            return HarppServiceResult::failure('Unable to delete user.', 500);
        }
    }

    private function validated(array $input, bool $creating): array|HarppServiceResult
    {
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $name = trim(strip_tags((string)($input['full_name'] ?? $input['name'] ?? '')));
        $role = strtolower(trim((string)($input['role'] ?? 'member')));
        $active = filter_var($input['is_active'] ?? $input['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 255) return HarppServiceResult::failure('Valid email and full name are required.');
        if (!in_array($role, ['owner','admin','member'], true)) return HarppServiceResult::failure('Role must be owner, admin, or member.');
        if ($active === null) return HarppServiceResult::failure('Active must be a boolean value.');
        $password = (string)($input['password'] ?? '');
        if ($creating && !(new HarppAuthService($this->db()))->validPassword($password)) return HarppServiceResult::failure('Password must be at least 12 characters and contain upper, lower, and numeric characters.');
        return ['email'=>$email, 'full_name'=>$name, 'role'=>$role, 'is_active'=>$active ? 1 : 0, 'password'=>$password];
    }

    private function activeOwnerCountLocked(): int
    {
        $rows = $this->db()->query("SELECT id FROM harpp_users WHERE role='owner' AND is_active=1 AND deleted_at IS NULL FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        return count($rows);
    }

    private function find(int $id): ?array
    {
        $stmt=$this->db()->prepare('SELECT id,email,full_name,role,is_active,created_at,updated_at,deleted_at FROM harpp_users WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->publicUser($row) : null;
    }

    private function publicUser(array $row): array
    {
        return ['id'=>(int)$row['id'], 'email'=>(string)$row['email'], 'full_name'=>(string)$row['full_name'], 'role'=>(string)$row['role'], 'is_active'=>(int)$row['is_active'] === 1, 'created_at'=>$row['created_at'] ?? null, 'updated_at'=>$row['updated_at'] ?? null, 'deleted_at'=>$row['deleted_at'] ?? null];
    }

    private function isAdmin(array $actor): bool
    {
        return (($actor['source'] ?? 'harpp') === 'harpp' && in_array(($actor['role'] ?? ''), ['owner','admin'], true))
            || (($actor['source'] ?? '') === 'kernel' && ($actor['role'] ?? '') === 'superadmin');
    }
    private function canManageRole(array $actor, string $role): bool
    {
        $ownerAuthority = (($actor['source'] ?? 'harpp') === 'harpp' && ($actor['role'] ?? '') === 'owner')
            || (($actor['source'] ?? '') === 'kernel' && ($actor['role'] ?? '') === 'superadmin');
        return $ownerAuthority || $role === 'member';
    }
    private function forbidden(): HarppServiceResult { return HarppServiceResult::failure('Admin access is required.', 403, 'forbidden'); }
    private function ownerRequired(): HarppServiceResult { return HarppServiceResult::failure('Owner access is required to manage administrators or owners.', 403, 'owner_required'); }
    private function rollback(HarppServiceResult $result): HarppServiceResult { if ($this->db()->inTransaction()) $this->db()->rollBack(); return $result; }
    private function db(): ModuleDB { if ($this->database instanceof ModuleDB) return $this->database; $db=\module('harpp')->db(); if (!$db instanceof ModuleDB) throw new \RuntimeException('HARPP module database is unavailable.'); return $this->database=$db; }
    private function log(string $message, Throwable $e): void { if (function_exists('write_log')) \write_log($message, 'error', ['module'=>'harpp','error'=>$e->getMessage()]); }
}
