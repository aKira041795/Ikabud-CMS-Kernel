<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use PDOException;

final class HarppWorkspaceService
{
    public function __construct(private ModuleDB $db) {}

    public function list(array $actor, array $filters = [], ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $where = empty($filters['archived']) ? "WHERE w.status='active'" : '';
        $rows = $this->db->query("SELECT w.*,COUNT(wm.id) AS membership_count FROM harpp_workspaces w LEFT JOIN harpp_workspace_memberships wm ON wm.workspace_id=w.id AND wm.status='active' {$where} GROUP BY w.id ORDER BY w.name,w.id")->fetchAll(PDO::FETCH_ASSOC);
        return HarppServiceResult::success(['workspaces' => $rows]);
    }

    public function get(array $actor, int $id, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $workspace = $this->find($id);
        return $workspace ? HarppServiceResult::success(['workspace' => $workspace]) : HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
    }

    public function create(array $actor, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $key = trim((string)($input['workspace_key'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $key)) return HarppServiceResult::failure('Valid workspace_key is required.', 422, 'invalid_workspace_key');
        if ($name === '' || strlen($name) > 255) return HarppServiceResult::failure('Valid name is required.', 422, 'invalid_name');
        try {
            $statement = $this->db->prepare("INSERT INTO harpp_workspaces (workspace_key,name,status,created_by,version) VALUES (:key,:name,'active',:actor,1)");
            $statement->execute([':key' => $key, ':name' => $name, ':actor' => $this->actorId($actor)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errno = (int)($exception->errorInfo[1] ?? 0);
                if ($errno === 1062) return HarppServiceResult::failure('Workspace key is already in use.', 409, 'workspace_key_taken');
                return HarppServiceResult::failure('Workspace could not be created.', 422, 'workspace_create_failed');
            }
            throw $exception;
        }
        $workspace = $this->find((int)$this->db->lastInsertId());
        return HarppServiceResult::success(['workspace' => $workspace], '', [], 'harpp_workspace', (int)$workspace['id']);
    }

    public function update(array $actor, int $id, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $name = trim((string)($input['name'] ?? ''));
        $status = (string)($input['status'] ?? 'active');
        $version = (int)($input['version'] ?? $input['expected_version'] ?? 0);
        if ($name === '' || strlen($name) > 255) return HarppServiceResult::failure('Valid name is required.', 422, 'invalid_name');
        if (!in_array($status, ['active', 'archived'], true)) return HarppServiceResult::failure('Valid status is required.', 422, 'invalid_status');
        if ($version < 1) return HarppServiceResult::failure('Expected version is required.', 422, 'expected_version_required');
        $statement = $this->db->prepare('UPDATE harpp_workspaces SET name=:name,status=:status,version=version+1 WHERE id=:id AND version=:version');
        $statement->execute([':name' => $name, ':status' => $status, ':id' => $id, ':version' => $version]);
        if ($statement->rowCount() === 0) return $this->find($id) ? HarppServiceResult::failure('Workspace version conflict.', 409, 'version_conflict') : HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
        return HarppServiceResult::success(['workspace' => $this->find($id)]);
    }

    public function archive(array $actor, int $id, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $workspace = $this->find($id);
        if (!$workspace) return HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
        $input['name'] = $workspace['name'];
        $input['status'] = 'archived';
        return $this->update($actor, $id, $input, $tenantId);
    }

    public function memberships(array $actor, int $workspaceId, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if (!$this->find($workspaceId)) return HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
        $statement = $this->db->prepare('SELECT wm.*,u.email,u.full_name,u.role AS user_role FROM harpp_workspace_memberships wm JOIN harpp_users u ON u.id=wm.user_id WHERE wm.workspace_id=:id ORDER BY u.full_name,u.id');
        $statement->execute([':id' => $workspaceId]);
        return HarppServiceResult::success(['memberships' => array_map([$this, 'decodeRoles'], $statement->fetchAll(PDO::FETCH_ASSOC))]);
    }

    public function enroll(array $actor, int $workspaceId, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if (!$this->find($workspaceId)) return HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
        $userId = (int)($input['user_id'] ?? 0);
        $action = (string)($input['action'] ?? 'enroll');
        if (!in_array($action, ['enroll', 'suspend', 'revoke'], true)) return HarppServiceResult::failure('Valid action is required.', 422, 'invalid_action');
        $userStatement = $this->db->prepare('SELECT id,role FROM harpp_users WHERE id=:id AND deleted_at IS NULL');
        $userStatement->execute([':id' => $userId]);
        $user = $userStatement->fetch(PDO::FETCH_ASSOC);
        if (!$user) return HarppServiceResult::failure('User not found.', 404, 'user_not_found');
        $roles = $input['roles'] ?? null;
        if ($roles !== null && (!is_array($roles) || !$this->validRoles($roles))) return HarppServiceResult::failure('Valid roles are required.', 422, 'invalid_roles');
        $roles = $roles ?? (in_array($user['role'], ['owner', 'admin'], true) ? ['manager', 'operator', 'reviewer', 'viewer'] : ['operator', 'reviewer', 'viewer']);
        $status = $action === 'enroll' ? 'active' : ($action === 'suspend' ? 'suspended' : 'revoked');
        try {
            $statement = $this->db->prepare('INSERT INTO harpp_workspace_memberships (workspace_id,user_id,roles,status,created_by,version) VALUES (:workspace,:user,:roles,:status,:actor,1) ON DUPLICATE KEY UPDATE roles=VALUES(roles),status=VALUES(status),version=version+1');
            $statement->execute([':workspace' => $workspaceId, ':user' => $userId, ':roles' => $this->json($roles), ':status' => $status, ':actor' => $this->actorId($actor)]);
        } catch (PDOException $exception) {
            $errno = (int)($exception->errorInfo[1] ?? 0);
            if ($errno === 1452) return HarppServiceResult::failure('User cannot be enrolled in this workspace.', 422, 'workspace_enroll_failed');
            throw $exception;
        }
        return HarppServiceResult::success(['membership' => $this->membership($workspaceId, $userId)]);
    }

    private function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM harpp_workspaces WHERE id=:id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function membership(int $workspaceId, int $userId): array
    {
        $statement = $this->db->prepare('SELECT * FROM harpp_workspace_memberships WHERE workspace_id=:workspace AND user_id=:user');
        $statement->execute([':workspace' => $workspaceId, ':user' => $userId]);
        return $this->decodeRoles($statement->fetch(PDO::FETCH_ASSOC));
    }

    private function decodeRoles(array $row): array { $row['roles'] = (array)json_decode((string)$row['roles'], true); return $row; }
    private function validRoles(array $roles): bool { return $roles !== [] && count(array_diff($roles, ['manager', 'operator', 'reviewer', 'viewer'])) === 0; }
    private function actorId(array $actor): ?int
    {
        $id = (int)($actor['id'] ?? $actor['user_id'] ?? 0);
        if ($id <= 0) return null;
        $statement = $this->db->prepare('SELECT id FROM harpp_users WHERE id=:id AND deleted_at IS NULL LIMIT 1');
        $statement->execute([':id' => $id]);
        return $statement->fetchColumn() ? $id : null;
    }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function ownerActor(array $actor): bool
    {
        return (($actor['source'] ?? 'harpp') === 'harpp' && in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true))
            || (($actor['source'] ?? '') === 'kernel' && ($actor['role'] ?? '') === 'superadmin');
    }
}