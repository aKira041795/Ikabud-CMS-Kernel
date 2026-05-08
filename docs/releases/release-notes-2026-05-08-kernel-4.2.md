# Kernel 4.2.0 — DiSyL Type System v1

**Date:** 2026-05-08
**Codename:** atlas (4.x line)
**Status:** Released

## TL;DR

DiSyL templates can now declare a structural type for their context and have
a static checker catch unknown properties before render. This is an opt-in
addition shipped as a *practical subset* of TypeScript-style structural
typing — the rules that catch the most common template bugs without the
multi-week complexity of a full TS-class checker.

```disyl
{types}
type User = { id: number; name: string; email?: string }
context: { user: User; items: User[] }
{/types}

<h1>Hello, {user.name}!</h1>
{foreach items as person}
  <li>{person.email}</li>
{/foreach}
```

If a template references `{user.fullname}` (not declared) the checker reports
`DISYL_TYPE_UNKNOWN_PROP` with template name + line number. The `{types}`
block itself is **always stripped from rendered output** — it is compile-time
metadata only.

## What's new

- **`{types}` block** parsed by `Ikabud\Kernel\DiSyL\Types\TypeParser`.
- **Type AST** in `kernel/DiSyL/Types/TypeAst.php`: `PrimitiveType`,
  `LiteralType`, `ObjectType`, `ArrayType`, `UnionType`, `TypeRef`.
- **Structural subtyping** via `Subtype::assignable()` covering:
  - Literal-to-primitive widening (`'admin'` ⊆ `string`)
  - Object width subtyping with optional + readonly props
  - Union distribution (source all-of, target any-of)
  - Array element compatibility with readonly variance
- **Utility types (5)** in `UtilityTypes`: `Partial`, `Required`,
  `Readonly`, `Pick<T, K>`, `Omit<T, K>`.
- **TypeChecker** walks the template, gathers `{var.path}` references and
  control-tag heads (`{if x.y}`, `{for v in items}`,
  `{foreach list as v}`, `{match expr}`), and validates each path against
  the declared context type.
- **CLI tool** `scripts/disyl-typecheck.php` for batch checking with
  `--json` and `--quiet` flags. Exits non-zero on any diagnostic.
- **Engine integration**: `removeComments()` now strips `{types}` blocks
  alongside `{!--…--}` and `{*…*}`, so existing render paths automatically
  ignore them.

## Files added

```
kernel/DiSyL/Types/TypeAst.php            (108 lines)
kernel/DiSyL/Types/TypeParser.php         (340 lines)
kernel/DiSyL/Types/Subtype.php            (130 lines)
kernel/DiSyL/Types/UtilityTypes.php       (135 lines)
kernel/DiSyL/Types/TypeChecker.php        (245 lines)
scripts/disyl-typecheck.php                (90 lines)
tests/disyl_v42_types_test.php            (200 lines, 34 assertions)
```

## Files modified

```
kernel/App.php                          KERNEL_VERSION → 4.2.0
kernel/DiSyL/TemplateEngine.php         removeComments() now strips {types}
```

## Verification

```
php tests/disyl_v4_test.php           → 36/36 pass
php tests/disyl_v41_match_test.php    → 14/14 pass
php tests/disyl_v41_i18n_test.php     → 12/12 pass
php tests/disyl_v42_types_test.php    → 34/34 pass
php scripts/disyl-typecheck.php …     → exit 0 clean / exit 1 with diags
```

## Compatibility

- **Backward compatible.** Templates without a `{types}` block are
  unchanged. The checker is opt-in.
- No public API renamed or removed.
- The engine's render pipeline is unchanged — `{types}` blocks are stripped
  in the same comment-removal pass that already handled `{!--…--}`.
- The autoloader convention required adding `require_once` of `TypeAst.php`
  at the top of consumer files (the AST is multi-class-per-file). No
  bootstrap changes were needed.

## Honest scope statement

This release ships a **practical subset** of structural typing. Implemented:
primitives, literals, objects, arrays, unions, optional + readonly,
Partial / Required / Readonly / Pick / Omit, dotted-path navigation, and
local-binding awareness for `{for}` / `{foreach}` / `{set}`.

Deferred to **4.2.1** (multi-week work each, not pretended here):

- Intersection types (`A & B`)
- Conditional types with `extends` and `infer`
- `keyof`, `typeof`, indexed access (`T[K]`)
- `Record`, `Exclude`, `Extract`, `NonNullable`, `ReturnType`,
  `Parameters`, `Awaited`
- Per-branch narrowing in `{if}` / `{match}`
- Tuple types, generic type aliases, mapped types

The 4.2.0 surface catches the common cases (unknown property, missing
required prop, primitive mismatch on literal unions) without the formal
complexity of a complete TS checker. Heavier features will land in 4.2.1
when time permits a careful subtype-relation rewrite.

## Diagnostic codes

- `DISYL_TYPE_PARSE_ERROR` — malformed `{types}` block syntax
- `DISYL_TYPE_NO_CONTEXT` — `{types}` block declared no `context: TYPE`
- `DISYL_TYPE_UNKNOWN_PROP` — referenced property not on context type
- `DISYL_TYPE_BAD_INDEX` — dotted path through a non-object type

## Migration notes

None required. To opt in for a given template, add a `{types}` block and
run `php scripts/disyl-typecheck.php path/to/template.disyl` in CI.
