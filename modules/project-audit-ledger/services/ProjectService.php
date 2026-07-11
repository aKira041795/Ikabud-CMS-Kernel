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
        if (!is_array($row)) return null;
        $row['items'] = $this->getItems($id);
        return $row;
    }

    public function getItems(int $projectId): array
    {
        $stmt = $this->db->prepare("SELECT pi.*, m.name AS material_name, m.material_code
                                     FROM pal_project_items pi
                                     LEFT JOIN pal_materials m ON pi.material_id = m.id
                                     WHERE pi.project_id = :pid AND pi.tenant_id = :tid
                                     ORDER BY pi.sort_order ASC");
        $stmt->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $this->validate($data);

        // Auto-generate JO number if not provided
        if (empty($data['job_order_number'])) {
            $joCount = $this->db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND job_order_number IS NOT NULL");
            $joCount->execute([':tid' => $this->tenantId]);
            $data['job_order_number'] = 'JO-' . date('Ymd') . '-' . str_pad((string)((int)$joCount->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        }

        // Determine JO type: 'items' (quotation) or 'contract' (contracted amount)
        $joType = $data['_jo_type'] ?? (empty($data['items']) ? 'contract' : 'items');
        $withInstallation = !empty($data['with_installation']) ? 1 : 0;

        // Calculate contract_amount based on JO type
        if ($joType === 'contract') {
            $contractAmount = !empty($data['contract_amount']) ? (float)$data['contract_amount'] : 0;
            $installationCharge = 0;
            $mobilizationCharge = 0;
            $otherCharges = 0;
            $items = [];
        } else {
            $items = $data['items'] ?? [];
            $calculatedTotal = $this->calculateItemsTotal($items);
            $contractAmount = !empty($data['contract_amount']) ? (float)$data['contract_amount'] : $calculatedTotal;
            $installationCharge = (float)($data['installation_charge'] ?? 0);
            $mobilizationCharge = (float)($data['mobilization_charge'] ?? 0);
            $otherCharges = (float)($data['other_charges'] ?? 0);
            $contractAmount = max($contractAmount, $calculatedTotal + $installationCharge + $mobilizationCharge + $otherCharges);
        }

        // Fabrication fields: only when with_installation = YES and items-type JO
        $fabTeamLeadId = ($withInstallation && $joType === 'items' && !empty($data['fabrication_team_lead_id']))
            ? (int)$data['fabrication_team_lead_id'] : null;
        $fabAllocPct = ($withInstallation && $joType === 'items') ? ($data['fabrication_alloc_pct'] ?? null) : null;
        $fabAllocBasis = ($withInstallation && $joType === 'items') ? ($data['fabrication_alloc_basis'] ?? 'expenses') : null;
        $fabAllocFixed = ($withInstallation && $joType === 'items') ? ($data['fabrication_alloc_fixed'] ?? null) : null;

        $sql = "INSERT INTO pal_projects (
                    tenant_id, project_id, job_order_number, title, client_id,
                    project_type_id, scope_of_work, with_installation,
                    description, location, contract_amount, installation_charge,
                    mobilization_charge, other_charges, mode_of_payment,
                    down_payment, down_payment_type, estimated_cost,
                    start_date, target_completion_date,
                    project_manager, fabrication_team_lead_id,
                    fabrication_alloc_pct, fabrication_alloc_basis,
                    fabrication_alloc_fixed, status, budget_warning_pct,
                    notes, created_by
                ) VALUES (
                    :tenant_id, :project_id, :job_order_number, :title, :client_id,
                    :project_type_id, :scope_of_work, :with_installation,
                    :description, :location, :contract_amount, :installation_charge,
                    :mobilization_charge, :other_charges, :mode_of_payment,
                    :down_payment, :down_payment_type, :estimated_cost,
                    :start_date, :target_completion_date,
                    :project_manager, :fabrication_team_lead_id,
                    :fabrication_alloc_pct, :fabrication_alloc_basis,
                    :fabrication_alloc_fixed, :status, :budget_warning_pct,
                    :notes, :created_by
                )";

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $this->tenantId,
                ':project_id' => $data['project_id'],
                ':job_order_number' => $data['job_order_number'],
                ':title' => $data['title'],
                ':client_id' => !empty($data['client_id']) ? (int)$data['client_id'] : null,
                ':project_type_id' => !empty($data['project_type_id']) ? (int)$data['project_type_id'] : null,
                ':scope_of_work' => $data['scope_of_work'] ?? null,
                ':with_installation' => $withInstallation,
                ':description' => $data['description'] ?? null,
                ':location' => $data['location'] ?? null,
                ':contract_amount' => $contractAmount,
                ':installation_charge' => $installationCharge,
                ':mobilization_charge' => $mobilizationCharge,
                ':other_charges' => $otherCharges,
                ':mode_of_payment' => $data['mode_of_payment'] ?? null,
                ':down_payment' => !empty($data['down_payment']) ? (float)$data['down_payment'] : null,
                ':down_payment_type' => $data['down_payment_type'] ?? null,
                ':estimated_cost' => $data['estimated_cost'] ?? 0,
                ':start_date' => $data['start_date'] ?? null,
                ':target_completion_date' => $data['target_completion_date'] ?? null,
                ':project_manager' => $data['project_manager'] ?? null,
                ':fabrication_team_lead_id' => $fabTeamLeadId,
                ':fabrication_alloc_pct' => $fabAllocPct,
                ':fabrication_alloc_basis' => $fabAllocBasis,
                ':fabrication_alloc_fixed' => $fabAllocFixed,
                ':status' => $data['status'] ?? 'draft',
                ':budget_warning_pct' => $data['budget_warning_pct'] ?? 80,
                ':notes' => $data['notes'] ?? null,
                ':created_by' => $this->userId,
            ]);

            $newId = (int)$this->db->lastInsertId();

            // Save items
            if (!empty($items)) {
                $this->saveItems($newId, $items);
            }

            $this->db->commit();
            palFireEvent('pal.project.created', ['project_id' => $newId, 'title' => $data['title']]);
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        $project = $this->get($id);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $allowed = ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed'];
        $fields = [];
        $params = [':id' => $id, ':tenant_id' => $this->tenantId];

        foreach ([
            'project_id', 'job_order_number', 'title', 'client_id', 'project_type_id',
            'scope_of_work', 'with_installation',
            'description', 'location', 'contract_amount', 'estimated_cost',
            'installation_charge', 'mobilization_charge', 'other_charges',
            'mode_of_payment', 'down_payment', 'down_payment_type',
            'start_date', 'target_completion_date', 'actual_completion_date',
            'project_manager', 'fabrication_team_lead_id',
            'fabrication_alloc_pct', 'fabrication_alloc_basis',
            'fabrication_alloc_fixed', 'notes', 'budget_warning_pct',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                // Handle with_installation as int
                if ($field === 'with_installation') {
                    $val = !empty($val) ? 1 : 0;
                }
                // Null out fabrication fields when with_installation is off
                if ($val === 0 && $field === 'with_installation') {
                    // Also null out fabrication fields
                    if (array_key_exists('fabrication_team_lead_id', $data)) {
                        $fields[] = 'fabrication_team_lead_id = :_fab_tlid';
                        $params[':_fab_tlid'] = null;
                    }
                    if (array_key_exists('fabrication_alloc_pct', $data)) {
                        $fields[] = 'fabrication_alloc_pct = :_fab_pct';
                        $params[':_fab_pct'] = null;
                    }
                    if (array_key_exists('fabrication_alloc_basis', $data)) {
                        $fields[] = 'fabrication_alloc_basis = :_fab_basis';
                        $params[':_fab_basis'] = null;
                    }
                    if (array_key_exists('fabrication_alloc_fixed', $data)) {
                        $fields[] = 'fabrication_alloc_fixed = :_fab_fixed';
                        $params[':_fab_fixed'] = null;
                    }
                }
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $val;
            }
        }

        // Determine JO type and recalculate contract_amount
        $joType = $data['_jo_type'] ?? (isset($data['items']) ? 'items' : null);
        if ($joType === 'contract' && !empty($data['contract_amount'])) {
            // Contracted amount: zero out charges, keep contract_amount as-is
            $fields[] = 'installation_charge = :_ic_zero';
            $params[':_ic_zero'] = 0;
            $fields[] = 'mobilization_charge = :_mc_zero';
            $params[':_mc_zero'] = 0;
            $fields[] = 'other_charges = :_oc_zero';
            $params[':_oc_zero'] = 0;
        } elseif ($joType === 'items' && isset($data['items'])) {
            // Items mode: recalculate from items + charges
            $installationCharge = (float)($data['installation_charge'] ?? $project['installation_charge'] ?? 0);
            $mobilizationCharge = (float)($data['mobilization_charge'] ?? $project['mobilization_charge'] ?? 0);
            $otherCharges = (float)($data['other_charges'] ?? $project['other_charges'] ?? 0);
            $itemsTotal = $this->calculateItemsTotal($data['items']);
            $newTotal = $itemsTotal + $installationCharge + $mobilizationCharge + $otherCharges;
            $fields[] = 'contract_amount = :_new_total';
            $params[':_new_total'] = $newTotal;
        } elseif (isset($data['items'])) {
            // Legacy: items provided without _jo_type
            $installationCharge = (float)($data['installation_charge'] ?? $project['installation_charge'] ?? 0);
            $mobilizationCharge = (float)($data['mobilization_charge'] ?? $project['mobilization_charge'] ?? 0);
            $otherCharges = (float)($data['other_charges'] ?? $project['other_charges'] ?? 0);
            $itemsTotal = $this->calculateItemsTotal($data['items']);
            $newTotal = $itemsTotal + $installationCharge + $mobilizationCharge + $otherCharges;
            $fields[] = 'contract_amount = :_new_total';
            $params[':_new_total'] = $newTotal;
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

        $this->db->beginTransaction();
        try {
            $sql = 'UPDATE pal_projects SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            // Save items if provided
            if (isset($data['items'])) {
                $this->db->prepare("DELETE FROM pal_project_items WHERE project_id = :pid AND tenant_id = :tid")
                    ->execute([':pid' => $id, ':tid' => $this->tenantId]);
                $this->saveItems($id, $data['items']);
            }

            $this->db->commit();

            $changed = $stmt->rowCount() > 0;
            if ($changed) {
                palFireEvent('pal.project.updated', ['project_id' => $id, 'updated_fields' => array_keys($data)]);
            }
            return $changed;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status): bool
    {
        $allowed = ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed'];
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

    private function saveItems(int $projectId, array $items): void
    {
        if (empty($items)) return;

        $stmt = $this->db->prepare("INSERT INTO pal_project_items 
            (tenant_id, project_id, material_id, particulars, width, height, uom, quantity,
             price_per_unit, price_per_sqft, line_total, sort_order)
            VALUES (:t, :pj, :mi, :part, :w, :h, :uom, :qty, :ppu, :psf, :lt, :so)");

        foreach ($items as $i => $item) {
            $qty = (float)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['price_per_unit'] ?? 0);
            $sqftPrice = (float)($item['price_per_sqft'] ?? 0);
            $w = (float)($item['width'] ?? 0);
            $h = (float)($item['height'] ?? 0);

            if ($sqftPrice > 0 && $w > 0 && $h > 0) {
                $lineTotal = $w * $h * $sqftPrice * $qty;
            } else {
                $lineTotal = $unitPrice * $qty;
            }

            $stmt->execute([
                ':t' => $this->tenantId,
                ':pj' => $projectId,
                ':mi' => !empty($item['material_id']) ? (int)$item['material_id'] : null,
                ':part' => $item['particulars'] ?? '',
                ':w' => !empty($item['width']) ? (float)$item['width'] : null,
                ':h' => !empty($item['height']) ? (float)$item['height'] : null,
                ':uom' => $item['uom'] ?? null,
                ':qty' => $qty,
                ':ppu' => $unitPrice,
                ':psf' => $sqftPrice > 0 ? $sqftPrice : null,
                ':lt' => $lineTotal,
                ':so' => $i + 1,
            ]);
        }
    }

    private function calculateItemsTotal(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['price_per_unit'] ?? 0);
            $sqftPrice = (float)($item['price_per_sqft'] ?? 0);
            $w = (float)($item['width'] ?? 0);
            $h = (float)($item['height'] ?? 0);

            if ($sqftPrice > 0 && $w > 0 && $h > 0) {
                $total += $w * $h * $sqftPrice * $qty;
            } else {
                $total += $unitPrice * $qty;
            }
        }
        return $total;
    }
}
