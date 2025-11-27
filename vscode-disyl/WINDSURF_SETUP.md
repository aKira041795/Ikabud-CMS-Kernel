# DiSyL Extension Setup for Windsurf IDE

## Quick Setup (3 Steps)

### 1. Install the Extension

```bash
cd /var/www/html/ikabud-kernel/vscode-disyl
./install.sh
```

### 2. Reload Windsurf

- Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
- Type "Reload Window"
- Press Enter

### 3. Test It

Open any `.disyl` file and verify:
- ✅ Syntax highlighting is active
- ✅ Bottom-right shows "DiSyL" as the language
- ✅ Type `section` and press Tab to test snippets

---

## Verification Checklist

### ✓ Extension Installed
```bash
# Check if extension is installed
ls ~/.config/Windsurf/User/extensions/ | grep disyl
# or
ls ~/.windsurf/extensions/ | grep disyl
```

### ✓ Language Detection
1. Open a `.disyl` file
2. Check bottom-right corner of editor
3. Should display "DiSyL"

### ✓ Syntax Highlighting
Open the test file:
```bash
code /var/www/html/ikabud-kernel/vscode-disyl/test-syntax.disyl
```

You should see:
- **Purple/Blue**: DiSyL components (`ikb_section`, `ikb_text`, etc.)
- **Orange/Yellow**: Control keywords (`if`, `for`, `else`)
- **Green**: Strings and attribute values
- **Cyan**: Variables and expressions
- **Gray**: Comments

### ✓ Snippets Working
1. Create a new `.disyl` file
2. Type `section` and press Tab
3. Should expand to:
   ```disyl
   {ikb_section type="" padding="large"}
       
   {/ikb_section}
   ```

---

## Common Snippets

| Prefix | Expands To | Description |
|--------|-----------|-------------|
| `section` | `{ikb_section}...{/ikb_section}` | Section component |
| `container` | `{ikb_container}...{/ikb_container}` | Container component |
| `text` | `{ikb_text}...{/ikb_text}` | Text component |
| `button` | `{ikb_button}...{/ikb_button}` | Button component |
| `if` | `{if}...{/if}` | If statement |
| `ifelse` | `{if}...{else}...{/if}` | If-else statement |
| `for` | `{for}...{/for}` | For loop |
| `include` | `{include file="" /}` | Include directive |
| `query` | `{ikb_query}...{/ikb_query}` | Query component |
| `fesc_html` | `{var \| esc_html}` | HTML escape filter |
| `ftruncate` | `{var \| truncate:length=}` | Truncate filter |
| `template` | Full template structure | Complete template |

---

## Troubleshooting

### Issue: Syntax highlighting not working

**Solution 1: Manually set language**
1. Click language indicator (bottom-right)
2. Type "DiSyL"
3. Select "DiSyL" from list

**Solution 2: Reload window**
1. Press `Ctrl+Shift+P`
2. Type "Reload Window"
3. Press Enter

**Solution 3: Reinstall extension**
```bash
cd /var/www/html/ikabud-kernel/vscode-disyl
./install.sh
```

### Issue: Extension not found

**Check installation:**
```bash
# List all extensions
ls ~/.config/Windsurf/User/extensions/
# or
ls ~/.windsurf/extensions/
```

**Manual install:**
1. Open Windsurf
2. Go to Extensions (`Ctrl+Shift+X`)
3. Click "..." menu
4. Select "Install from VSIX..."
5. Navigate to: `/var/www/html/ikabud-kernel/vscode-disyl/`
6. Select `disyl-0.4.0.vsix`

### Issue: Snippets not expanding

**Solution 1: Check settings**
1. Open Settings (`Ctrl+,`)
2. Search for "snippet suggestions"
3. Ensure it's not set to "none"

**Solution 2: Use Ctrl+Space**
- Type the prefix
- Press `Ctrl+Space`
- Select snippet from list
- Press Enter

**Solution 3: Verify language mode**
- Ensure file is recognized as DiSyL
- Check bottom-right corner
- Should show "DiSyL"

---

## Features Overview

### 🎨 Syntax Highlighting
- DiSyL components (`ikb_*`)
- Control structures (`if`, `for`, `else`)
- Expressions (`{variable}`)
- Filter pipelines (`{var | filter}`)
- Comments (`{!-- comment --}`)
- HTML tags and attributes

### ✨ Code Snippets
- 30+ pre-built snippets
- Common patterns (hero, post loop, etc.)
- Filter shortcuts
- Template structures

### 🔧 Editor Features
- Auto-closing brackets and quotes
- Smart indentation
- Code folding
- Bracket matching
- Comment toggling (`Ctrl+/`)

### 📝 Language Configuration
- Tab size: 4 spaces
- Word wrap: enabled
- Quick suggestions: enabled
- Auto-indent on Enter

---

## Advanced Configuration

### Custom Settings for DiSyL

Add to your Windsurf `settings.json`:

```json
{
  "[disyl]": {
    "editor.tabSize": 4,
    "editor.insertSpaces": true,
    "editor.wordWrap": "on",
    "editor.formatOnSave": false,
    "editor.quickSuggestions": {
      "other": true,
      "comments": false,
      "strings": true
    },
    "editor.suggest.snippetsPreventQuickSuggestions": false
  }
}
```

### File Associations

If `.disyl` files aren't auto-detected, add to `settings.json`:

```json
{
  "files.associations": {
    "*.disyl": "disyl"
  }
}
```

---

## Next Steps

1. ✅ Extension installed and working
2. 📖 Read the [README.md](README.md) for full documentation
3. 🧪 Try the [test-syntax.disyl](test-syntax.disyl) file
4. 📚 Check [INSTALL.md](INSTALL.md) for detailed instructions
5. 🎯 Start building with DiSyL!

---

## Support

- **Issues**: Report problems on GitHub
- **Documentation**: https://ikabud.com/disyl
- **Examples**: Check `/instances/wp-brutus-cli/wp-content/themes/phoenix/disyl/`

---

**Happy coding with DiSyL in Windsurf!** 🌊✨
