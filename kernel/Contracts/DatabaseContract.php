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
     * Execute a raw query (SELECT only) and return the statement.
     * 
     * @throws \RuntimeException if the query is not a SELECT or accesses unauthorized tables
     */
    public function query(string $sql): PDOStatement;

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
     * Roll back a transaction.
     */
    public function rollBack(): bool;
}
