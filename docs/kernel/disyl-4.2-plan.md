# DiSyL 4.2 — Type System v1 (compile-time)

**Target kernel:** 4.2.0
**Risk:** medium (new compiler phase, but compile-time only — zero runtime cost)
**Depends on:** 4.1

## Goals

Make render-context contracts expressible **in DiSyL itself** with TypeScript-class
expressiveness, checked at compile time, with first-class IDE error positions.

Today contracts are PHP arrays validated at boot ([kernel/DiSyL/v4/RenderContext.php](../../kernel/DiSyL/v4/RenderContext.php)). This release keeps that for runtime, but adds a parallel **type declaration** surface checked by a new compiler pass.

## Non-goals

- No runtime type checking (boot-time validation stays as today).
- No structural inference across templates in 4.2 — only within a single template + its declared imports.
- No higher-kinded types.

## Surface

### Declaration block

```disyl
{[ types ]}
  type ProductCard = {
    id: string;
    name: string;
    price: number;
    image?: string;
    tags: readonly string[];
  };

  type ProductWithStock = ProductCard & {
    stock: number | null;
  };

  type ListProps = {
    items: ProductCard[];
    layout: 'grid' | 'list';
  };

  context: ListProps;
{[ endtypes ]}
```

Rules:
- One `{[ types ]}` block per template, near the top.
- Declares zero or more named types and exactly one `context: TYPE` line.
- Types persist for the template's lifetime; not visible to includes unless re-imported.

### Operators (from `Grammar\Planned::TYPE_OPERATORS`)

| Operator | Meaning |
|---|---|
| `\|` | Union |
| `&` | Intersection |
| `?` after a key | Optional property |
| `!` | Non-null assertion (rejects `null` / `undefined` from a wider type) |
| `...` | Spread (in object literal type) |
| `extends` | Conditional / constraint |
| `infer` | Bind a name in conditional |
| `keyof` | Keys of an object type |
| `typeof` | Type of an existing value (limited to declared context fields) |
| `readonly` | Mark prop / array readonly |

### Utility types (from `Grammar\Planned::UTILITY_TYPES`)

`Partial`, `Required`, `Readonly`, `Pick`, `Omit`, `Record`, `Exclude`, `Extract`,
`NonNullable`, `ReturnType`, `Parameters`, `Awaited`.

`ReturnType` / `Parameters` / `Awaited` only make sense once 4.5 lands; in 4.2 they
parse and are checked structurally but resolve to `unknown` if applied to anything
that isn't a known async type.

## Architecture

New package `kernel/DiSyL/Types/`:

| File | Purpose |
|---|---|
| `TypeAst.php` | Type expression AST (Union, Intersection, ObjectType, ArrayType, LiteralType, TypeRef, Conditional, Mapped, ReadonlyMod, Optional, Spread). |
| `TypeParser.php` | Parses the contents of `{[ types ]}` blocks. Reused for `infer`/`keyof` clauses inside conditionals. |
| `TypeChecker.php` | Walks the template AST. Each variable/property/expression node yields an inferred type that's compared against the declared context type. Reports errors with template+line. |
| `Subtype.php` | Structural subtype + assignability rules (TS-style: object width, union distribution, intersection narrowing). |
| `UtilityTypes.php` | Implements the 12 utility types as `TypeChecker` transformations. |
| `TypeReport.php` | Diagnostic format consumed by the IDE and CLI checker. |

### Engine integration

- Parser collects `{[ types ]}` block content as a `TypesBlockNode`.
- Compile pipeline invokes `TypeChecker` after the AST is fully built but before
  string-processing/evaluation. Failures are non-fatal in dev (rendered as a banner)
  and fatal in production (`$this->strictMode === true`).
- Cached: type-check result keyed by `(template_path, mtime, types_block_hash)`.
  Runtime cost = APCu hit on warm path.

### CLI

`php scripts/disyl-typecheck.php [--module=ID] [--template=PATH] [--json]`

- Walks every template in scope.
- Emits a structured report.
- Exit non-zero on any error.

## Errors

`DISYL_TYPE_CTX_MISMATCH`, `DISYL_TYPE_UNKNOWN_PROP`, `DISYL_TYPE_BAD_INDEX`,
`DISYL_TYPE_NULL_DEREF`, `DISYL_TYPE_DUP_TYPES_BLOCK`, `DISYL_TYPE_NO_CONTEXT`,
`DISYL_TYPE_RECURSION_LIMIT`, `DISYL_TYPE_BAD_UTILITY_ARG`.

## Tests

`tests/disyl_v42_types_test.php` (~30 cases):
- Each operator parses correctly
- Each utility type behaves like TS for canonical cases
- Subtype: object width, contravariant args, covariant returns
- Union narrowing on `if (x.kind === 'a')` patterns
- `keyof` on declared object → string literal union
- `Partial<T>` / `Required<T>` / `Pick<T, K>` / `Omit<T, K>` / `Record<K, V>`
- Recursion depth limit (50)

## Acceptance

- All tests pass.
- Existing `disyl_v4_test.php` + `disyl_v41_*` still green.
- Type-check on the full module template tree completes in < 1s on a warm cache.
- CLI returns 0 on a clean tree.
