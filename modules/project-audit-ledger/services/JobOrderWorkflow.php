<?php

declare(strict_types=1);

/**
 * Job Order Workflow — Formal state machine for PAL project lifecycle.
 *
 * Defines all allowed status transitions with guard conditions and
 * side-effect hooks. Centralizes state logic that was previously
 * scattered across ProjectService::update(), updateStatus(), and
 * completeProject().
 *
 * Status flow:
 *   draft ──→ pending ──→ approved ──→ started ──→ ongoing ──→ completed ──→ closed
 *     │                      │            │            │
 *     └──→ cancelled ←───────┘────────────┘────────────┘
 *
 * All transitions enforce guards; invalid transitions throw
 * InvalidArgumentException with a descriptive message.
 */
class palJobOrderWorkflow
{
    /** @var array<string, string[]> Allowed transitions map */
    private const TRANSITIONS = [
        'draft'     => ['pending', 'cancelled'],
        'pending'   => ['approved', 'cancelled'],
        'approved'  => ['started', 'ongoing', 'completed', 'cancelled'],
        'started'   => ['ongoing', 'completed', 'cancelled'],
        'ongoing'   => ['completed', 'cancelled'],
        'completed' => ['closed'],
        'cancelled' => [],
        'closed'    => [],
    ];

    /** @var array<string, string> Human-readable labels */
    private const LABELS = [
        'draft'     => 'Draft',
        'pending'   => 'Pending',
        'approved'  => 'Approved',
        'started'   => 'Started',
        'ongoing'   => 'Ongoing',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'closed'    => 'Closed',
    ];

    /** @var array<string, string[]> Statuses that are considered "final" (irreversible) */
    private const FINAL_STATUSES = ['cancelled', 'closed'];

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
     * Check if a transition is allowed.
     */
    public static function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Get allowed next statuses for a given current status.
     *
     * @return string[]
     */
    public static function allowedTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /**
     * Get human-readable label for a status.
     */
    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * Check if a status is final (irreversible).
     */
    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STATUSES, true);
    }

    /**
     * All valid statuses.
     *
     * @return string[]
     */
    public static function allStatuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * Validate and apply a status transition.
     * When called from within a transaction with a locked row, pass the
     * pre-loaded status and client_id via context to avoid a separate SELECT.
     *
     * @param int $projectId
     * @param string $newStatus
     * @param array $context Extra context. If 'status' is set, uses it as current status
     *                       (avoids re-fetching when caller already holds a lock).
     *                       Supports 'client_id' for guard evaluation.
     * @return bool True if status was changed
     * @throws InvalidArgumentException if transition is not allowed or guard fails
     */
    public function transition(int $projectId, string $newStatus, array $context = []): bool
    {
        // Use pre-loaded status from context if available (caller holds lock)
        if (array_key_exists('status', $context)) {
            $currentStatus = $context['status'];
            $clientId = (int)($context['client_id'] ?? 0);
        } else {
            // Fetch current state
            $stmt = $this->db->prepare("SELECT status, client_id FROM pal_projects WHERE id = :id AND tenant_id = :tid");
            $stmt->execute([':id' => $projectId, ':tid' => $this->tenantId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                throw new InvalidArgumentException('Project not found.');
            }

            $currentStatus = $current['status'];
            $clientId = (int)($current['client_id'] ?? 0);
        }

        if ($currentStatus === $newStatus) {
            return false; // No change needed
        }

        // Validate transition
        if (!self::isAllowed($currentStatus, $newStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition from '" . self::label($currentStatus) . "' to '" . self::label($newStatus) . "'."
            );
        }

        // Guard: completed requires a client
        if ($newStatus === 'completed' && $clientId <= 0) {
            throw new InvalidArgumentException('Cannot complete a project without a client.');
        }

        // Guard: cannot un-complete a project with a paid invoice
        if ($currentStatus === 'completed' && $newStatus !== 'completed') {
            $this->guardNotPaid($projectId);
        }

        return true;
    }

    /**
     * Apply the status update to the database.
     * Returns true if the row was actually changed.
     */
    public function apply(int $projectId, string $newStatus): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE pal_projects SET status = :status, version = version + 1, updated_by = :ub
             WHERE id = :id AND tenant_id = :tid"
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':ub' => $this->userId,
            ':id' => $projectId,
            ':tid' => $this->tenantId,
        ]);

        $changed = $stmt->rowCount() > 0;

        // Set actual_completion_date when completing
        if ($changed && $newStatus === 'completed') {
            $this->db->prepare("UPDATE pal_projects SET actual_completion_date = CURDATE() WHERE id = :id")
                ->execute([':id' => $projectId]);
        }

        return $changed;
    }

    /**
     * Guard: prevent reversing a project that has a paid invoice.
     */
    private function guardNotPaid(int $projectId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pal_sales WHERE project_id = :pid AND tenant_id = :tid AND status = 'paid'"
        );
        $stmt->execute([':pid' => $projectId, ':tid' => $this->tenantId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Cannot change status: this project has a paid invoice.');
        }
    }
}
