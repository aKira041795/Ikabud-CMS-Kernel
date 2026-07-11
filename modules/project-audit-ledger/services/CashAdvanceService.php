<?php

declare(strict_types=1);

class palCashAdvanceService
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
        $where = ['ca.tenant_id = :tid'];
        $params = [':tid' => $this->tenantId];

        if (!empty($filters['status'])) {
            $where[] = 'ca.status = :st';
            $params[':st'] = $filters['status'];
        }
        if (!empty($filters['team_lead_id'])) {
            $where[] = 'ca.team_lead_id = :tl';
            $params[':tl'] = (int)$filters['team_lead_id'];
        }

        $w = implode(' AND ', $where);
        $sql = "SELECT ca.*, tl.name AS team_lead_name, p.title AS project_title 
                FROM pal_cash_advances ca 
                LEFT JOIN pal_team_leads tl ON ca.team_lead_id = tl.id 
                LEFT JOIN pal_projects p ON ca.project_id = p.id 
                WHERE {$w} ORDER BY ca.advance_date DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT ca.*, tl.name AS team_lead_name, tl.contact_number AS team_lead_contact, 
                       p.title AS project_title 
                FROM pal_cash_advances ca 
                LEFT JOIN pal_team_leads tl ON ca.team_lead_id = tl.id 
                LEFT JOIN pal_projects p ON ca.project_id = p.id 
                WHERE ca.id = :id AND ca.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("INSERT INTO pal_cash_advances 
            (tenant_id, team_lead_id, project_id, amount, advance_date, description, status, created_by) 
            VALUES (:t, :tl, :pj, :amt, :ad, :desc, 'pending', :cb)");
        $stmt->execute([
            ':t' => $this->tenantId,
            ':tl' => (int)$data['team_lead_id'],
            ':pj' => !empty($data['project_id']) ? (int)$data['project_id'] : null,
            ':amt' => (float)($data['amount'] ?? 0),
            ':ad' => $data['advance_date'] ?? date('Y-m-d'),
            ':desc' => $data['description'] ?? null,
            ':cb' => $this->userId,
        ]);

        $id = (int)$this->db->lastInsertId();

        palAudit('pal.cash_advance.created', $this->userId, 'pal_cash_advances', (string)$id,
            null, ['amount' => $data['amount'] ?? 0, 'team_lead_id' => $data['team_lead_id']]);
        palFireEvent('pal.cash_advance.created', [
            'cash_advance_id' => $id, 'amount' => $data['amount'] ?? 0,
        ]);

        return $id;
    }

    public function approve(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE pal_cash_advances SET status = 'approved' WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);

        palAudit('pal.cash_advance.approved', $this->userId, 'pal_cash_advances', (string)$id, null, []);
        palFireEvent('pal.cash_advance.approved', ['cash_advance_id' => $id]);
    }

    public function settle(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE pal_cash_advances SET status = 'settled', settled_at = NOW() WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);

        palAudit('pal.cash_advance.settled', $this->userId, 'pal_cash_advances', (string)$id, null, []);
        palFireEvent('pal.cash_advance.settled', ['cash_advance_id' => $id]);
    }

    public function void(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE pal_cash_advances SET status = 'voided' WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);

        palAudit('pal.cash_advance.voided', $this->userId, 'pal_cash_advances', (string)$id, null, []);
        palFireEvent('pal.cash_advance.voided', ['cash_advance_id' => $id]);
    }

    /**
     * Get total outstanding advances for a team lead.
     */
    public function getTeamLeadBalance(int $teamLeadId): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_cash_advances WHERE team_lead_id = :tl AND tenant_id = :tid AND status IN ('pending', 'approved')");
        $stmt->execute([':tl' => $teamLeadId, ':tid' => $this->tenantId]);
        return (float)$stmt->fetchColumn();
    }
}
