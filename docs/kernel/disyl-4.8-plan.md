# DiSyL 4.8 Plan

## Status

Planned. Current runtime baseline remains DiSyL 4.7.

## Goal

Introduce explicitly typed template assignment and tighten authoring-time validation without regressing 4.7 compatibility.

## Planned language additions

1. Typed `{set}` assignment syntax:

```disyl
{set title: string = "Welcome"}
{set amount: number = price * qty}
```

2. Expanded validation feedback for type mismatches in strict mode.
3. Optional tooling support for typed variable hints in editor/lint output.

## Compatibility rules

- 4.7 syntax (`{set var = expr}`) remains fully supported.
- Typed syntax is additive; no mandatory migration for existing templates.
- Compiled and interpreted modes must stay behaviorally aligned.

## Implementation checkpoints

1. Parser support for typed assignment grammar.
2. Compiler support for typed assignment nodes.
3. Interpreted mode support and error reporting parity.
4. Docs updates (`disyl-language-reference.md`, grammar docs, instructions).
5. Regression tests for both typed + untyped assignment forms.

## Out of scope

- Full static type system rollout.
- Breaking syntax changes to existing `{set}` behavior.

## Acceptance criteria

1. Typed assignment works in interpreted and compiled modes.
2. Existing templates render unchanged.
3. Lint/tests include typed assignment coverage and pass in CI.