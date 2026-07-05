# DiSyL Documentation Ownership Split

## Goal

Keep one canonical source per concern to prevent drift between grammar/spec docs and runtime implementation docs.

## Ownership Rules

### `docs/disyl/` (Language Spec Surface)

Use this tree for language-level, implementation-agnostic content:

- grammar references and EBNF
- syntax and keyword contracts
- operator semantics at language level
- RFC/proposals for syntax changes

Canonical files include:

- `docs/disyl/disyl-grammar-v4.7.ebnf`
- `docs/disyl/disyl-grammar-v4.7.md`

### `docs/kernel/disyl-*.md` (Runtime / Engine Surface)

Use this tree for engine/runtime behavior and operational guidance:

- parser/compiler/runtime implementation behavior
- compiled vs interpreted execution notes
- cache/runtime/deploy considerations
- kernel integration with entity views/components/async execution

Canonical files include:

- `docs/kernel/disyl-language-reference.md`
- `docs/kernel/disyl-overview.md`
- `docs/kernel/disyl-component-system.md`
- `docs/kernel/disyl-async-fibers-scheduler.md`

## Synchronization Policy

1. If grammar/syntax changes, update `docs/disyl/` first.
2. If runtime behavior changes without syntax changes, update `docs/kernel/` docs first.
3. Any PR that changes both syntax and runtime must update both trees in one change set.
4. Cross-link both canonical entry points in changed docs.

## Drift Guard

During review, reject new duplicate "language reference" content in both trees unless one is explicitly marked as summary with a pointer to the canonical file.