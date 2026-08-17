<?php

declare(strict_types=1);

class palMaterialIssuanceService
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
        $where = ['mi.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];
        if (!empty($filters['project_id'])) { $where[] = 'mi.project_id = :pid'; $params[':pid'] = (int)$filters['project_id']; }
        if (!empty($filters['status'])) { $where[] = 'mi.status = :s'; $params[':s'] = $filters['status']; }
        $w = implode(' AND ', $where);
        $sql = "SELECT mi.*, p.title AS project_title, (SELECT COUNT(*) FROM pal_material_issuance_items WHERE issuance_id = mi.id) AS item_count FROM pal_material_issuances mi LEFT JOIN pal_projects p ON mi.project_id = p.id WHERE {$w} ORDER BY mi.created_at DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT mi.*, p.title AS project_title FROM pal_material_issuances mi LEFT JOIN pal_projects p ON mi.project_id = p.id WHERE mi.id = :id AND mi.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $items = $this->db->prepare("SELECT mii.*, m.name AS material_name FROM pal_material_issuance_items mii LEFT JOIN pal_materials m ON mii.material_id = m.id WHERE mii.issuance_id = :iid");
        $items->execute([':iid' => $id]);
        $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_material_issuances WHERE tenant_id = :tid");
            $cStmt->execute([':tid' => $this->tenantId]);
            $num = 'MI-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            $stmt = $this->db->prepare("INSERT INTO pal_material_issuances (tenant_id, issuance_number, project_id, issuance_date, purpose, notes, status, requested_by, created_by) VALUES (:t, :n, :pj, :d, :purp, :no, 'requested', :rb, :cb)");
            $stmt->execute([':t' => $this->tenantId, ':n' => $num, ':pj' => (int)$data['project_id'], ':d' => $data['issuance_date'] ?? date('Y-m-d'), ':purp' => $data['purpose'] ?? null, ':no' => $data['notes'] ?? null, ':rb' => $this->userId, ':cb' => $this->userId]);
            $issuanceId = (int)$this->db->lastInsertId();

            if (!empty($data['items']) && is_array($data['items'])) {
                $ins = $this->db->prepare("INSERT INTO pal_material_issuance_items (tenant_id, issuance_id, material_id, requested_qty, unit_cost) VALUES (:t, :iid, :mid, :qty, :uc)");
                foreach ($data['items'] as $item) {
                    $avgStmt = $this->db->prepare("SELECT COALESCE(avg_cost, 0) FROM pal_inventory_balances WHERE material_id = :mid AND tenant_id = :tid");
                    $avgStmt->execute([':mid' => (int)$item['material_id'], ':tid' => $this->tenantId]);
                    $avgCost = (float)$avgStmt->fetchColumn();
                    $ins->execute([':t' => $this->tenantId, ':iid' => $issuanceId, ':mid' => (int)$item['material_id'], ':qty' => $item['quantity'] ?? 0, ':uc' => $avgCost]);
                }
            }
            $this->db->commit();
            return $issuanceId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function submit(int $id): int
    {
        $iss = $this->db->prepare("SELECT * FROM pal_material_issuances WHERE id = :id AND tenant_id = :tid");
        $iss->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $iss->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Issuance not found.');
        if ($row['status'] !== 'requested') throw new InvalidArgumentException('Only requested issuances can be submitted.');

        $this->db->prepare("UPDATE pal_material_issuances SET status = 'pending_approval', version = version + 1 WHERE id = :id AND tenant_id = :tid")
             ->execute([':id' => $id, ':tid' => $this->tenantId]);

        $approvalId = palCreateApproval('issuance', $id, $this->userId, $row['status'], 'pending_approval');
        palAudit('pal.issuance.submitted', $this->userId, 'pal_material_issuances', (string)$id,
            ['status' => $row['status']], ['status' => 'pending_approval']);
        palFireEvent('pal.issuance.submitted', ['issuance_id' => $id, 'approval_id' => $approvalId]);
        return $approvalId;
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id, ':tid' => $this->tenantId];
        $mappings = ['project_id', 'purpose', 'notes', 'issuance_date'];
        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] ?: null;
            }
        }
        if (empty($fields)) return;
        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_material_issuances SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
