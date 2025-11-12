<?php
/**
 * Transaction Manager
 * 
 * Provides atomic transaction support for kernel operations
 * Supports nested transactions and automatic rollback
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class TransactionManager
{
    private PDO $db;
    private array $activeTransactions = [];
    private int $transactionLevel = 0;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Begin a new transaction with optional context metadata
     */
    public function begin(string $transactionId, array $context = []): void
    {
        $this->transactionLevel++;
        
        if ($this->transactionLevel === 1) {
            $this->db->beginTransaction();
        }
        
        $this->activeTransactions[$transactionId] = [
            'started_at' => microtime(true),
            'level' => $this->transactionLevel,
            'operations' => [],
            'savepoint' => "sp_level_{$this->transactionLevel}",
            'context' => $context
        ];
        
        // Create savepoint for nested transactions
        if ($this->transactionLevel > 1) {
            $savepoint = $this->activeTransactions[$transactionId]['savepoint'];
            $this->db->exec("SAVEPOINT {$savepoint}");
        }
    }
    
    /**
     * Add an operation with rollback handler
     */
    public function addOperation(string $transactionId, callable $operation, callable $rollback): void
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            throw new Exception("Transaction not found: {$transactionId}");
        }
        
        $this->activeTransactions[$transactionId]['operations'][] = [
            'execute' => $operation,
            'rollback' => $rollback,
            'executed_at' => microtime(true)
        ];
    }
    
    /**
     * Execute an operation within transaction context
     */
    public function execute(string $transactionId, callable $operation, ?callable $rollback = null): mixed
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            throw new Exception("Transaction not found: {$transactionId}");
        }
        
        try {
            $result = $operation();
            
            if ($rollback) {
                $this->addOperation($transactionId, $operation, $rollback);
            }
            
            return $result;
        } catch (Exception $e) {
            $this->rollback($transactionId);
            throw $e;
        }
    }
    
    /**
     * Commit transaction
     */
    public function commit(string $transactionId): bool
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            throw new Exception("Transaction not found: {$transactionId}");
        }
        
        $transaction = $this->activeTransactions[$transactionId];
        
        try {
            if ($transaction['level'] === 1) {
                $this->db->commit();
            } else {
                // Release savepoint for nested transaction
                $savepoint = $transaction['savepoint'];
                $this->db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            
            $this->transactionLevel--;
            unset($this->activeTransactions[$transactionId]);
            
            return true;
        } catch (Exception $e) {
            $this->rollback($transactionId);
            throw new Exception("Transaction commit failed: " . $e->getMessage());
        }
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(string $transactionId): void
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            return; // Already rolled back
        }
        
        $transaction = $this->activeTransactions[$transactionId];
        
        try {
            if ($transaction['level'] === 1) {
                $this->db->rollBack();
            } else {
                // Rollback to savepoint for nested transaction
                $savepoint = $transaction['savepoint'];
                $this->db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }
        } catch (Exception $e) {
            error_log("Transaction rollback failed: " . $e->getMessage());
        }
        
        // Execute rollback handlers in reverse order
        $operations = array_reverse($transaction['operations'] ?? []);
        foreach ($operations as $op) {
            try {
                $op['rollback']();
            } catch (Exception $e) {
                error_log("Rollback handler failed: " . $e->getMessage());
            }
        }
        
        $this->transactionLevel--;
        unset($this->activeTransactions[$transactionId]);
    }
    
    /**
     * Check if transaction is active
     */
    public function isActive(string $transactionId): bool
    {
        return isset($this->activeTransactions[$transactionId]);
    }
    
    /**
     * Get transaction info
     */
    public function getInfo(string $transactionId): ?array
    {
        if (!isset($this->activeTransactions[$transactionId])) {
            return null;
        }
        
        $tx = $this->activeTransactions[$transactionId];
        return [
            'id' => $transactionId,
            'level' => $tx['level'],
            'started_at' => $tx['started_at'],
            'duration' => microtime(true) - $tx['started_at'],
            'operations_count' => count($tx['operations'])
        ];
    }
    
    /**
     * Get all active transactions
     */
    public function getActiveTransactions(): array
    {
        return array_keys($this->activeTransactions);
    }
    
    /**
     * Rollback all active transactions (emergency cleanup)
     */
    public function rollbackAll(): void
    {
        foreach (array_keys($this->activeTransactions) as $txId) {
            $this->rollback($txId);
        }
    }
}
