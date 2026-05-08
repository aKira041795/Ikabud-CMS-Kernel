<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Types;

require_once __DIR__ . '/TypeAst.php';

/**
 * DiSyL 4.2 — Subtype check.
 *
 * Structural assignability: returns true iff a value of type `$source` can be
 * safely used where a value of type `$target` is expected.
 *
 * Implemented rules (4.2 subset):
 *   - `any` / `unknown` accept everything; everything assigns to `any`.
 *   - Primitive equality: string→string, number→number, boolean→boolean.
 *   - Literal subtyping: a literal assigns to its base primitive (and to
 *     itself, and to any union containing it).
 *   - Union: source assigns to target if every source-member assigns to
 *     target; a single value assigns to a union if it assigns to any member.
 *   - Object: width subtyping — every required prop in target must exist in
 *     source with an assignable type. Optional target props need not exist
 *     in source. Source may have extra props (TS allows; we follow).
 *   - Array: element type assignability; readonly arrays accept mutable
 *     arrays of the same element type but not vice-versa.
 *   - TypeRef: resolved against the declared `types` map; cycles cut at
 *     {@see RECURSION_LIMIT}.
 *
 * Out of scope (4.2): variance on conditional types, distributive
 * conditionals, intersection narrowing.
 */
final class Subtype
{
    public const RECURSION_LIMIT = 50;

    /** @param array<string, TypeNode> $env */
    public static function assignable(TypeNode $source, TypeNode $target, array $env, int $depth = 0): bool
    {
        if ($depth > self::RECURSION_LIMIT) {
            return true; // bail open to avoid infinite loops
        }

        $source = self::resolve($source, $env, $depth);
        $target = self::resolve($target, $env, $depth);

        // any / unknown sinks
        if ($target instanceof PrimitiveType && ($target->name === 'any' || $target->name === 'unknown')) {
            return true;
        }
        if ($source instanceof PrimitiveType && $source->name === 'any') {
            return true;
        }

        // Union on the source: every member must assign.
        if ($source instanceof UnionType) {
            foreach ($source->members as $m) {
                if (!self::assignable($m, $target, $env, $depth + 1)) return false;
            }
            return true;
        }

        // Union on the target: at least one member must accept.
        if ($target instanceof UnionType) {
            foreach ($target->members as $m) {
                if (self::assignable($source, $m, $env, $depth + 1)) return true;
            }
            return false;
        }

        // Literal → primitive widening
        if ($source instanceof LiteralType && $target instanceof PrimitiveType) {
            return match ($target->name) {
                'string'  => is_string($source->value),
                'number'  => is_int($source->value) || is_float($source->value),
                'boolean' => is_bool($source->value),
                default   => false,
            };
        }

        // Literal → literal (exact)
        if ($source instanceof LiteralType && $target instanceof LiteralType) {
            return $source->value === $target->value;
        }

        // Primitive → primitive
        if ($source instanceof PrimitiveType && $target instanceof PrimitiveType) {
            return $source->name === $target->name;
        }

        // Array → array
        if ($source instanceof ArrayType && $target instanceof ArrayType) {
            if (!$target->readonly && $source->readonly) {
                return false;
            }
            return self::assignable($source->element, $target->element, $env, $depth + 1);
        }

        // Object → object: width subtyping
        if ($source instanceof ObjectType && $target instanceof ObjectType) {
            foreach ($target->properties as $name => $tspec) {
                $sspec = $source->properties[$name] ?? null;
                if ($sspec === null) {
                    if ($tspec['optional']) continue;
                    return false;
                }
                if (!self::assignable($sspec['type'], $tspec['type'], $env, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Resolve a TypeRef through the env (handles utility types and named
     * type aliases).
     *
     * @param array<string, TypeNode> $env
     */
    public static function resolve(TypeNode $node, array $env, int $depth = 0): TypeNode
    {
        if ($depth > self::RECURSION_LIMIT) return $node;
        if (!$node instanceof TypeRef) return $node;
        $resolved = UtilityTypes::apply($node, $env, $depth + 1);
        if ($resolved !== null) {
            return self::resolve($resolved, $env, $depth + 1);
        }
        if (array_key_exists($node->name, $env)) {
            return self::resolve($env[$node->name], $env, $depth + 1);
        }
        return $node;
    }
}
