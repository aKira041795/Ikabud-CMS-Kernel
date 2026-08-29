<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use PDOException;

final class HarppProjectService
{
    public function __construct(private ModuleDB $db) {}

    public function list(array $actor, int $workspaceId, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $statement = $this->db->prepare("SELECT p.*,COUNT(pm.id) AS membership_count FROM harpp_projects p LEFT JOIN harpp_project_memberships pm ON pm.project_id=p.id AND pm.status='active' WHERE p.workspace_id=:workspace GROUP BY p.id ORDER BY p.name,p.id");
        $statement->execute([':workspace' => $workspaceId]);
        return HarppServiceResult::success(['projects' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function get(array $actor, int $id, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $project = $this->find($id);
        return $project ? HarppServiceResult::success(['project' => $project]) : HarppServiceResult::failure('Project not found.', 404, 'project_not_found');
    }

    public function create(array $actor, int $workspaceId, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if (!$this->workspaceExists($workspaceId)) return HarppServiceResult::failure('Workspace not found.', 404, 'workspace_not_found');
        $key = trim((string)($input['project_key'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $key)) return HarppServiceResult::failure('Valid project_key is required.', 422, 'invalid_project_key');
        if ($name === '' || strlen($name) > 255) return HarppServiceResult::failure('Valid name is required.', 422, 'invalid_name');
        try {
            $statement = $this->db->prepare("INSERT INTO harpp_projects (workspace_id,project_key,name,status,created_by,version) VALUES (:workspace,:key,:name,'active',:actor,1)");
            $statement->execute([':workspace' => $workspaceId, ':key' => $key, ':name' => $name, ':actor' => $this->actorId($actor)]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errno = (int)($exception->errorInfo[1] ?? 0);
                if ($errno === 1062) return HarppServiceResult::failure('Project key is already in use.', 409, 'project_key_taken');
                return HarppServiceResult::failure('Project could not be created.', 422, 'project_create_failed');
            }
            throw $exception;
        }
        $project = $this->find((int)$this->db->lastInsertId());
        return HarppServiceResult::success(['project' => $project], '', [], 'harpp_project', (int)$project['id']);
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
        $statement = $this->db->prepare('UPDATE harpp_projects SET name=:name,status=:status,version=version+1 WHERE id=:id AND version=:version');
        $statement->execute([':name' => $name, ':status' => $status, ':id' => $id, ':version' => $version]);
        if ($statement->rowCount() === 0) return $this->find($id) ? HarppServiceResult::failure('Project version conflict.', 409, 'version_conflict') : HarppServiceResult::failure('Project not found.', 404, 'project_not_found');
        return HarppServiceResult::success(['project' => $this->find($id)]);
    }

    public function archive(array $actor, int $id, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $project = $this->find($id);
        if (!$project) return HarppServiceResult::failure('Project not found.', 404, 'project_not_found');
        $input['name'] = $project['name'];
        $input['status'] = 'archived';
        return $this->update($actor, $id, $input, $tenantId);
    }

    public function memberships(array $actor, int $projectId, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if (!$this->find($projectId)) return HarppServiceResult::failure('Project not found.', 404, 'project_not_found');
        $statement = $this->db->prepare('SELECT pm.*,u.email,u.full_name,u.role AS user_role FROM harpp_project_memberships pm JOIN harpp_users u ON u.id=pm.user_id WHERE pm.project_id=:id ORDER BY u.full_name,u.id');
        $statement->execute([':id' => $projectId]);
        return HarppServiceResult::success(['memberships' => array_map([$this, 'decodeRoles'], $statement->fetchAll(PDO::FETCH_ASSOC))]);
    }

    public function enroll(array $actor, int $projectId, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->ownerActor($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $project = $this->find($projectId);
        if (!$project) return HarppServiceResult::failure('Project not found.', 404, 'project_not_found');
        $userId = (int)($input['user_id'] ?? 0);
        $action = (string)($input['action'] ?? 'enroll');
        if (!in_array($action, ['enroll', 'suspend', 'revoke'], true)) return HarppServiceResult::failure('Valid action is required.', 422, 'invalid_action');
        $workspaceMember = $this->db->prepare("SELECT u.role FROM harpp_users u JOIN harpp_workspace_memberships wm ON wm.user_id=u.id AND wm.workspace_id=:workspace AND wm.status='active' WHERE u.id=:user AND u.deleted_at IS NULL");
        $workspaceMember->execute([':workspace' => $project['workspace_id'], ':user' => $userId]);
        $user = $workspaceMember->fetch(PDO::FETCH_ASSOC);
        if (!$user && $action === 'enroll') return HarppServiceResult::failure('Active workspace membership is required.', 422, 'workspace_membership_required');
        if (!$user) {
            $userStatement = $this->db->prepare('SELECT role FROM harpp_users WHERE id=:id AND deleted_at IS NULL');
            $userStatement->execute([':id' => $userId]);
            $user = $userStatement->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user) return HarppServiceResult::failure('User not found.', 404, 'user_not_found');
        $roles = $input['roles'] ?? null;
        if ($roles !== null && (!is_array($roles) || !$this->validRoles($roles))) return HarppServiceResult::failure('Valid roles are required.', 422, 'invalid_roles');
        $roles = $roles ?? (in_array($user['role'], ['owner', 'admin'], true) ? ['manager', 'operator', 'reviewer', 'viewer'] : ['operator', 'reviewer', 'viewer']);
        $status = $action === 'enroll' ? 'active' : ($action === 'suspend' ? 'suspended' : 'revoked');
        try {
            $statement = $this->db->prepare('INSERT INTO harpp_project_memberships (project_id,user_id,roles,status) VALUES (:project,:user,:roles,:status) ON DUPLICATE KEY UPDATE roles=VALUES(roles),status=VALUES(status)');
            $statement->execute([':project' => $projectId, ':user' => $userId, ':roles' => $this->json($roles), ':status' => $status]);
        } catch (PDOException $exception) {
            $errno = (int)($exception->errorInfo[1] ?? 0);
            if ($errno === 1452) return HarppServiceResult::failure('User cannot be enrolled in this project.', 422, 'project_enroll_failed');
            throw $exception;
        }
        $statement = $this->db->prepare('SELECT * FROM harpp_project_memberships WHERE project_id=:project AND user_id=:user');
        $statement->execute([':project' => $projectId, ':user' => $userId]);
        return HarppServiceResult::success(['membership' => $this->decodeRoles($statement->fetch(PDO::FETCH_ASSOC))]);
    }

    private function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM harpp_projects WHERE id=:id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function workspaceExists(int $id): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM harpp_workspaces WHERE id=:id');
        $statement->execute([':id' => $id]);
        return (bool)$statement->fetchColumn();
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