# DiSyL - Declarative Ikabud Syntax Language

VSCode/Windsurf extension for DiSyL template files with syntax highlighting, snippets, and language support.

## Quick Install

```bash
cd vscode-disyl
./setup.sh
```

That's it! The script will:
1. Build the extension package (if needed)
2. Install for Windsurf (with profile registration)
3. Install for VSCode
4. Verify the installation

After installation, **reload your editor** (`Ctrl+Shift+P` → "Developer: Reload Window").

## Features

- **Syntax Highlighting** - Full grammar support for DiSyL v1.2.0
  - Component tags (`{ikb_section}`, `{ikb_container}`, etc.)
  - Control structures (`{if}`, `{for}`, `{switch}`, etc.)
  - Expressions and filter pipelines (`{$var|filter:arg}`)
  - Platform declarations (`{ikb_platform}`, `{ikb_cms}`)
  - Embedded HTML, CSS, JavaScript

- **Code Snippets** - Quick insertion with Tab completion
  - `ikb-section` → Section component
  - `ikb-container` → Container component
  - `ikb-if` → Conditional block
  - `ikb-for` → Loop block
  - `ikb-include` → Include statement

- **Language Configuration**
  - Bracket matching and auto-closing
  - Comment toggling (`{!-- comment --}`)
  - Smart indentation
  - Code folding

## Manual Installation

If the setup script doesn't work, you can install manually:

```bash
# Build the extension
./build-vsix.sh

# Install for Windsurf
windsurf --install-extension disyl-0.8.0.vsix --force

# Install for VSCode
code --install-extension disyl-0.8.0.vsix --force
```

**Important for Windsurf:** After CLI installation, you may need to run `./install.sh` to register the extension in your Windsurf profiles.

## Troubleshooting

### .disyl files not recognized

1. Click the language indicator in the status bar (bottom-right)
2. Select "DiSyL" from the list
3. If "DiSyL" is not in the list, reload the editor

### Extension not activating in Windsurf

Windsurf uses profile-based extension management. Run:

```bash
./install.sh
```

This registers the extension in all Windsurf profiles.

### Diagnostics

Run the diagnostic script:

```bash
./diagnose.sh
```

## File Structure

```
vscode-disyl/
├── setup.sh                    # One-command installer
├── build-vsix.sh               # Build script
├── install.sh                  # Install with profile registration
├── package.json                # Extension manifest
├── language-configuration.json # Brackets, comments, folding
├── syntaxes/
│   └── disyl.tmLanguage.json   # TextMate grammar
└── snippets/
    └── disyl.json              # Code snippets
```

## Requirements

- VSCode 1.80.0+ or Windsurf
- Python 3 (for Windsurf profile registration)

## Version History

See [CHANGELOG.md](CHANGELOG.md) for release notes.

## License

MIT
