<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Types;

require_once __DIR__ . '/TypeAst.php';

/**
 * DiSyL 4.2 — Type expression parser.
 *
 * Parses the contents of a {types} block into a map of named types plus a
 * `context` declaration. Grammar (informal):
 *
 *   types-block ::= ( type-decl | context-decl ) ';' ...
 *   type-decl   ::= 'type' IDENT '=' type-expr
 *   context-decl::= 'context' ':' type-expr
 *
 *   type-expr   ::= union
 *   union       ::= postfix ( '|' postfix )*
 *   postfix     ::= primary ( '[' ']' )*
 *   primary     ::= literal | primitive | object | type-ref | '(' union ')'
 *   primitive   ::= 'string' | 'number' | 'boolean' | 'null' | 'unknown' | 'any' | 'void'
 *   literal     ::= STRING | NUMBER | 'true' | 'false' | 'null'
 *   object      ::= '{' ( prop ( ';' | ',' ) )* '}'
 *   prop        ::= [ 'readonly' ] IDENT [ '?' ] ':' union
 *   type-ref    ::= IDENT [ '<' union ( ',' union )* '>' ]
 *
 * Whitespace and `;` separators are insignificant. Comments are stripped.
 *
 * The parser is intentionally small: it accepts the subset documented in
 * {@see TypeAst.php} and rejects unsupported constructs with a clear error.
 */
final class TypeParser
{
    /** @var list<array{kind: string, value: string, pos: int}> */
    private array $tokens = [];
    private int $pos = 0;

    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, TypeNode> */
    private array $types = [];

    private ?TypeNode $contextType = null;

    /**
     * Parse a {types} block body.
     *
     * @return array{types: array<string, TypeNode>, context: ?TypeNode, errors: list<string>}
     */
    public function parse(string $source): array
    {
        $this->tokens = $this->tokenize($source);
        $this->pos = 0;
        $this->errors = [];
        $this->types = [];
        $this->contextType = null;

        while (!$this->isEnd()) {
            $tok = $this->peek();
            if ($tok['kind'] === 'ident' && $tok['value'] === 'type') {
                $this->parseTypeDecl();
            } elseif ($tok['kind'] === 'ident' && $tok['value'] === 'context') {
                $this->parseContextDecl();
            } elseif ($tok['kind'] === 'punct' && ($tok['value'] === ';' || $tok['value'] === ',')) {
                $this->advance();
            } else {
                $this->errors[] = 'Unexpected token "' . $tok['value'] . '" at position ' . $tok['pos'];
                $this->advance();
            }
        }

        return [
            'types'   => $this->types,
            'context' => $this->contextType,
            'errors'  => $this->errors,
        ];
    }

    private function parseTypeDecl(): void
    {
        $this->advance(); // 'type'
        $name = $this->expect('ident', 'expected type name');
        if ($name === null) return;
        if (!$this->consumePunct('=')) {
            $this->errors[] = 'Expected "=" after type name "' . $name['value'] . '"';
            return;
        }
        $expr = $this->parseUnion();
        if ($expr === null) return;
        $this->types[$name['value']] = $expr;
    }

    private function parseContextDecl(): void
    {
        $this->advance(); // 'context'
        if (!$this->consumePunct(':')) {
            $this->errors[] = 'Expected ":" after "context"';
            return;
        }
        $expr = $this->parseUnion();
        if ($expr === null) return;
        $this->contextType = $expr;
    }

    private function parseUnion(): ?TypeNode
    {
        $first = $this->parsePostfix();
        if ($first === null) return null;
        $members = [$first];
        while ($this->matchPunct('|')) {
            $next = $this->parsePostfix();
            if ($next === null) {
                return new UnionType($members);
            }
            $members[] = $next;
        }
        return count($members) === 1 ? $members[0] : new UnionType($members);
    }

    private function parsePostfix(): ?TypeNode
    {
        $node = $this->parsePrimary();
        if ($node === null) return null;
        while ($this->peek()['kind'] === 'punct' && $this->peek()['value'] === '[') {
            $save = $this->pos;
            $this->advance(); // '['
            if ($this->consumePunct(']')) {
                $node = new ArrayType($node);
                continue;
            }
            $this->pos = $save;
            break;
        }
        return $node;
    }

    private function parsePrimary(): ?TypeNode
    {
        $tok = $this->peek();
        if ($tok['kind'] === 'eof') {
            $this->errors[] = 'Unexpected end of type expression';
            return null;
        }

        // readonly modifier on array type: `readonly T[]`
        if ($tok['kind'] === 'ident' && $tok['value'] === 'readonly') {
            $this->advance();
            $inner = $this->parsePrimary();
            if ($inner === null) return null;
            // After readonly, expect [] suffix.
            if ($this->peek()['kind'] === 'punct' && $this->peek()['value'] === '[') {
                $this->advance();
                if (!$this->consumePunct(']')) {
                    $this->errors[] = 'Expected "]" in readonly array type';
                    return null;
                }
                return new ArrayType($inner, true);
            }
            // readonly applied to a non-array: just return inner (TS allows this on tuples; we degrade).
            return $inner;
        }

        if ($tok['kind'] === 'punct' && $tok['value'] === '(') {
            $this->advance();
            $expr = $this->parseUnion();
            if (!$this->consumePunct(')')) {
                $this->errors[] = 'Expected ")" in parenthesized type';
            }
            return $expr;
        }

        if ($tok['kind'] === 'punct' && $tok['value'] === '{') {
            return $this->parseObject();
        }

        if ($tok['kind'] === 'string') {
            $this->advance();
            return new LiteralType($tok['value']);
        }

        if ($tok['kind'] === 'number') {
            $this->advance();
            $val = $tok['value'];
            return new LiteralType(str_contains($val, '.') ? (float) $val : (int) $val);
        }

        if ($tok['kind'] === 'ident') {
            $this->advance();
            $name = $tok['value'];
            switch ($name) {
                case 'string':
                case 'number':
                case 'boolean':
                case 'unknown':
                case 'any':
                case 'void':
                    return new PrimitiveType($name);
                case 'null':
                    return new LiteralType(null);
                case 'true':
                    return new LiteralType(true);
                case 'false':
                    return new LiteralType(false);
            }
            // type reference, possibly with generics
            $args = [];
            if ($this->matchPunct('<')) {
                while (true) {
                    $arg = $this->parseUnion();
                    if ($arg === null) break;
                    $args[] = $arg;
                    if ($this->matchPunct(',')) continue;
                    if ($this->consumePunct('>')) break;
                    $this->errors[] = 'Expected "," or ">" in type arguments for "' . $name . '"';
                    break;
                }
            }
            return new TypeRef($name, $args);
        }

        $this->errors[] = 'Unexpected token "' . $tok['value'] . '" in type expression';
        $this->advance();
        return null;
    }

