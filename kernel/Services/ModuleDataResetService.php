<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Module Data Reset Service — standard, audited data reset for module DBs.
 *
 * Standard reset pattern for module-owned databases. Modules describe the
 * operations (wipe a table, or zero specific columns) and the service executes
 * them inside a FOREIGN_KEY_CHECKS=0 block with an audit trail — so reset
 * implementations don't drift per module (AW/PAL/DC Cafe all used bespoke
 * copies before this service).
 *
 * Operations (in order):
 *   ['table' => 'x', 'mode' => 'truncate']                → DELETE FROM `x`
 *   ['table' => 'x', 'mode' => 'set_zero',                → UPDATE `x` SET c=0...
 *    'columns' => ['a','b']]
 *
 * Schema/catalog rows (e.g. product names, users, config) are untouched — this
 * wipes DATA only, as specified by the caller.
 *
 * @package Ikabud\Kernel\Services
 */
final class ModuleDataResetService
{
    /**
     * Execute the given data-wipe operations on the module DB.
     *
     * @param \Ikabud\Kernel\Contracts\ModuleDB $db module-scoped DB
     * @param list<array{table:string,mode:string,columns?:list<string>}> $operations
     * @param array $options [ 'event' => string audit event, 'by_user' => int ]
     * @return list<string> the tables that were modified
     */
    public static function reset(\Ikabud\Kernel\Contracts\ModuleDB $db, array $operations, array $options = []): array
    {
        $modified = [];

        $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($operations as $op) {
                $table = (string) ($op['table'] ?? '');
                $mode = (string) ($op['mode'] ?? '');
                if ($table === '' || $mode === '') {
                    continue;
                }
                $safe = self::safeIdentifier($table);

                if ($mode === 'truncate') {
                    $db->execute('DELETE FROM `' . $safe . '`');
                    $modified[] = $safe;
                } elseif ($mode === 'set_zero') {
                    $columns = $op['columns'] ?? [];
                    if ($columns === []) {
                        continue;
                    }
                    $sets = [];
                    foreach ($columns as $col) {
                        $sets[] = '`' . self::safeIdentifier((string) $col) . '` = 0';
                    }
                    $db->execute('UPDATE `' . $safe . '` SET ' . implode(', ', $sets));
                    $modified[] = $safe;
                }
            }
        } finally {
            $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        if (function_exists('write_log')) {
            write_log((string) ($options['event'] ?? 'module.data.reset'), 'info', [
                'by_user' => (int) ($options['by_user'] ?? 0),
                'tables' => $modified,
            ]);
        }

        return $modified;
    }

    private static function safeIdentifier(string $name): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/i', '', $name);
        if (!is_string($safe) || $safe === '' || $safe !== $name) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
        return $safe;
    }
}
