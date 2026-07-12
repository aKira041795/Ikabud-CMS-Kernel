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
        $sql = "SELECT s.*, p.title AS project_title, COALESCE(s.client_name, c.name) AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE {$w} ORDER BY s.created_at DESC LIMIT 50";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT s.*, p.title AS project_title, COALESCE(s.client_name, c.name) AS client_name FROM pal_sales s LEFT JOIN pal_projects p ON s.project_id = p.id LEFT JOIN pal_clients c ON s.client_id = c.id WHERE s.id = :id AND s.tenant_id = :tid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $colStmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM pal_collections WHERE sales_id = :sid AND tenant_id = :tid AND status = 'approved'");
        $colStmt->execute([':sid' => $id, ':tid' => $this->tenantId]);
        $row['total_collected'] = (float)$colStmt->fetchColumn();
        // Use the canonical invoice total for outstanding, not net_amount alone.
        $row['invoice_total'] = palInvoiceTotalCalculator::total($row);
        $row['outstanding'] = max(0, $row['invoice_total'] - $row['total_collected']);
        $row['items'] = $this->getItems($id);
        return $row;
    }

    public function getItems(int $saleId): array
    {
        $stmt = $this->db->prepare("SELECT si.*, m.name AS material_name, m.material_code 
                                     FROM pal_sale_items si 
                                     LEFT JOIN pal_materials m ON si.material_id = m.id 
                                     WHERE si.sale_id = :sid AND si.tenant_id = :tid 
                                     ORDER BY si.sort_order ASC");
        $stmt->execute([':sid' => $saleId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int
    {
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_sales WHERE tenant_id = :tid");
        $cStmt->execute([':tid' => $this->tenantId]);
        $prefix = (function_exists('palSettings') ? (palSettings()['sales_prefix'] ?? 'INV') : 'INV');
        $num = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$cStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $items = $data['items'] ?? [];
        $grossAmount = (float)($data['gross_amount'] ?? 0);
        if (!empty($items) && $grossAmount == 0) {
            $grossAmount = $this->calculateItemsTotal($items);
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO pal_sales 
                (tenant_id, sales_number, project_id, client_id, 
                 client_name, client_contact, client_email, client_phone, client_address,
                 quotation_id, invoice_number, sales_date,
                 gross_amount, discount_amount, tax_amount, installation_charge, mobilization_charge, other_charges,
                 down_payment, down_payment_type, mode_of_payment, scope_of_work, with_installation,
                 due_date, payment_terms, notes, status, created_by) 
                VALUES (:t, :sn, :pj, :cl, :cn, :cc, :ce, :cp, :ca, :qi, :inv, :sd, :ga, :da, :ta, :ic, :mc, :oc, :dp, :dpt, :mop, :sow, :wi, :dd, :pt, :no, 'issued', :cb)");

            // Snapshot client information at time of invoice creation
            $clientSnapshot = $this->loadClientSnapshot($data['client_id'] ?? null);

            $stmt->execute([
                ':t' => $this->tenantId,
                ':sn' => $num,
                ':pj' => !empty($data['project_id']) ? (int)$data['project_id'] : null,
                ':cl' => !empty($data['client_id']) ? (int)$data['client_id'] : null,
                ':cn' => $clientSnapshot['name'],
                ':cc' => $clientSnapshot['contact_person'],
                ':ce' => $clientSnapshot['email'],
                ':cp' => $clientSnapshot['phone'],
                ':ca' => $clientSnapshot['address'],
                ':qi' => !empty($data['quotation_id']) ? (int)$data['quotation_id'] : null,
                ':inv' => !empty($data['invoice_number']) ? $data['invoice_number'] : $num,
                ':sd' => $data['sales_date'] ?? date('Y-m-d'),
                ':ga' => $grossAmount,
                ':da' => $data['discount_amount'] ?? 0,
                ':ta' => $data['tax_amount'] ?? 0,
                ':ic' => $data['installation_charge'] ?? 0,
                ':mc' => $data['mobilization_charge'] ?? 0,
                ':oc' => $data['other_charges'] ?? 0,
                ':dp' => !empty($data['down_payment']) ? (float)$data['down_payment'] : null,
                ':dpt' => $data['down_payment_type'] ?? null,
                ':mop' => $data['mode_of_payment'] ?? null,
                ':sow' => $data['scope_of_work'] ?? null,
                ':wi' => !empty($data['with_installation']) ? 1 : 0,
                ':dd' => $data['due_date'] ?? null,
                ':pt' => $data['payment_terms'] ?? null,
                ':no' => $data['notes'] ?? null,
                ':cb' => $this->userId,
            ]);

            $saleId = (int)$this->db->lastInsertId();

            if (!empty($items)) {
                $this->saveItems($saleId, $items);
            }

            // Create receivable for this invoice.
            // Canonical invoice total = gross + charges - discount + tax.
            // This matches what the client actually owes (net_amount in pal_sales
            // doesn't include installation/mobilization/other charges, so we
            // compute the full amount here).
            $invoiceTotal = $grossAmount
                + (float)($data['installation_charge'] ?? 0)
                + (float)($data['mobilization_charge'] ?? 0)
                + (float)($data['other_charges'] ?? 0)
                - (float)($data['discount_amount'] ?? 0)
                + (float)($data['tax_amount'] ?? 0);
            if ($invoiceTotal < 0) $invoiceTotal = 0;
            $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
            $clientId = !empty($data['client_id']) ? (int)$data['client_id'] : null;
            $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;

            $rcvService = new palReceivableService($this->db, $this->tenantId, $this->userId);
            $rcvService->createFromInvoice($saleId, $projectId, $clientId, $invoiceTotal, $dueDate);

            $this->db->commit();

            palAudit('pal.sale.created', $this->userId, 'pal_sales', (string)$saleId, null,
                ['amount' => $invoiceTotal, 'gross_amount' => $grossAmount]);
            palFireEvent('pal.sale.created', [
                'sale_id' => $saleId,
                'amount' => $invoiceTotal,
                'gross_amount' => $grossAmount,
            ]);

            return $saleId;
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
            'project_id', 'client_id', 'quotation_id', 'invoice_number', 'sales_date',
            'gross_amount', 'discount_amount', 'tax_amount',
            'installation_charge', 'mobilization_charge', 'other_charges',
            'down_payment', 'down_payment_type', 'mode_of_payment', 'scope_of_work',
            'with_installation', 'due_date', 'payment_terms', 'notes',
        ];

        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] !== '' ? $data[$f] : null;
            }
        }

        // Treat submitted items as an invoice-total change (even empty array =
        // deleting all items recalculates gross to 0).
        $itemsSubmitted = array_key_exists('items', $data) && is_array($data['items']);

        if ($itemsSubmitted) {
            $gross = $this->calculateItemsTotal($data['items']);
            $fields[] = 'gross_amount = :ga';
            $params[':ga'] = $gross;
        }

        if (empty($fields)) return;
        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_sales SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ($itemsSubmitted) {
                $this->db->prepare("DELETE FROM pal_sale_items WHERE sale_id = :sid AND tenant_id = :tid")
                    ->execute([':sid' => $id, ':tid' => $this->tenantId]);
                if (!empty($data['items'])) {
                    $this->saveItems($id, $data['items']);
                }
            }

            // Determine whether this edit changes the invoice total.
            // Both direct amount fields AND items changes count.
            $changesInvoiceTotal = $itemsSubmitted
                || !empty(array_intersect(
                    array_keys($data),
                    ['gross_amount', 'discount_amount', 'tax_amount',
                     'installation_charge', 'mobilization_charge', 'other_charges']
                ));

            if ($changesInvoiceTotal) {
                $hasApprovedAllocations = $this->hasApprovedPayments($id);
                if ($hasApprovedAllocations) {
                    $this->db->rollBack();
                    throw new InvalidArgumentException(
                        'Cannot change invoice amounts after payments have been allocated. '
                        . 'Create a credit note or debit note instead.'
                    );
                }

                // Recalculate and sync the receivable
                $this->syncReceivableAmount($id);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Check if a sale has any approved payment allocations.
     */
    private function hasApprovedPayments(int $saleId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM pal_collections
             WHERE sales_id = :si AND tenant_id = :tid AND status = 'approved'
             LIMIT 1"
        );
        $stmt->execute([':si' => $saleId, ':tid' => $this->tenantId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Recalculate the receivable amount to match the canonical invoice total.
     * Only call within a transaction. Only safe when no payments have been allocated.
     */
    private function syncReceivableAmount(int $saleId): void
    {
        $sale = $this->get($saleId);
        if ($sale === null) return;

        $invoiceTotal = palInvoiceTotalCalculator::total($sale);

        $this->db->prepare(
            "UPDATE pal_receivables
             SET amount = :amt, version = version + 1
             WHERE sales_id = :si AND tenant_id = :tid AND status IN ('pending', 'partial', 'overdue')"
        )->execute([':amt' => $invoiceTotal, ':si' => $saleId, ':tid' => $this->tenantId]);
    }

    /**
     * @deprecated Use PaymentService::record() instead.
     *             This method delegates to PaymentService and is retained
     *             temporarily for backward compatibility. Do not call directly
     *             in new code. Will be removed in a future release.
     */
    public function recordCollection(array $data): int
    {
        $pmt = new palPaymentService($this->db, $this->tenantId, $this->userId);
        $saleId = (int)($data['sales_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $method = $data['payment_method'] ?? 'cash';
        $date = $data['payment_date'] ?? date('Y-m-d');
        $ref = $data['reference_number'] ?? null;
        $notes = $data['notes'] ?? null;

        // If the caller explicitly asked for immediate approval, use recordAndApprove.
        $status = $data['status'] ?? 'pending';
        if ($status === 'approved') {
            return $pmt->recordAndApprove($saleId, $amount, $method, $date, $ref, $notes);
        }

        return $pmt->record($saleId, $amount, $method, $date, $ref, $notes);
    }

    /**
     * Load client snapshot data at the time of invoice creation.
     * Returns defaults if no client is linked (walk-in sale).
     */
    private function loadClientSnapshot(?int $clientId): array
    {
        if ($clientId === null || $clientId <= 0) {
            return ['name' => null, 'contact_person' => null, 'email' => null, 'phone' => null, 'address' => null];
        }
        $stmt = $this->db->prepare("SELECT name, contact_person, email, phone, address FROM pal_clients WHERE id = :id AND tenant_id = :tid");
        $stmt->execute([':id' => $clientId, ':tid' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['name' => null, 'contact_person' => null, 'email' => null, 'phone' => null, 'address' => null];
    }

    /**
     * Save items for a sale — public entry point for the ProjectCompletionCoordinator.
     */
    public function saveItemsForSale(int $saleId, array $items): void
    {
        $this->saveItems($saleId, $items);
    }

    private function saveItems(int $saleId, array $items): void
    {
        if (empty($items)) return;

        $stmt = $this->db->prepare("INSERT INTO pal_sale_items 
            (tenant_id, sale_id, material_id, particulars, width, height, uom, quantity,
             price_per_unit, price_per_sqft, line_total, sort_order)
            VALUES (:t, :si, :mi, :part, :w, :h, :uom, :qty, :ppu, :psf, :lt, :so)");

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
                ':si' => $saleId,
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

    public function updateSaleCollectionStatus(int $salesId): void
    {
        $sale = $this->get($salesId);
        if (!$sale) return;
        $totalCollected = (float)($sale['total_collected'] ?? 0);
        $invoiceTotal = palInvoiceTotalCalculator::total($sale);
        if ($totalCollected >= $invoiceTotal && $invoiceTotal > 0) {
            $this->db->prepare("UPDATE pal_sales SET status = 'paid', version = version + 1 WHERE id = :id AND tenant_id = :tid")
                 ->execute([':id' => $salesId, ':tid' => $this->tenantId]);
        } elseif ($totalCollected > 0) {
            $this->db->prepare("UPDATE pal_sales SET status = 'partially_paid', version = version + 1 WHERE id = :id AND tenant_id = :tid AND status = 'issued'")
                 ->execute([':id' => $salesId, ':tid' => $this->tenantId]);
        }
    }
}
