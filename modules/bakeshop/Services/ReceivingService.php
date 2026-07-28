<?php

declare(strict_types=1);

/**
 * Bakeshop — Receiving Service
 *
 * Manages the delivery/document lifecycle with inventory ledger integration.
 * Wraps existing bakeshopDeliveriesCreate with status transitions,
 * version checks, and automatic ledger posting.
 *
 * States: draft -> posted -> voided
 */

class BakeshopReceivingService
{
    private BakeshopInventoryLedgerService $ledger;
    private ?BakeshopOperationalPeriodService $periods;

    public function __construct(?BakeshopInventoryLedgerService $ledger = null, ?BakeshopOperationalPeriodService $periods = null)
    {
        $this->ledger = $ledger ?? new BakeshopInventoryLedgerService();
        $this->periods = $periods;
    }

    /**
     * Create a draft delivery. No ledger impact.
     */
    public function createDraft(array $input): array
    {
        return bakeshopDeliveriesCreate($input);
    }

    /**
     * Post a delivery: set status=posted, record movements in ledger.
     * Uses optimistic concurrency: caller must provide expected version.
     *
     * @throws \RuntimeException on stale version or posting failure
     */
    public function post(int $deliveryId, array $items, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            // Lock and version-check
            $stmt = $db->prepare(
                'SELECT id, status, version, branch_id, delivered_at FROM bakeshop_deliveries WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $deliveryId]);
            $delivery = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$delivery) {
                throw new \RuntimeException('Delivery not found.');
            }
            if ($delivery['status'] !== 'draft') {
                throw new \RuntimeException("Cannot post delivery in status '{$delivery['status']}'.");
            }
            if ((int)$delivery['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $delivery['version']);
            }

            // Period-close guard
            if ($this->periods !== null) {
                $this->periods->requireDateOpen((int)$delivery['branch_id'] ?? 0, substr((string)$delivery['delivered_at'], 0, 10));
            }

            // Update status + increment version
            $upd = $db->prepare(
                "UPDATE bakeshop_deliveries SET status = 'posted', version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([':id' => $deliveryId, ':ver' => $expectedVersion]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on delivery ' . $deliveryId);
            }

            // Record movements in ledger
            $this->ledger->recordDeliveryPosting($deliveryId, $items, $userId);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return bakeshopDeliveriesFindById($deliveryId) ?? [];
    }

    /**
     * Void a posted delivery: set status=voided, record compensating movements.
     */
    public function void(int $deliveryId, string $reason, int $expectedVersion, ?int $userId = null): array
    {
        $db = bakeshopDb();

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, status, version FROM bakeshop_deliveries WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $deliveryId]);
            $delivery = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$delivery) {
                throw new \RuntimeException('Delivery not found.');
            }
            if ($delivery['status'] !== 'posted') {
                throw new \RuntimeException("Cannot void delivery in status '{$delivery['status']}'.");
            }
            if ((int)$delivery['version'] !== $expectedVersion) {
                throw new \RuntimeException('Stale version: expected ' . $expectedVersion . ', current ' . $delivery['version']);
            }

            $upd = $db->prepare(
                "UPDATE bakeshop_deliveries SET status = 'voided', void_reason = :reason, voided_by = :uid, voided_at = NOW(), version = version + 1 WHERE id = :id AND version = :ver"
            );
            $upd->execute([
                ':id' => $deliveryId, ':ver' => $expectedVersion,
                ':reason' => $reason, ':uid' => $userId,
            ]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Concurrent modification detected on delivery ' . $deliveryId);
            }

            // Record compensating movements
            $this->ledger->recordVoid('delivery', $deliveryId, $reason, $userId);

            // Fire event
            if (function_exists('app') && app()->events()) {
                app()->events()->fire('bakeshop.delivery.voided', [
                    'id' => $deliveryId, 'reason' => $reason,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return bakeshopDeliveriesFindById($deliveryId) ?? [];
    }

    /**
     * Get the delivery's current version for optimistic concurrency.
     */
    public function getVersion(int $deliveryId): int
    {
        $stmt = bakeshopDb()->prepare('SELECT version FROM bakeshop_deliveries WHERE id = :id');
        $stmt->execute([':id' => $deliveryId]);
        return (int)$stmt->fetchColumn();
    }
}
