# DiSyL Language Support for VS Code

Syntax highlighting, diagnostics, autocomplete, and navigation for `.disyl` template files.

## Features

- **Syntax highlighting** — Full DiSyL grammar including variables, filters, blocks, governed components, and 4.x extensions
- **Diagnostics** — Runs `php ikabud disyl:lint` on save to detect unclosed components and syntax issues
- **Autocomplete** — 23 DiSyL filters, 31 governed components, and all block keywords
- **Hover documentation** — Filter descriptions and component docs on hover
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
