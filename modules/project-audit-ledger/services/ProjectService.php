<?php

declare(strict_types=1);

/**
 * Domain service for project CRUD and status management.
 */
class palProjectService
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

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['p.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];

        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'p.client_id = :client_id';
            $params[':client_id'] = (int)$filters['client_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.title LIKE :search OR p.project_id LIKE :search2)';
            $params[':search'] = '%' . $filters['search'] . '%';
            $params[':search2'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM pal_projects p WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT p.*, c.name AS client_name, pt.name AS project_type_name,
                       ROUND((p.estimated_cost + COALESCE((SELECT SUM(fa2.approved_amount) FROM pal_fabrication_allocations fa2 WHERE fa2.project_id = p.id), 0)) / NULLIF(p.contract_amount, 0) * 100, 1) AS budget_used_pct
                FROM pal_projects p
                LEFT JOIN pal_clients c ON p.client_id = c.id
                LEFT JOIN pal_project_types pt ON p.project_type_id = pt.id
                WHERE {$whereClause}
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['rows' => $rows, 'total' => $total];
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT p.*, c.name AS client_name, c.contact_person AS client_contact,
                       c.email AS client_email, c.phone AS client_phone,
                       pt.name AS project_type_name, tl.name AS team_lead_name
                FROM pal_projects p
                LEFT JOIN pal_clients c ON p.client_id = c.id
                LEFT JOIN pal_project_types pt ON p.project_type_id = pt.id
                LEFT JOIN pal_team_leads tl ON p.fabrication_team_lead_id = tl.id
                WHERE p.id = :id AND p.tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tenant_id' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $this->validate($data);

        $sql = "INSERT INTO pal_projects (
                    tenant_id, project_id, job_order_number, title, client_id,
                    project_type_id, description, location, contract_amount,
                    estimated_cost, start_date, target_completion_date,
                    project_manager, fabrication_team_lead_id,
                    fabrication_alloc_pct, fabrication_alloc_basis,
                    fabrication_alloc_fixed, status, budget_warning_pct,
                    notes, created_by
                ) VALUES (
                    :tenant_id, :project_id, :job_order_number, :title, :client_id,
                    :project_type_id, :description, :location, :contract_amount,
                    :estimated_cost, :start_date, :target_completion_date,
                    :project_manager, :fabrication_team_lead_id,
                    :fabrication_alloc_pct, :fabrication_alloc_basis,
                    :fabrication_alloc_fixed, :status, :budget_warning_pct,
                    :notes, :created_by
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':project_id' => $data['project_id'],
            ':job_order_number' => $data['job_order_number'] ?? null,
            ':title' => $data['title'],
            ':client_id' => !empty($data['client_id']) ? (int)$data['client_id'] : null,
            ':project_type_id' => !empty($data['project_type_id']) ? (int)$data['project_type_id'] : null,
            ':description' => $data['description'] ?? null,
            ':location' => $data['location'] ?? null,
            ':contract_amount' => $data['contract_amount'] ?? 0,
            ':estimated_cost' => $data['estimated_cost'] ?? 0,
            ':start_date' => $data['start_date'] ?? null,
            ':target_completion_date' => $data['target_completion_date'] ?? null,
            ':project_manager' => $data['project_manager'] ?? null,
            ':fabrication_team_lead_id' => !empty($data['fabrication_team_lead_id']) ? (int)$data['fabrication_team_lead_id'] : null,
            ':fabrication_alloc_pct' => $data['fabrication_alloc_pct'] ?? null,
            ':fabrication_alloc_basis' => $data['fabrication_alloc_basis'] ?? 'expenses',
            ':fabrication_alloc_fixed' => $data['fabrication_alloc_fixed'] ?? null,
            ':status' => $data['status'] ?? 'draft',
            ':budget_warning_pct' => $data['budget_warning_pct'] ?? 80,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $this->userId,
        ]);

        $newId = (int)$this->db->lastInsertId();
        palFireEvent('pal.project.created', ['project_id' => $newId, 'title' => $data['title']]);
        return $newId;
    }

    public function update(int $id, array $data): bool
    {
        $project = $this->get($id);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $allowed = ['draft', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'closed'];
        $fields = [];
        $params = [':id' => $id, ':tenant_id' => $this->tenantId];

        foreach ([
            'project_id', 'job_order_number', 'title', 'client_id', 'project_type_id',
            'description', 'location', 'contract_amount', 'estimated_cost',
            'start_date', 'target_completion_date', 'actual_completion_date',
            'project_manager', 'fabrication_team_lead_id',
            'fabrication_alloc_pct', 'fabrication_alloc_basis',
            'fabrication_alloc_fixed', 'notes', 'budget_warning_pct',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            if (!in_array($data['status'], $allowed, true)) {
                throw new InvalidArgumentException('Invalid status: ' . $data['status']);
            }
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'version = version + 1';
        $fields[] = 'updated_by = :updated_by';
        $params[':updated_by'] = $this->userId;

        $sql = 'UPDATE pal_projects SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            palFireEvent('pal.project.updated', ['project_id' => $id, 'updated_fields' => array_keys($data)]);
        }
        return $changed;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $allowed = ['draft', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status: ' . $status);
        }

        $sql = 'UPDATE pal_projects SET status = :status, version = version + 1, updated_by = :updated_by WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':updated_by' => $this->userId,
            ':id' => $id,
            ':tenant_id' => $this->tenantId,
        ]);

        $changed = $stmt->rowCount() > 0;
        if ($changed && $status === 'completed') {
            $this->db->prepare('UPDATE pal_projects SET actual_completion_date = CURDATE() WHERE id = :id')
                 ->execute([':id' => $id]);
        }

        return $changed;
    }

    private function validate(array $data): void
    {
        if (empty($data['project_id'])) {
            throw new InvalidArgumentException('Project ID is required.');
        }
        if (empty($data['title'])) {
            throw new InvalidArgumentException('Project title is required.');
        }
        if (isset($data['contract_amount']) && $data['contract_amount'] < 0) {
            throw new InvalidArgumentException('Contract amount cannot be negative.');
        }
        if (isset($data['estimated_cost']) && $data['estimated_cost'] < 0) {
            throw new InvalidArgumentException('Estimated cost cannot be negative.');
        }
    }
}
