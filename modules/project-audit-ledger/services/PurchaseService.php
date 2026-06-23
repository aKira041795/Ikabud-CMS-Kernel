<?php

declare(strict_types=1);

class palPurchaseService
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
        $where = ['p.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];
        if (!empty($filters['status'])) { $where[] = 'p.status = :status'; $params[':status'] = $filters['status']; }
        if (!empty($filters['supplier_id'])) { $where[] = 'p.supplier_id = :supplier_id'; $params[':supplier_id'] = (int)$filters['supplier_id']; }
        $w = implode(' AND ', $where);
        $sql = "SELECT p.*, s.name AS supplier_name, (SELECT COUNT(*) FROM pal_purchase_items pi WHERE pi.purchase_id = p.id) AS item_count FROM pal_purchases p LEFT JOIN pal_suppliers s ON p.supplier_id = s.id WHERE {$w} ORDER BY p.created_at DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT p.*, s.name AS supplier_name FROM pal_purchases p LEFT JOIN pal_suppliers s ON p.supplier_id = s.id WHERE p.id = :id AND p.tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tenant_id' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $stmt2 = $this->db->prepare("SELECT pi.*, m.name AS material_name, u.abbreviation AS unit_abbr FROM pal_purchase_items pi LEFT JOIN pal_materials m ON pi.material_id = m.id LEFT JOIN pal_units u ON pi.unit_id = u.id WHERE pi.purchase_id = :pid");
        $stmt2->execute([':pid' => $id]);
        $row['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function create(array $data): int
    {
        $this->db->beginTransaction();
        try {
            $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_purchases WHERE tenant_id = :tid");
            $cStmt->execute([':tid' => $this->tenantId]);
            $num = 'PO-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

            // Build items from flat form array if not already structured
            if (!isset($data['items']) && isset($data['material_id']) && is_array($data['material_id'])) {
                $data['items'] = [];
                foreach ($data['material_id'] as $i => $mid) {
                    $data['items'][] = [
                        'material_id' => (int)$mid,
                        'quantity' => (float)($data['quantity'][$i] ?? 0),
                        'unit_cost' => (float)($data['unit_cost'][$i] ?? 0),
                    ];
                }
            }

            $totalAmount = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $totalAmount += (float)($item['quantity'] ?? 0) * (float)($item['unit_cost'] ?? 0);
                }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO pal_purchases (tenant_id, purchase_number, supplier_id, purchase_date, invoice_number, total_amount, tax_amount, discount_amount, freight_amount, notes, status, created_by)
                 VALUES (:t, :n, :s, :d, :inv, :ta, :tax, :disc, :fr, :no, 'draft', :cb)"
            );
            $stmt->execute([
                ':t' => $this->tenantId, ':n' => $num,
                ':s' => !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
                ':d' => $data['purchase_date'] ?? date('Y-m-d'), ':inv' => $data['invoice_number'] ?? null,
                ':ta' => $totalAmount ?: ($data['total_amount'] ?? 0), ':tax' => $data['tax_amount'] ?? 0,
                ':disc' => $data['discount_amount'] ?? 0, ':fr' => $data['freight_amount'] ?? 0,
                ':no' => $data['notes'] ?? null, ':cb' => $this->userId,
            ]);
            $purchaseId = (int)$this->db->lastInsertId();

            if (!empty($data['items']) && is_array($data['items'])) {
                $ins = $this->db->prepare("INSERT INTO pal_purchase_items (purchase_id, material_id, description, quantity, unit_id, unit_cost, batch_number) VALUES (:pid, :mid, :desc, :qty, :uid, :uc, :bn)");
                foreach ($data['items'] as $item) {
                    $ins->execute([
                        ':pid' => $purchaseId, ':mid' => (int)($item['material_id'] ?? 0),
                        ':desc' => $item['description'] ?? null, ':qty' => $item['quantity'] ?? 0,
                        ':uid' => !empty($item['unit_id']) ? (int)$item['unit_id'] : null,
                        ':uc' => $item['unit_cost'] ?? 0, ':bn' => $item['batch_number'] ?? null,
                    ]);
                }
            }
            $this->db->commit();
            return $purchaseId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function submit(int $id): int
    {
        $purchase = $this->get($id);
        if (!$purchase) throw new InvalidArgumentException('Purchase not found.');
        if ($purchase['status'] !== 'draft') throw new InvalidArgumentException('Only draft purchases can be submitted.');

        $this->db->prepare("UPDATE pal_purchases SET status = 'submitted', submitted_by = :sb, submitted_at = NOW(), version = version + 1 WHERE id = :id AND tenant_id = :tid")
             ->execute([':sb' => $this->userId, ':id' => $id, ':tid' => $this->tenantId]);

        $approvalId = palCreateApproval('purchase', $id, $this->userId, $purchase['status'], 'submitted');
        palAudit('pal.purchase.submitted', $this->userId, 'pal_purchases', (string)$id,
            ['status' => $purchase['status']], ['status' => 'submitted']);
        palFireEvent('pal.purchase.submitted', ['purchase_id' => $id, 'approval_id' => $approvalId]);
        return $approvalId;
    }

    public function update(int $id, array $data): void
    {
        $purchase = $this->get($id);
        if (!$purchase) throw new InvalidArgumentException('Purchase not found.');

        $fields = []; $params = [':id' => $id, ':tid' => $this->tenantId];
        foreach (['supplier_id','purchase_date','invoice_number','total_amount','tax_amount','discount_amount','freight_amount','notes'] as $f) {
            if (isset($data[$f])) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) return;
        $fields[] = 'updated_at = NOW()';
        $this->db->prepare("UPDATE pal_purchases SET " . implode(', ', $fields) . " WHERE id = :id AND tenant_id = :tid")->execute($params);
    }
}
