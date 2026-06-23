<?php

declare(strict_types=1);

class palInventoryService
{
    private Ikabud\Kernel\Contracts\ModuleDB $db;
    private int $tenantId;

    public function __construct(Ikabud\Kernel\Contracts\ModuleDB $db, int $tenantId)
    {
        $this->db = $db;
        $this->tenantId = $tenantId;
    }

    public function listMaterials(array $filters = []): array
    {
        $where = ['m.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];
        if (!empty($filters['category_id'])) { $where[] = 'm.category_id = :cat'; $params[':cat'] = (int)$filters['category_id']; }
        if (!empty($filters['search'])) { $where[] = '(m.name LIKE :s OR m.material_code LIKE :s2)'; $params[':s'] = "%{$filters['search']}%"; $params[':s2'] = "%{$filters['search']}%"; }
        $w = implode(' AND ', $where);
        $sql = "SELECT m.*, mc.name AS category_name, COALESCE(b.quantity, 0) AS stock_qty, COALESCE(b.avg_cost, m.current_avg_cost) AS avg_cost, COALESCE(b.quantity * COALESCE(b.avg_cost, m.current_avg_cost), 0) AS stock_value FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id = mc.id LEFT JOIN pal_inventory_balances b ON m.id = b.material_id WHERE {$w} ORDER BY m.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMaterial(int $id): ?array
    {
        $sql = "SELECT m.*, mc.name AS category_name, u.name AS unit_name, cu.name AS conversion_unit_name, COALESCE(b.quantity, 0) AS stock_qty, COALESCE(b.avg_cost, m.current_avg_cost) AS avg_cost FROM pal_materials m LEFT JOIN pal_material_categories mc ON m.category_id = mc.id LEFT JOIN pal_units u ON m.unit_id = u.id LEFT JOIN pal_units cu ON m.conversion_unit_id = cu.id LEFT JOIN pal_inventory_balances b ON m.id = b.material_id WHERE m.id = :id AND m.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $mv = $this->db->prepare("SELECT * FROM pal_inventory_movements WHERE material_id = :mid AND tenant_id = :tid ORDER BY created_at DESC LIMIT 100");
        $mv->execute([':mid' => $id, ':tid' => $this->tenantId]);
        $row['movements'] = $mv->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function createMaterial(array $data): int
    {
        $stmt = $this->db->prepare("INSERT INTO pal_materials (tenant_id, material_code, name, category_id, description, unit_id, reorder_level, preferred_supplier_id, storage_location, is_active, created_by) VALUES (:t, :mc, :n, :cat, :desc, :u, :rl, :ps, :sl, 1, :cb)");
        $stmt->execute([':t' => $this->tenantId, ':mc' => $data['material_code'], ':n' => $data['name'], ':cat' => !empty($data['category_id']) ? (int)$data['category_id'] : null, ':desc' => $data['description'] ?? null, ':u' => !empty($data['unit_id']) ? (int)$data['unit_id'] : null, ':rl' => $data['reorder_level'] ?? null, ':ps' => !empty($data['preferred_supplier_id']) ? (int)$data['preferred_supplier_id'] : null, ':sl' => $data['storage_location'] ?? null, ':cb' => (int)($data['created_by'] ?? 0)]);
        return (int)$this->db->lastInsertId();
    }
}
