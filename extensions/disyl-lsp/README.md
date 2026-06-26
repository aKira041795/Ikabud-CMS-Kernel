# DiSyL Language Support for VS Code

**Version:** 1.0.0 | **Extension ID:** `disyl-lsp`

Syntax highlighting, hover documentation, go-to-definition, close-tag completion, diagnostics, and autocomplete for `.disyl` template files.

> **Note:** This extension uses native VS Code extension providers (not the Language Server Protocol). The features are identical to what an LSP-based extension would provide, but the implementation is direct through the VS Code extension API.

## Features

### Syntax Highlighting
Full DiSyL grammar including variables, filters, blocks, governed components, and 4.x extensions.

### Hover Provider (New in 1.0.0)
Hover over any DiSyL keyword or governed component to see documentation:

| Keyword | Docs |
|---|---|
| `{if}` / `{elseif}` / `{else}` | Conditional rendering details |
| `{for}` / `{forelse}` | Loop syntax and semantics |
| `{await}` / `{parallel}` | Async block with Fibers |
| `{include}` | Template inclusion |
| `{ikb_entity_list}` | Entity list rendering docs |
| `{ikb_entity_view}` | View contract registration |
| All 32 `ikb_*` components | Purpose, attributes, examples |

### Go-to-Definition (New in 1.0.0)
- **`{include "path/to/template.disyl"}`** — Ctrl+Click or F12 navigates to the included file
- Resolves both relative and absolute template paths

### Close-Tag Completion (New in 1.0.0)
| Open | Completes |
|---|---|
| `{if}` → `{endif}` | `{for}` → `{endfor}` |
| `{while}` → `{endwhile}` | `{await}` → `{endawait}` |
| `{parallel}` → `{endparallel}` | `{capture}` → `{endcapture}` |

### Diagnostics
Runs `php ikabud disyl:lint` on save to detect unclosed components and syntax issues.

### Autocomplete
23 DiSyL filters, 31 governed components, and all block keywords.
- **Go to definition** — `{extends}`, `{include}`, and `{component}` paths navigate to source files
- **Bracket matching** — `{if}`/`{/if}`, `{foreach}`/`{/foreach}`, `{block}`/`{/block}`
- **Comment support** — `{! ... !}` block comments with toggle

## Requirements

- PHP 8.2+ with the Ikabud Kernel CLI available at the workspace root
- The `ikabud` script must be executable: `php ikabud disyl:lint`

## Extension Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `disyl.lintOnSave` | `true` | Run linter on file save |
| `disyl.lintOnType` | `false` | Run linter as you type |
| `disyl.phpCommand` | `php` | Path to PHP executable |
| `disyl.ikabudPath` | (auto) | Path to ikabud CLI script |

## Commands

- `DiSyL: Lint current file` — Lint the active `.disyl` file
- `DiSyL: Lint all templates` — Lint all templates and show results in output panel
- `DiSyL: Show cheatsheet` — Quick reference of all DiSyL syntax patterns (select to copy)
- `DiSyL: Open quickstart guide` — Open the DiSyL in 5 Minutes guide (`docs/disyl/quickstart.md`)

## What's New in v1.1.0

- **EBNF-based structural validator** — Instant in-process validation without PHP. Detects unclosed blocks ({if}/{foreach}/{block}), unbalanced component tags (<ikb_*>), malformed expressions, and string quoting errors.
- **lintOnType enabled by default** — The EBNF validator runs in ~1ms, so real-time diagnostics as you type are now viable.
- **Two-phase linting** — Phase 1: EBNF structural validation (instant, offline). Phase 2: PHP semantic validation (capability checks, variable existence, filter validity).
- **Grammar reference** — The EBNF grammar is now available at `docs/disyl/disyl-grammar-v4.7.ebnf` (56 production rules, ISO/IEC 14977).
