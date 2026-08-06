<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Version-tolerant PDO MySQL driver attribute constants.
 *
 * PHP 8.5 deprecated the legacy PDO::MYSQL_ATTR_* constants in favour of the
 * Pdo\Mysql::ATTR_* class constants. This resolver prefers the new constant on
 * 8.5+ (keeping error.log deprecation-clean) and falls back to the legacy name
 * on 8.2–8.4, so one code path works across the supported PHP range without
 * @-suppression or repeated ternaries.
 */
final class PdoMysql
{
    /** @var array<string, int> Resolved values keyed by constant suffix */
    private static array $resolved = [];

    /** Resolve a PDO MySQL attribute constant, e.g. PdoMysql::attr('ATTR_INIT_COMMAND'). */
    public static function attr(string $suffix): int
    {
        if (isset(self::$resolved[$suffix])) {
            return self::$resolved[$suffix];
        }

        $newConstant = 'Pdo\\Mysql::' . $suffix;
        $value = defined($newConstant)
            ? constant($newConstant)
            : constant('PDO::' . $suffix);

        return self::$resolved[$suffix] = (int) $value;
    }

    /** Whether the attribute constant exists on this runtime (new 8.5 name or legacy). */
    public static function available(string $suffix): bool
    {
        return defined('Pdo\\Mysql::' . $suffix) || defined('PDO::' . $suffix);
    }
}
