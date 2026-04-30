<?php
/**
 * DiSyL v4 Function Registry
 *
 * Provides a safe, whitelisted set of built-in functions that can be called
 * from Disyl templates via the function-call syntax: funcname(arg1, arg2, ...).
 *
 * Only functions registered here are reachable from templates — unknown names
 * return null rather than executing arbitrary PHP.
 *
 * @package Ikabud\Kernel\DiSyL\v4
 */

namespace Ikabud\Kernel\DiSyL\v4;

class FunctionRegistry
{
    /** @var array<string, callable> */
    private static array $functions = [];

    private static bool $initialized = false;

    /**
     * Return true if $name is a known, callable function.
     */
    public static function has(string $name): bool
    {
        self::init();
        return isset(self::$functions[$name]);
    }

    /**
     * Call the registered function $name with $args.
     * Returns null for unknown function names (safe no-op).
     *
     * @param mixed[] $args
     */
    public static function call(string $name, array $args): mixed
    {
        self::init();
        if (!isset(self::$functions[$name])) {
            return null;
        }
        return (self::$functions[$name])(...$args);
    }

    /**
     * Register an additional user-defined function.
     * Existing registrations are not overwritten; call unregister() first if needed.
     */
    public static function register(string $name, callable $fn): void
    {
        self::init();
        self::$functions[$name] = $fn;
    }

    /**
     * Remove a registered function.
     */
    public static function unregister(string $name): void
    {
        unset(self::$functions[$name]);
    }

    // ── Built-in functions ──────────────────────────────────────────────────

    private static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$functions = [
            // Sequence
            'range' => static function (mixed $start, mixed $end, mixed $step = 1): array {
                $step = (int)($step ?: 1);
                return range((int)$start, (int)$end, $step);
            },

            // Math
            'abs'   => static fn(mixed $v): float|int => abs((float)$v),
            'round' => static fn(mixed $v, mixed $p = 0): float => round((float)$v, (int)$p),
            'floor' => static fn(mixed $v): float => floor((float)$v),
            'ceil'  => static fn(mixed $v): float => ceil((float)$v),
            'min'   => static fn(mixed ...$args): mixed => min(...$args),
            'max'   => static fn(mixed ...$args): mixed => max(...$args),

            // Counting / size
            'count'  => static fn(mixed $v): int => is_countable($v) ? count($v) : 0,
            'length' => static fn(mixed $v): int => is_string($v) ? mb_strlen($v) : (is_countable($v) ? count($v) : 0),

            // String helpers
            'str_pad'    => static fn(mixed $v, mixed $len, mixed $pad = ' ', mixed $type = STR_PAD_RIGHT): string
                => str_pad((string)$v, (int)$len, (string)$pad, (int)$type),
            'str_repeat' => static fn(mixed $v, mixed $n): string
                => str_repeat((string)$v, max(0, (int)$n)),
            'str_replace' => static fn(mixed $search, mixed $replace, mixed $subject): string
                => str_replace((string)$search, (string)$replace, (string)$subject),
            'strtolower' => static fn(mixed $v): string => strtolower((string)$v),
            'strtoupper' => static fn(mixed $v): string => strtoupper((string)$v),
            'trim'       => static fn(mixed $v): string => trim((string)$v),
            'ltrim'      => static fn(mixed $v): string => ltrim((string)$v),
            'rtrim'      => static fn(mixed $v): string => rtrim((string)$v),
            'substr'     => static fn(mixed $v, mixed $start, mixed $len = null): string
                => $len !== null ? substr((string)$v, (int)$start, (int)$len) : substr((string)$v, (int)$start),
            'strlen'     => static fn(mixed $v): int => strlen((string)$v),

            // Number formatting
            'number_format' => static fn(mixed $v, mixed $dec = 0, mixed $dp = '.', mixed $ts = ','): string
                => number_format((float)$v, (int)$dec, (string)$dp, (string)$ts),
        ];
    }
}
