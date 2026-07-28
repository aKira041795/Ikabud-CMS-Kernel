<?php

declare(strict_types=1);

/**
 * Bakeshop — Operational Period Service
 *
 * Enforces period-close rules for backdated postings.
 * Closed periods reject new or corrected postings unless explicitly reopened.
 *
 * Periods are managed per branch per calendar date.
 * Default policy: all dates are open unless explicitly closed.
 */

class BakeshopOperationalPeriodService
{
    private \Ikabud\Kernel\Contracts\ModuleDB $db;

    public function __construct(?\Ikabud\Kernel\Contracts\ModuleDB $db = null)
    {
        $this->db = $db ?? bakeshopDb();
    }

    /**
     * Check whether a given date is open for operations at a branch.
     * Dates without an explicit period record are considered open.
     */
    public function isDateOpen(int $branchId, string $date): bool
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM bakeshop_operational_periods
             WHERE branch_id = :bid AND period_date = :dt
             LIMIT 1"
        );
        $stmt->execute([':bid' => $branchId, ':dt' => $date]);
        $status = $stmt->fetchColumn();
        // No record = open; explicit record must be 'open'
        return $status === false || $status === 'open';
    }

    /**
     * Guard: throw if the date is closed at this branch.
     */
    public function requireDateOpen(int $branchId, string $date): void
    {
        if (!$this->isDateOpen($branchId, $date)) {
            throw new \RuntimeException(
                "Operations are closed for {$date} at this branch. Reopen the period or choose a different date."
            );
        }
    }

    /**
     * Close a period. Rejects if already closed.
     */
    public function closePeriod(int $branchId, string $date, int $userId, string $notes = ''): void
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM bakeshop_operational_periods WHERE branch_id = :bid AND period_date = :dt LIMIT 1'
        );
        $stmt->execute([':bid' => $branchId, ':dt' => $date]);
        $existing = $stmt->fetchColumn();

        if ($existing === 'closed') {
            throw new \RuntimeException("Period {$date} is already closed.");
        }

        if ($existing === false) {
            $this->db->prepare(
                'INSERT INTO bakeshop_operational_periods (branch_id, period_date, status, closed_at, closed_by, notes)
                 VALUES (:bid, :dt, \'closed\', NOW(), :uid, :notes)'
            )->execute([':bid' => $branchId, ':dt' => $date, ':uid' => $userId, ':notes' => $notes]);
        } else {
            $this->db->prepare(
                "UPDATE bakeshop_operational_periods SET status = 'closed', closed_at = NOW(), closed_by = :uid, notes = :notes
                 WHERE branch_id = :bid AND period_date = :dt"
            )->execute([':bid' => $branchId, ':dt' => $date, ':uid' => $userId, ':notes' => $notes]);
        }
    }

    /**
     * Reopen a closed period.
     */
    public function reopenPeriod(int $branchId, string $date, int $userId, string $notes = ''): void
    {
        $stmt = $this->db->prepare(
            'SELECT status FROM bakeshop_operational_periods WHERE branch_id = :bid AND period_date = :dt LIMIT 1'
        );
        $stmt->execute([':bid' => $branchId, ':dt' => $date]);
        $existing = $stmt->fetchColumn();

        if ($existing === false || $existing === 'open') {
            throw new \RuntimeException("Period {$date} is not closed.");
        }

        $this->db->prepare(
            "UPDATE bakeshop_operational_periods
             SET status = 'open', reopened_at = NOW(), reopened_by = :uid, notes = CONCAT(notes, ' | Reopened: ', :notes2)
             WHERE branch_id = :bid AND period_date = :dt"
        )->execute([
            ':bid' => $branchId, ':dt' => $date, ':uid' => $userId, ':notes2' => $notes,
        ]);
    }

    /**
     * Get period status for a date range.
     */
    public function getPeriodStatuses(int $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT period_date, status, closed_at, closed_by, reopened_at, reopened_by, notes
             FROM bakeshop_operational_periods
             WHERE branch_id = :bid AND period_date >= :from AND period_date <= :to
             ORDER BY period_date'
        );
        $stmt->execute([':bid' => $branchId, ':from' => $fromDate, ':to' => $toDate]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
