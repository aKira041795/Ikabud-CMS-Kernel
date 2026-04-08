<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityAuthority;

/**
 * Tracks and routes entity synchronization contracts.
 * Since data federation is strictly disallowed, consumers rely on Sync Contracts
 * (which map CRUD-like operations over the Event Bridge) rather than cross-module direct DB/API writes.
 */
final class SyncContractRegistry
{
    /**
     * @var array<string, array<int, array{consumer: string, operation: string, handler: string}>>
     * entityType => list of consumer contracts
     */
    private array $contracts = [];

    public function registerContract(string $entityType, string $consumerModuleId, string $operation, string $handler): void
    {
        if (!isset($this->contracts[$entityType])) {
            $this->contracts[$entityType] = [];
        }

        $this->contracts[$entityType][] = [
            'consumer' => $consumerModuleId,
            'operation' => $operation,
            'handler' => $handler,
        ];
    }
    
    public function getContractsForEntity(string $entityType): array
    {
        return $this->contracts[$entityType] ?? [];
    }
    
    public function reset(): void
    {
        $this->contracts = [];
    }
}
