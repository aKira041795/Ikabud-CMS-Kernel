<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Database;

use PDOStatement;

/**
 * Intercepts execute() calls for the Database Interceptor Seam.
 */
class KernelPDOStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        $result = parent::execute($params);

        if (function_exists('app')) {
            try {
                app()->events()->fire('kernel.database.query.after', [
                    'sql'         => $this->queryString,
                    'duration_ms' => (microtime(true) - $start) * 1000,
                    'source'      => 'pdo_statement',
                ]);
            } catch (\Throwable $ignored) {}
        }

        return $result;
    }
}
