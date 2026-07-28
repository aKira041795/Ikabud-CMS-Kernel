<?php

declare(strict_types=1);

/**
 * Bakeshop — Branch Scope Service
 *
 * Gates read/write operations by explicit branch-user assignment.
 * A user with module access is not automatically authorized for every branch.
 * Admins and superadmins bypass branch scoping (they see all branches).
 *
 * Branch access is stored in the bakeshop_user_branches pivot table.
 */

class BakeshopBranchScopeService
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(?\Ikabud\Kernel\Contracts\ModuleDB $db = null)
    {
        $this->db = $db ?? bakeshopDb();
    }

    /**
     * Check whether a user has explicit access to a branch.
     * Admins and superadmins always have access.
     */
    public function userHasBranchAccess(int $userId, int $branchId, array $user = []): bool
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['admin', 'superadmin'], true)) {
            return true;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM bakeshop_user_branches WHERE user_id = :uid AND branch_id = :bid LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':bid' => $branchId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Get the list of branch IDs a user can access.
     * Admins/superadmins get all active branches.
     */
    public function getUserBranchIds(int $userId, array $user = []): array
    {
        $role = (string)($user['role'] ?? '');
        if (in_array($role, ['admin', 'superadmin'], true)) {
            $stmt = $this->db->query('SELECT id FROM bakeshop_branches WHERE is_active = 1 ORDER BY name');
            return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        }

        $stmt = $this->db->prepare(
            'SELECT b.id FROM bakeshop_user_branches ub
             INNER JOIN bakeshop_branches b ON b.id = ub.branch_id AND b.is_active = 1
             WHERE ub.user_id = :uid ORDER BY b.name'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Assign a user to a branch. Idempotent — no-op if already assigned.
     */
    public function assignUserToBranch(int $userId, int $branchId): void
    {
        $this->db->prepare(
            'INSERT IGNORE INTO bakeshop_user_branches (user_id, branch_id, created_at) VALUES (:uid, :bid, NOW())'
        )->execute([':uid' => $userId, ':bid' => $branchId]);
    }

    /**
     * Remove a user from a branch.
     */
    public function removeUserFromBranch(int $userId, int $branchId): void
    {
        $this->db->prepare(
            'DELETE FROM bakeshop_user_branches WHERE user_id = :uid AND branch_id = :bid'
        )->execute([':uid' => $userId, ':bid' => $branchId]);
    }

    /**
     * Get all branches a user is NOT assigned to (for assignment UI).
     */
    public function getAvailableBranches(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.id, b.code, b.name FROM bakeshop_branches b
             WHERE b.is_active = 1 AND b.id NOT IN (
                 SELECT branch_id FROM bakeshop_user_branches WHERE user_id = :uid
             ) ORDER BY b.name'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Guard: throw if user lacks branch access.
     */
    public function requireBranchAccess(int $userId, int $branchId, array $user = []): void
    {
        if (!$this->userHasBranchAccess($userId, $branchId, $user)) {
            throw new \RuntimeException('Access denied to branch #' . $branchId);
        }
    }
}
