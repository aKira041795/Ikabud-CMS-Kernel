<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Types;

/**
 * DiSyL 4.2 — Type AST.
 *
 * Lightweight value-objects representing parsed type expressions used by
 * {@see TypeParser}, {@see TypeChecker}, and {@see Subtype}.
 *
 * Scope (4.2):
 *   - Primitives:     string | number | boolean | null | unknown | any | void
 *   - Literals:       'foo' | 42 | true | null
 *   - Object types:   { a: T; b?: T; readonly c: T }
 *   - Array types:    T[]   (and `readonly T[]`)
 *   - Union types:    A | B
 *   - Type refs:      Foo, Pick<T, 'a'|'b'>
 *
 * Out of scope for 4.2 (queued for 4.2.1):
 *   - Intersection (`&`), `keyof`, `typeof`, `infer`, conditional `extends`
 *   - Spread (`...`), `Record`, `Exclude`, `Extract`, `NonNullable`,
 *     `ReturnType`, `Parameters`, `Awaited`
 */

abstract class TypeNode
{
    abstract public function describe(): string;
}

final class PrimitiveType extends TypeNode
{
    public function __construct(public readonly string $name) {}
    public function describe(): string { return $this->name; }
}

final class LiteralType extends TypeNode
{
    /** @param string|int|float|bool|null $value */
    public function __construct(public readonly string|int|float|bool|null $value) {}
    public function describe(): string
    {
        if ($this->value === null) return 'null';
        if (is_bool($this->value)) return $this->value ? 'true' : 'false';
        if (is_string($this->value)) return "'" . $this->value . "'";
        return (string) $this->value;
    }
}

final class ObjectType extends TypeNode
{
    /**
     * @param array<string, array{type: TypeNode, optional: bool, readonly: bool}> $properties
     */
    public function __construct(public readonly array $properties) {}
    public function describe(): string
    {
        $parts = [];
        foreach ($this->properties as $name => $spec) {
            $parts[] = ($spec['readonly'] ? 'readonly ' : '')
                . $name
                . ($spec['optional'] ? '?' : '')
                . ': ' . $spec['type']->describe();
        }
        return '{ ' . implode('; ', $parts) . ' }';
    }
}

final class ArrayType extends TypeNode
{
    public function __construct(
        public readonly TypeNode $element,
        public readonly bool $readonly = false
    ) {}
    public function describe(): string
    {
        return ($this->readonly ? 'readonly ' : '') . $this->element->describe() . '[]';
    }
}

final class UnionType extends TypeNode
{
    /** @param list<TypeNode> $members */
    public function __construct(public readonly array $members) {}
    public function describe(): string
    {
        return implode(' | ', array_map(static fn(TypeNode $t) => $t->describe(), $this->members));
    }
}

final class TypeRef extends TypeNode
{
    /** @param list<TypeNode> $args */
    public function __construct(public readonly string $name, public readonly array $args = []) {}
    public function describe(): string
    {
        if ($this->args === []) return $this->name;
        $argDescs = array_map(static fn(TypeNode $t) => $t->describe(), $this->args);
        return $this->name . '<' . implode(', ', $argDescs) . '>';
    }
}
