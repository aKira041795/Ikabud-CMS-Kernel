# DiSyL Grammar — v11 Planned Type System

> **Status:** Roadmap / future-looking (keyof now implemented at runtime)  
> **Removed from:** `kernel/DiSyL/Grammar.php` in kernel 4.7+  
> **Canonical roadmap:** `kernel/DiSyL/Grammar/Planned.php`  
> **Date archived:** 2026-06-19  
> **Updated:** 2026-06-24 — `keyof` runtime implementation complete in `TemplateEngine::resolveKeyof()`

## Why these types are NOT in the live Grammar

The v11 advanced type constants (`TYPE_UNION`, `TYPE_GENERIC`, etc.) were declared in
`Grammar.php` as forward-looking placeholders. They were **never wired** into any runtime
path:

| Component | References to v11 types |
|-----------|------------------------|
| `v4/Parser.php` | 0 — parses only basic types |
| `v4/TemplateCompiler.php` | 0 — compiles only basic expressions |
| `TemplateEngine.php` | 0 — evaluates only basic types |
| `Grammar::validateType()` | Falls through to `default => true` (no actual validation) |
| Any test file | 0 |

Keeping them in the live `Grammar.php` was misleading:
- They appear to be usable types but silently pass validation without checks.
- They add ~50 lines of constants + dead bridge methods to a 199-line class.
- The actual v11/v11.1 roadmap lives properly in `Grammar/Planned.php`.

## Removed constants

```php
// v11: Advanced Type Constants (removed from Grammar.php in 4.7+)
public const TYPE_NEVER = 'never';
public const TYPE_UNKNOWN = 'unknown';
public const TYPE_VOID = 'void';
public const TYPE_ANY = 'any';
public const TYPE_UNION = 'union';
public const TYPE_INTERSECTION = 'intersection';
public const TYPE_GENERIC = 'generic';
public const TYPE_TUPLE = 'tuple';
public const TYPE_LITERAL = 'literal';
public const TYPE_TEMPLATE_LITERAL = 'template_literal';
public const TYPE_CONDITIONAL = 'conditional';
public const TYPE_MAPPED = 'mapped';
public const TYPE_INFER = 'infer';
```

## Removed bridge methods

```php
// These delegated to Grammar\Planned but were never called externally:
public static function getAllKeywords(): array    // → removed (implemented keywords moved to Grammar.php)
public static function getV11Keywords(): array    // → removed (implemented keywords moved to Grammar.php)
public static function isUtilityType(string $type): bool  // → Planned::isUtilityType() (still there)
public static function getAdvancedTypes(): array  // returned the 13 constants above
```

## Implementation Status

| Operator | Status | Since |
|----------|--------|-------|
| `keyof` | ✅ Runtime implementation in `TemplateEngine::resolveKeyof()`. Supports direct output, filters (`\| json`, `\| join`), and `{for}` iteration. | v4.10 |
| `typeof` | 🔴 Planned — no code | — |
| `\|` (union) | 🔴 Planned — no code | — |
| `&` (intersection) | 🔴 Planned — no code | — |

## Reinstatement guide

When DiSyL reaches a version where these types are actually parsed/compiled/validated:

1. **Add the type constant** back to `Grammar.php` under the `// Type Constants` block.
2. **Wire it in `validateType()`** — add a `match` arm that actually validates the type.
3. **Update `getTypes()`** — include it in the return array so it's recognized as a valid type.
4. **Implement parser support** in `v4/Parser.php` or a new `v5/Parser.php`.
5. **Implement compiler support** in `Compiler/TemplateCompiler.php`.
6. **Add tests** covering type validation, parsing, and compilation.

Do NOT add a type constant until steps 2-6 are done. A constant without runtime
support is a false promise.

## Related: Planned.php

`kernel/DiSyL/Grammar/Planned.php` is the canonical home for roadmap keywords.
It is intentionally separate from the live grammar and uses `PLANNED — not yet implemented`
annotations throughout. When a planned feature graduates to implementation:

1. Move its constants from `Planned.php` to the appropriate runtime class.
2. Remove the `PLANNED` annotation.
3. Add parser/compiler/engine support.
4. Add tests.

## v11 Type system design notes

These types are inspired by TypeScript's advanced type system and are intended
for a future DiSyL version that supports:

- **Union types** (`string | int`) — value can be one of several types
- **Intersection types** (`A & B`) — value must satisfy all constituent types
- **Generic types** (`Array<string>`) — parameterized containers
- **Tuple types** (`[string, int]`) — fixed-length, typed arrays
- **Literal types** (`"active" | "inactive"`) — exact value constraints
- **Template literal types** — string pattern matching
- **Conditional types** (`T extends U ? X : Y`) — type-level branching
- **Mapped types** — transform object shapes
- **Type inference** (`infer T`) — extract types from patterns
- **Utility types** (`Partial<T>`, `Pick<T, K>`, etc.) — built-in type transforms

These are aspirational. The v4 type system is intentionally simple: `string`,
`integer`, `number`, `boolean`, `array`, `object`, `mixed`, `null`, `callable`,
`expression`. This covers 100% of current template use cases.
