<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Types;

require_once __DIR__ . '/TypeAst.php';

/**
 * DiSyL 4.2 — Utility types (subset).
 *
 * Implements the 5 most commonly-used TypeScript utility types by AST
 * transformation. Each utility takes a TypeRef whose name matches and whose
 * type-arg shape matches; it returns the transformed TypeNode.
 *
 * Implemented in 4.2:
 *   - Partial<T>     — make all props of object T optional
 *   - Required<T>    — make all props of object T required
 *   - Readonly<T>    — mark all props of object T readonly
 *   - Pick<T, K>     — keep only props in K (string-literal union)
 *   - Omit<T, K>     — drop props in K (string-literal union)
 *
 * Queued for 4.2.1: Record, Exclude, Extract, NonNullable, ReturnType,
 * Parameters, Awaited.
 */
final class UtilityTypes
{
    /**
     * @param array<string, TypeNode> $env
     */
    public static function apply(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        return match ($ref->name) {
            'Partial'  => self::partial($ref, $env, $depth),
            'Required' => self::required($ref, $env, $depth),
            'Readonly' => self::readonly($ref, $env, $depth),
            'Pick'     => self::pick($ref, $env, $depth),
            'Omit'     => self::omit($ref, $env, $depth),
            default    => null,
        };
    }

    /** @param array<string, TypeNode> $env */
    private static function partial(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        $obj = self::firstObjectArg($ref, $env, $depth);
        if ($obj === null) return null;
        $props = [];
        foreach ($obj->properties as $name => $spec) {
            $props[$name] = ['type' => $spec['type'], 'optional' => true, 'readonly' => $spec['readonly']];
        }
        return new ObjectType($props);
    }

    /** @param array<string, TypeNode> $env */
    private static function required(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        $obj = self::firstObjectArg($ref, $env, $depth);
        if ($obj === null) return null;
        $props = [];
        foreach ($obj->properties as $name => $spec) {
            $props[$name] = ['type' => $spec['type'], 'optional' => false, 'readonly' => $spec['readonly']];
        }
        return new ObjectType($props);
    }

    /** @param array<string, TypeNode> $env */
    private static function readonly(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        $obj = self::firstObjectArg($ref, $env, $depth);
        if ($obj === null) return null;
        $props = [];
        foreach ($obj->properties as $name => $spec) {
            $props[$name] = ['type' => $spec['type'], 'optional' => $spec['optional'], 'readonly' => true];
        }
        return new ObjectType($props);
    }

    /** @param array<string, TypeNode> $env */
    private static function pick(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        if (count($ref->args) !== 2) return null;
        $obj = self::asObject(Subtype::resolve($ref->args[0], $env, $depth), $env, $depth);
        if ($obj === null) return null;
        $keys = self::collectStringLiterals($ref->args[1]);
        $props = [];
        foreach ($obj->properties as $name => $spec) {
            if (in_array($name, $keys, true)) {
                $props[$name] = $spec;
            }
        }
        return new ObjectType($props);
    }

    /** @param array<string, TypeNode> $env */
    private static function omit(TypeRef $ref, array $env, int $depth): ?TypeNode
    {
        if (count($ref->args) !== 2) return null;
        $obj = self::asObject(Subtype::resolve($ref->args[0], $env, $depth), $env, $depth);
        if ($obj === null) return null;
        $keys = self::collectStringLiterals($ref->args[1]);
        $props = [];
        foreach ($obj->properties as $name => $spec) {
            if (!in_array($name, $keys, true)) {
                $props[$name] = $spec;
            }
        }
        return new ObjectType($props);
    }

    /** @param array<string, TypeNode> $env */
    private static function firstObjectArg(TypeRef $ref, array $env, int $depth): ?ObjectType
    {
        if (count($ref->args) !== 1) return null;
        return self::asObject(Subtype::resolve($ref->args[0], $env, $depth), $env, $depth);
    }

    /** @param array<string, TypeNode> $env */
    private static function asObject(TypeNode $node, array $env, int $depth): ?ObjectType
    {
        if ($node instanceof ObjectType) return $node;
        return null;
    }

    /** @return list<string> */
    private static function collectStringLiterals(TypeNode $node): array
    {
        if ($node instanceof LiteralType && is_string($node->value)) return [$node->value];
        if ($node instanceof UnionType) {
            $out = [];
            foreach ($node->members as $m) {
                if ($m instanceof LiteralType && is_string($m->value)) $out[] = $m->value;
            }
            return $out;
        }
        return [];
    }
}
