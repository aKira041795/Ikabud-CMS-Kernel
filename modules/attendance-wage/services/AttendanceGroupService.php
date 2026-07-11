<?php

declare(strict_types=1);

class AttendanceGroupService
{
    private PDO $db;
    private string $tenantId;
    private int $userId;

    public function __construct(PDO $db, string $tenantId, int $userId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function list(): array
    {
        $stmt = $this->db->prepare("
            SELECT g.*, 
                   CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS leader_name,
                   ep.position AS leader_position,
                   (SELECT COUNT(*) FROM attendance_group_members gm WHERE gm.group_id = g.group_id AND gm.tenant_id = g.tenant_id) AS member_count
            FROM attendance_groups g
            LEFT JOIN employee_profiles ep ON g.leader_profile_id = ep.profile_id AND g.tenant_id = ep.tenant_id
            WHERE g.tenant_id = :tid AND g.is_active = 1
            ORDER BY g.name ASC
        ");
        $stmt->execute([':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $groupId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT g.*,
                   CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS leader_name
            FROM attendance_groups g
            LEFT JOIN employee_profiles ep ON g.leader_profile_id = ep.profile_id AND g.tenant_id = ep.tenant_id
            WHERE g.group_id = :gid AND g.tenant_id = :tid
        ");
        $stmt->execute([':gid' => $groupId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['members'] = $this->getMembers($groupId);
        return $row;
    }

    public function getMembers(int $groupId): array
    {
        $stmt = $this->db->prepare("
            SELECT gm.*, 
                   CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS name,
                   ep.position, ep.department, ep.employee_number, ep.is_active
            FROM attendance_group_members gm
            JOIN employee_profiles ep ON gm.profile_id = ep.profile_id AND gm.tenant_id = ep.tenant_id
            WHERE gm.group_id = :gid AND gm.tenant_id = :tid
            ORDER BY ep.last_name, ep.first_name
        ");
        $stmt->execute([':gid' => $groupId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO attendance_groups (tenant_id, name, leader_profile_id, pal_team_lead_email, description)
                VALUES (:t, :name, :lid, :pal, :desc)
            ");
            $stmt->execute([
                ':t' => $this->tenantId,
                ':name' => $data['name'],
                ':lid' => (int)$data['leader_profile_id'],
                ':pal' => $data['pal_team_lead_email'] ?? null,
                ':desc' => $data['description'] ?? null,
            ]);
            $groupId = (int)$this->db->lastInsertId();

            // Auto-add leader as first member
            $this->addMember($groupId, (int)$data['leader_profile_id']);

            // Add additional members
            if (!empty($data['member_profile_ids']) && is_array($data['member_profile_ids'])) {
                foreach ($data['member_profile_ids'] as $pid) {
                    if ((int)$pid !== (int)$data['leader_profile_id']) {
                        $this->addMember($groupId, (int)$pid);
                    }
                }
            }

            $this->db->commit();
            return $groupId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $groupId, array $data): void
    {
        $fields = [];
        $params = [':gid' => $groupId, ':tid' => $this->tenantId];

        $mappings = ['name', 'leader_profile_id', 'pal_team_lead_email', 'description'];
        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] !== '' ? $data[$f] : null;
            }
        }
        if (empty($fields)) return;

        $sql = 'UPDATE attendance_groups SET ' . implode(', ', $fields) . ' WHERE group_id = :gid AND tenant_id = :tid';
        $this->db->prepare($sql)->execute($params);

        // Update members if provided
        if (isset($data['member_profile_ids']) && is_array($data['member_profile_ids'])) {
            $this->db->beginTransaction();
            try {
                $this->db->prepare("DELETE FROM attendance_group_members WHERE group_id = :gid AND tenant_id = :tid")
                    ->execute([':gid' => $groupId, ':tid' => $this->tenantId]);

                // Always include leader
                $group = $this->get($groupId);
                $leaderId = (int)($data['leader_profile_id'] ?? $group['leader_profile_id']);
                $this->addMember($groupId, $leaderId);

                foreach ($data['member_profile_ids'] as $pid) {
                    if ((int)$pid !== $leaderId) {
                        $this->addMember($groupId, (int)$pid);
                    }
                }
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    public function addMember(int $groupId, int $profileId): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO attendance_group_members (tenant_id, group_id, profile_id)
            VALUES (:t, :gid, :pid)
        ");
        $stmt->execute([':t' => $this->tenantId, ':gid' => $groupId, ':pid' => $profileId]);
    }

    public function removeMember(int $groupId, int $profileId): void
    {
        $this->db->prepare("
            DELETE FROM attendance_group_members WHERE group_id = :gid AND profile_id = :pid AND tenant_id = :tid
        ")->execute([':gid' => $groupId, ':pid' => $profileId, ':tid' => $this->tenantId]);
    }

    public function toggleActive(int $groupId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE attendance_groups SET is_active = NOT is_active, updated_at = NOW()
            WHERE group_id = :gid AND tenant_id = :tid
        ");
        $stmt->execute([':gid' => $groupId, ':tid' => $this->tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get groups where a specific profile is the leader.
     */
    public function getGroupsByLeader(int $profileId): array
    {
        $stmt = $this->db->prepare("
            SELECT g.*,
                   (SELECT COUNT(*) FROM attendance_group_members gm WHERE gm.group_id = g.group_id AND gm.tenant_id = g.tenant_id) AS member_count
            FROM attendance_groups g
            WHERE g.tenant_id = :tid AND g.leader_profile_id = :pid AND g.is_active = 1
            ORDER BY g.name ASC
        ");
        $stmt->execute([':tid' => $this->tenantId, ':pid' => $profileId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get group attendance records for a date range.
     * This is the bridge query PAL will use for team lead attendance view.
     */
    public function getGroupAttendance(int $groupId, string $dateFrom, string $dateTo): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                ar.id,
                ar.clock_in, ar.clock_out,
                TIMESTAMPDIFF(MINUTE, ar.clock_in, ar.clock_out) AS minutes_worked,
                ROUND(TIMESTAMPDIFF(MINUTE, ar.clock_in, ar.clock_out) / 60.0, 2) AS hours_worked,
                ar.status,
                ar.created_at,
                CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, '')) AS employee_name,
                ep.position, ep.employee_number, ep.profile_id
            FROM attendance_records ar
            JOIN attendance_group_members gm ON ar.user_id = (
                SELECT au.id FROM attendance_wage_users au 
                JOIN employee_profiles ep2 ON au.id = ep2.user_id 
                WHERE ep2.profile_id = gm.profile_id AND ep2.tenant_id = gm.tenant_id
                LIMIT 1
            )
            JOIN employee_profiles ep ON gm.profile_id = ep.profile_id AND gm.tenant_id = ep.tenant_id
            WHERE gm.group_id = :gid 
              AND gm.tenant_id = :tid
              AND ar.clock_in >= :df 
              AND ar.clock_in <= :dt
            ORDER BY ar.clock_in DESC
        ");
        $stmt->execute([
            ':gid' => $groupId,
            ':tid' => $this->tenantId,
            ':df' => $dateFrom . ' 00:00:00',
            ':dt' => $dateTo . ' 23:59:59',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
