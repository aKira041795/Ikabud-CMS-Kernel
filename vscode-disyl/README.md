# DiSyL Language Support for VS Code / Windsurf

**Full-featured Language Server Protocol (LSP) extension for DiSyL (Declarative Ikabud Syntax Language) with IntelliSense, validation, formatting, and more.**

[![Version](https://img.shields.io/badge/version-0.5.0-blue.svg)](https://github.com/ikabud/disyl)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Windsurf](https://img.shields.io/badge/Windsurf-Compatible-purple.svg)](https://codeium.com/windsurf)
[![LSP](https://img.shields.io/badge/LSP-Enabled-brightgreen.svg)](LSP_FEATURES.md)

## ✨ Features

### 🚀 Language Server Protocol (LSP) - NEW in v0.5.0!

- **IntelliSense**: Smart auto-completion for components, filters, and control structures
- **Real-time Validation**: Instant error detection for unclosed tags, mismatched components, and invalid filters
- **Hover Documentation**: Detailed docs for components and filters on hover
- **Document Formatting**: Auto-format with proper indentation (`Shift+Alt+F`)
- **Signature Help**: Parameter hints while typing
- **Go to Definition**: Jump to included files
- **Document Symbols**: Navigate your template structure

[📖 See full LSP features documentation](LSP_FEATURES.md)

### 🎨 Syntax Highlighting

- **Components**: `ikb_section`, `ikb_text`, `ikb_button`, etc.
- **Control Structures**: `if`, `for`, `include`
- **Expressions**: `{variable}`, `{item.property}`
- **Filter Pipelines**: `{text | upper | truncate:50}`
- **Comments**: `{!-- comment --}`
- **HTML Integration**: Full HTML syntax support

### ✨ Snippets

Over 30 pre-built snippets for common DiSyL patterns:

#### Components
- `section` → ikb_section
- `container` → ikb_container
- `text` → ikb_text
- `button` → ikb_button
- `card` → ikb_card
- `image` → ikb_image
- `grid` → ikb_grid
- `query` → ikb_query

#### Control Structures
- `if` → if statement
- `ifelse` → if-else statement
- `for` → for loop
- `include` → include directive

#### Filters
- `fesc_html` → {var | esc_html}
- `fesc_url` → {var | esc_url}
- `fupper` → {var | upper}
- `ftruncate` → {var | truncate:length=50}
- `fdate` → {var | date:format="Y-m-d"}
- `fraw` → {var | raw}

#### Templates
- `template` → Complete template structure
- `postloop` → Post loop pattern
- `hero` → Hero section

### 🔧 Language Configuration

- **Auto-closing pairs**: `{}`, `[]`, `()`, `""`, `''`
- **Bracket matching**: Highlights matching braces
- **Comment toggling**: Block comments with `{!-- --}`
- **Code folding**: Fold DiSyL components and control structures
- **Smart indentation**: Auto-indent inside components

### 📝 Editor Settings

Optimized editor settings for DiSyL files:
- Tab size: 4 spaces
- Word wrap: enabled
- Quick suggestions: enabled

## Installation

### 🚀 Quick Install (Recommended)

```bash
cd vscode-disyl
./install.sh
```

The script will:
- Install dependencies
- Package the extension
- Auto-detect Windsurf/VS Code
- Install to all detected IDEs

### Manual Installation

#### From VSIX

1. Download or build the `.vsix` file
2. Open Windsurf or VS Code
3. Go to Extensions (`Ctrl+Shift+X` / `Cmd+Shift+X`)
4. Click the "..." menu → "Install from VSIX..."
5. Select the `.vsix` file

#### From Source

```bash
cd vscode-disyl
npm install
npm run package
# For VS Code:
code --install-extension disyl-0.4.0.vsix
# For Windsurf:
windsurf --install-extension disyl-0.4.0.vsix
```

### Windsurf-Specific Notes

- Extension is fully compatible with Windsurf IDE
- Syntax highlighting works out of the box
- All snippets and auto-completion features supported
- File association for `.disyl` files automatic

## Usage

### File Extension

Create files with `.disyl` extension:
- `home.disyl`
- `single.disyl`
- `components/header.disyl`

### Basic Syntax

```disyl
{!-- DiSyL Template --}
{include file="components/header.disyl" /}

{ikb_section type="main" padding="large"}
    {ikb_container size="large"}
        {ikb_text size="2xl" weight="bold"}
            {post.title | esc_html}
        {/ikb_text}
        
        <div class="content">
            {post.content | raw}
        </div>
    {/ikb_container}
{/ikb_section}

{include file="components/footer.disyl" /}
```

### Filter Pipelines

```disyl
{!-- Single filter --}
{post.title | esc_html}

{!-- Multiple filters --}
{post.excerpt | strip_tags | truncate:length=150 | esc_html}

{!-- Named arguments --}
{post.date | date:format="F j, Y"}
{item.price | number_format:decimals=2,dec_point=".",thousands_sep=","}

{!-- Mixed positional and named --}
{text | truncate:50,append="..."}
```

### Control Structures

```disyl
{!-- If statement --}
{if condition="{user.logged_in}"}
    Welcome back, {user.name | esc_html}!
{else}
    Please log in.
{/if}

{!-- For loop --}
{for items="{posts}" as="post"}
    <h2>{post.title | esc_html}</h2>
    <p>{post.excerpt | strip_tags | truncate:150}</p>
{/for}

{!-- Include --}
{include file="components/sidebar.disyl" /}
```

## DiSyL Grammar v0.3

This extension supports DiSyL Grammar v0.3 with:

- ✅ Filter pipeline syntax
- ✅ Multiple filter arguments (positional and named)
- ✅ Unified control structures
- ✅ Expression contexts (standalone, attribute, text)
- ✅ Unicode support
- ✅ CMS-agnostic design

## Color Themes

DiSyL syntax highlighting works with all VS Code themes. Recommended themes for best experience:

- **Dark**: One Dark Pro, Dracula, Night Owl
- **Light**: Light+, Solarized Light, Atom One Light

## Keyboard Shortcuts

- **Toggle Comment**: `Ctrl+/` (Cmd+/ on Mac)
- **Format Document**: `Shift+Alt+F` (Shift+Option+F on Mac)
- **Go to Definition**: `F12`
- **Peek Definition**: `Alt+F12` (Option+F12 on Mac)

## Known Issues

- Inline comments `/* */` are defined in grammar but not yet implemented in the parser
- Method calls `item.method()` are defined in grammar but not yet implemented

## Roadmap

- [x] IntelliSense / Autocomplete ✅ v0.5.0
- [x] Hover documentation ✅ v0.5.0
- [x] Go to definition for components ✅ v0.5.0
- [x] Linting / Error detection ✅ v0.5.0
- [x] Code formatting ✅ v0.5.0
- [ ] Code actions and quick fixes
- [ ] Refactoring support (rename, extract)
- [ ] Semantic highlighting
- [ ] Debugging support
- [ ] Live preview with hot reload

## Contributing

Contributions are welcome! Please visit:
- GitHub: https://github.com/ikabud/disyl
- Documentation: https://ikabud.com/disyl

## License

MIT License - see LICENSE file for details

## Credits

**DiSyL** is developed by the Ikabud team as a universal, CMS-agnostic templating language.

- **Grammar**: DiSyL v0.3 (Production-ready)
- **Extension Version**: 0.5.0 (LSP-enabled)
- **Last Updated**: November 16, 2025
- **Architecture**: TypeScript + Language Server Protocol

---

**Enjoy coding with DiSyL!** 🚀
