<?php

declare(strict_types=1);

class palSalesService
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
        $where = ['s.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];
        if (!empty($filters['project_id'])) { $where[] = 's.project_id = :pid'; $params[':pid'] = (int)$filters['project_id']; }
        if (!empty($filters['status'])) { $where[] = 's.status = :st'; $params[':st'] = $filters['status']; }
        $w = implode(' AND ', $where);
        $sql = "SELECT s.*, p.title AS project_title, c.name AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE {$w} ORDER BY s.created_at DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT s.*, p.title AS project_title, c.name AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE s.id = :id AND s.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $colStmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE sales_id = :sid AND status = 'approved'");
        $colStmt->execute([':sid' => $id]);
        $row['total_collected'] = (float)$colStmt->fetchColumn();
        $row['outstanding'] = max(0, (float)$row['net_amount'] - $row['total_collected']);
        return $row;
    }

    public function create(array $data): int
    {
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_sales WHERE tenant_id = :tid");
        $cStmt->execute([':tid' => $this->tenantId]);
        $num = 'INV-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare("INSERT INTO pal_sales (tenant_id, sales_number, project_id, client_id, invoice_number, sales_date, gross_amount, discount_amount, tax_amount, due_date, payment_terms, notes, status, created_by) VALUES (:t, :sn, :pj, :cl, :inv, :sd, :ga, :da, :ta, :dd, :pt, :no, 'issued', :cb)");
        $stmt->execute([':t' => $this->tenantId, ':sn' => $num, ':pj' => !empty($data['project_id']) ? (int)$data['project_id'] : null, ':cl' => !empty($data['client_id']) ? (int)$data['client_id'] : null, ':inv' => $data['invoice_number'] ?? null, ':sd' => $data['sales_date'] ?? date('Y-m-d'), ':ga' => $data['gross_amount'] ?? 0, ':da' => $data['discount_amount'] ?? 0, ':ta' => $data['tax_amount'] ?? 0, ':dd' => $data['due_date'] ?? null, ':pt' => $data['payment_terms'] ?? null, ':no' => $data['notes'] ?? null, ':cb' => $this->userId]);
        return (int)$this->db->lastInsertId();
    }

    public function recordCollection(array $data): int
    {
        $sale = $this->get((int)$data['sales_id']);
        if (!$sale) throw new InvalidArgumentException('Sale not found.');

        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_collections WHERE tenant_id = :tid");
        $cStmt->execute([':tid' => $this->tenantId]);
        $num = 'COL-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO pal_collections (tenant_id, collection_number, sales_id, project_id, client_id, payment_date, amount, payment_method, reference_number, notes, received_by, status, created_by) VALUES (:t, :cn, :si, :pj, :cl, :pd, :amt, :pm, :ref, :no, :rb, 'pending', :cb)");
            $stmt->execute([':t' => $this->tenantId, ':cn' => $num, ':si' => (int)$data['sales_id'], ':pj' => $sale['project_id'], ':cl' => $sale['client_id'], ':pd' => $data['payment_date'] ?? date('Y-m-d'), ':amt' => $data['amount'] ?? 0, ':pm' => $data['payment_method'] ?? null, ':ref' => $data['reference_number'] ?? null, ':no' => $data['notes'] ?? null, ':rb' => $this->userId, ':cb' => $this->userId]);

            $collectionId = (int)$this->db->lastInsertId();

            // Create approval record — ApprovalService::decide() updates sale status on approval
            $approvalId = palCreateApproval('collection', $collectionId, $this->userId, 'pending', 'pending_approval');

            $this->db->commit();

            palAudit('pal.collection.recorded', $this->userId, 'pal_collections', (string)$collectionId,
                null, ['sales_id' => $data['sales_id'], 'amount' => $data['amount'] ?? 0]);
            palFireEvent('pal.collection.recorded', [
                'collection_id' => $collectionId, 'sales_id' => (int)$data['sales_id'],
                'amount' => $data['amount'] ?? 0, 'approval_id' => $approvalId,
            ]);

            return $collectionId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id, ':tid' => $this->tenantId];
        $mappings = ['project_id', 'client_id', 'invoice_number', 'sales_date', 'gross_amount', 'discount_amount', 'tax_amount', 'due_date', 'payment_terms', 'notes'];
        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] ?: null;
            }
        }
        if (empty($fields)) return;
        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_sales SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
