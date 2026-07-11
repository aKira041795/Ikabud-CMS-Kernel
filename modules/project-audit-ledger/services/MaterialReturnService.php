<?php

declare(strict_types=1);

class palMaterialReturnService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;
    private int $userId;

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId, int $userId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
        $this->userId = $userId;
    }

    public function list(array $filters = []): array
    {
        $where = ['mr.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];
        if (!empty($filters['project_id'])) { $where[] = 'mr.project_id = :pj'; $params[':pj'] = (int)$filters['project_id']; }
        if (!empty($filters['material_id'])) { $where[] = 'mr.material_id = :mi'; $params[':mi'] = (int)$filters['material_id']; }
        $w = implode(' AND ', $where);
        $sql = "SELECT mr.*, p.title AS project_title, m.name AS material_name, mi.issuance_number
                FROM pal_material_returns mr
                LEFT JOIN pal_projects p ON mr.project_id = p.id
                LEFT JOIN pal_materials m ON mr.material_id = m.id
                LEFT JOIN pal_material_issuances mi ON mr.issuance_id = mi.id
                WHERE {$w} ORDER BY mr.created_at DESC LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $tid = $this->tenantId;
            $uid = $this->userId;

            $stmt = $this->db->prepare(
                "INSERT INTO pal_material_returns (tenant_id, project_id, issuance_id, material_id, quantity_returned, condition, reason, return_date, received_by, created_by)
                 VALUES (:t, :pj, :ii, :mi, :qr, :cond, :rsn, :rd, :rb, :cb)"
            );
            $stmt->execute([
                ':t' => $tid,
                ':pj' => (int)($data['project_id'] ?? 0),
                ':ii' => !empty($data['issuance_id']) ? (int)$data['issuance_id'] : null,
                ':mi' => (int)($data['material_id'] ?? 0),
                ':qr' => $data['quantity'] ?? 0,
                ':cond' => $data['condition'] ?? 'reusable',
                ':rsn' => $data['reason'] ?? null,
                ':rd' => $data['return_date'] ?? date('Y-m-d'),
                ':rb' => !empty($data['received_by']) ? (int)$data['received_by'] : null,
                ':cb' => $uid,
            ]);
            $returnId = (int)$this->db->lastInsertId();

            // Restock if reusable
            if (($data['condition'] ?? 'reusable') === 'reusable') {
                $bal = $this->db->prepare(
                    "INSERT INTO pal_inventory_balances (tenant_id, material_id, quantity) VALUES (:t, :m, :q)
                     ON DUPLICATE KEY UPDATE quantity = quantity + :q2"
                );
                $bal->execute([
                    ':t' => $tid,
                    ':m' => (int)($data['material_id'] ?? 0),
                    ':q' => $data['quantity'] ?? 0,
                    ':q2' => $data['quantity'] ?? 0,
                ]);

                // Record movement
                $mv = $this->db->prepare(
                    "INSERT INTO pal_inventory_movements (tenant_id, material_id, movement_type, reference_type, reference_id, project_id, quantity, description, created_by)
                     VALUES (:t, :m, 'return', 'material_return', :rid, :pj, :qty, :desc, :cb)"
                );
                $mv->execute([
                    ':t' => $tid,
                    ':m' => (int)($data['material_id'] ?? 0),
                    ':rid' => $returnId,
                    ':pj' => (int)($data['project_id'] ?? 0),
                    ':qty' => $data['quantity'] ?? 0,
                    ':desc' => 'Material return from project',
                    ':cb' => $uid,
                ]);
            }

            palFireEvent('pal.inventory.material_returned', [
                'return_id' => $returnId,
                'material_id' => (int)($data['material_id'] ?? 0),
                'quantity' => $data['quantity'] ?? 0,
                'project_id' => (int)($data['project_id'] ?? 0),
                'condition' => $data['condition'] ?? 'reusable',
            ]);

            $this->db->commit();
            return $returnId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
