<?php
/**
 * Ikabud Kernel — Database Contract
 * 
 * Modules consume this interface for database access.
 * The kernel implementation enforces table ownership rules:
 *   - owns_tables:  full CRUD
 *   - reads_tables: SELECT only
 *   - anything else: denied + logged
 * 
 * Modules never get raw PDO. They get this.
 * 
 * @package Ikabud\Kernel\Contracts
 */

namespace Ikabud\Kernel\Contracts;

use PDOStatement;

interface DatabaseContract
{
    /**
     * Prepare and return a PDOStatement for a SQL query.
     * The implementation MUST validate table access before execution.
     * 
     * @throws \RuntimeException if the query accesses unauthorized tables
     */
    public function prepare(string $sql): PDOStatement;

    /**
     * Execute a raw query and return the statement.
     * If $params is provided, the query is prepared and executed with those parameters.
     *
     * @param array $params Positional (?) or named (:key) parameters
     * @throws \RuntimeException if the query accesses unauthorized tables
     */
    public function query(string $sql, array $params = []): PDOStatement;

    /**
     * Prepare and execute a write statement (INSERT/UPDATE/DELETE).
     *
     * @param array $params Positional (?) or named (:key) parameters
     * @throws \RuntimeException if access is denied or execution fails
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): string;

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): bool;

    /**
     * Commit a transaction.
     */
    public function commit(): bool;

    /**
     * Check whether a transaction is currently active.
     */
    public function inTransaction(): bool;

    /**
     * Roll back a transaction.
     */
    public function rollBack(): bool;
}