    private function parseObject(): ?TypeNode
    {
        $this->advance(); // '{'
        $props = [];
        while (true) {
            $tok = $this->peek();
            if ($tok['kind'] === 'punct' && $tok['value'] === '}') {
                $this->advance();
                break;
            }
            if ($tok['kind'] === 'eof') {
                $this->errors[] = 'Unterminated object type';
                break;
            }
            if ($tok['kind'] === 'punct' && ($tok['value'] === ';' || $tok['value'] === ',')) {
                $this->advance();
                continue;
            }

            $readonly = false;
            if ($tok['kind'] === 'ident' && $tok['value'] === 'readonly') {
                $readonly = true;
                $this->advance();
                $tok = $this->peek();
            }

            if ($tok['kind'] !== 'ident' && $tok['kind'] !== 'string') {
                $this->errors[] = 'Expected property name in object type, got "' . $tok['value'] . '"';
                $this->advance();
                continue;
            }
            $name = $tok['value'];
            $this->advance();

            $optional = false;
            if ($this->peek()['kind'] === 'punct' && $this->peek()['value'] === '?') {
                $this->advance();
                $optional = true;
            }

            if (!$this->consumePunct(':')) {
                $this->errors[] = 'Expected ":" after property name "' . $name . '"';
                continue;
            }
            $type = $this->parseUnion();
            if ($type === null) continue;
            $props[$name] = ['type' => $type, 'optional' => $optional, 'readonly' => $readonly];
        }
        return new ObjectType($props);
    }

    private function isEnd(): bool { return $this->peek()['kind'] === 'eof'; }

    /** @return array{kind:string,value:string,pos:int} */
    private function peek(): array { return $this->tokens[$this->pos] ?? ['kind' => 'eof', 'value' => '', 'pos' => -1]; }

    private function advance(): void { $this->pos++; }

    private function matchPunct(string $value): bool
    {
        $tok = $this->peek();
        if ($tok['kind'] === 'punct' && $tok['value'] === $value) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function consumePunct(string $value): bool { return $this->matchPunct($value); }

    /** @return array{kind:string,value:string,pos:int}|null */
    private function expect(string $kind, string $msg): ?array
    {
        $tok = $this->peek();
        if ($tok['kind'] !== $kind) {
            $this->errors[] = $msg . ' (got "' . $tok['value'] . '")';
            return null;
        }
        $this->advance();
        return $tok;
    }

    /**
     * @return list<array{kind:string,value:string,pos:int}>
     */
    private function tokenize(string $source): array
    {
        // Strip line comments.
        $source = preg_replace('!//[^\n]*!', '', $source) ?? $source;
        // Strip block comments.
        $source = preg_replace('!/\*.*?\*/!s', '', $source) ?? $source;

        $tokens = [];
        $len = strlen($source);
        $i = 0;
        while ($i < $len) {
            $ch = $source[$i];
            if (ctype_space($ch)) { $i++; continue; }

            if ($ch === "'" || $ch === '"') {
                $start = $i;
                $i++;
                $buf = '';
                while ($i < $len && $source[$i] !== $ch) {
                    if ($source[$i] === "\\" && $i + 1 < $len) {
                        $buf .= $source[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $buf .= $source[$i];
                    $i++;
                }
                $i++; // closing quote
                $tokens[] = ['kind' => 'string', 'value' => $buf, 'pos' => $start];
                continue;
            }

            if (ctype_digit($ch) || ($ch === '-' && $i + 1 < $len && ctype_digit($source[$i + 1]))) {
                $start = $i;
                if ($ch === '-') $i++;
                while ($i < $len && (ctype_digit($source[$i]) || $source[$i] === '.')) $i++;
                $tokens[] = ['kind' => 'number', 'value' => substr($source, $start, $i - $start), 'pos' => $start];
                continue;
            }

            if (ctype_alpha($ch) || $ch === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($source[$i]) || $source[$i] === '_')) $i++;
                $tokens[] = ['kind' => 'ident', 'value' => substr($source, $start, $i - $start), 'pos' => $start];
                continue;
            }

            // Single-char punctuation
            if (str_contains('{}[]()<>|&,;:?=', $ch)) {
                $tokens[] = ['kind' => 'punct', 'value' => $ch, 'pos' => $i];
                $i++;
                continue;
            }

            // Unknown character — skip.
            $i++;
        }
        return $tokens;
    }
}
