# DiSyL Extension Troubleshooting

## Syntax Highlighting Not Working

If `.disyl` files are not being highlighted properly, follow these steps:

### 1. Verify Extension Installation

```bash
# Check if extension is installed
windsurf --list-extensions | grep disyl
# or
code --list-extensions | grep disyl
```

Expected output: `ikabud.disyl`

### 2. Check Extension Location

```bash
# For Windsurf
ls -la ~/.windsurf/extensions/ikabud.disyl-*/

# For VS Code
ls -la ~/.vscode/extensions/ikabud.disyl-*/
```

You should see:
- `syntaxes/disyl.tmLanguage.json` (syntax highlighting rules)
- `package.json` (extension manifest)
- `out/extension.js` (compiled extension code)

### 3. Reinstall Extension

```bash
cd /var/www/html/ikabud-kernel/vscode-disyl

# Remove old versions
rm -rf ~/.windsurf/extensions/ikabud.disyl-*
rm -rf ~/.vscode/extensions/ikabud.disyl-*

# Install latest version
./install.sh
```

### 4. Reload IDE

After installation, **you must reload the IDE**:

1. Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
2. Type: `Developer: Reload Window`
3. Press Enter

### 5. Verify File Association

Open a `.disyl` file and check the bottom-right corner of the IDE. It should show:
- Language: **DiSyL** (not "Plain Text")

If it shows "Plain Text":
1. Click on "Plain Text"
2. Select "Configure File Association for '.disyl'"
3. Choose "DiSyL"

### 6. Test Syntax Highlighting

Open `test-syntax.disyl` to verify highlighting works:

```bash
windsurf test-syntax.disyl
```

You should see:
- **Blue/Purple**: Keywords like `if`, `for`, `else`, `include`
- **Green**: Component names like `ikb_section`, `ikb_text`
- **Orange**: Variables and expressions like `{post.title}`
- **Gray**: Comments like `{!-- comment --}`
- **Red/Pink**: Strings in quotes

## Common Issues

### Issue: "Extension not found"

**Solution**: Build the VSIX first:
```bash
npm run compile
npm run package
./install.sh
```

### Issue: "Old version still active"

**Solution**: Remove all old versions:
```bash
windsurf --uninstall-extension ikabud.disyl
./install.sh
```

### Issue: "Syntax highlighting only partially works"

**Solution**: Check if the syntax file is corrupted:
```bash
cat ~/.windsurf/extensions/ikabud.disyl-*/syntaxes/disyl.tmLanguage.json | jq .
```

If this shows an error, reinstall the extension.

### Issue: "Extension installed but not loading"

**Solution**: Check IDE logs:
1. Press `Ctrl+Shift+P`
2. Type: `Developer: Show Logs`
3. Select "Extension Host"
4. Look for errors related to "disyl"

## Manual Installation (If CLI Fails)

1. Build the VSIX:
   ```bash
   npm run compile
   npm run package
   ```

2. In Windsurf/VS Code:
   - Press `Ctrl+Shift+P`
   - Type: `Extensions: Install from VSIX`
   - Select: `/var/www/html/ikabud-kernel/vscode-disyl/disyl-0.5.0.vsix`

3. Reload the window

## Verify Installation

Run this command to verify everything is in place:

```bash
cd /var/www/html/ikabud-kernel/vscode-disyl
./verify-installation.sh
```

## Get Help

If issues persist:
1. Check the extension version: `windsurf --list-extensions --show-versions | grep disyl`
2. Check IDE version: `windsurf --version`
3. Review extension logs in the IDE
4. File an issue with the output of the above commands
