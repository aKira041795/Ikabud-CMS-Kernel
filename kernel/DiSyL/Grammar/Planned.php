<?php
/**
 * DiSyL Grammar — TRULY PLANNED future features
 *
 * Holds constants for aspirational DiSyL features that have NO parser
 * or evaluator code yet.  Implemented keyword groups (cache, sandbox,
 * experiment, i18n, async, federation, AI) live in Grammar.php.
 *
 * Still truly PLANNED (no parser or evaluator code yet):
 *   - Type operators: union (|), intersection (&), keyof, typeof, infer
 *   - Utility types: Record, Exclude, Extract, NonNullable, ReturnType etc.
 *   - Template-level Fibers-based I/O multiplexing (Kernel runtime concern)
 *
 * @package Ikabud\Kernel\DiSyL\Grammar
 * @version 1.1.0
 */

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Grammar;

final class Planned
{
    // ── v11: Pattern-Matching Sub-Keywords (PLANNED) ──
    // {match} itself is implemented (evaluateMatchBody).  These are the
    // sub-keywords for extended pattern-matching syntax (guarded arms,
    // exhaustiveness checking, nested pattern destructuring) that have
    // no parser or evaluator code yet.
    public const PATTERN_KEYWORDS = [
        'endmatch', 'endwhen',
        'case', 'guard',
    ];

    // ── v11: Type Operators (PLANNED — no parser or evaluator code) ──
    // Adding these requires deep changes across Parser, Compiler,
    // TypeChecker, and Grammar::validateType().  The v4 type system
    // (string, int, float, bool, array, etc.) covers all current needs.
    public const TYPE_OPERATORS = [
        '|' => 'union',
        '&' => 'intersection',
        '?' => 'optional',
        '!' => 'non_null',
        '...' => 'spread',
        'extends' => 'constraint',
        'infer' => 'infer',
        'keyof' => 'keyof',
        'typeof' => 'typeof',
        'readonly' => 'readonly',
    ];

    // ── v11: Built-in Utility Types (PLANNED — blocked on type operators) ──
    // Utility types like Partial<T> require generics and type parameter
    // support.  Zero parser or evaluator code exists.
    public const UTILITY_TYPES = [
        'Partial', 'Required', 'Readonly', 'Pick', 'Omit', 'Record',
        'Exclude', 'Extract', 'NonNullable', 'ReturnType', 'Parameters',
        'Awaited',
    ];

    public static function isUtilityType(string $type): bool
    {
        return in_array($type, self::UTILITY_TYPES, true);
    }
}
