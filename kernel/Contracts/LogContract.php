<?php
/**
 * Ikabud Kernel — Log Contract
 * 
 * Modules consume this interface for logging.
 * They never write directly to log files or audit_logs table.
 * 
 * @package Ikabud\Kernel\Contracts
 */

namespace Ikabud\Kernel\Contracts;

interface LogContract
{
    /**
     * Write a log message.
     */
    public function log(string $message, string $level = 'info', array $context = []): void;

    /**
     * Write a centralized audit log entry.
     */
    public function audit(
        string $action,
        ?int $branchId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        mixed $oldData = null,
        mixed $newData = null,
        ?string $reason = null
    ): void;
}
