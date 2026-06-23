<?php

declare(strict_types=1);

/**
 * Domain service for expense management with approval workflow.
 */
class palExpenseService
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
        $where = ['e.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $this->tenantId];

        if (!empty($filters['project_id'])) {
            $where[] = 'e.project_id = :project_id';
            $params[':project_id'] = (int)$filters['project_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'e.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'e.category_id = :category_id';
            $params[':category_id'] = (int)$filters['category_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'e.expense_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'e.expense_date <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM pal_expenses e WHERE {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT e.*, ec.name AS category_name, p.title AS project_title,
                       s.name AS supplier_name
                FROM pal_expenses e
                LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id
                LEFT JOIN pal_projects p ON e.project_id = p.id
                LEFT JOIN pal_suppliers s ON e.supplier_id = s.id
                WHERE {$whereClause}
                ORDER BY e.expense_date DESC, e.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function get(int $id): ?array
    {
        $sql = "SELECT e.*, ec.name AS category_name, p.title AS project_title,
                       s.name AS supplier_name
                FROM pal_expenses e
                LEFT JOIN pal_expense_categories ec ON e.category_id = ec.id
                LEFT JOIN pal_projects p ON e.project_id = p.id
                LEFT JOIN pal_suppliers s ON e.supplier_id = s.id
                WHERE e.id = :id AND e.tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':tenant_id' => $this->tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(array $data): int
    {
        $this->validate($data);

        $expenseNumber = $this->generateExpenseNumber();

        $sql = "INSERT INTO pal_expenses (
                    tenant_id, expense_number, expense_date, project_id,
                    category_id, description, payee, supplier_id, amount,
                    tax_amount, payment_method, reference_number, notes,
                    status, created_by
                ) VALUES (
                    :tenant_id, :expense_number, :expense_date, :project_id,
                    :category_id, :description, :payee, :supplier_id, :amount,
                    :tax_amount, :payment_method, :reference_number, :notes,
                    :status, :created_by
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $this->tenantId,
            ':expense_number' => $expenseNumber,
            ':expense_date' => $data['expense_date'],
            ':project_id' => !empty($data['project_id']) ? (int)$data['project_id'] : null,
            ':category_id' => !empty($data['category_id']) ? (int)$data['category_id'] : null,
            ':description' => $data['description'],
            ':payee' => $data['payee'] ?? null,
            ':supplier_id' => !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            ':amount' => $data['amount'],
            ':tax_amount' => $data['tax_amount'] ?? 0,
            ':payment_method' => $data['payment_method'] ?? null,
            ':reference_number' => $data['reference_number'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':status' => $data['status'] ?? 'draft',
            ':created_by' => $this->userId,
        ]);

        $newId = (int)$this->db->lastInsertId();
        palFireEvent('pal.expense.created', ['expense_id' => $newId, 'amount' => $data['amount']]);
        return $newId;
    }

    public function submit(int $id): int
    {
        $expense = $this->get($id);
        if ($expense === null) {
            throw new InvalidArgumentException('Expense not found.');
        }
        if ($expense['status'] !== 'draft') {
            throw new InvalidArgumentException('Only draft expenses can be submitted.');
        }

        $this->db->prepare(
            "UPDATE pal_expenses SET status = 'pending_approval', submitted_by = :sb,
             submitted_at = NOW(), version = version + 1
             WHERE id = :id AND tenant_id = :tid"
        )->execute([':sb' => $this->userId, ':id' => $id, ':tid' => $this->tenantId]);

        // Create approval record — ApprovalService::decide() handles the rest
        $approvalId = palCreateApproval('expense', $id, $this->userId, $expense['status'], 'pending_approval');

        palAudit('pal.expense.submitted', $this->userId, 'pal_expenses', (string)$id,
            ['status' => $expense['status']], ['status' => 'pending_approval']);
        palFireEvent('pal.expense.submitted', ['expense_id' => $id, 'approval_id' => $approvalId]);

        return $approvalId;
    }

    public function void(int $id, int $userId, string $reason): bool
    {
        $expense = $this->get($id);
        if ($expense === null) {
            throw new InvalidArgumentException('Expense not found.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        $stmt = $this->db->prepare(
            "UPDATE pal_expenses SET status = 'voided', voided_by = :voided_by,
             voided_at = NOW(), void_reason = :void_reason, version = version + 1
             WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute([
            ':voided_by' => $userId,
            ':void_reason' => $reason,
            ':id' => $id,
            ':tenant_id' => $this->tenantId,
        ]);

        $voided = $stmt->rowCount() > 0;
        if ($voided) {
            palFireEvent('pal.expense.voided', ['expense_id' => $id, 'reason' => $reason]);
        }
        return $voided;
    }

    private function validate(array $data): void
    {
        if (empty($data['expense_date'])) {
            throw new InvalidArgumentException('Expense date is required.');
        }
        if (empty($data['description'])) {
            throw new InvalidArgumentException('Description is required.');
        }
        if (!isset($data['amount']) || $data['amount'] <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
    }

    private function generateExpenseNumber(): string
    {
        $prefix = 'EXP-' . date('Ymd') . '-';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pal_expenses WHERE expense_number LIKE :prefix AND tenant_id = :tenant_id"
        );
        $stmt->execute([':prefix' => $prefix . '%', ':tenant_id' => $this->tenantId]);
        $count = (int)$stmt->fetchColumn();
        return $prefix . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id, ':tid' => $this->tenantId];
        $mappings = ['category_id', 'project_id', 'supplier_id', 'amount', 'tax_amount', 'expense_date', 'description', 'payee', 'payment_method', 'reference_number', 'notes'];
        foreach ($mappings as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $data[$f] ?: null;
            }
        }
        if (empty($fields)) return;
        $fields[] = 'version = version + 1';
        $sql = 'UPDATE pal_expenses SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tid';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
