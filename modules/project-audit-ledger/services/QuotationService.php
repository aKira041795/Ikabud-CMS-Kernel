<?php

declare(strict_types=1);

class palQuotationService
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
        $where = ['q.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];

        if (!empty($filters['status'])) {
            $where[] = 'q.status = :st';
            $params[':st'] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'q.client_id = :cl';
            $params[':cl'] = (int)$filters['client_id'];
        }

        $w = implode(' AND ', $where);
        $sql = "SELECT q.*, c.name AS client_name, p.title AS project_title 
                FROM pal_quotations q 
                LEFT JOIN pal_clients c ON q.client_id = c.id 
                LEFT JOIN pal_projects p ON q.project_id = p.id 
                WHERE {$w} ORDER BY q.created_at DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT q.*, c.name AS client_name, c.contact_person, c.email AS client_email, c.phone AS client_phone, 
                       c.address AS client_address, p.title AS project_title 
                FROM pal_quotations q 
                LEFT JOIN pal_clients c ON q.client_id = c.id 
                LEFT JOIN pal_projects p ON q.project_id = p.id 
                WHERE q.id = :id AND q.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $row['items'] = $this->getItems($id);
        return $row;
    }

    public function getItems(int $quotationId): array
    {
        $stmt = $this->db->prepare("SELECT qi.*, m.name AS material_name, m.material_code 
                                     FROM pal_quotation_items qi 
                                     LEFT JOIN pal_materials m ON qi.material_id = m.id 
                                     WHERE qi.quotation_id = :qid AND qi.tenant_id = :tid 
                                     ORDER BY qi.sort_order ASC");
        $stmt->execute([':qid' => $quotationId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_quotations WHERE tenant_id = :tid");
        $cStmt->execute([':tid' => $this->tenantId]);
        $prefix = (function_exists('palSettings') ? (palSettings()['quotation_prefix'] ?? 'QTN') : 'QTN');
        $num = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $items = $data['items'] ?? [];
        $subtotal = 0;
        foreach ($items as $item) {
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
            $subtotal += $lineTotal;
        }

        $installationCharge = (float)($data['installation_charge'] ?? 0);
        $mobilizationCharge = (float)($data['mobilization_charge'] ?? 0);
        $otherCharges = (float)($data['other_charges'] ?? 0);
        $totalAmount = $subtotal + $installationCharge + $mobilizationCharge + $otherCharges;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO pal_quotations 
                (tenant_id, quotation_number, project_id, client_id, quotation_date, scope_of_work, 
                 with_installation, mode_of_payment, installation_charge, mobilization_charge, other_charges,
                 down_payment, down_payment_type, subtotal, total_amount, notes, status, created_by) 
                VALUES (:t, :qn, :pj, :cl, :qd, :sow, :wi, :mop, :ic, :mc, :oc, :dp, :dpt, :sub, :tot, :no, 'draft', :cb)");

            $stmt->execute([
                ':t' => $this->tenantId,
                ':qn' => $num,
                ':pj' => !empty($data['project_id']) ? (int)$data['project_id'] : null,
                ':cl' => !empty($data['client_id']) ? (int)$data['client_id'] : null,
                ':qd' => $data['quotation_date'] ?? date('Y-m-d'),
                ':sow' => $data['scope_of_work'] ?? null,
                ':wi' => !empty($data['with_installation']) ? 1 : 0,
                ':mop' => $data['mode_of_payment'] ?? null,
                ':ic' => $installationCharge,
                ':mc' => $mobilizationCharge,
                ':oc' => $otherCharges,
                ':dp' => !empty($data['down_payment']) ? (float)$data['down_payment'] : null,
                ':dpt' => $data['down_payment_type'] ?? null,
                ':sub' => $subtotal,
                ':tot' => $totalAmount,
                ':no' => $data['notes'] ?? null,
                ':cb' => $this->userId,
            ]);

            $quotationId = (int)$this->db->lastInsertId();

            // Insert items
            $this->saveItems($quotationId, $items);

            $this->db->commit();

            palAudit('pal.quotation.created', $this->userId, 'pal_quotations', (string)$quotationId,
                null, ['total_amount' => $totalAmount]);
            palFireEvent('pal.quotation.created', [
                'quotation_id' => $quotationId,
                'total_amount' => $totalAmount,
            ]);

            return $quotationId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id, ':tid' => $this->tenantId];

        $mappings = [
            'project_id', 'client_id', 'quotation_date', 'scope_of_work',
            'with_installation', 'mode_of_payment', 'installation_charge',
            'mobilization_charge', 'other_charges', 'down_payment',
            'down_payment_type', 'notes', 'status',
        ];

        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] !== '' ? $data[$f] : null;
            }
        }

        // Recalculate totals if items are provided
        if (isset($data['items'])) {
            $items = $data['items'];
            $subtotal = 0;
            foreach ($items as $item) {
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
                $subtotal += $lineTotal;
            }

            $installationCharge = isset($data['installation_charge']) ? (float)$data['installation_charge'] : 0;
            $mobilizationCharge = isset($data['mobilization_charge']) ? (float)$data['mobilization_charge'] : 0;
            $otherCharges = isset($data['other_charges']) ? (float)$data['other_charges'] : 0;

            $fields[] = 'subtotal = :sub';
            $params[':sub'] = $subtotal;
            $fields[] = 'total_amount = :tot';
            $params[':tot'] = $subtotal + $installationCharge + $mobilizationCharge + $otherCharges;
        }

        if (empty($fields)) return;
        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_quotations SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            // Save items if provided
            if (isset($data['items'])) {
                $this->db->prepare("DELETE FROM pal_quotation_items WHERE quotation_id = :qid AND tenant_id = :tid")
                    ->execute([':qid' => $id, ':tid' => $this->tenantId]);
                $this->saveItems($id, $data['items']);
            }

            $this->db->commit();

            palAudit('pal.quotation.updated', $this->userId, 'pal_quotations', (string)$id, null, []);
            palFireEvent('pal.quotation.updated', ['quotation_id' => $id]);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateStatus(int $id, string $status): void
    {
        $allowed = ['draft', 'sent', 'approved', 'rejected', 'converted', 'expired'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid quotation status: ' . $status);
        }
        $stmt = $this->db->prepare("UPDATE pal_quotations SET status = :st, version = version + 1 WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':st' => $status, ':id' => $id, ':tid' => $this->tenantId]);
    }

    /**
     * Convert a quotation to a sales invoice.
     * Creates a pal_sales record and copies line items to pal_sale_items.
     */
    public function convertToSale(int $id): int
    {
        $quotation = $this->get($id);
        if (!$quotation) throw new InvalidArgumentException('Quotation not found.');
        if ($quotation['status'] === 'converted') throw new InvalidArgumentException('Quotation already converted.');

        // Generate sales number
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_sales WHERE tenant_id = :tid");
        $cStmt->execute([':tid' => $this->tenantId]);
        $prefix = (function_exists('palSettings') ? (palSettings()['sales_prefix'] ?? 'INV') : 'INV');
        $salesNum = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $this->db->beginTransaction();
        try {
            // Create the sale record, copying quotation data
            $stmt = $this->db->prepare("INSERT INTO pal_sales 
                (tenant_id, sales_number, project_id, client_id, quotation_id, invoice_number, sales_date,
                 gross_amount, discount_amount, tax_amount, installation_charge, mobilization_charge,
                 other_charges, down_payment, down_payment_type, mode_of_payment,
                 scope_of_work, with_installation, due_date, notes, status, created_by)
                VALUES (:t, :sn, :pj, :cl, :qi, :inv, :sd, :ga, :da, :ta, :ic, :mc, :oc, :dp, :dpt, :mop, :sow, :wi, :dd, :no, 'issued', :cb)");

            $stmt->execute([
                ':t' => $this->tenantId,
                ':sn' => $salesNum,
                ':pj' => $quotation['project_id'],
                ':cl' => $quotation['client_id'],
                ':qi' => $id,
                ':inv' => null,
                ':sd' => $quotation['quotation_date'],
                ':ga' => $quotation['subtotal'],
                ':da' => 0,
                ':ta' => 0,
                ':ic' => $quotation['installation_charge'],
                ':mc' => $quotation['mobilization_charge'],
                ':oc' => $quotation['other_charges'],
                ':dp' => $quotation['down_payment'],
                ':dpt' => $quotation['down_payment_type'],
                ':mop' => $quotation['mode_of_payment'],
                ':sow' => $quotation['scope_of_work'],
                ':wi' => $quotation['with_installation'],
                ':dd' => null,
                ':no' => $quotation['notes'],
                ':cb' => $this->userId,
            ]);

            $saleId = (int)$this->db->lastInsertId();

            // Auto-generate Job Order Number on the linked project if missing
            if (!empty($quotation['project_id'])) {
                $pjStmt = $this->db->prepare("SELECT job_order_number FROM pal_projects WHERE id = :pid AND tenant_id = :tid");
                $pjStmt->execute([':pid' => $quotation['project_id'], ':tid' => $this->tenantId]);
                $projectRow = $pjStmt->fetch(PDO::FETCH_ASSOC);
                if ($projectRow && empty($projectRow['job_order_number'])) {
                    $joCount = $this->db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND job_order_number IS NOT NULL");
                    $joCount->execute([':tid' => $this->tenantId]);
                    $joNum = 'JO-' . date('Ymd') . '-' . str_pad((string)((int)$joCount->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
                    $this->db->prepare("UPDATE pal_projects SET job_order_number = :jo, version = version + 1 WHERE id = :pid AND tenant_id = :tid")
                        ->execute([':jo' => $joNum, ':pid' => $quotation['project_id'], ':tid' => $this->tenantId]);
                }
            }

            // Copy quotation items to sale_items
            if (!empty($quotation['items'])) {
                $itemStmt = $this->db->prepare("INSERT INTO pal_sale_items 
                    (tenant_id, sale_id, material_id, particulars, width, height, uom, quantity,
                     price_per_unit, price_per_sqft, line_total, sort_order)
                    VALUES (:t, :si, :mi, :part, :w, :h, :uom, :qty, :ppu, :psf, :lt, :so)");

                foreach ($quotation['items'] as $item) {
                    $itemStmt->execute([
                        ':t' => $this->tenantId,
                        ':si' => $saleId,
                        ':mi' => $item['material_id'],
                        ':part' => $item['particulars'],
                        ':w' => $item['width'],
                        ':h' => $item['height'],
                        ':uom' => $item['uom'],
                        ':qty' => $item['quantity'],
                        ':ppu' => $item['price_per_unit'],
                        ':psf' => $item['price_per_sqft'],
                        ':lt' => $item['line_total'],
                        ':so' => $item['sort_order'],
                    ]);
                }
            }

            // Mark quotation as converted
            $this->db->prepare("UPDATE pal_quotations SET status = 'converted', converted_to_sale_id = :si, version = version + 1 WHERE id = :qid AND tenant_id = :tid")
                ->execute([':si' => $saleId, ':qid' => $id, ':tid' => $this->tenantId]);

            $this->db->commit();

            palAudit('pal.quotation.converted', $this->userId, 'pal_quotations', (string)$id,
                null, ['sale_id' => $saleId, 'total_amount' => $quotation['total_amount']]);
            palFireEvent('pal.quotation.converted', [
                'quotation_id' => $id, 'sale_id' => $saleId,
            ]);

            return $saleId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Convert an approved quotation to a Project.
     * Creates pal_projects with contract_amount = quotation total,
     * auto-generates JO number, sets status to 'pending'.
     */
    public function convertToProject(int $id): int
    {
        $quotation = $this->get($id);
        if (!$quotation) throw new InvalidArgumentException('Quotation not found.');
        if ($quotation['status'] !== 'approved') throw new InvalidArgumentException('Only approved quotations can become projects.');
        if ($quotation['status'] === 'converted') throw new InvalidArgumentException('Quotation already converted.');

        // Auto-generate project ID and JO number
        $projId = 'P-' . date('Ymd') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $joCount = $this->db->prepare("SELECT COUNT(*) FROM pal_projects WHERE tenant_id = :tid AND job_order_number IS NOT NULL");
        $joCount->execute([':tid' => $this->tenantId]);
        $joNum = 'JO-' . date('Ymd') . '-' . str_pad((string)((int)$joCount->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        // Fetch quotation line items
        $items = $this->db->prepare("SELECT * FROM pal_quotation_items WHERE quotation_id = :qid AND tenant_id = :tid ORDER BY sort_order");
        $items->execute([':qid' => $id, ':tid' => $this->tenantId]);
        $lineItems = $items->fetchAll(PDO::FETCH_ASSOC);

        // Recalculate contract_amount from items + charges
        $itemsTotal = 0.0;
        foreach ($lineItems as $item) {
            $itemsTotal += (float)$item['line_total'];
        }
        $contractAmount = $itemsTotal + (float)$quotation['installation_charge'] + (float)$quotation['mobilization_charge'] + (float)$quotation['other_charges'];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO pal_projects 
                (tenant_id, project_id, job_order_number, title, client_id,
                 description, location, scope_of_work, with_installation,
                 contract_amount, estimated_cost, installation_charge, mobilization_charge, other_charges,
                 mode_of_payment, down_payment, down_payment_type,
                 start_date, status, notes, created_by)
                VALUES (:t, :pi, :jo, :title, :cl, :desc, :loc, :sow, :wi,
                        :ca, :ec, :ic, :mc, :oc,
                        :mop, :dp, :dpt,
                        :sd, 'pending', :no, :cb)");

            $stmt->execute([
                ':t' => $this->tenantId,
                ':pi' => $projId,
                ':jo' => $joNum,
                ':title' => $quotation['project_title'] ?? ('Quotation #' . $quotation['quotation_number']),
                ':cl' => $quotation['client_id'],
                ':desc' => 'Created from quotation #' . $quotation['quotation_number'],
                ':loc' => null,
                ':sow' => $quotation['scope_of_work'] ?? null,
                ':wi' => (int)($quotation['with_installation'] ?? 0),
                ':ca' => $contractAmount,
                ':ec' => $itemsTotal,
                ':ic' => (float)($quotation['installation_charge'] ?? 0),
                ':mc' => (float)($quotation['mobilization_charge'] ?? 0),
                ':oc' => (float)($quotation['other_charges'] ?? 0),
                ':mop' => $quotation['mode_of_payment'] ?? null,
                ':dp' => $quotation['down_payment'] !== null ? (float)$quotation['down_payment'] : null,
                ':dpt' => $quotation['down_payment_type'] ?? null,
                ':sd' => $quotation['quotation_date'],
                ':no' => $quotation['notes'],
                ':cb' => $this->userId,
            ]);

            $projectId = (int)$this->db->lastInsertId();

            // Copy line items from quotation to project
            if (!empty($lineItems)) {
                $itemStmt = $this->db->prepare("INSERT INTO pal_project_items 
                    (tenant_id, project_id, material_id, particulars, width, height, uom, quantity,
                     price_per_unit, price_per_sqft, line_total, sort_order)
                    VALUES (:t, :pj, :mi, :part, :w, :h, :uom, :qty, :ppu, :psf, :lt, :so)");
                foreach ($lineItems as $item) {
                    $itemStmt->execute([
                        ':t' => $this->tenantId,
                        ':pj' => $projectId,
                        ':mi' => $item['material_id'],
                        ':part' => $item['particulars'] ?? '',
                        ':w' => $item['width'] !== null ? (float)$item['width'] : null,
                        ':h' => $item['height'] !== null ? (float)$item['height'] : null,
                        ':uom' => $item['uom'] ?? null,
                        ':qty' => (float)$item['quantity'],
                        ':ppu' => (float)$item['price_per_unit'],
                        ':psf' => $item['price_per_sqft'] !== null ? (float)$item['price_per_sqft'] : null,
                        ':lt' => (float)$item['line_total'],
                        ':so' => (int)$item['sort_order'],
                    ]);
                }
            }

            // Link quotation to the new project
            $this->db->prepare("UPDATE pal_quotations SET project_id = :pj, status = 'converted', version = version + 1 WHERE id = :qid AND tenant_id = :tid")
                ->execute([':pj' => $projectId, ':qid' => $id, ':tid' => $this->tenantId]);

            $this->db->commit();

            palAudit('pal.quotation.converted_to_project', $this->userId, 'pal_quotations', (string)$id,
                null, ['project_id' => $projectId, 'total_amount' => $contractAmount, 'jo_number' => $joNum, 'item_count' => count($lineItems)]);
            palFireEvent('pal.quotation.converted', [
                'quotation_id' => $id, 'project_id' => $projectId, 'jo_number' => $joNum,
            ]);

            return $projectId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function saveItems(int $quotationId, array $items): void
    {
        if (empty($items)) return;

        $stmt = $this->db->prepare("INSERT INTO pal_quotation_items 
            (tenant_id, quotation_id, material_id, particulars, width, height, uom, quantity,
             price_per_unit, price_per_sqft, line_total, sort_order)
            VALUES (:t, :qi, :mi, :part, :w, :h, :uom, :qty, :ppu, :psf, :lt, :so)");

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
                ':qi' => $quotationId,
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
}
