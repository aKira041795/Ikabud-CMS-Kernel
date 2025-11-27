# DiSyL Language Server Protocol Features

This document describes the advanced Language Server Protocol (LSP) features available in the DiSyL extension v0.5.0+.

## 🚀 Features Overview

### 1. IntelliSense / Auto-Completion

The extension provides intelligent code completion for:

#### Components
- `ikb_section` - Container section for organizing content
- `ikb_container` - Responsive container with size options
- `ikb_text` - Text component with styling
- `ikb_button` - Interactive button component
- `ikb_card` - Card component with shadow and padding
- `ikb_image` - Image component with lazy loading
- `ikb_grid` - Responsive grid layout
- `ikb_query` - Query and loop through data

#### Control Structures
- `if` - Conditional statement with condition attribute
- `for` - Loop through items with items and as attributes
- `include` - Include another DiSyL file

#### Filters
- `esc_html` - Escape HTML entities
- `esc_url` - Escape and sanitize URL
- `esc_attr` - Escape HTML attribute
- `strip_tags` - Remove HTML tags
- `truncate` - Truncate text to specified length
- `upper` / `lower` - Case conversion
- `date` - Format date
- `number_format` - Format numbers
- `raw` - Output raw HTML (use with caution)

**Trigger Characters:** `{`, `|`, ` `, `=`, `"`

### 2. Real-Time Validation & Diagnostics

The language server validates your DiSyL code as you type:

#### Error Detection
- **Unclosed tags**: Detects components without closing tags
- **Mismatched tags**: Identifies when closing tags don't match opening tags
- **Invalid filters**: Warns about unknown or misspelled filters

#### Example Errors

```disyl
{ikb_section}
    {ikb_text}Hello{/ikb_text}
{/ikb_container}  ❌ Error: Mismatched closing tag
```

```disyl
{ikb_section}
    Content here
❌ Error: Unclosed tag: {ikb_section}
```

```disyl
{post.title | unknownfilter}  ⚠️ Warning: Unknown filter
```

### 3. Hover Documentation

Hover over any DiSyL component or filter to see detailed documentation:

#### Component Hover
```disyl
{ikb_section type="hero"}
     ↑ Hover shows:
     **ikb_section** - Container section for organizing content
     
     **Attributes:**
     - type: Section type (hero, main, footer)
     - padding: Padding size (small, medium, large)
```

#### Filter Hover
```disyl
{post.title | truncate:length=50}
                ↑ Hover shows:
                **truncate** - Truncate text to specified length
                
                Usage: {text | truncate:length=100,append="..."}
```

### 4. Document Formatting

Format your DiSyL documents with proper indentation:

**Before:**
```disyl
{ikb_section type="main"}
{ikb_container}
{ikb_text}Hello{/ikb_text}
{/ikb_container}
{/ikb_section}
```

**After (Formatted):**
```disyl
{ikb_section type="main"}
    {ikb_container}
        {ikb_text}Hello{/ikb_text}
    {/ikb_container}
{/ikb_section}
```

**Keyboard Shortcut:** `Shift+Alt+F` (Windows/Linux) or `Shift+Option+F` (Mac)

**Command:** `DiSyL: Format Document`

### 5. Signature Help

Get parameter hints while typing component attributes:

```disyl
{ikb_image src=
           ↑ Shows available attributes:
           - src: Image source URL
           - alt: Alt text
           - lazy: Enable lazy loading (true/false)
           - width: Image width
           - height: Image height
```

### 6. Document Symbols

Navigate your DiSyL document structure easily with the outline view.

### 7. Go to Definition

Jump to included files:

```disyl
{include file="components/header.disyl" /}
              ↑ F12 to jump to file
```

## 🎯 Commands

The extension provides several commands accessible via Command Palette (`Ctrl+Shift+P` / `Cmd+Shift+P`):

### DiSyL: Format Document
Formats the current DiSyL document with proper indentation.

### DiSyL: Show Component Preview
Opens a webview panel showing a preview of DiSyL components.

### DiSyL: Validate Document
Manually triggers validation of the current document.

### DiSyL: Insert Component
Opens a quick pick menu to insert a DiSyL component snippet.

## ⚙️ Settings

Configure the DiSyL extension behavior in VS Code settings:

### disyl.maxNumberOfProblems
- **Type:** `number`
- **Default:** `100`
- **Description:** Maximum number of problems to report

### disyl.validateOnType
- **Type:** `boolean`
- **Default:** `true`
- **Description:** Validate DiSyL syntax as you type

### disyl.formatOnSave
- **Type:** `boolean`
- **Default:** `true`
- **Description:** Automatically format DiSyL files on save

## 🔧 Configuration Example

```json
{
  "disyl.maxNumberOfProblems": 50,
  "disyl.validateOnType": true,
  "disyl.formatOnSave": true,
  "[disyl]": {
    "editor.tabSize": 4,
    "editor.insertSpaces": true,
    "editor.wordWrap": "on",
    "editor.formatOnSave": true,
    "editor.quickSuggestions": {
      "other": true,
      "comments": false,
      "strings": true
    }
  }
}
```

## 🐛 Troubleshooting

### Language Server Not Starting

1. Check the Output panel: `View > Output > DiSyL Language Server`
2. Restart the extension: `Developer: Reload Window`
3. Check for errors in the Developer Console: `Help > Toggle Developer Tools`

### Completions Not Working

1. Ensure you're in a `.disyl` file
2. Check that the language mode is set to "DiSyL" (bottom right of status bar)
3. Try triggering manually with `Ctrl+Space`

### Validation Not Working

1. Check `disyl.validateOnType` setting is enabled
2. Look for errors in the Output panel
3. Try saving the file to trigger validation

## 📊 Performance

The DiSyL language server is designed to be lightweight and fast:

- **Startup time:** < 100ms
- **Validation:** Real-time, incremental
- **Memory usage:** < 50MB
- **CPU usage:** Minimal, event-driven

## 🔮 Future Features

Planned enhancements for future releases:

- [ ] Code actions and quick fixes
- [ ] Refactoring support (rename component, extract to include)
- [ ] Semantic highlighting
- [ ] Inlay hints for filter parameters
- [ ] Code lens for component usage
- [ ] Debugging support
- [ ] Live preview with hot reload
- [ ] Component library browser
- [ ] Snippet generator from HTML
- [ ] Integration with DiSyL CLI tools

## 📚 Resources

- [DiSyL Grammar Specification](https://ikabud.com/disyl/grammar)
- [Component Reference](https://ikabud.com/disyl/components)
- [Filter Reference](https://ikabud.com/disyl/filters)
- [GitHub Repository](https://github.com/ikabud/disyl)
- [Issue Tracker](https://github.com/ikabud/disyl/issues)

## 🤝 Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## 📄 License

MIT License - see [LICENSE](LICENSE) for details.
