<?php

declare(strict_types=1);

/**
 * Project Completion Coordinator — Orchestrates the project completion workflow.
 *
 * Coordinates across multiple domain services to perform a single
 * atomic completion operation:
 *
 *   1. Validate transition via JobOrderWorkflow
 *   2. Lock project row (SELECT FOR UPDATE)
 *   3. Update status to 'completed'
 *   4. Create invoice (via SalesService) if none exists
 *   5. Copy line items from project to sale
 *   6. Create receivable for the invoice
 *   7. Record audit and emit domain events
 *   8. Commit
 *
 * Previously, this orchestration was embedded in ProjectService::completeProject(),
 * making it a domain monolith. Extracting it here allows each downstream
 * service to be tested independently.
 */
class palProjectCompletionCoordinator
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

    /**
     * Complete a project: lock, validate, update, invoice, receivable, audit.
     * Domain events are collected during the transaction and emitted only
     * after a successful commit to prevent orphan events on rollback.
     *
     * @param int $projectId
     * @return bool True on success
     * @throws InvalidArgumentException on precondition failure
     * @throws Throwable on unexpected error (transaction rolled back)
     */
    public function complete(int $projectId): bool
    {
        $workflow = new palJobOrderWorkflow($this->db, $this->tenantId, $this->userId);
        $pendingEvents = [];

        $this->db->beginTransaction();
        try {
            // 1. Lock project row for concurrency safety
            $lockStmt = $this->db->prepare(
                "SELECT p.status, p.contract_amount, p.client_id,
                        p.jo_type,
                        p.installation_charge, p.mobilization_charge, p.other_charges,
                        p.mode_of_payment, p.down_payment, p.down_payment_type,
                        p.scope_of_work, p.with_installation, p.title AS project_title,
                        c.name AS client_name, c.contact_person, c.email, c.phone, c.address
                 FROM pal_projects p
                 LEFT JOIN pal_clients c ON p.client_id = c.id
                 WHERE p.id = :id AND p.tenant_id = :tid
                 FOR UPDATE"
            );
            $lockStmt->execute([':id' => $projectId, ':tid' => $this->tenantId]);
            $project = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$project) {
                throw new InvalidArgumentException('Project not found.');
            }
            if ($project['status'] === 'completed') {
                // Already completed — idempotent early return
                $this->db->commit();
                return true;
            }

            // 2. Validate transition using the LOCKED status (safe from race)
            $workflow->transition($projectId, 'completed', ['status' => $project['status'], 'client_id' => (int)($project['client_id'] ?? 0)]);

            // 3. Apply status change
            $workflow->apply($projectId, 'completed');

            // 4. Check for existing sale
            $saleStmt = $this->db->prepare(
                "SELECT id, status FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid LIMIT 1"
            );
            $saleStmt->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
            $existingSale = $saleStmt->fetch(PDO::FETCH_ASSOC);
            $hasSale = $existingSale !== false;

            $saleId = null;
            $contractAmount = (float)($project['contract_amount'] ?? 0);

            if (!$hasSale) {
                // 5. Create invoice
                // Use grossFromContract to avoid double-counting: when contract_amount
                // already includes separate charges (items mode), gross_amount is
                // contract_amount minus those charges.
                $salesService = new palSalesService($this->db, $this->tenantId, $this->userId);
                $saleId = $this->createInvoiceFromProject($project, $projectId, $contractAmount);

                // 6. Copy line items
                $items = $this->getProjectItems($projectId);
                if (!empty($items)) {
                    $salesService->saveItemsForSale($saleId, $items);
                }

                // 7. Create receivable using the canonical invoice total.
                // Query the created sale to get the DB-computed net_amount
                // (which includes charges per migration 008).
                $dueDate = date('Y-m-d', strtotime('+30 days'));
                $clientId = (int)($project['client_id'] ?? 0);
                $saleRow = $salesService->get($saleId);
                $invoiceTotal = $saleRow !== null
                    ? palInvoiceTotalCalculator::total($saleRow)
                    : $contractAmount;
                $this->createReceivable($saleId, $projectId, $clientId, $invoiceTotal, $dueDate);

                // 8. Audit inside transaction (safe — app.log is append-only)
                palAudit('pal.sale.created', $this->userId, 'pal_sales', (string)$saleId, null,
                    ['project_id' => $projectId, 'auto_created_on_completion' => true, 'amount' => $invoiceTotal]);

                // Collect events for post-commit emission
                $pendingEvents[] = ['pal.sale.created', [
                    'sale_id' => $saleId,
                    'project_id' => $projectId,
                    'amount' => $invoiceTotal,
                    'auto_created_on_completion' => true,
                ]];
            } else {
                $saleId = (int)$existingSale['id'];
            }

            // Collect completion event
            $pendingEvents[] = ['pal.project.completed', [
                'project_id' => $projectId,
                'auto_invoiced' => !$hasSale,
                'contract_amount' => $contractAmount,
                'sale_id' => $saleId,
            ]];

            $this->db->commit();

            // 9. Emit all collected events AFTER commit (never before)
            foreach ($pendingEvents as [$event, $payload]) {
                palFireEvent($event, $payload);
            }

            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Create an invoice from project data with client snapshot.
     * Uses a retry loop for invoice number generation: if the unique
     * constraint on sales_number catches a race, it retries with a new number.
     *
     * @return int New sale ID
     * @throws RuntimeException after max retries
     */
    private function createInvoiceFromProject(array $project, int $projectId, float $contractAmount): int
    {
        $prefix = (function_exists('palSettings') ? (palSettings()['sales_prefix'] ?? 'INV') : 'INV');
        $clientId = !empty($project['client_id']) ? (int)$project['client_id'] : null;
        $maxRetries = 3;

        // When contract_amount already includes separate charges (items mode),
        // gross_amount must not double-count them.
        $grossAmount = palInvoiceTotalCalculator::grossFromContract($contractAmount, $project);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Generate unique number each attempt
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pal_sales WHERE tenant_id = :tid");
            $countStmt->execute([':tid' => $this->tenantId]);
            $invNum = $prefix . '-' . date('Ymd') . '-' . str_pad((string)((int)$countStmt->fetchColumn() + 1), 4, '0', STR_PAD_LEFT) . ($attempt > 1 ? '-r' . $attempt : '');

            try {
                $stmt = $this->db->prepare(
                    "INSERT INTO pal_sales
                        (tenant_id, sales_number, invoice_number, project_id, client_id,
                         client_name, client_contact, client_email, client_phone, client_address,
                         sales_date, gross_amount,
                         installation_charge, mobilization_charge, other_charges, mode_of_payment,
                         down_payment, down_payment_type, scope_of_work, with_installation,
                         due_date, status, created_by)
                     VALUES (:t, :sn, :invn, :pj, :cl, :cn, :cc, :ce, :cp, :cad,
                             :sd, :ga, :ic, :mc, :oc, :mop, :dp, :dpt, :sow, :wi, :dd, 'issued', :cb)"
                );
                $stmt->execute([
                    ':t' => $this->tenantId,
                    ':sn' => $invNum,
                    ':invn' => $invNum,
                    ':pj' => $projectId,
                    ':cl' => $clientId,
                    ':cn' => $project['client_name'] ?? null,
                    ':cc' => $project['contact_person'] ?? null,
                    ':ce' => $project['email'] ?? null,
                    ':cp' => $project['phone'] ?? null,
                    ':cad' => $project['address'] ?? null,
                    ':sd' => date('Y-m-d'),
                    ':ga' => $grossAmount,
                    ':ic' => (float)($project['installation_charge'] ?? 0),
                    ':mc' => (float)($project['mobilization_charge'] ?? 0),
                    ':oc' => (float)($project['other_charges'] ?? 0),
                    ':mop' => $project['mode_of_payment'] ?? null,
                    ':dp' => !empty($project['down_payment']) ? (float)$project['down_payment'] : null,
                    ':dpt' => $project['down_payment_type'] ?? null,
                    ':sow' => $project['scope_of_work'] ?? null,
                    ':wi' => !empty($project['with_installation']) ? 1 : 0,
                    ':dd' => date('Y-m-d', strtotime('+30 days')),
                    ':cb' => $this->userId,
                ]);

                return (int)$this->db->lastInsertId();
            } catch (\PDOException $e) {
                // Unique constraint violation (23000) — retry with new number
                if ($e->getCode() === '23000' && $attempt < $maxRetries) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \RuntimeException('Failed to generate unique invoice number after ' . $maxRetries . ' attempts.');
    }

    /**
     * Create a receivable for the invoice.
     */
    private function createReceivable(int $saleId, int $projectId, ?int $clientId, float $amount, string $dueDate): int
    {
        $rcvService = new palReceivableService($this->db, $this->tenantId, $this->userId);
        return $rcvService->createFromInvoice($saleId, $projectId, $clientId, $amount, $dueDate);
    }

    /**
     * Get project line items.
     */
    private function getProjectItems(int $projectId): array
    {
        $stmt = $this->db->prepare(
            "SELECT material_id, particulars, width, height, uom, quantity,
                    price_per_unit, price_per_sqft, line_total, sort_order
             FROM pal_project_items
             WHERE project_id = :pid AND tenant_id = :tid
             ORDER BY sort_order ASC"
        );
        $stmt->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
