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
                        c.email AS client_email, c.phone AS client_phone, c.address AS client_address,
                        pt.name AS project_type_name, tl.name AS team_lead_name
                 FROM pal_projects p
                 LEFT JOIN pal_clients c ON p.client_id = c.id AND c.tenant_id = p.tenant_id
                 LEFT JOIN pal_project_types pt ON p.project_type_id = pt.id AND pt.tenant_id = p.tenant_id
                 LEFT JOIN pal_team_leads tl ON p.fabrication_team_lead_id = tl.id AND tl.tenant_id = p.tenant_id
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
        $data = $this->sanitize($data);
        $this->validate($data);

        // Auto-generate JO number if not provided
        if (empty($data['job_order_number'])) {
            $joCount = $this->db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND job_order_number IS NOT NULL");
            $joCount->execute([':tid' => $this->tenantId]);
            $data['job_order_number'] = 'JO-' . date('Ymd') . '-' . str_pad((string)((int)$joCount->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        }

        // Determine JO type: 'items' (quotation) or 'contract' (contracted amount)
        $joType = $this->normalizeJoType($data['_jo_type'] ?? $data['jo_type'] ?? null, empty($data['items']) ? 'contract' : 'items');
        $withInstallation = !empty($data['with_installation']) ? 1 : 0;

        // Items are always saved (BOM / material tracking)
        $items = $data['items'] ?? [];
        $itemsTotal = !empty($items) ? $this->calculateItemsTotal($items) : 0;

        // Source of truth for contract_amount:
        // - If user explicitly set contract_amount (non-empty, non-zero), keep it
        // - If contract_amount is empty/zero but items exist → items total is truth
        // - If both are empty → 0
        $userEnteredAmount = !empty($data['contract_amount']) ? (float)$data['contract_amount'] : 0;
        $contractAmount = $userEnteredAmount > 0 ? $userEnteredAmount : $itemsTotal;

        if ($joType === 'items') {
            $installationCharge = (float)($data['installation_charge'] ?? 0);
            $mobilizationCharge = (float)($data['mobilization_charge'] ?? 0);
            $otherCharges = (float)($data['other_charges'] ?? 0);
            // For items mode, contract_amount = items + charges if user didn't override
            if ($userEnteredAmount <= 0 && $itemsTotal > 0) {
                $contractAmount = $itemsTotal + $installationCharge + $mobilizationCharge + $otherCharges;
            }
        } else {
            // Contract mode: zero out charges, items are for tracking only
            $installationCharge = 0;
            $mobilizationCharge = 0;
            $otherCharges = 0;
        }

        // Fabrication fields: only when with_installation = YES
        $fabTeamLeadId = ($withInstallation && !empty($data['fabrication_team_lead_id']))
            ? (int)$data['fabrication_team_lead_id'] : null;
        $fabAllocPct = $withInstallation ? ($data['fabrication_alloc_pct'] ?? null) : null;
        $fabAllocBasis = $withInstallation ? ($data['fabrication_alloc_basis'] ?? 'expenses') : null;
        $fabAllocFixed = $withInstallation ? ($data['fabrication_alloc_fixed'] ?? null) : null;

        $sql = "INSERT INTO pal_projects (
                    tenant_id, project_id, job_order_number, jo_type, title, client_id,
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
                    :tenant_id, :project_id, :job_order_number, :jo_type, :title, :client_id,
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
                ':jo_type' => $joType,
                ':title' => $data['title'],
                ':client_id' => !empty($data['client_id']) ? (int)$data['client_id'] : null,
                ':project_type_id' => !empty($data['project_type_id']) ? (int)$data['project_type_id'] : null,
                ':scope_of_work' => !empty($data['scope_of_work']) ? $data['scope_of_work'] : null,
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

            // Sync client data back to pal_clients if edited inline
            $this->syncClientData($newId, $data);

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
        $data = $this->sanitize($data);
        $project = $this->get($id);
        if ($project === null) {
            throw new InvalidArgumentException('Project not found.');
        }

        $allowed = ['draft', 'pending', 'approved', 'started', 'ongoing', 'completed', 'cancelled', 'closed'];
        $fields = [];
        $params = [':id' => $id, ':tenant_id' => $this->tenantId];

        // Guard: cannot un-complete a project with a paid invoice
        if ($project['status'] === 'completed' && array_key_exists('status', $data) && $data['status'] !== 'completed') {
            $this->guardNotPaid($id);
        }

        // Map form field _jo_type to DB column jo_type.
        $joType = $this->normalizeJoType(
            $data['_jo_type'] ?? $data['jo_type'] ?? null,
            $project['jo_type'] ?? (isset($data['items']) ? 'items' : 'contract')
        );
        if (array_key_exists('_jo_type', $data) || array_key_exists('jo_type', $data)) {
            $data['jo_type'] = $joType;
        }

        foreach ([
            'project_id', 'job_order_number', 'jo_type', 'title', 'client_id', 'project_type_id',
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
                // Null empty decimal fields (MySQL strict mode rejects '' for DECIMAL)
                if (in_array($field, ['down_payment', 'fabrication_alloc_pct', 'fabrication_alloc_fixed', 'estimated_cost', 'contract_amount', 'installation_charge', 'mobilization_charge', 'other_charges'], true)) {
                    if ($val === '' || $val === null) {
                        $val = in_array($field, ['contract_amount', 'estimated_cost', 'installation_charge', 'mobilization_charge', 'other_charges'], true) ? 0 : null;
                    } else {
                        $val = (float)$val;
                    }
                }
                // Null empty ENUM/string fields (MySQL strict mode rejects '' for ENUM)
                if (in_array($field, ['scope_of_work', 'mode_of_payment', 'down_payment_type', 'fabrication_alloc_basis'], true)) {
                    if ($val === '' || $val === null) {
                        $val = null;
                    }
                }
                // Null out fabrication fields when with_installation is off
                if ($val === 0 && $field === 'with_installation') {
                    // Also null out fabrication fields
                    if (array_key_exists('fabrication_team_lead_id', $data)) {
                        $fields[] = 'fabrication_team_lead_id = :_fab_tlid';
                        $params[':_fab_tlid'] = null;
                        unset($data['fabrication_team_lead_id']);
                    }
                    if (array_key_exists('fabrication_alloc_pct', $data)) {
                        $fields[] = 'fabrication_alloc_pct = :_fab_pct';
                        $params[':_fab_pct'] = null;
                        unset($data['fabrication_alloc_pct']);
                    }
                    if (array_key_exists('fabrication_alloc_basis', $data)) {
                        $fields[] = 'fabrication_alloc_basis = :_fab_basis';
                        $params[':_fab_basis'] = null;
                        unset($data['fabrication_alloc_basis']);
                    }
                    if (array_key_exists('fabrication_alloc_fixed', $data)) {
                        $fields[] = 'fabrication_alloc_fixed = :_fab_fixed';
                        $params[':_fab_fixed'] = null;
                        unset($data['fabrication_alloc_fixed']);
                    }
                }
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $val;
            }
        }

        if ($joType === 'contract') {
            // Contracted amount: zero out charges
            $fields[] = 'installation_charge = :_ic_zero';
            $params[':_ic_zero'] = 0;
            $fields[] = 'mobilization_charge = :_mc_zero';
            $params[':_mc_zero'] = 0;
            $fields[] = 'other_charges = :_oc_zero';
            $params[':_oc_zero'] = 0;
            // Items are saved for tracking — contract_amount is authoritative if set
            // If contract_amount is empty/zero and items exist → items total is truth
            $userAmount = !empty($data['contract_amount']) ? (float)$data['contract_amount'] : 0;
            if ($userAmount <= 0 && isset($data['items'])) {
                $itemsTotal = $this->calculateItemsTotal($data['items']);
                if ($itemsTotal > 0) {
                    $fields[] = 'contract_amount = :_ct_new';
                    $params[':_ct_new'] = $itemsTotal;
                }
            }
        } elseif (isset($data['items'])) {
            // Items mode: recalculate from items + charges
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
            if ($data['status'] === 'completed' && empty($project['client_id'])) {
                throw new InvalidArgumentException('Cannot complete a project without a client.');
            }
            // Delegate 'completed' to completeProject() which handles smart completion
            if ($data['status'] === 'completed') {
                return $this->completeProject($id);
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

            // Save items if provided (use !empty so empty array does not trigger delete)
            if (!empty($data['items'])) {
                $this->db->prepare("DELETE FROM pal_project_items WHERE project_id = :pid AND tenant_id = :tid")
                    ->execute([':pid' => $id, ':tid' => $this->tenantId]);
                $this->saveItems($id, $data['items']);
            }

            // Sync client data back to pal_clients if edited inline
            $this->syncClientData($id, $data);

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

        $project = $this->get($id);
        if (!$project) throw new InvalidArgumentException('Project not found.');

        // Guard: cannot un-complete a project with a paid invoice
        if ($project['status'] === 'completed' && $status !== 'completed') {
            $this->guardNotPaid($id);
        }

        if ($status === 'completed') {
            if (empty($project['client_id'])) {
                throw new InvalidArgumentException('Cannot complete a project without a client.');
            }
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

    /**
     * Smart completion: marks project completed + auto-creates invoice if needed.
     * Delegates to ProjectCompletionCoordinator for orchestration.
     * Call this from the handler when status = 'completed'.
     */
    public function completeProject(int $id): bool
    {
        $coordinator = new palProjectCompletionCoordinator($this->db, $this->tenantId, $this->userId);
        return $coordinator->complete($id);
    }

    /**
     * Guard: prevent un-completing a project when a paid invoice exists.
     */
    private function guardNotPaid(int $projectId): void
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid AND status = 'paid'");
        $stmt->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Cannot change status: this project has a paid invoice.');
        }
    }

    /**
     * Sanitize input data: convert empty strings to null for ENUM/decimal/date fields,
     * so MySQL strict mode doesn't reject '' values. Run before create/update.
     */
    private function sanitize(array $data): array
    {
        // Fields that must never be empty strings (null = "not set")
        $nonTextFields = [
            // ENUMs
            'scope_of_work', 'mode_of_payment', 'down_payment_type', 'fabrication_alloc_basis',
            'project_type_id', 'status', 'jo_type',
            // Decimals
            'contract_amount', 'estimated_cost', 'installation_charge', 'mobilization_charge',
            'other_charges', 'down_payment', 'fabrication_alloc_pct', 'fabrication_alloc_fixed',
            'budget_warning_pct', 'client_id',
            // Dates
            'start_date', 'target_completion_date', 'actual_completion_date',
            // Ints
            'fabrication_team_lead_id', 'with_installation',
        ];
        foreach ($nonTextFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
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

    private function normalizeJoType(mixed $value, ?string $fallback = 'items'): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (in_array($value, ['items', 'contract'], true)) {
            return $value;
        }

        return in_array($fallback, ['items', 'contract'], true) ? (string)$fallback : 'items';
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
                ':mi' => !empty($item['material_id']) && is_numeric($item['material_id']) ? (int)$item['material_id'] : null,
                ':part' => $this->buildParticulars($item),
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

    /**
     * Build the particulars value for a line item, avoiding double-appending
     * the custom_material name on subsequent saves.
     */
    private function buildParticulars(array $item): string
    {
        $particulars = $item['particulars'] ?? '';
        $custom = $item['custom_material'] ?? '';

        if ($custom === '') {
            return mb_substr($particulars, 0, 255);
        }

        // Strip any existing brackets from the custom name (it may come from the
        // form pre-filled with the full particulars)
        $clean = trim(str_replace(['[', ']'], '', $custom));

        // Check if custom name is already appended in brackets to avoid doubling
        $bracketed = '[' . $clean . ']';
        if (str_ends_with(trim($particulars), $bracketed)) {
            return mb_substr($particulars, 0, 255);
        }

        // Also check if the clean name already appears at the end (without brackets)
        if (str_ends_with(trim($particulars), $clean)) {
            return mb_substr($particulars, 0, 255);
        }

        // If customs and particulars are practically the same, just use particulars
        if (trim($particulars) === $clean || trim($particulars) === $bracketed) {
            return mb_substr($bracketed, 0, 255);
        }

        return mb_substr(($particulars ? $particulars . ' ' : '') . $bracketed, 0, 255);
    }

    /**
     * Load client snapshot data at the time of invoice creation.
     * Returns defaults if no client is linked.
     */
    private function loadClientSnapshot(int $clientId): array
    {
        if ($clientId <= 0) {
            return ['name' => null, 'contact_person' => null, 'email' => null, 'phone' => null, 'address' => null];
        }
        $stmt = $this->db->prepare("SELECT name, contact_person, email, phone, address FROM pal_clients WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $clientId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['name' => null, 'contact_person' => null, 'email' => null, 'phone' => null, 'address' => null];
    }

    /**
     * Sync client data edited inline in the JO form back to pal_clients.
     * Updates contact_person (company), phone, email, address.
     * Only updates when the project has a client_id and the form
     * includes the relevant fields.
     */
    private function syncClientData(int $projectId, array $data): void
    {
        $clientId = !empty($data['client_id']) ? (int)$data['client_id'] : 0;
        if ($clientId <= 0) return;

        $fields = [];
        $params = [':id' => $clientId, ':tenant_id' => $this->tenantId];

        // Map form field names to DB column names
        $map = [
            'client_company' => 'contact_person',
            'client_phone' => 'phone',
            'client_email' => 'email',
            'client_address' => 'address',
        ];
        foreach ($map as $formField => $dbColumn) {
            if (array_key_exists($formField, $data)) {
                $val = $data[$formField];
                if ($val === '' || $val === null) {
                    $val = null;
                }
                $fields[] = "{$dbColumn} = :{$dbColumn}";
                $params[":{$dbColumn}"] = $val;
            }
        }

        if (empty($fields)) return;

        $fields[] = 'updated_by = :updated_by';
        $params[':updated_by'] = $this->userId;

        $sql = 'UPDATE pal_clients SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id';
        $this->db->prepare($sql)->execute($params);
    }
}
