<?php
/**
 * DiSyL Grammar — PLANNED keywords / type system extensions
 *
 * Holds constants that describe future DiSyL surface area (v11 / v11.1).
 * Most keywords here are NOT yet parsed or executed by the v4 TemplateEngine.
 *
 * NOTE: TemplateEngine::evaluateStructureBody() has dispatch entries for
 * several v11.1 keywords ({sandbox}, {trans}, {cache}, {experiment},
 * {parallel}, {await}, {suspense}, {federated_query}, {ai_generate},
 * {ai_query}, {ai_complete}) but their evaluator methods are stubs that
 * either no-op or return placeholder content. They are wired in the
 * parser/dispatch layer but NOT yet functional at runtime.
 *
 * Split out of `kernel/DiSyL/Grammar.php` in kernel 4.0.0 so that the live
 * grammar surface stays focused on what the runtime actually understands.
 *
 * @package Ikabud\Kernel\DiSyL\Grammar
 * @version 1.0.0
 */

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Grammar;

final class Planned
{
    // ── v11: Pattern Matching Keywords (PLANNED — not yet implemented) ──
    public const PATTERN_KEYWORDS = [
        'match', 'when', 'endmatch', 'endwhen',
        'case', 'default', 'guard', 'if',
    ];

    // ── v11: Async Keywords (PLANNED — not yet implemented) ──
    public const ASYNC_KEYWORDS = [
        'await', 'endawait', 'loading', 'catch',
        'parallel', 'endparallel', 'fetch', 'then',
        'suspense', 'endsuspense', 'fallback',
    ];

    // ── v11: i18n Keywords (PLANNED — not yet implemented) ──
    public const I18N_KEYWORDS = [
        'trans', 'endtrans', 'plural', 'context',
    ];

    // ── v11: Type Operators (PLANNED) ──
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

    // ── v11: Built-in Utility Types (PLANNED) ──
    public const UTILITY_TYPES = [
        'Partial', 'Required', 'Readonly', 'Pick', 'Omit', 'Record',
        'Exclude', 'Extract', 'NonNullable', 'ReturnType', 'Parameters',
        'Awaited',
    ];

    // ── v11.1: Experimentation Keywords (PLANNED) ──
    public const EXPERIMENT_KEYWORDS = [
        'experiment', 'variant', 'endexperiment', 'convert',
    ];

    // ── v11.1: Cache Keywords (PLANNED) ──
    public const CACHE_KEYWORDS = [
        'cache', 'endcache', 'depends_on', 'invalidate', 'ttl',
    ];

    // ── v11.1: Security Keywords (PLANNED) ──
    public const SECURITY_KEYWORDS = [
        'sandbox', 'endsandbox', 'trusted', 'untrusted',
    ];

    // ── v11.1: Federation Keywords (PLANNED) ──
    public const FEDERATION_KEYWORDS = [
        'federated_query', 'remote', 'aggregate',
    ];

    // ── v11.1: AI Keywords (PLANNED) ──
    public const AI_KEYWORDS = [
        'ai_generate', 'ai_query', 'ai_complete', 'ai_optimize',
    ];

    public static function isUtilityType(string $type): bool
    {
        return in_array($type, self::UTILITY_TYPES, true);
    }

    public static function getAllV11Keywords(): array
    {
        return array_merge(
            self::PATTERN_KEYWORDS,
            self::ASYNC_KEYWORDS,
            self::I18N_KEYWORDS
        );
    }

    public static function getAllV11_1Keywords(): array
    {
        return array_merge(
            self::EXPERIMENT_KEYWORDS,
            self::CACHE_KEYWORDS,
            self::SECURITY_KEYWORDS,
            self::FEDERATION_KEYWORDS,
            self::AI_KEYWORDS
        );
    }
}
